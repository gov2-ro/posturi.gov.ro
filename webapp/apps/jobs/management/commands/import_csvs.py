"""Import the scraper's three CSVs into the database (idempotent).

Run from inside webapp/:
    python manage.py import_csvs [--data-dir ../data]
"""
from __future__ import annotations

import csv
import re
from collections import defaultdict
from datetime import date, datetime, time, timedelta
from pathlib import Path

from django.conf import settings
from django.core.management.base import BaseCommand, CommandError
from django.db import connection, transaction
from django.utils import timezone
from django.utils.text import slugify

from apps.jobs.judete import COUNTIES as CANONICAL_COUNTIES
from apps.jobs.judete import normalize_judet
from apps.jobs.models import CalendarEvent, Employer, JobPosting, Judet

RO_MONTHS = {
    "ianuarie": 1, "februarie": 2, "martie": 3, "aprilie": 4,
    "mai": 5, "iunie": 6, "iulie": 7, "august": 8,
    "septembrie": 9, "octombrie": 10, "noiembrie": 11, "decembrie": 12,
}

DATE_ISO_RE = re.compile(r"\b(\d{4})-(\d{1,2})-(\d{1,2})\b")
DATE_DOT_RE = re.compile(r"\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b")
DATE_SLASH_RE = re.compile(r"\b(\d{1,2})/(\d{1,2})/(\d{4})\b")
DATE_RO_RE = re.compile(r"\b(\d{1,2})\s+([a-zăâîșţț]+)\s*,?\s*(\d{4})\b", re.IGNORECASE)
TIME_RE = re.compile(r"ora\s+(\d{1,2})[.:](\d{2})", re.IGNORECASE)


def parse_date(value: str) -> date | None:
    """Parse the various date formats appearing in the CSVs."""
    if not value:
        return None
    s = value.strip()
    if not s:
        return None
    # Strip leading "Publicat în:" / "Expiră in" / similar
    s = re.sub(r"^\s*(publicat\s+[îi]n\s*:?|expir[aă]\s+[îi]n)\s*", "", s, flags=re.IGNORECASE).strip()
    # YYYY-MM-DD (parse-anunturi.py emits ISO dates in the detail CSV)
    m = DATE_ISO_RE.search(s)
    if m:
        y, mo, d = (int(x) for x in m.groups())
        try:
            return date(y, mo, d)
        except ValueError:
            return None
    # DD.MM.YYYY
    m = DATE_DOT_RE.search(s)
    if m:
        d, mo, y = (int(x) for x in m.groups())
        try:
            return date(y, mo, d)
        except ValueError:
            return None
    # DD/MM/YYYY
    m = DATE_SLASH_RE.search(s)
    if m:
        d, mo, y = (int(x) for x in m.groups())
        try:
            return date(y, mo, d)
        except ValueError:
            return None
    # "9 septembrie, 2024"
    m = DATE_RO_RE.search(s)
    if m:
        d, month_name, y = m.groups()
        mo = RO_MONTHS.get(month_name.lower())
        if mo:
            try:
                return date(int(y), mo, int(d))
            except ValueError:
                return None
    return None


RECENT_WINDOW_DAYS = 30
MISSING_EXPIRY_RATIO = 0.5


def expiry_sanity_warnings(active: int, recent_total: int, recent_without_expiry: int) -> list[str]:
    """Check that expiry parsing still works, and return a warning per problem.

    Exists because the 2026-07 site redesign swapped absolute dates on index
    cards for a relative countdown ("1 zi rămasă"). `expires_at` silently went
    NULL for every posting scraped afterwards, which left the deployed
    active-only export with 3 rows — and nothing failed, so it went unnoticed
    for five weeks. Both checks are ratios rather than absolute floors, so they
    stay meaningful as the dataset grows.
    """
    warnings = []
    if recent_total and recent_without_expiry / recent_total > MISSING_EXPIRY_RATIO:
        pct = 100 * recent_without_expiry / recent_total
        warnings.append(
            f"{recent_without_expiry}/{recent_total} ({pct:.0f}%) of postings published in the "
            f"last {RECENT_WINDOW_DAYS} days have no expires_at — expiry parsing is probably "
            f"broken (check the `expira_in` / `Data Expirare` formats against the live site)."
        )
    if recent_total and not active:
        warnings.append(
            f"0 active postings, but {recent_total} were published in the last "
            f"{RECENT_WINDOW_DAYS} days — an active-only export would ship an empty site."
        )
    return warnings


#: A county count above this means the Judet table is fragmenting again.
MAX_EXPECTED_COUNTIES = len(CANONICAL_COUNTIES)


def judet_sanity_warnings(county_count: int, unresolved_postings: int, total: int) -> list[str]:
    """Check that county normalisation is still holding, one warning per problem.

    Exists because the source badge changed shape at the 2026-07 redesign
    ("Timiş" → "TIMIŞOARA, Timiș") and the importer stored both verbatim, giving
    one county up to 11 separate Judet rows — 261 in total. Nothing failed; the
    județ facet just quietly stopped matching most postings. Both checks are
    cheap and run on every import.
    """
    warnings = []
    if county_count > MAX_EXPECTED_COUNTIES:
        warnings.append(
            f"{county_count} Judet rows, but Romania has {MAX_EXPECTED_COUNTIES} counties — "
            f"normalisation is not catching some spelling. Run "
            f"`manage.py normalize_judete --dry-run` to see the split."
        )
    if unresolved_postings:
        pct = 100 * unresolved_postings / total if total else 0
        warnings.append(
            f"{unresolved_postings} posting(s) ({pct:.1f}%) have a județ the normaliser could not "
            f"match to a county — they answer to no county filter. Inspect them in admin "
            f"(Județ — rezolvare → Nerecunoscut) and add an entry to apps.jobs.judete.ALIASES."
        )
    return warnings


def plausible_expiry(d: date | None, max_year: int) -> date | None:
    """Drop expiry dates outside a sane window.

    The source site occasionally carries a typo'd year — e.g. an
    `Expiră in 14/01/2046` on a posting published in December 2025 — which
    would otherwise leave that posting permanently "active".
    """
    if d is None or not (2000 <= d.year <= max_year):
        return None
    return d


def parse_datetime_with_time(value: str) -> datetime | None:
    """Parse 'DD.MM.YYYY' or 'DD.MM.YYYY, ora HH.MM' into an aware datetime."""
    d = parse_date(value)
    if not d:
        return None
    t = time(0, 0)
    m = TIME_RE.search(value)
    if m:
        h, mi = int(m.group(1)), int(m.group(2))
        if 0 <= h < 24 and 0 <= mi < 60:
            t = time(h, mi)
    tz = timezone.get_current_timezone()
    return timezone.make_aware(datetime.combine(d, t), tz)


def parse_int(value: str) -> int | None:
    if not value:
        return None
    m = re.search(r"\d+", value)
    return int(m.group(0)) if m else None


def split_links(value: str) -> list[str]:
    if not value:
        return []
    return [s.strip() for s in value.split(",") if s.strip().startswith("http")]


def unique_slug(name: str, model, taken: set[str], max_len: int = 240) -> str:
    base = slugify(name)[:max_len] or "x"
    candidate = base
    i = 1
    # Check both in-memory and DB
    while candidate in taken or model.objects.filter(slug=candidate).exists():
        i += 1
        candidate = f"{base}-{i}"[: max_len + 6]
    taken.add(candidate)
    return candidate


class Command(BaseCommand):
    help = "Import scraper CSVs (index + anunturi + calendar) into the database."

    def add_arguments(self, parser):
        parser.add_argument(
            "--strict",
            action="store_true",
            help="Exit non-zero if the post-import sanity checks fail (for cron/CI).",
        )
        parser.add_argument(
            "--data-dir",
            type=Path,
            default=settings.DATA_DIR,
            help="Path to the data/ directory containing the CSVs.",
        )

    def handle(self, *args, **opts):
        data_dir: Path = opts["data_dir"]
        index_csv = data_dir / "posturi_gov_ro.csv"
        anunturi_csv = data_dir / "anunturi" / "anunturi.csv"
        calendar_csv = data_dir / "calendar.csv"

        for p in (index_csv, anunturi_csv, calendar_csv):
            if not p.exists():
                self.stderr.write(self.style.WARNING(f"Missing: {p}"))

        if not index_csv.exists():
            raise CommandError(f"Required file missing: {index_csv}")

        today = timezone.localdate()
        max_year = today.year + 2

        # ---- Pass 0: collect distinct judet + employer names ----
        with index_csv.open(newline="", encoding="utf-8") as f:
            rows = list(csv.DictReader(f))

        # Normalise the source's județ badge before it reaches the database.
        # It arrives in two shapes ("Timiş" and "TIMIŞOARA, Timiș"), and storing
        # both verbatim used to give one county several Judet rows — and so
        # several separate entries in the browse facet. See apps.jobs.judete.
        judet_parse_by_raw = {
            raw: normalize_judet(raw)
            for raw in {r.get("judet", "").strip() for r in rows}
            if raw
        }
        judet_names = sorted({p.judet for p in judet_parse_by_raw.values() if p.judet})
        unresolved_raw = sorted(
            {raw for raw, p in judet_parse_by_raw.items() if not p.resolved}
        )
        employer_names = set()
        for r in rows:
            n = r.get("angajator", "").strip()
            if n:
                employer_names.add(n)

        # Add employers from anunturi (some may differ)
        if anunturi_csv.exists():
            with anunturi_csv.open(newline="", encoding="utf-8") as f:
                anunt_rows = list(csv.DictReader(f))
            for r in anunt_rows:
                n = r.get("Employer", "").strip()
                if n:
                    employer_names.add(n)
        else:
            anunt_rows = []

        # ---- Build expires_at lookup from the detail CSV ----
        # The redesigned site shows a relative countdown on index cards
        # ("1 zi rămasă"), not a date, so `expira_in` is unparseable for every
        # posting scraped after the 2026-07 redesign. The detail page carries an
        # absolute date, which parse-anunturi.py writes as `Data Expirare`.
        expires_by_url: dict[str, date] = {}
        implausible = 0
        for r in anunt_rows:
            src = r.get("Source URL", "").strip()
            raw = r.get("Data Expirare", "").strip()
            if not src or not raw:
                continue
            parsed = parse_date(raw)
            d = plausible_expiry(parsed, max_year)
            if d is None:
                implausible += int(parsed is not None)
                continue
            expires_by_url[src] = d
        self.stdout.write(
            f"Detail expiry dates: {len(expires_by_url)} usable"
            + (f", {implausible} rejected as implausible" if implausible else "")
        )

        # ---- Seed Judet ----
        self.stdout.write(
            f"Seeding Judet ({len(judet_names)} canonical counties "
            f"from {len(judet_parse_by_raw)} distinct source values)…"
        )
        if unresolved_raw:
            # Loud on purpose: an unmatched value means either a new spelling
            # from the source or a scraper regression, and it silently removes
            # the posting from every county filter.
            self.stderr.write(self.style.WARNING(
                f"  {len(unresolved_raw)} județ value(s) could not be matched to a county — "
                f"postings keep them in `judet_raw` and have no county: "
                + ", ".join(repr(v) for v in unresolved_raw[:10])
                + (" …" if len(unresolved_raw) > 10 else "")
            ))
        judet_by_name: dict[str, Judet] = {}
        with transaction.atomic():
            existing_slugs = set(Judet.objects.values_list("slug", flat=True))
            for name in judet_names:
                obj = Judet.objects.filter(name=name).first()
                if obj is None:
                    slug = unique_slug(name, Judet, existing_slugs, max_len=140)
                    obj = Judet.objects.create(name=name, slug=slug)
                judet_by_name[name] = obj

        # ---- Seed Employer ----
        self.stdout.write(f"Seeding Employer ({len(employer_names)} distinct)…")
        employer_by_name: dict[str, Employer] = {}
        with transaction.atomic():
            existing_slugs = set(Employer.objects.values_list("slug", flat=True))
            for name in sorted(employer_names):
                obj = Employer.objects.filter(name=name).first()
                if obj is None:
                    slug = unique_slug(name, Employer, existing_slugs, max_len=240)
                    obj = Employer.objects.create(name=name, slug=slug)
                employer_by_name[name] = obj

        # ---- Pass 1: upsert JobPosting from index CSV ----
        self.stdout.write(f"Importing index rows ({len(rows)})…")
        created = updated = errors = 0
        implausible_index = 0
        for r in rows:
            url = r["url"].strip()
            if not url:
                errors += 1
                continue
            employer = employer_by_name.get(r["angajator"].strip())
            judet_parse = judet_parse_by_raw.get(r.get("judet", "").strip())
            judet = judet_by_name.get(judet_parse.judet) if judet_parse and judet_parse.judet else None
            # Detail page first; the index `expira_in` is a countdown string on
            # the new site and only parses for pre-redesign rows.
            expires_at = expires_by_url.get(url)
            if expires_at is None:
                parsed = parse_date(r.get("expira_in", ""))
                expires_at = plausible_expiry(parsed, max_year)
                implausible_index += int(parsed is not None and expires_at is None)
            defaults = {
                "title": r.get("pozitie", "").strip(),
                "employer": employer,
                "detalii_raw": r.get("detalii", "").strip(),
                "published_at": parse_date(r.get("publicat_in", "")),
                "expires_at": expires_at,
                "judet": judet,
                "locality": (judet_parse.locality or "") if judet_parse else "",
                # Only populated when the county could not be resolved, so
                # `judet_raw != ""` is the "needs attention" filter.
                "judet_raw": (judet_parse.raw if judet_parse and not judet_parse.resolved else ""),
                "url_judet": r.get("url_judet", "").strip(),
                "tip": r.get("tip", "").strip(),
                "updates_raw": r.get("updates", "").strip(),
                "last_seen_at": today,
            }
            try:
                obj, was_created = JobPosting.objects.update_or_create(url=url, defaults=defaults)
                created += int(was_created)
                updated += int(not was_created)
            except Exception as e:  # pragma: no cover
                self.stderr.write(f"index row error for {url}: {e}")
                errors += 1
        self.stdout.write(self.style.SUCCESS(
            f"  Index: created={created} updated={updated} errors={errors}"
            + (f" (dropped {implausible_index} implausible expiry dates)" if implausible_index else "")
        ))

        # ---- Pass 2: detail rows from anunturi.csv (join on Source URL) ----
        if anunt_rows:
            self.stdout.write(f"Importing detail rows ({len(anunt_rows)})…")
            matched = unmatched = d_errors = 0
            postings_by_url = {
                p.url: p for p in JobPosting.objects.in_bulk(
                    [r["Source URL"].strip() for r in anunt_rows if r.get("Source URL")],
                    field_name="url",
                ).values()
            }
            for r in anunt_rows:
                src = r.get("Source URL", "").strip()
                if not src:
                    d_errors += 1
                    continue
                posting = postings_by_url.get(src)
                if posting is None:
                    unmatched += 1
                    continue
                try:
                    posting.job_level = r.get("Job Level", "").strip()
                    posting.job_type = r.get("Job Type", "").strip()
                    posting.employer_category = r.get("Employer Category", "").strip()
                    posting.categorie = r.get("Categorie", "").strip()
                    posting.announcement_url = r.get("Announcement URL", "").strip()
                    body = r.get("Main Body Markdown", "")
                    # parse-anunturi.py escapes newlines as literal "\n"; restore them
                    posting.body_markdown = body.replace("\\n", "\n")
                    posting.other_links = split_links(r.get("Other Links", ""))
                    posting.nr_posturi = parse_int(r.get("Nr Posturi", ""))
                    posting.contact_phone = r.get("Contact Telefon", "").strip()
                    posting.contact_email = r.get("Contact Email", "").strip()
                    posting.contact_person = r.get("Contact Persoana", "").strip()
                    posting.data_limita_depunere = parse_datetime_with_time(r.get("Data Limita Depunere", ""))
                    posting.data_proba_scrisa = parse_date(r.get("Data Proba Scrisa", ""))
                    posting.data_interviu = parse_date(r.get("Data Interviu", ""))
                    posting.data_rezultate_finale = parse_date(r.get("Data Rezultate Finale", ""))
                    posting.save(update_fields=[
                        "job_level", "job_type", "employer_category", "categorie",
                        "announcement_url", "body_markdown", "other_links", "nr_posturi",
                        "contact_phone", "contact_email", "contact_person",
                        "data_limita_depunere", "data_proba_scrisa", "data_interviu",
                        "data_rezultate_finale", "updated_at",
                    ])
                    matched += 1
                except Exception as e:  # pragma: no cover
                    self.stderr.write(f"detail row error for {src}: {e}")
                    d_errors += 1
            self.stdout.write(self.style.SUCCESS(
                f"  Detail: matched={matched} unmatched={unmatched} errors={d_errors}"
            ))

        # ---- Pass 3: calendar events ----
        if calendar_csv.exists():
            with calendar_csv.open(newline="", encoding="utf-8") as f:
                cal_rows = list(csv.DictReader(f))
            self.stdout.write(f"Importing calendar events ({len(cal_rows)})…")

            by_url: dict[str, list[dict]] = defaultdict(list)
            for r in cal_rows:
                u = r.get("url", "").strip()
                if u:
                    by_url[u].append(r)

            postings_by_url = {
                p.url: p for p in JobPosting.objects.filter(url__in=list(by_url.keys())).only("id", "url")
            }

            c_created = c_unmatched = c_errors = 0
            for u, items in by_url.items():
                posting = postings_by_url.get(u)
                if posting is None:
                    c_unmatched += len(items)
                    continue
                try:
                    with transaction.atomic():
                        CalendarEvent.objects.filter(posting=posting).delete()
                        objs = []
                        for r in items:
                            d = parse_date(r.get("data", ""))
                            if not d:
                                c_errors += 1
                                continue
                            t = None
                            ora_str = r.get("ora", "").strip()
                            if ora_str:
                                # formats: "14:00" or "14.00"
                                tm = re.match(r"(\d{1,2})[.:](\d{2})", ora_str)
                                if tm:
                                    h, mi = int(tm.group(1)), int(tm.group(2))
                                    if 0 <= h < 24 and 0 <= mi < 60:
                                        t = time(h, mi)
                            objs.append(CalendarEvent(
                                posting=posting,
                                eveniment=r.get("eveniment", "").strip()[:500],
                                data=d,
                                ora=t,
                            ))
                        CalendarEvent.objects.bulk_create(objs)
                        c_created += len(objs)
                except Exception as e:  # pragma: no cover
                    self.stderr.write(f"calendar error for {u}: {e}")
                    c_errors += len(items)
            self.stdout.write(self.style.SUCCESS(
                f"  Calendar: created={c_created} unmatched={c_unmatched} errors={c_errors}"
            ))

        # ---- Pass 4: search_vector ----
        self.stdout.write("Updating search_vector…")
        with connection.cursor() as cur:
            cur.execute(
                """
                UPDATE jobs_jobposting AS j
                SET search_vector =
                    setweight(to_tsvector('romanian_unaccent', coalesce(j.title, '')), 'A')
                    || setweight(to_tsvector('romanian_unaccent', coalesce(e.name, '')), 'B')
                    || setweight(to_tsvector('romanian_unaccent', coalesce(j.body_markdown, '')), 'C')
                    || setweight(to_tsvector('romanian_unaccent', coalesce(j.attachment_text, '')), 'D')
                FROM jobs_employer AS e
                WHERE j.employer_id = e.id
                """
            )
        self.stdout.write(self.style.SUCCESS("  search_vector updated."))

        # ---- Post-import sanity check ----
        recent_cutoff = today - timedelta(days=RECENT_WINDOW_DAYS)
        recent = JobPosting.objects.filter(published_at__gte=recent_cutoff)
        recent_total = recent.count()
        recent_without_expiry = recent.filter(expires_at__isnull=True).count()
        active = JobPosting.objects.filter(expires_at__gte=today).count()

        self.stdout.write(
            f"Sanity: {active} active postings; "
            f"{recent_without_expiry}/{recent_total} published in the last "
            f"{RECENT_WINDOW_DAYS} days lack an expiry date."
        )
        county_count = Judet.objects.count()
        unresolved_postings = JobPosting.objects.exclude(judet_raw="").count()
        with_locality = JobPosting.objects.exclude(locality="").count()
        self.stdout.write(
            f"Sanity: {county_count} counties; {unresolved_postings} postings with an "
            f"unresolved județ; {with_locality} with a locality."
        )

        warnings = expiry_sanity_warnings(active, recent_total, recent_without_expiry)
        warnings += judet_sanity_warnings(
            county_count, unresolved_postings, JobPosting.objects.count()
        )
        for w in warnings:
            self.stderr.write(self.style.ERROR(f"  ✗ {w}"))
        if warnings and opts.get("strict"):
            raise CommandError("Post-import sanity checks failed.")

        self.stdout.write(self.style.SUCCESS("Done."))

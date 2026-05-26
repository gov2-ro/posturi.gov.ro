"""Import the scraper's three CSVs into the database (idempotent).

Run from inside webapp/:
    python manage.py import_csvs [--data-dir ../data]
"""
from __future__ import annotations

import csv
import re
from collections import defaultdict
from datetime import date, datetime, time
from pathlib import Path

from django.conf import settings
from django.core.management.base import BaseCommand, CommandError
from django.db import connection, transaction
from django.utils import timezone
from django.utils.text import slugify

from apps.jobs.models import CalendarEvent, Employer, JobPosting, Judet

RO_MONTHS = {
    "ianuarie": 1, "februarie": 2, "martie": 3, "aprilie": 4,
    "mai": 5, "iunie": 6, "iulie": 7, "august": 8,
    "septembrie": 9, "octombrie": 10, "noiembrie": 11, "decembrie": 12,
}

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

        # ---- Pass 0: collect distinct judet + employer names ----
        with index_csv.open(newline="", encoding="utf-8") as f:
            rows = list(csv.DictReader(f))

        judet_names = sorted({r["judet"].strip() for r in rows if r.get("judet", "").strip()})
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

        # ---- Seed Judet ----
        self.stdout.write(f"Seeding Judet ({len(judet_names)} distinct)…")
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
        for r in rows:
            url = r["url"].strip()
            if not url:
                errors += 1
                continue
            employer = employer_by_name.get(r["angajator"].strip())
            judet = judet_by_name.get(r["judet"].strip()) if r.get("judet") else None
            defaults = {
                "title": r.get("pozitie", "").strip(),
                "employer": employer,
                "detalii_raw": r.get("detalii", "").strip(),
                "published_at": parse_date(r.get("publicat_in", "")),
                "expires_at": parse_date(r.get("expira_in", "")),
                "judet": judet,
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
                FROM jobs_employer AS e
                WHERE j.employer_id = e.id
                """
            )
        self.stdout.write(self.style.SUCCESS("  search_vector updated."))

        self.stdout.write(self.style.SUCCESS("Done."))

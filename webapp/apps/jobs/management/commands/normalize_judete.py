"""Collapse the Judet table onto the 42 canonical Romanian counties.

`import_csvs` used to store the source's județ badge verbatim, and the badge has
two shapes — bare `Timiş` before the 2026-07 redesign, `TIMIŞOARA, Timiș` after.
Both became their own `Judet` row, so one county ended up spread across many:
261 distinct values for a country with 42 counties, and a browse facet where
picking "Cluj" missed every posting filed under `CLUJ-NAPOCA, Cluj`.

The importer now normalises on the way in (see `apps.jobs.judete`); this repairs
what is already stored. Idempotent — a second run reports nothing to do.

    python manage.py normalize_judete --dry-run
    python manage.py normalize_judete
"""

from __future__ import annotations

from collections import defaultdict

from django.core.management.base import BaseCommand
from django.db import transaction
from django.utils.text import slugify

from apps.jobs.judete import COUNTIES, normalize_judet
from apps.jobs.models import JobPosting, Judet

BATCH = 2000


class Command(BaseCommand):
    help = "Merge Judet rows onto canonical counties; backfill locality and judet_raw."

    def add_arguments(self, parser):
        parser.add_argument(
            "--dry-run",
            action="store_true",
            help="Report what would change without writing.",
        )

    def handle(self, *args, **opts):
        dry = opts["dry_run"]
        existing = list(Judet.objects.all())
        self.stdout.write(f"{len(existing)} Judet rows, {JobPosting.objects.count()} postings")

        # ---- Plan: every existing row → a canonical county (or None) ----
        plan: dict[int, tuple[str | None, str | None]] = {}
        unresolved: list[Judet] = []
        for j in existing:
            parsed = normalize_judet(j.name)
            plan[j.id] = (parsed.judet, parsed.locality)
            if not parsed.resolved:
                unresolved.append(j)

        targets = sorted({c for c, _ in plan.values() if c})
        already_canonical = sum(1 for j in existing if plan[j.id][0] == j.name)
        self.stdout.write(
            f"  → {len(targets)} canonical counties (of {len(COUNTIES)}); "
            f"{already_canonical} rows already canonical; {len(unresolved)} unresolved"
        )
        if unresolved:
            self.stderr.write(self.style.WARNING(
                "  unresolved (postings will keep the raw value in `judet_raw` and lose their county): "
                + ", ".join(repr(j.name) for j in unresolved[:15])
                + (" …" if len(unresolved) > 15 else "")
            ))

        by_target: dict[str, list[Judet]] = defaultdict(list)
        for j in existing:
            county = plan[j.id][0]
            if county:
                by_target[county].append(j)
        merges = {t: rows for t, rows in by_target.items() if len(rows) > 1}
        if merges:
            self.stdout.write(f"  {len(merges)} counties are currently split across several rows:")
            for county, rows in sorted(merges.items(), key=lambda kv: -len(kv[1]))[:8]:
                names = ", ".join(repr(r.name) for r in sorted(rows, key=lambda r: r.name)[:4])
                more = f" (+{len(rows) - 4})" if len(rows) > 4 else ""
                self.stdout.write(f"    {county}: {len(rows)} rows — {names}{more}")

        if dry:
            self.stdout.write(self.style.WARNING("dry run — nothing written"))
            return

        with transaction.atomic():
            # ---- Canonical rows. Reuse an exact-name match where one exists. ----
            canonical: dict[str, Judet] = {}
            for county in targets:
                obj = Judet.objects.filter(name=county).first()
                if obj is None:
                    # The ideal slug may still be held by a row about to be
                    # deleted; take a temporary one and reclaim it below.
                    obj = Judet(name=county, slug=self._free_slug(county))
                    obj.save()
                canonical[county] = obj

            # ---- Re-point postings, and backfill locality / judet_raw ----
            written = repointed = localities = flagged = 0
            qs = JobPosting.objects.select_related("judet").only("id", "judet", "locality", "judet_raw")
            buffer: list[JobPosting] = []
            for posting in qs.iterator(chunk_size=BATCH):
                county, locality = plan.get(posting.judet_id, (None, None)) if posting.judet_id else (None, None)
                new_judet_id = canonical[county].id if county else None
                # The locality is recovered from the *pre-merge* Judet name,
                # which this command then deletes. So only ever fill one in,
                # never clear one — otherwise a second run would erase what the
                # first recovered, silently and with a "0 changed" report.
                # Corrections belong to import_csvs, which reads the CSV.
                new_locality = locality or posting.locality
                # Keep the raw string only when it could not be matched, so
                # `judet_raw != ""` is the "needs attention" filter.
                new_raw = "" if (county or not posting.judet_id) else (posting.judet.name if posting.judet else "")

                if (posting.judet_id, posting.locality, posting.judet_raw) == (new_judet_id, new_locality, new_raw):
                    continue
                written += 1
                repointed += int(posting.judet_id != new_judet_id)
                localities += int(bool(new_locality) and not posting.locality)
                flagged += int(bool(new_raw) and not posting.judet_raw)
                posting.judet_id = new_judet_id
                posting.locality = new_locality
                posting.judet_raw = new_raw
                buffer.append(posting)
                if len(buffer) >= BATCH:
                    JobPosting.objects.bulk_update(buffer, ["judet", "locality", "judet_raw"])
                    buffer.clear()
            if buffer:
                JobPosting.objects.bulk_update(buffer, ["judet", "locality", "judet_raw"])

            # ---- Drop the now-unreferenced rows ----
            keep = {j.id for j in canonical.values()}
            # Collect ids first: Django refuses delete() on a distinct() queryset,
            # and postings__isnull needs the join to be de-duplicated.
            stale_ids = set(
                Judet.objects.exclude(id__in=keep)
                .filter(postings__isnull=True)
                .values_list("id", flat=True)
            )
            removed = len(stale_ids)
            Judet.objects.filter(id__in=stale_ids).delete()

            # ---- Reclaim the clean slugs the deleted rows were squatting on ----
            reslugged = 0
            for county, obj in canonical.items():
                ideal = slugify(county)[:140]
                if obj.slug != ideal and not Judet.objects.filter(slug=ideal).exclude(id=obj.id).exists():
                    obj.slug = ideal
                    obj.save(update_fields=["slug"])
                    reslugged += 1

        self.stdout.write(self.style.SUCCESS(
            f"Done: {written} postings written ({repointed} re-pointed, "
            f"{localities} localities backfilled, {flagged} newly flagged via judet_raw), "
            f"{removed} stale Judet rows deleted, {reslugged} slugs reclaimed. "
            f"{Judet.objects.count()} counties remain."
        ))

        left = Judet.objects.exclude(name__in=COUNTIES).count()
        if left:
            self.stderr.write(self.style.WARNING(
                f"{left} non-canonical Judet row(s) still referenced by postings — inspect them in admin."
            ))

    @staticmethod
    def _free_slug(name: str) -> str:
        base = slugify(name)[:140] or "judet"
        candidate, i = base, 1
        while Judet.objects.filter(slug=candidate).exists():
            i += 1
            candidate = f"{base}-tmp{i}"[:140]
        return candidate

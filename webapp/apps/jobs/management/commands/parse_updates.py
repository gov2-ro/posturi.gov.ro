"""
Parse the scraper change-log stored in JobPosting.updates_raw and create
JobPostingUpdate records for each detected event.

Format written by fetch-index.py::compare_and_update():
    "YYYY-MM-DD: New entry"
    "YYYY-MM-DD: New entry; YYYY-MM-DD: field1, field2; ..."

Usage:
    python manage.py parse_updates
    python manage.py parse_updates --force   # re-parse even if records exist
"""

from __future__ import annotations

import re
from datetime import date

from django.core.management.base import BaseCommand
from django.db import transaction

from apps.jobs.models import JobPosting, JobPostingUpdate

_SEGMENT_RE = re.compile(r"(\d{4}-\d{2}-\d{2}):\s*(.+?)(?=;\s*\d{4}-\d{2}-\d{2}:|$)")


def _parse_updates_raw(raw: str) -> list[tuple[date, str]]:
    """Return list of (changed_at, fields_changed) from a raw updates string."""
    results = []
    for m in _SEGMENT_RE.finditer(raw.strip()):
        try:
            changed_at = date.fromisoformat(m.group(1))
        except ValueError:
            continue
        fields = m.group(2).strip().rstrip(";").strip()
        if fields:
            results.append((changed_at, fields))
    return results


class Command(BaseCommand):
    help = "Parse updates_raw change-log into JobPostingUpdate records"

    def add_arguments(self, parser):
        parser.add_argument(
            "--force",
            action="store_true",
            help="Re-parse all postings even if JobPostingUpdate records already exist",
        )

    def handle(self, *args, **opts):
        force = opts["force"]

        qs = JobPosting.objects.exclude(updates_raw="")
        if not force:
            already_parsed = set(
                JobPostingUpdate.objects.values_list("posting_id", flat=True).distinct()
            )
            qs = qs.exclude(pk__in=already_parsed)

        total = qs.count()
        self.stdout.write(f"Parsing {total} postings…")

        created = skipped = errors = 0
        objs = []

        for posting in qs.iterator(chunk_size=500):
            segments = _parse_updates_raw(posting.updates_raw)
            if not segments:
                skipped += 1
                continue
            for changed_at, fields_changed in segments:
                objs.append(
                    JobPostingUpdate(
                        posting=posting,
                        changed_at=changed_at,
                        fields_changed=fields_changed,
                    )
                )

        if objs:
            with transaction.atomic():
                JobPostingUpdate.objects.bulk_create(objs, ignore_conflicts=True)
            created = len(objs)

        self.stdout.write(
            self.style.SUCCESS(
                f"Done. {created} records created, {skipped} postings skipped (no parseable segments), {errors} errors."
            )
        )

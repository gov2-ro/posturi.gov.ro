"""Record durable observations of the facts a reader may have saved a job for (UX-12).

Nothing else in the pipeline keeps an old value: columns, `schema_json` and the
cached HTML are overwritten in place, and `cancelled` is a boolean recomputed on
every import. So "the deadline moved from 14 to 21 October" can only ever be proven
by a record taken at the time. This command takes it: for each posting with a
successful detail retrieval it computes the tracked facts (`posting_facts.py`),
compares them with the latest stored observation of the same URL, and inserts a new
immutable `PostingObservation` row only when the source revision, the extraction
revision or the facts differ — or when there is none yet (the baseline).

    python manage.py observe_postings --dry-run
    python manage.py observe_postings
    python manage.py observe_postings --window-days 60 --retain-days 400

What it will NOT do, on purpose:
  * observe a posting that was never successfully retrieved (`detail_fetched_at`
    NULL): there is no source revision to anchor facts to;
  * record a failed fetch — a failed fetch changes nothing in the posting, so there
    is nothing to differ (the attempt itself is not persisted anywhere yet);
  * derive change events. Old/new facts, role association and stable event IDs are
    the read slice's job; role keys are revision-scoped and continuity across
    revisions is unresolved by design (posting_roles.py).

Exit status is non-zero only on a real error (a posting whose facts could not be
computed, a bad flag, a database failure). The pipeline treats this step as
optional, so even then the export still ships.
"""

from __future__ import annotations

import sys
from collections import Counter
from datetime import timedelta
from pathlib import Path

from django.core.management.base import BaseCommand, CommandError
from django.db import connection, transaction
from django.db.models import Min, Q
from django.utils import timezone

REPO_ROOT = Path(__file__).resolve().parents[5]
sys.path.insert(0, str(REPO_ROOT))

import posting_facts  # noqa: E402  (pure module at the repo root)

from apps.jobs.models import JobPosting, PostingObservation  # noqa: E402

#: Window for postings that are not cancelled: expired at most this many days ago.
#: Cancelled postings are always in scope — a cancellation is the fact readers need.
DEFAULT_WINDOW_DAYS = 30

BATCH = 500

#: Key of the transaction-scoped advisory lock that serialises runs (arbitrary,
#: but unique among this project's advisory locks).
_ADVISORY_LOCK_KEY = 20261010

_POSTING_FIELDS = (
    "id", "url", "expires_at", "cancelled", "detail_fetched_at", "detail_content_hash",
    "data_limita_depunere", "inferred", "schema_json",
    "schema_provider", "schema_model", "schema_prompt_version",
    "schema_source_revision", "schema_extracted_at",
)

_COMPARE_FIELDS = (
    "source_revision", *posting_facts.EXTRACTION_KEYS, "facts_hash",
)


def build_observation(row: dict, run_at) -> dict:
    """The `PostingObservation` field values for one posting row (a `.values()` dict)."""
    schema = row["schema_json"] if isinstance(row["schema_json"], dict) else None
    facts = posting_facts.tracked_facts(row, schema)
    return {
        "url": row["url"],
        "posting_id": row["id"],
        "observed_at": run_at,
        "retrieved_at": row["detail_fetched_at"],
        "source_revision": row["detail_content_hash"] or "",
        "extraction_provider": row["schema_provider"] or "",
        "extraction_model": row["schema_model"] or "",
        "extraction_prompt_version": row["schema_prompt_version"] or "",
        "extraction_source_revision": row["schema_source_revision"] or "",
        "extraction_extracted_at": row["schema_extracted_at"],
        "facts": facts,
        "facts_hash": posting_facts.facts_hash(facts),
        "facts_format": posting_facts.FACTS_FORMAT,
        "valid": facts["requirements_available"],
    }


def latest_observations() -> dict[str, dict]:
    """The newest observation of every URL, in ONE query (DISTINCT ON)."""
    qs = (
        PostingObservation.objects
        .order_by("url", "-observed_at", "-id")
        .distinct("url")
        .values("url", *_COMPARE_FIELDS)
    )
    return {r["url"]: r for r in qs.iterator(chunk_size=2000)}


def retention_candidates(cutoff):
    """Observations older than `cutoff`, except each URL's newest one."""
    latest_ids = (
        PostingObservation.objects
        .order_by("url", "-observed_at", "-id")
        .distinct("url")
        .values("pk")
    )
    return PostingObservation.objects.filter(observed_at__lt=cutoff).exclude(pk__in=latest_ids)


class Command(BaseCommand):
    help = "Record immutable observations of tracked posting facts (deadline, cancellation, roles)."

    def add_arguments(self, parser):
        parser.add_argument(
            "--window-days", type=int, default=DEFAULT_WINDOW_DAYS, metavar="N",
            help=f"Observe postings that expired at most N days ago (default {DEFAULT_WINDOW_DAYS}); "
                 "cancelled postings are always observed.",
        )
        parser.add_argument(
            "--dry-run", action="store_true",
            help="Count what would be recorded; write nothing.",
        )
        parser.add_argument(
            "--retain-days", type=int, default=None, metavar="N",
            help="Delete observations older than N days, EXCEPT the newest per URL. "
                 "Default: keep everything (the storage policy is an open decision).",
        )

    def handle(self, *args, **opts):
        window_days: int = opts["window_days"]
        retain_days: int | None = opts["retain_days"]
        dry_run: bool = opts["dry_run"]
        if window_days < 0:
            raise CommandError("--window-days must be >= 0")
        if retain_days is not None and retain_days < 1:
            raise CommandError("--retain-days must be >= 1")

        run_at = timezone.now()
        cutoff_date = timezone.localdate() - timedelta(days=window_days)
        in_window = JobPosting.objects.filter(Q(expires_at__gte=cutoff_date) | Q(cancelled=True))
        skipped = in_window.filter(detail_fetched_at__isnull=True).count()
        scope = in_window.filter(detail_fetched_at__isnull=False).order_by("id")

        counts: Counter = Counter()
        errors: list[str] = []
        inserted = deleted = 0

        # One transaction and one advisory lock around the whole run: the compare is
        # read-then-insert, so two overlapping runs could both see "no observation"
        # and both baseline the same URL. pipeline.py's flock makes that unlikely;
        # this makes it impossible.
        with transaction.atomic():
            if not dry_run:
                with connection.cursor() as cur:
                    cur.execute("SELECT pg_advisory_xact_lock(%s)", [_ADVISORY_LOCK_KEY])

            latest = latest_observations()
            pending: list[PostingObservation] = []

            def flush():
                nonlocal inserted
                if pending:
                    PostingObservation.objects.bulk_create(pending, batch_size=BATCH)
                    inserted += len(pending)
                    pending.clear()

            for row in scope.values(*_POSTING_FIELDS).iterator(chunk_size=BATCH):
                try:
                    obs = build_observation(row, run_at)
                except Exception as exc:  # a malformed payload must not stop the run
                    errors.append(f"{row['url']}: {type(exc).__name__}: {exc}")
                    continue
                outcome = posting_facts.classify(latest.get(obs["url"]), obs)
                counts[outcome] += 1
                if outcome == posting_facts.UNCHANGED:
                    continue
                if not dry_run:
                    pending.append(PostingObservation(**obs))
                    if len(pending) >= BATCH:
                        flush()
            flush()

            # Retention runs after the inserts, so "newest per URL" includes this
            # run's rows. A dry run counts against the table as it is now: rows that
            # this run would supersede are not yet counted.
            if retain_days is not None:
                stale = retention_candidates(run_at - timedelta(days=retain_days))
                if dry_run:
                    deleted = stale.count()
                else:
                    deleted = stale.delete()[0]

        total = PostingObservation.objects.count()
        oldest = PostingObservation.objects.aggregate(m=Min("observed_at"))["m"]
        in_scope = sum(counts.values()) + len(errors)

        self.stdout.write(
            ("[dry-run] " if dry_run else "")
            + "observe_postings: "
            + f"in_scope={in_scope} "
            + f"baseline={counts[posting_facts.BASELINE]} "
            + f"source_change={counts[posting_facts.SOURCE_CHANGE]} "
            + f"extraction_only={counts[posting_facts.EXTRACTION_ONLY]} "
            + f"facts_only={counts[posting_facts.FACTS_ONLY]} "
            + f"unchanged={counts[posting_facts.UNCHANGED]} "
            + f"skipped_no_retrieval={skipped} "
            + f"inserted={inserted} "
            + (f"{'would_delete' if dry_run else 'deleted'}={deleted} "
               if retain_days is not None else "")
            + f"errors={len(errors)} "
            + f"stored={total} "
            + f"oldest_observed_at={timezone.localtime(oldest).isoformat(timespec='seconds') if oldest else 'none'}"
        )
        for line in errors[:10]:
            self.stderr.write(self.style.ERROR(f"  ✗ {line}"))
        if errors:
            raise CommandError(
                f"{len(errors)} posting(s) could not be observed (the rest were recorded)."
            )

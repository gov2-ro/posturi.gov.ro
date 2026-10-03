# FIX-05 — Roll out reliable application deadlines

Priority: P1. Dependencies: FIX-02 and FIX-04; refreshed source inputs from FIX-03
before a production backfill. Audit finding: 2.

## Problem and scope

The newest 200 live JSON results had 194 expiry fallbacks and six scraped dates.
Prompt v4 exists and was sampled, but the unattended wrapper defaults to v3.
`iter_postings()` skips a non-null production schema unless forced, so changing
the prompt flag alone does not upgrade existing rows. `--resume` checks a variant,
which can also exist from a compare-only run without a production write.

Own version-aware production selection/promotion, deadline normalization/export,
status semantics, v4 sample checks and rollout documentation. Do not overwrite
`expires_at` with a submission date; the two facts remain distinct.

## Required behavior

- Add explicit production extraction provenance (provider/model/prompt and source
  revision). Upgrade stale-version production rows even when `schema_json` is
  non-null. Resume skips only a current successful production result, or promotes
  a validated matching variant explicitly. Compare-only must remain non-mutating.
  A crash between variant and production writes must recover correctly.
- Re-sample v4 on current refreshed inputs, including multi-role, scanned,
  missing-calendar, result/contestation and conflicting-date examples. Report
  successes, failure reasons, deadline coverage with denominators, output budget
  headroom, scraped/model disagreements and supporting source evidence. Pin the
  exact model/prompt revision. Normal tests use fixtures; paid samples are a
  separately recorded operational action.
- Keep `application_deadline`, announcement expiry and event dates separate.
  Prefer a validated submission date; preserve provenance and disagreement rather
  than silently accepting an impossible date. Review `_find_calendar_date`
  callers so written-test/interview/results dates cannot match an unrelated
  broad keyword. Decide which deterministic calendar parsing remains canonical.
- Centralize status predicates for browse, facet counts, employers, stats,
  sitemap and feeds. Confirmed deadline past → applications closed. Confirmed
  deadline future → open unless cancelled. Expiry-only → deadline unconfirmed,
  visibly separate from confirmed open; no known date → unknown. Preserve old
  status links by documented mapping and count query consistency.
- Preserve exact submission time where available; otherwise state date precision
  honestly. Do not fabricate a closing hour from a date-only extraction.
- Treat `--active-only` export as candidate selection by announcement expiry,
  not a guarantee that applications are open. Measure and document exclusions
  for missing expiry and cancellations. Do not drop the export row floor to make
  a legitimate status reclassification pass: explain the candidate/result counts
  and stage the transition instead.
- After a reviewed sample, document a bounded, restartable backfill and pin the
  chosen version for subsequent intake. Avoid re-extracting the expired archive
  just to improve the public active slice. Repeat infer/occupation/salary only
  where changed inputs affect them.

## Acceptance and tests

Fixtures cover existing v3 schema + requested v4, existing compare-only variant,
source revision change, interrupted promotion, v4 repeat no-op, timezone offsets,
multiple submission dates, cancellation and no dates. Assert identical counts
and eligibility across PHP/feed surfaces and explain unknown categories. Verify
before/after metrics on a copied export: fallback share, confirmed open/closed,
unknown, total rows, parser errors and schema coverage. No old schema is destroyed
by a failed attempt.

## Rollout and completion

Deliver exact backfill commands, dry/sample selection, current measured unit-cost
estimate, rollback to a previous export/config and criteria for promotion. Keep
code readiness and production completion as separate checkboxes. Completion of
production rollout requires evidence that the deployed export contains the new
version and agreed status behavior; merely running `--compare` is insufficient.

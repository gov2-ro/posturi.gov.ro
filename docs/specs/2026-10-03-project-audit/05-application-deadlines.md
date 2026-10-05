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

## FIX-05-RUN runbook (prepared 2026-10-05)

Run on gov2-1 from `/home/pax/g2-dev/posturi.gov.ro`, only after the 20-posting
sample (`ops/check-v4-sample.py --since … --evidence`) has been reviewed. Start
off-peak for DeepSeek (not 01–04 or 06–10 UTC on weekdays) and in a gap between
scheduled runs: the whole block holds `.pipeline.lock`, so a slot that fires
meanwhile exits 75 and is skipped. About 2–2.5 h at 4 workers.

```bash
export POSTURI_RUN_ID="v4-backfill-$(date -u +%Y-%m-%dT%H:%M:%SZ)" POSTURI_RUN_TRIGGER=manual
flock -n -E 75 .pipeline.lock bash -euo pipefail -c '
  PY=.venv/bin/python
  # 0. Rollback material: the extraction being replaced, and the live export.
  psql "$(grep ^DATABASE_URL= .env | cut -d= -f2-)" -c "\copy (SELECT id, schema_json, schema_provider, schema_model, schema_prompt_version, schema_source_revision, schema_extracted_at FROM jobs_jobposting WHERE expires_at >= CURRENT_DATE) TO data/schema-pre-v4.csv CSV HEADER"
  cp webapp-php/posturi.sqlite data/posturi.pre-v4.sqlite
  # 1. The paid step; restartable (--resume skips rows already current at v4).
  $PY llm-schema.py --active-only --resume --upgrade-legacy --prompt-version v4 --workers 4
  # 2. infer reprocesses only new/stale rows, so refresh the salary range it
  #    lifts from schema_json (pure Python, no LLM, every posting).
  $PY webapp/manage.py infer_postings --conditions-only
  # 3. The tail as the scheduled run does it. occupations is keyed on the title,
  #    so no new LLM calls are expected; salary recomputes from schema_json.
  $PY pipeline.py --steps infer,occupations,salary,export-sqlite --active-only --prompt-version v4
  # 4. Gate, deploy, record — the order ops/run-pipeline.sh uses.
  $PY ops/check-export.py --prompt-version v4
  ./deploy-php.sh --data-only --no-export
  $PY ops/record-deploy.py
'
```

**Gates are not at risk from reclassification.** `check-export`'s `active`,
`no_collapse` and the export's row floors all count by `expires_at`; v4 changes
`application_status` (unconfirmed → confirmed_open / closed), not which rows are
exported. Expect the status control's "Active" to fall sharply — the local
September sample put 17 of 19 v4 deadlines before the expiry date, by 18 days
on average. That fall is the point of the rollout, not a regression.

**Afterwards:** pin `LLM_PROMPT_VERSION` to v4 in `ops/run-pipeline.sh` (code
deploy + VPS pull), then compare the newest-200 `deadline_source` mix on
`/posturi.json` with the baseline (194 `expirare` / 6 `anunt` / 0 `concurs`).

**Rollback.** Site: `cp data/posturi.pre-v4.sqlite webapp-php/posturi.sqlite &&
./deploy-php.sh --data-only --no-export`. Database: restore the seven columns
from `data/schema-pre-v4.csv` (a temp table + `UPDATE … FROM`); the v3 answers
also survive as `jobs_jobpostingschemavariant` rows with `prompt_version = 'v3'`,
since variants are keyed per prompt version. Then re-run step 2–4 and keep the
pin at v3.

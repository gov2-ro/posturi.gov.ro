# FIX-04 — Fail on unusable pipeline output

Priority: P1. Dependencies: none; publish the summary contract for FIX-06/10.
Audit finding: 4. Historical evidence: October 2 incident entries in the archive.

## Problem and scope

The schema runner can print “0 ok, N failed” and exit 0. Adjacent-run coverage
warnings miss slow decline. Export build age proves a build happened, not useful
intake. Own `llm-schema.py`, `pipeline.py`, `ops/check-export.py`,
`ops/run-pipeline.sh` and their tests. Preserve atomic promotion and useful partial
successes; do not make legitimate zero-work runs fail.

## Required behavior

- Emit one versioned machine-readable summary per model/run, with `run_id`, step,
  provider/model/prompt, selected/attempted/ok/failed/skipped, retries,
  failure classes, fatal reason, duration and known usage totals. Define whether
  counts mean postings or API attempts. Use a stable sentinel plus JSON; retain
  human output. Fold it into the durable pipeline step record without storing
  credentials or announcement text.
- Nonzero if all attempted postings fail, failure share exceeds a configurable
  default of 50%, or a provider-wide fatal error occurs. HTTP 402 and invalid
  account authentication are fatal; stop scheduling new calls and safely drain
  already-running work. Keep bounded transient retries. Aggregate failure status
  across comparison models instead of letting the last model mask earlier ones.
- Zero eligible work is healthy only when selection succeeded and this is
  explicitly reported. Missing/invalid selections, config or unavailable source
  cannot masquerade as a no-op. Incomplete detail fetching must report its failed
  counts and a meaningful failure status as well.
- Compare coverage against the last successfully deployed baseline and a rolling
  window (initial target: seven successful exports), not the most recent failed
  candidate. Preserve failed records for diagnosis but exclude them as healthy
  baselines. Associate promotion/deployment outcome with the candidate/run ID.
- Add configurable absolute schema/v3 floors and sustained-decline checks. The
  earlier proposals of 85% schema and 70% v3 are starting values to evaluate,
  not blind hard-coded production gates. Versioned expectations must support a
  deliberate v4 rollout without counting it as a v3 coverage failure.
- Check intake freshness and parse yield separately from build freshness. A
  weekday newest-publication-age warning (>2 days initially) is only one signal:
  use successful scan time/completeness, card count and expected-field fill rates
  too. Account for weekends/source outages; a zero-card scan already fails and
  that protection must remain.
- Make disposition explicit: corrupt/empty exports never deploy; known scrape or
  account-wide failures report `/fail` and retain the last published good data.
  Partial degraded publication, if allowed, is an explicit configured policy with
  persistent warning state, not unconditional “ok”. Avoid enabling global
  `--strict` on uncalibrated unrelated warnings.

## Acceptance and tests

Mock providers, subprocesses, healthcheck calls and deployment. Cover all-success,
zero-work, 1/10 failure, >50% failure, all-failure, 402, 429 recovery and mixed
comparison outcomes. A 1.5-point coverage loss each run must trigger the sustained
check despite no single 5-point drop. A failed candidate must not lower the next
baseline. Verify absent/old/malformed run logs and new model versions. Simulate
an export/check failure and prove the existing deployed database is untouched.

## Rollout and completion

Run threshold checks against copied historical JSONL records, report false
positives, then document approved values and the recovery procedure. Verify one
future unattended success and one controlled failure without touching real
provider balances. Do not close the operations item from unit tests alone.

## October 10 implementation baseline and remaining acceptance

This section supersedes the original all-failure rule where they conflict.
REV-08 separates systemic failures from posting-content failures: systemic errors
count at any sample size; content failures (including truncation, validation and
parse errors) gate only at 20 or more attempted postings. An empty-content
selection likewise blocks only at 20 or more selected postings. Provider-wide
fatal errors still stop immediately. Preserve successful partial results and
explicit zero-work reporting. Test 5/5 content failures as a warning, 20/20 as a
failure, and a small systemic-failure batch as a failure.

Small recurring residues must remain observable: report affected posting IDs,
failure classes, consecutive failed observations and last successful extraction,
without storing source text in run logs. Define a bounded diagnostic history and
repeat-warning policy; do not introduce an automatic paid retry/backfill loop.
FIX-05-STAGES is the known recurring residue, not proof the whole provider failed.

OPS-FLOORS proposes a soft schema floor of 95%, based on dated post-recovery
coverage of 99.8–100%. Calibrate against copied JSONL before enabling it; keep
v3-only floors disabled when v4 is pinned. Do not enable global strict mode merely
to enforce one floor. Freshness uses Bucharest working days; all date comparisons
must use the same timezone, including runs around UTC midnight.

Runner checks must preserve handled nonzero statuses despite the ERR trap,
check pending migrations before work, and retain OPS-04 balance pre-flight exit
69 behavior. Balance records are excluded from healthy export/deploy baselines.
Operational acceptance still requires recorded unattended success, a controlled
failure, calibrated thresholds, and documented backups/restore and log retention.
Manual and cron triggers must be distinguishable (OPS-TRIGGER).

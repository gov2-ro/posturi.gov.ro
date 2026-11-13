# Brief — FIX-04 threshold calibration and failure evidence

Owner: Sonnet builds; Opus reviews. Date: 2026-11-13. Baseline: `4834532`.
Spec: [FIX-04](../specs/2026-10-03-project-audit/04-pipeline-health.md), § *Rollout and completion* and § *October 10 implementation baseline*. Read both first.
Backlog: FIX-04 (P1), OPS-FLOORS (P3), OPS-ALERT-CHECK.

## Goal

The FIX-04 code is done. Two rollout conditions in the spec are still open:

1. Calibrate the thresholds against the recorded run history, and report the false positives.
2. Record one controlled failure, without touching real provider balances.

The goal is evidence and a short report, not new gates. Opus approves the threshold values.

## What exists

- `ops/check-export.py` checks the export after each run, and it writes an `export-check` record to `data/pipeline-runs.jsonl`. These are the knobs to calibrate:

  | Knob | Default | Production value |
  |---|---|---|
  | `--schema-floor` | none | `95`, from `ops/run-pipeline.sh:160` (soft, since 2026-10-16) |
  | `--v3-floor` | none | not set; it is skipped under the v4 pin |
  | `--sustained-drop` | 5.0 points against the best of the last 7 healthy runs | default |
  | `--intake-max-age-days` | 2.0 Bucharest working days | default |
  | `--max-age-hours` | 6.0 | default |
  | `ANOMALY_STEP_PTS`, `ANOMALY_SUSTAINED_PTS` | 1.5, 1.0 | constants (`check-export.py:58`, `:66`) |

- `llm-schema.py`: `--max-failure-share` defaults to 0.5. `CONTENT_GATE_MIN_SAMPLE` is 20 (`:1121`). `evaluate_exit()` is at `:1124`.
- `load_baselines()` (`check-export.py:628`) reads the healthy baselines from the run log, and `evaluate()` (`:317`) applies the checks.

## Known incidents (the replay must flag them)

| Window | What happened |
|---|---|
| 2026-09-23T15:33Z → 2026-10-02T15:33Z | `schema_coverage_pct` fell from 85.6% to 60.1% over 19 runs. Every export-check said `ok`. |
| 2026-10-12 18:33 | The run aborted on a low DeepSeek balance ($0.45). |
| 2026-11-10T11:15Z, 11-10T16:33Z, 11-11T11:15Z | `import` failed (csv field limit). No export, and no deploy. |
| 2026-11-11T16:33Z | `import` was killed (exit -9). See BUG-IMPORT-KILL. |

Every other run is presumed healthy. A check that fires on a presumed-healthy run is a false positive.

## Work

### 1. Replay script — `ops/replay-export-checks.py` (new, read-only)

- Input: `--run-log PATH` (default `data/pipeline-runs.jsonl`). The script never writes to that file, to Postgres or to the export.
- For each `export-check` record, in time order:
  - Rebuild the baselines that `load_baselines()` would have seen at that moment. Use only the records before it.
  - Run `evaluate()` on the recorded metrics with the production knob values.
  - Run it again with each candidate set in the table below.
- Reuse `load_baselines()` and `evaluate()` by import. Do not copy them. If they need a small change to take a record list instead of a path, make the smallest change and keep the current behaviour for `main()`.
- First check whether the `export-check` records hold enough metrics for `evaluate()`. If they do not, say which fields are missing. Replay only what the records support, and do not invent values.
- Output: one row per run (`run_id`, `trigger`, which checks fired, soft or hard), plus a summary per knob set (true positives against the incident table, false positives).

Candidate sets, as a starting point. You may add others, and you must say why.

| Set | `schema-floor` | `sustained-drop` | `intake-max-age-days` |
|---|---|---|---|
| A (production) | 95 | 5.0 | 2.0 |
| B | 90 | 5.0 | 2.0 |
| C | 95 | 3.0 | 2.0 |
| D | 95 | 5.0 | 3.0 |

- Add a test, `webapp/tests/test_replay_export_checks.py`. Use a small synthetic JSONL that has one slow slide like the September one. Prove that the replay flags it, and that a failed run does not lower the next baseline.

### 2. Run the replay on gov2-1

The Mac has no SSH access and no current run log. The `posturi-vps` session runs the replay:

1. pax commits and pushes the script. pax approves `git pull` in the `posturi-vps` session.
2. The session runs `.venv/bin/python ops/replay-export-checks.py > logs/replay-2026-11-13.txt` and sends back the summary and the false-positive rows.

The replay is read-only. Say so in the message, because that session's classifier asks pax to approve each action.

### 3. Controlled failure — a proposal only

Do not run a failure. Write 2–3 options with their cost and risk, and **stop and ask pax**. Constraints:

- Spend no LLM tokens, and do not touch the provider balance.
- Do not deploy, and do not change the live database.
- Do not run outside the cron slots unless pax agrees.
- The proposal must verify alert delivery to healthchecks.io and ntfy, because nothing records it now (OPS-ALERT-CHECK).

Note: the four failed runs in the incident table already show that a failed `import` does not deploy, and that the site keeps the last good data. Use them as evidence for that part of the spec. The open part is alert delivery.

### 4. Record the alert result (small code change)

`ping_health` and `notify_run` in `ops/run-pipeline.sh` end in `|| true`. A delivery failure leaves no trace.

- Keep `|| true`. A failed alert must not fail the run.
- Record the result: append one record with `kind: "alert"` to `data/pipeline-runs.jsonl`. Its fields are `run_id`, `channel` (`healthcheck` or `ntfy`), `event` (`start`, `success` or `fail`), `ok` (true or false), and the HTTP status or exit code.
- Do not log the healthcheck URL or the ntfy topic. Both are secrets.
- `/pipeline-check` reads the run log. Add the alert record to what it reports, but only if the change is small. Otherwise add a backlog item.

## Do not

- Do not change a default threshold or `run-pipeline.sh:160`. Opus decides the values from your report.
- Do not enable `--strict`.
- Do not change the spec or an ADR. Stop and ask instead.

## Report

- The replay table, the false positives per set, and your recommended set with its reason.
- Which fields the replay could not rebuild.
- The controlled-failure options.
- The test results (full suite count before and after).
- Write the activity-log entry and update FIX-04, OPS-FLOORS and OPS-ALERT-CHECK in the backlog. Say what you measured and what you assumed.

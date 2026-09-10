---
description: Audit the unattended pipeline — did the cron runs happen, did they succeed, is the data still good, and is the live site serving what the last run built. Run on the VPS, or locally against a copied export.
---

# /pipeline-check

Answer one question: **is the unattended pipeline healthy right now?**

This is the operator's read of `data/pipeline-runs.jsonl`, `logs/pipeline.log`,
the cron schedule and the live site. It is not a data-quality deep-dive on
individual postings — that is `/quality-review`, which samples postings and
reads their source. Reach for that one when this one says the *data* looks
wrong; reach for this one when you want to know whether the *machine* is
working.

## Where you are

Work out the context first, because it changes what you can check:

- **On the VPS** (`~/g2-dev/posturi.gov.ro`, hostname `gov2-1`) — everything
  below is available: the run log, the cron schedule, Postgres, the export.
- **On the dev Mac** — the run log and the export are from local hand-runs, and
  there is no cron. Say so, and check only what is local. Do not report a
  missing crontab as a fault.

`hostname` and `crontab -l` settle it.

## Steps

### 1. Read the run log

`data/pipeline-runs.jsonl` — one JSON object per line, two kinds, joined by
`run_id`:

- `kind: "run"` — written by `pipeline.py`: `trigger` (cron / manual), `git_sha`,
  `started_at` / `finished_at` / `duration_s`, `flags`, and a `steps` array of
  `{step, ok, exit, duration_s}`. `failed_steps` and `aborted_at` summarise it.
- `kind: "export-check"` — written by `ops/check-export.py`: `status`
  (ok / warn / fail), the full `metrics` dict, and every `check` with its
  `level` (hard / soft) and message.

Read the last ~10 of each. If the file does not exist, the instrumentation has
never run — say that, and stop rather than guessing from the logfile.

### 2. Did the runs happen?

The slots are **11:45 and 18:33 Europe/Bucharest**, daily (`crontab -l`, or
`systemctl list-timers 'posturi*'` on a systemd install).

- Count the `trigger: "cron"` records over the last 7 days against the number of
  slots that have passed. A missing slot is the failure that a failure alert
  cannot report — the site went five weeks stale exactly this way.
- Check `HEALTHCHECK_URL` is set in `.env`. If it is empty, the dead-man's
  switch does not exist; say so plainly, because every other check here assumes
  someone eventually looks.
- `timedatectl` must say `Europe/Bucharest`. The export filters on
  `expires_at >= CURRENT_DATE` and the slots fire on local time; a drifted
  timezone moves the day boundary under both.

### 3. Did they succeed?

Per run: `exit`, `failed_steps`, `aborted_at`, and the export-check `status`.

- A run with `exit: 0` and `status: "ok"` needs no comment.
- **`failed_steps` recurring across runs** is the signal that matters — one bad
  night is the network, the same step failing four runs running is a bug. Name
  the step and the exit code.
- **`aborted_at` set** means `--continue-on-error` was off; the steps after it
  never ran.
- **Step durations trending up** — compare `duration_s` per step against the
  older records. `schema` is the long pole; `fetch-index` creeping up means the
  source site got slower or the listing grew.
- Exit `65` from `run-pipeline.sh` means the export failed its hard checks and
  **was not deployed**: the shared host is still serving the previous database.
  This is the loudest thing this command can find. Report it first.

### 4. Is the data still good?

From the last `export-check` record and the one before it:

- Every failing check, hard first, with its message verbatim.
- The `metrics` deltas that matter even when nothing failed:
  `active`, `schema_coverage_pct`, `v3_coverage_pct`, `family_altele_pct`,
  `short_body_pct`, `calendar_events`. A check that passes at 4.9 points of
  coverage loss twice running has lost 10 points; the thresholds are per-run and
  will not catch a slow slide. **Look at the trend across all the records, not
  just the last delta.** This is the main thing you can see that the script
  cannot.
- `family_shares_pct` and `anomaly_pct` — a family or a flag moving steadily in
  one direction over several runs.

Re-run the check yourself for live numbers if the last record is old:

```bash
.venv/bin/python ops/check-export.py --no-log
```

`--no-log` so an interactive read never becomes the baseline the next real run
compares against. Expect `build_meta_fresh` to FAIL here whenever the last run was
over six hours ago — that check is asking "did *this* run build the file", which is
only a real question inside a run. Add `--max-age-hours 999` to silence it, and do
not report it as a fault unless a run genuinely just finished.

### 5. Is the live site serving it?

```bash
curl -sI "$SITE_URL" | head -1
```

Then compare three timestamps, which mean three different things:

| Value | Where | Meaning |
|---|---|---|
| `build_meta.built_at` | the local export | when this file was generated |
| `MAX(last_seen_at)` | the local export | when the source was last scraped |
| the "actualizat" stamp | the live page | what the shared host is serving |

If the live stamp lags the local `built_at` by more than a run, the deploy is
failing while the pipeline succeeds — check the tail of `logs/pipeline.log` for
the rsync.

### 6. Read the log tail only if something is unexplained

`logs/pipeline.log` is where the steps' own output goes. Runs are delimited by
`=== posturi pipeline run <run_id> (<trigger>) — ... ===`. Go here to find out
*why* a step in step 3 failed, not to discover that it did. Grep the run id.

Also worth a look when a run is missing entirely: cron mails output to
`MAILTO=pax@mioritics.ro`, which needs an MTA on the box — if there is none,
that channel is silently dead and a failing cron produces nothing anywhere.

## Output

Lead with the verdict, in one paragraph. Then:

**Verdict** — healthy / degraded / broken, and the single reason.

**Runs** — a compact table of the last 5: run id, trigger, duration, failed
steps, export-check status. Note any missed slot.

**Regressions** — each with its magnitude and the run it started in. Distinguish
"a check failed" from "a metric is sliding while every check passes" — the
second is the one only you can see.

**What to do** — a ranked list, most urgent first, each a concrete action:
a command to run, a file to look at, a threshold to reconsider. If a soft check
has warned on every run for a fortnight without anything actually being wrong,
say the threshold is wrong and propose the new value.

If everything is fine, say so in two sentences and stop. A clean pipeline should
produce a short report, not a long one.

## Notes

- **Quote real numbers.** "schema coverage 98.1% → 91.4% since the run of
  2026-09-08" — never "coverage seems to have dropped".
- The hard checks are deliberately narrow: they only fire on corruption that
  cannot be a bad day's data. If a hard check fires, treat it as a bug in the
  pipeline or the source site, not as noise to be tuned away.
- Soft checks are thresholds picked before there was any run history. Proposing
  a better one from the data you can now see is a *result*, not a workaround.
- Do not run `ops/check-export.py` without `--no-log`, and never run
  `pipeline.py` or `run-pipeline.sh` to "see what happens" — the run holds a
  flock, costs LLM calls, and deploys to the live site.
- The full watch-list this implements, and the parts still unbuilt (the
  LLM-judge sampling pass), are in `docs/pipeline-quality-checks.md`.

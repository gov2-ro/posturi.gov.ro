# Brief — a data deploy keeps the anomaly tables (OPS-ANOMALY-DEPLOY)

Date: 2026-11-24. Author: Opus. Builder: Sonnet.

## Problem

`/admin/anomalii` and `/anomalii` showed no signals from 24 Nov 00:21 until the data redeploy of 24 Nov.

- `export-to-sqlite.py` builds a new `webapp-php/posturi.sqlite` each time. The new file has no `anomaly_signals` and no `anomaly_checks`.
- Only `ops/run-pipeline.sh` (lines ~170–182) writes those tables, with `ops/anomaly-survey.py --write-table`.
- The VPS session made three manual deploys on 23–24 Nov: `export-to-sqlite.py`, then `check-export.py`, then `deploy-php.sh --data-only --no-export`. Nothing in that path ran the survey.
- `check-export.py` printed `[WARN] anomaly_tables 0 of 2` each time (line ~375). The check is soft, so nobody acted on it.

The cause: a manual data deploy skips a step that only the cron runner knows about, and no check blocks the deploy.

## Decision

`deploy-php.sh` is the gate for every data deploy. It refuses a `posturi.sqlite` without both anomaly tables. The error message gives the exact survey command.

The cron runner keeps its current rule: a survey failure does not block the data deploy (comment at `ops/run-pipeline.sh` ~170). It passes a new flag to `deploy-php.sh` only when its own survey step failed.

Rejected alternatives:
- Run the survey inside `export-to-sqlite.py`. The export also builds dev and test copies. The survey needs `--index-csv` and adds minutes. The export then has two jobs.
- Let `deploy-php.sh` run the survey itself. That gives two copies of the survey command, or a new shared script. A refusal with the command in the message is enough for a manual deploy, and it is simpler.
- Make the `check-export.py` warning hard. `run-pipeline.sh` runs that check after a failed survey too, so a hard check would block the cron deploy. That breaks the rule above.

## Read first

- `deploy-php.sh`: options (lines ~8–80), the database checks (lines ~227–239).
- `ops/run-pipeline.sh`: the survey step (lines ~170–182) and the `deploy-php.sh` call.
- `ops/check-export.py` lines ~166 and ~374 (only to read; no change).
- README § deploy (lines ~495–520 and ~775), `docs/deploy-vps.md` (line ~319).

## Steps

1. **`deploy-php.sh`.** Add the option `--allow-no-anomalies`. Put it in the usage text and the header comment.
2. **`deploy-php.sh`.** In the database check block, when `deploy_data` is 1, count the tables `anomaly_signals` and `anomaly_checks` in `sqlite_master`. When the count is not 2 and `--allow-no-anomalies` is not set, call `die` with this message (keep it to 3 lines):
   `posturi.sqlite has N of 2 anomaly tables — the site would show no signals.`
   `Run: $PYTHON ops/anomaly-survey.py webapp-php/posturi.sqlite --write-table --json data/anomaly-survey.json --csv data/anomaly-survey.csv [--index-csv data/posturi_gov_ro.csv]`
   `Or pass --allow-no-anomalies to deploy without signals.`
   When the flag is set and the tables are missing, print a `WARNING` line and continue. Print the counts of both tables in the `==> Database:` line.
   Do the check only when `sqlite3` exists, the same as the checks next to it.
3. **`ops/run-pipeline.sh`.** When `survey_status` is not 0, pass `--allow-no-anomalies` to `deploy-php.sh`. When the survey succeeds, do not pass it.
4. **Docs.** In the README deploy section and in `docs/deploy-vps.md`, write the manual data deploy as four commands in order: export, survey, `check-export.py`, `deploy-php.sh --data-only --no-export`. Say in one sentence that `deploy-php.sh` refuses an export without the anomaly tables.
5. **Test.** Make a copy of a local export with no anomaly tables. Run `deploy-php.sh` against a target that cannot be reached, or stop it before rsync, and show that:
   - without the tables and without the flag, it stops with the message of step 2 and exit code 1;
   - without the tables and with the flag, it prints the warning and goes on;
   - after `anomaly-survey.py --write-table` on the copy, it goes on with no warning.
   Do not push to the VPS. Do not let the test overwrite `webapp-php/posturi.sqlite`. Use a copy and the `DB_FILE` that the script reads, or ask if the script has no way to point at a copy.
6. **Backlog and log.** Mark OPS-ANOMALY-DEPLOY `[x]` in `docs/backlog.md`. Add an activity-log entry with the three test results.

## Out of scope; stop and ask

- No change to `export-to-sqlite.py`, `ops/anomaly-survey.py`, `ops/check-export.py` or the PHP pages.
- No deploy. The VPS session deploys after pax reviews.
- Stop and ask when a step needs a file that this brief does not name, or when `deploy-php.sh` has no safe way to test without a real target.

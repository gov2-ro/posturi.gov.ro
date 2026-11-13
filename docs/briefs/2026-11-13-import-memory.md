# Brief — BUG-IMPORT-KILL: measure the memory of `import`

Owner: Sonnet builds; Opus reviews. Date: 2026-11-13. Baseline: `4834532`.
Backlog: BUG-IMPORT-KILL. Related: OPS-RUNTIME, LATER-05.

## Problem

Run `2026-11-11T16:33Z` was the first full parse after `PARSER_VERSION` 4. It reused 0 of 15,686 files. Then `import` exited -9 after 1,067.6 s, with no traceback. The normal `import` takes 454–474 s.

Assumed, not confirmed: the kernel OOM killer stopped it. gov2-1 has 3,819 MB RAM, and Postgres, PHP and other projects share it. The kernel log has no OOM line. It has not happened again in three runs.

We do not know the peak memory of any pipeline step. Measure first, and then fix only what the numbers show.

## Leads (not yet verified)

`webapp/apps/jobs/management/commands/import_csvs.py`:

- `:276` and `:300` read the whole CSV into a list (`list(csv.DictReader(f))`). `anunturi.csv` has 15,942 rows, and its body column alone holds up to 102,323 characters a row.
- `:431` `JobPosting.objects.in_bulk(...)` loads one full model for every row, with all text and JSON columns.
- `:508` and `:518` load the calendar rows and a second posting map.

A full parse marks every row as changed. Then the import revises and invalidates more rows than on a normal run. Check whether that path (`invalidate_enrichment`, `:206`) holds more objects in memory.

## Work

### 1. Record the peak memory of each step (permanent)

- In `pipeline.py`, the step runner (near `:266`) uses `subprocess.run`. Record each child's peak RSS in the step record, as `max_rss_mb`.
- Use `os.wait4()` on a `Popen` child, or an equal method that gives the rusage of **one** child. Do not use `resource.getrusage(RUSAGE_CHILDREN)`: its `ru_maxrss` is the largest child so far, not this step.
- `ru_maxrss` is in kilobytes on Linux and in bytes on macOS. Convert both.
- Keep the current exit-code and timing behaviour. Add a test with a child process that allocates a known amount of memory.

After the next cron runs, every step has a measured peak. This also gives OPS-RUNTIME its numbers.

### 2. Measure `import` on the Mac

- Use the local Postgres (a stale copy from September) and the local CSVs. Do not use the VPS for this.
- Measure with `/usr/bin/time -l` (macOS) on two cases:
  - a normal import;
  - a full import, where every row counts as changed.
- If the local `anunturi.csv` is much smaller than 15,942 rows, say so. Scale the result linearly only if you state the assumption.
- Find which of the leads above holds the most memory (`tracemalloc` snapshots, or RSS between the phases).

### 3. Fix, if the measurement shows a clear cause

- Prefer streaming and batching: read the CSV in chunks, and use `.only(...)` or `values_list` instead of full models where the code reads a few fields.
- Keep the import result identical. Compare row counts, and compare a sample of fields before and after the change, on the local database.
- If the cause is not clear, do not fix anything. Report the numbers.

### 4. Check the kernel log on gov2-1 (pax decides)

`journalctl -k` and `dmesg` may need `sudo`. Ask pax to run them in the `posturi-vps` session, for 2026-11-11 between 17:00 and 17:15 UTC. Search for `Out of memory` and `oom-kill`.

## Do not

- Do not run `import`, the pipeline or a parse on gov2-1.
- Do not change `PARSER_VERSION`.
- Do not change the run-log schema other than adding `max_rss_mb`.

## Report

- The peak RSS of each case, and how you measured it.
- The memory per phase, and which lead holds the most.
- The fix and its before/after numbers, or the reason for no fix.
- The test results (full suite count before and after).
- Write the activity-log entry and update BUG-IMPORT-KILL in the backlog. Say what you measured and what you assumed.

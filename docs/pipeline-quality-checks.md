# Pipeline & data quality checks — what to watch

Design notes for the "data / pipeline-run quality check script & agent command"
tracked in `docs/backlog.md` (Tooling & ops). Nothing here is built yet; this is
the watch-list and the shape of the thing.

## Why

`ops/run-pipeline.sh` runs unattended twice a day on the VPS and pushes a SQLite
file to the shared host. Today the only signals are:

- a `HEALTHCHECK_URL` ping (`/start`, `/fail`, success) — presence/absence only;
- whatever each step printed to the cron logfile / journal;
- the export floors in `export-to-sqlite.py` (min-rows, `integrity_check`, refusal
  to lose >50% of rows vs the file being replaced);
- `deploy-php.sh`'s post-deploy `curl` of `SITE_URL` (HTTP status only).

There is **no durable per-run record** and **no automated judgement on whether the
data is any good**. Every data regression in the project's history got caught by a
human reading output weeks later — the site served 3 jobs instead of 1,656 for five
weeks (`expires_at` NULL), the județ facet fragmented to 261 rows, `IT` absorbed
every failed classification, `.doc` extraction silently returned "" twice. All of
these are cheap to assert against.

## Already in the tree — reuse, don't re-implement

| Asset | What it does | Gap |
|---|---|---|
| `quality_check.py` | Samples N postings (default 8), scores CSV completeness / attachment readability / infer / schema per posting, writes a JSON report; LLM-capable | Sample only; reads the CSV not the shipped SQLite; no run-over-run trend; not wired into the pipeline; gates nothing |
| `quality-review` skill | Reads that JSON report, writes a narrative assessment | Manual; same sample-only scope |
| `import_csvs.py::expiry_sanity_warnings()` | On every import: flags >50% of recent postings missing `expires_at`, or zero active while recent ones exist. `--strict` → non-zero exit | Runs at import, not against the final export; warnings only unless `--strict` |
| `import_csvs.py::judet_sanity_warnings()` | On every import: fires if the Judet table exceeds 42 rows or any posting has an unresolved county | Same |
| `export-to-sqlite.py` floors | `--min-rows` (default 100), `integrity_check`, row-loss guard vs the previous file | Row-count only; no schema/inference/anomaly checks |
| `fetch-index.py` truncation guard | "refusing to scrape a truncated listing" when `nav.pg-arc-pagi` stops exposing the last page number | Scrape-time only |

The check should **call the two `*_sanity_warnings()` functions** rather than
duplicate them, run against the **SQLite that is about to ship** (not the CSV), and
add the trend + run-log layers below.

## Part 1 — structured run log (per pipeline run)

Emit one machine-readable record per `run-pipeline.sh` invocation. Candidate homes:
`data/pipeline-runs.jsonl` (append), or a `pipeline_runs` Postgres table, or both.
Keep the last ~90 days. Fields:

- **run**: id, host, start/end UTC, wall-clock, trigger (cron slot / hand-run),
  git SHA, `--since` / `--prompt-version` / `--workers` in effect, overall exit code.
- **per step** (`fetch-index`, `fetch-detail`, `parse`, `download`, `import`,
  `extract`, `infer`, `schema`, `export-sqlite`): name, start/end, exit status,
  duration, and the counters each step already prints —
  - fetch-index: pages scanned, pages with new/updated entries, `ALLOW_SHRINK` used?
  - parse: rows in / rows out, old-vs-new HTML split
  - download: files fetched, skipped, failed
  - import: postings new / updated / skipped, `JobPostingUpdate` rows created,
    both `*_sanity_warnings()` results
  - extract (attachments): success / by-reason failure counts (KeyError, BadZipFile,
    scanned-PDF, …), coverage % before/after
  - infer: keyword-classified / LLM-classified / preserved, `altele` count
  - schema: ok / failed / retried / unsupported-quote, LLM calls, tokens in/out,
    cached, **cost**, per-error-class tally (the `Expected dict, got str` family)
  - export-sqlite: rows per table, floor results, final file size, build timestamp
- **deploy**: rsync bytes sent, `SITE_URL` HTTP status, live-page "actualizat" date.

Surface the latest record on `/despre` or behind `?dev=1` so "when did the data last
actually change, and by how much" is answerable without SSH.

## Part 2 — data-quality assertions (post-export, on the shipped SQLite)

Run after `export-sqlite`, before / instead of trusting the deploy. Each assertion
is tied to the regression that motivates it. Breach → non-zero exit + `/fail` ping;
`--strict` makes soft warnings hard.

### Counts & shape
- **Active postings within a band** vs the previous run — e.g. flag <0.75× or
  >1.5× the last export, hard-fail near-zero. (Motivating bug: 1,656 → 3.)
- `job_postings` row count not collapsed vs the previous file (export floor already
  does >50%; tighten to ~20% here).
- No duplicate posting id / duplicate source URL in the export.
- Every `job_postings.employer_id` resolves to `employers`; every `judet_slug`
  resolves to `judete`.
- FTS5 shadow table row count matches `job_postings`; a sample query returns rows.

### Județe
- `SELECT count(*) FROM judete` **== 42**, exactly. (Bug: 261 rows, facet split
  every county.)
- Zero active postings with a non-empty `judet_raw` / unresolved county.
- No diacritic-variant duplicates (`Arges` vs `Argeș`).

### Dates & expiry
- <50% of postings published in the last 14 days missing `expires_at` (this is the
  five-week-stale bug). Zero active while recent postings exist.
- `expires_at` all within `2000‑01‑01 .. today+2y` — no `2046` typos leaking through.
- `datePosted` never in the future; `datePosted <= expires_at` where both present.
- `calendar_events`: count plausible vs postings, `data` / `ora` parse rate not
  regressing.

### Extraction coverage (regressions here are the biggest quality lever)
- **schema_json coverage** of active postings not lower than the previous run by
  more than a few points. (Stalled v2/v3 backfill; `.doc` extraction broke twice.)
- Attachment-text coverage % of active postings not regressing.
- If `--prompt-version v3`: the `v3_*` columns are populated on rows that have a v3
  extraction — all-empty `v3_*` means a v2 extraction landed under a v3 run.
- `responsibilities` fill rate tracked (historically the weakest standard section
  at ~18%) — alert on a sharp drop, not on the absolute level.

### Inference sanity
- profession_family / studies / experience / work_type populated % per active
  posting, tracked run-over-run.
- **`altele` share** of active postings — spike = dictionary or LLM classifier
  broke. (Bug: `--force --no-llm` rewrote 598 LLM classifications to `altele`.)
- **No single profession_family dominating** abnormally — the "`IT` absorbed every
  failed low-confidence classification" bug showed as `IT` jumping to an implausible
  share. Assert max family share < ~30% unless it was already that high last run.
- Skills: no single skill tag on an implausible fraction of postings (bug: "SAR" on
  87%, "atestat" on 36% from substring matching).

### Anomaly flags
- Distribution of `short_deadline`, `missing_contact` / `contact_in_attachment`,
  `no_body`, `gender_criteria`, `frequent_repost` — alert on a step-change vs the
  previous run, not on the level.
- `no_body` (< 100 chars) share not spiking — that catches a scrape/parse break
  that leaves bodies empty without failing a step.

### Encoding
- No literal `\n` / `\\n` in `body_markdown`; no mojibake (`Ã`, `â€`, replacement
  char) in title / employer / body; ș/ț are the comma-below forms, not cedilla.

## Part 3 — LLM-judge pass (sample, slower cadence — weekly?)

Cost-bounded. Take a random sample (~15–25) of postings **extracted in the last
run** and, for each, give the model the raw `body_markdown` + `attachment_text` and
the produced `schema_json` / inferred fields, asking for:

- **Fidelity score**: are the structured sections faithful to the source? Any
  hallucinated content? Specifically check the known failure modes —
  - `baseSalary` picking up *taxa de concurs* / application fee (guarded in the
    prompt; verify the guard holds);
  - deadline / `validThrough` not matching the body's stated date;
  - `responsibilities` containing generic conditions rather than actual duties;
  - `positions[]` missing on a visibly multi-role posting (~8% of postings).
- **Classification check**: is the `profession_family` / studies / experience
  defensible for this title + body?
- **Systematic-failure flags**: patterns across the sample (e.g. a field always
  empty, a provider always stringifying its JSON — the current DeepSeek
  `Expected dict, got str` at ~35% of the schema step).
- Output: a short narrative + pass/fail + the specific posting URLs to inspect.

Optionally, on a subset, run a second provider and record the **cross-provider
disagreement rate** on `isced_field` / `eqf_level` — that rate decides whether
those fields are trustworthy enough to filter on (open question in the v3 backlog).

Land the verdict in the run log + cron logfile; mail on fail if an MTA exists.

## Part 4 — where failure is signalled

1. **Hard assertions** (Part 2, `--strict`): non-zero exit from the check step →
   `run-pipeline.sh` pings `/fail` and exits non-zero. The already-shipped previous
   SQLite stays in place; a bad export should ideally be caught **before**
   `deploy-php.sh --data-only` runs, so wire the check between `export-sqlite` and
   the deploy in `pipeline.py` / `run-pipeline.sh`.
2. **Soft warnings**: recorded in the run log, printed, not fatal.
3. **LLM narrative**: run log + logfile; optional mail.
4. **Dead-man's-switch**: `HEALTHCHECK_URL` still covers "the run never happened".

## Part 5 — the agent command

`quality-review` already exists for the narrative pass over `quality_check.py`'s
sample report. Extend it, or add a sibling `pipeline-check` command, that:

- reads the last N entries of the run log + the latest data-quality assertion
  results + the LLM-judge output;
- pulls a few live numbers from the shipped SQLite and from Postgres;
- produces: a one-paragraph verdict, the specific regressions vs the previous run
  with magnitudes, and a ranked list of postings / employers / counties to inspect
  by hand;
- is safe to run locally against a copied export and on the VPS against the live one.

## Build order

1. Run log (Part 1) — pure instrumentation, no judgement, immediately useful.
2. Part 2 assertions, wired between `export-sqlite` and the deploy, `--strict` in cron.
3. Agent command (Part 5) over 1 + 2.
4. LLM-judge (Part 3) last — it needs a provider that isn't failing 35% of calls.

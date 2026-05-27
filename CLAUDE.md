# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

See README.md for project overview, pipeline, and data structure.

## Running scripts

```bash
python fetch-index.py   # preferred index scraper: scans all pages
python fetch-index.py                 # stops early at first page with no changes
python fetch-anunturi.py
python parse-anunturi.py
python download-attachments.py
python llm-schema.py                  # extracts 7 structured sections → JobPosting.schema_json
  # Single provider (production):
  python llm-schema.py --provider gemini --limit 10
  # Compare all enabled models (stores variants only, no schema_json overwrite):
  python llm-schema.py --compare --limit 10
  # Filter by model (regex):
  python llm-schema.py --compare --model-filter "gemini-.*" --limit 5
  # Test prompt versions:
  python llm-schema.py --compare --prompt-version v2 --limit 10
  # Models + pricing defined in models_config.json; enable/disable via "enabled" flag
# Data quality testing:
.venv/bin/python3 quality_check.py --no-llm          # fast: CSV + attachment + infer, no LLM
.venv/bin/python3 quality_check.py --provider anthropic  # full pass incl. schema.org generation
# Then run /quality-review in Claude Code for narrative assessment
```

## Key behaviors

**Two index scripts** — `fetch-index.py` always scans all pages and is preferred. The old early-stop version has been deleted.

**`parse-anunturi.py` outputs two files** — `data/anunturi/anunturi.csv` (one row per posting, with structured fields: contact info, competition dates, card fields) and `data/calendar.csv` (flat table: `url, eveniment, data, ora` — all competition timeline events across all postings).

**Incremental saves** — both index scripts save to CSV after each page that has new or updated entries (crash-safe). `fetch-index.py` skips the save on unchanged pages.

**Change tracking** — `compare_and_update()` diffs each scraped listing against the stored row and appends changed field names + date to the `updates` column.

**Rate limiting** — `random.uniform(0.5, 1.1)` sleep between pages, built into all fetch scripts.

## Project tracking

- When detecting things that need to be addressed later, add to `docs/backlog.md`. Use a checkbox `- [ ]` entry with a clear title and enough context to act on it later.
- After completing any meaningful work, add an entry to `docs/activity-log.md` under the relevant section heading with a `### YYYY-MM-DD — Short Title` entry. Include what was done, why, and any non-obvious decisions.

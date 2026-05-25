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
python anthropic-api.py               # LLM schema generation (needs .env)
```

## Key behaviors

**Two index scripts** — `fetch-index.py` always scans all pages and is preferred. `fetch-index.py` stops at the first page with no new entries, which misses updates to older listings.

**Incremental saves** — both index scripts save to CSV after each page that has new or updated entries (crash-safe). `fetch-index.py` skips the save on unchanged pages.

**Change tracking** — `compare_and_update()` diffs each scraped listing against the stored row and appends changed field names + date to the `updates` column.

**Rate limiting** — `random.uniform(0.5, 1.1)` sleep between pages, built into all fetch scripts.

## Project tracking

- When detecting things that need to be addressed later, add to `docs/backlog.md`. Use a checkbox `- [ ]` entry with a clear title and enough context to act on it later.
- After completing any meaningful work, add an entry to `docs/activity-log.md` under the relevant section heading with a `### YYYY-MM-DD — Short Title` entry. Include what was done, why, and any non-obvious decisions.

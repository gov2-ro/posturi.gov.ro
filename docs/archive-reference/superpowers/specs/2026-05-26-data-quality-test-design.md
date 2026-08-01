# Data Quality Test — Design Spec

**Date:** 2026-05-26
**Status:** Implemented

## Problem

The posturi.gov.ro pipeline has four transformation layers (HTML parsing →
attachment extraction → metadata inference → LLM schema generation) but no
systematic way to measure whether each layer is producing good output. Known
issues include body markdown duplication and unknown attachment extraction
failure rates.

## Goal

Sample 5–10 diverse job postings and run them through all four layers, producing:
1. An automated quality report (`data/quality_report.json`) with per-posting
   scores and aggregate stats
2. A Claude-powered interactive review (`/quality-review` slash command) that
   reads the report, deep-reads the source files, and produces a qualitative
   narrative with actionable findings

## Order of Operations (per posting)

```
1. SAMPLE          stratify by job type, level, county, attachment presence, body length
2. CSV FIELDS      completeness (8 checks) + body duplication detection
3. ATTACHMENT      extract text → score readability (alpha ratio, encoding, length)
4. INFER METADATA  profession family + confidence, seniority, skills, anomaly flags
5. LLM SCHEMA      call LLM → validate schema.org/JobPosting required fields
6. REPORT          data/quality_report.json + console table
```

## Artifacts

### `quality_check.py`

Standalone script at the repo root. Consistent CLI with existing scripts:

```bash
python quality_check.py                          # 8 diverse postings, anthropic
python quality_check.py --n 10
python quality_check.py --provider gemini|openai|anthropic
python quality_check.py --no-llm                 # fast: skip infer fallback + schema
python quality_check.py --slugs 2a66f376.doc,... # force specific postings
```

**Reuses:**
- `extract_text()` logic from `extract_attachments.py` (copied inline, no Django)
- All pure inference functions from `infer_postings.py` (copied inline)
- `make_schema_generator()` / `_parse_json_response()` from `llm-schema.py`

**Sampling strategy:** greedy bin-filling across Temporar/Permanent, conducere/execuție,
funcție publică/contractuală, has/no attachment, short/long body, diverse judets.

**Body duplication detection:** chunk body into 150-char segments, flag if >40%
of chunks are near-duplicates. (Known parsing artifact from HTML structure —
measured here, not fixed here.)

**Schema.org validation:** checks 7 required fields (`@type`, `title`,
`hiringOrganization`, `jobLocation`, `datePosted`, `validThrough`, `description`)
and description length ≥ 50 chars. Saves validated JSON to `data/schema/<slug>.json`.

### `.claude/commands/quality-review.md`

Project-scoped slash command available as `/quality-review` in Claude Code.

When invoked:
1. Reads `data/quality_report.json`
2. Flags worst cases across 6 dimensions (completeness, attachment, confidence, schema, duplication, anomalies)
3. Deep-reads raw CSV rows, attachment files, and schema JSONs for flagged postings
4. Produces a structured narrative: root cause → what LLM got right/wrong → recommended fix
5. Optionally opens source URLs in Playwright for visual verification of suspected scraping errors

## Known Issues Measured (not fixed here)

- Body markdown duplication: tracked in `docs/backlog.md`, fix belongs in `parse-anunturi.py`
- `Data Limita Depunere` deadline vs publish-date delta: anunturi.csv lacks
  `publicat_in` column so short-deadline anomaly check is skipped (needs join with posturi_gov_ro.csv)

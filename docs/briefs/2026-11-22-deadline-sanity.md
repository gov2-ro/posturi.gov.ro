# Brief: reject an impossible apply deadline (DATA-DEADLINE-SANITY)

Date: 2026-11-22. Author: Opus. Builder: Sonnet. Owner approval: given 2026-11-22.

## Problem (measured on the VPS, live export of 2026-11-22, 2,983 postings)

- 11 postings have an apply deadline before the publication day (survey check `R07`).
- In 4 sampled hits, the deadline comes from the LLM (`deadline_source = concurs`). The date is
  stale or a year off. The card `Termen` (`expires_at`) is plausible. Examples: `paznic-32`
  (published 2026-10-22, extracted 2026-10-02, card 2026-11-23); `muncitor-necalificat-ingrijitor-animale`
  (published 2026-10-30, extracted 2025-11-13).
- `posting_facts.apply_deadline()` and `export-to-sqlite._apply_deadline()` take the LLM date first,
  with no check. A test keeps the two functions equal.

## Decision (Opus)

A deadline candidate is **impossible** when its date is before the publication date
(Bucharest local date). Skip an impossible candidate and take the next source:
`concurs` → `anunt` → `expirare`. If every candidate is impossible, return no date.

Not in scope: a deadline that is plausible but short, a deadline far after `expires_at`,
a change to the extraction prompt, a re-extraction.

## Work

1. Add the publication date as an argument to both functions. Update every caller
   (`export-to-sqlite.py` lines ~613 and ~928, and the callers of `posting_facts.apply_deadline`).
2. Keep the two functions equal; the existing equality test must cover the new argument.
3. Record a skipped candidate so that an audit can find it, for example a field
   `rejected: ["concurs"]` in the returned dict. Do not add an export column without asking.
4. Tests: one per case — LLM date before publication falls back to `anunt`; both before
   falls back to `expirare`; all before gives no date; a date on the publication day is kept.
5. Check `_infer_anomaly_flags` (`infer_postings.py:470`). `short_deadline` uses
   `data_limita_depunere`, not `apply_deadline`. Report how a negative delta is handled.
   **Do not change the flag** — report only.
6. Run the full test suite. Run `ops/anomaly-survey.py --only R07,R08` on
   `tmp/261118/grid153/posturi-after2.sqlite` before and after only if the export can be
   rebuilt locally; otherwise say that the VPS measures it.

## Stop and ask

- Before you change `_infer_anomaly_flags`, a parser, the extraction prompt or the export schema.

## Report

Files changed, commit hashes, test output, the callers you changed, and the `short_deadline`
finding. Add an activity-log entry and a backlog item `[x]`. Say what you measured and what you
assumed.

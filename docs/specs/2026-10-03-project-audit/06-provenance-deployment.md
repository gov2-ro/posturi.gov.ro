# FIX-06 — Track truthful freshness and deployed versions

Priority: P1. Dependencies: source metadata from FIX-03 and summaries from FIX-04.
Audit finding: 6; backlog also records manual code/data deployment drift.

## Problem and scope

Cached import writes `last_seen_at = today`. Header combines that date with the
export's time, and About claims this is source verification. `build_meta.git_sha`
identifies the data builder, not the PHP release. A successful homepage HTTP 200
does not certify that a new database or code version is serving.

Own source/run provenance exported into `build_meta`, PHP header/footer/About,
`deploy-php.sh` verification and any version/health endpoint. Keep credentials,
source hostname and sensitive infrastructure details out of public output.

## Required behavior

- Persist separate `index_checked_at`, scan completeness/outcome,
  `detail_fetched_at` per posting, `built_at`, `run_id` and last successful run
  summary. Populate source times only from real successful retrieval. Legacy
  `last_seen_at` remains an import-era field until explicitly migrated/redefined;
  never synthesize a source time from build/import date.
- Export a versioned provenance contract with successful source observations,
  row deltas, enrichment health and unknown legacy values. Source failures must
  retain the old success timestamp while recording the current failure.
- As an immediate compatible fix, label the existing timestamp as database
  generation and use full `built_at`, rather than combining unrelated dates and
  times. Later display source check and build separately, in Bucharest local
  time, with a visible stale/degraded state where justified. Support old exports
  and missing metadata without a 500 or a false freshness claim.
- Stamp code releases independently from data exports. A deploy-visible code
  version comes from the code artifact; data version/run ID comes from SQLite.
  Data-only pushes must not alter the code marker, and code-only pushes must not
  alter the data marker. `/despre/` or a small read-only endpoint exposes these
  stable public identifiers.
- Verify served markers after deploy with bounded retry and cache bypass/revalidate
  behavior. Require the expected data run/build identity for data deploys and
  expected code identity for code deploys; HTTP 200 alone is insufficient.
  Record verification/deploy outcome for FIX-04's healthy baseline selection.
  Provide a local-vs-served version check to expose pending manual code releases.

## Acceptance and tests

Simulate a failed scrape followed by a new cached import/export: source time must
stay old and build time advance. Cover partial scans, unknown metadata, midnight,
UTC/Bucharest differences and mixed code/data release versions. Stub remote reads
to test old cached response, matching marker, missing marker, retries exhausted
and non-200. Verify no credentials/hostnames leak. Browser-check truthful copy on
header, footer and About with a copied fixture database.

## Rollout and completion

Ship PHP readers compatible with both export versions, then metadata producers.
Keep legacy fields during the transition. Document verification command and
cache behavior. A fresh code marker does not prove source freshness; a fresh
data marker does not prove code release parity. Record those separately.

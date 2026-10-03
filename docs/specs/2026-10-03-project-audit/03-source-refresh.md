# FIX-03 — Refresh source details and detect cancellations

Priority: P1. Dependencies: agree provenance fields with FIX-06 and attachment
identity with FIX-08. Audit finding: 3.

## Problem and scope

`fetch-anunturi.py::process_csv()` skips any existing HTML cache file. Cancellation
is still derived from index `expira_in`, whose status element disappeared in the
September 30 redesign. `last_seen_at` advances on cached imports, while stored
schema/variant existence can suppress enrichment of revised content.

Own fetch/detail parse/import behavior, model migrations for source revision
metadata, and stale-enrichment selection in extraction/inference/occupation
steps. Keep CSV interchange and existing URL/cache-key compatibility.

## Required behavior

- Maintain explicit successful retrieval metadata. Proposed contract:
  `detail_fetched_at`, `detail_content_hash` and `source_revision` on each posting,
  plus per-cache metadata so the fetcher can operate before import. Hash relevant
  normalized content and attachment references, not navigation/session noise.
  Hash attachment bytes or extracted text when it becomes available too.
- Refresh potentially open/recent competitions on a bounded policy (initial
  target: at least once per 24h), with a request cap and existing polite delays.
  Support a deterministic `--refresh`/age option for targeted repairs and expose
  skipped/fetched/changed/failed counts. Do not force-refetch the entire archive.
- Parse `.pg-status` (`is-off`/“Anulat”, `is-live`) from detail pages into an
  explicit status field/CSV column. Unknown markup is unknown, not implicitly
  live. Prefer an explicit current detail status over stale index status.
- Only a successful response and valid expected detail structure can replace
  cached HTML. Preserve the previous good cache on network/parse failure. Store
  cache and metadata atomically; do not infer cancellation from a transient 404
  or absence on one listing page.
- Inspect actual index traversal: it currently early-stops after three unchanged
  pages once a change has been seen. Discovery absence cannot establish removal
  unless a scan is known complete. Record scan completeness and plan a periodic
  full scan if needed for reconciliation.
- Invalidate derived data by source revision. Resume must distinguish current
  content from an old variant for the same provider/model/prompt. An unchanged
  source does not trigger paid work; changed body/attachments/status does. Keep
  last-good outputs with explicit stale provenance until replaced successfully;
  cancellation suppression must take effect immediately without waiting for LLM.
- Reconcile removed attachment references and calendar rows on re-import; ensure
  old events do not survive as if still current. Retry failures without deleting
  valid source history. Backfilled legacy hashes/timestamps start as unknown,
  not “fetched now”.

## Acceptance and tests

Use old/new markup fixtures and mocked requests: first fetch, unchanged refresh,
revised deadline, cancellation with unchanged future expiry, new/replaced
attachment, expired cache, malformed 200, timeout and 404. Repeated import is
idempotent. A changed revision schedules exactly the needed enrichment; a failed
replacement retains last-good output marked stale; compare-only variants cannot
silently certify/promote a production schema. Test both pretty and query-string
posting URLs. Demonstrate the end-to-end cancellation exclusion in SQLite.

## Migration and rollout

Add nullable source metadata and compatible CSV reading. Measure refresh volume
against a copied corpus, then refresh a bounded operational sample before broader
activation. Document how legacy caches enter the queue and estimate request/LLM
cost. Rolling back scheduling must not erase status/revision history or make a
known cancelled job visible again. Production fetching and paid refreshes are
separate operational steps, not part of writing this spec.

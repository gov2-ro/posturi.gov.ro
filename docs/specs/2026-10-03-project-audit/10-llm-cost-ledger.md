# FIX-10 — Record an append-only LLM usage ledger

Priority: P2. Dependency: FIX-04 run-summary contract.
Audit/backlog evidence: `write_variant()` upserts cost and resets `created_at`;
infer and occupation calls are uncounted.

## Problem and scope

The public spend chart aggregates mutable schema variants. Re-extraction moves
spend to the latest day and erases the earlier amount. Failed/repair calls and
other enrichment steps are not reliably represented. Own a Postgres usage model,
shared provider-call accounting, run summaries, SQLite cost aggregates and stats
copy. Do not turn the variant table into a second accounting authority.

## Required behavior

- Add immutable `llm_calls` records per API attempt, including run/step,
  posting or occupation identity, provider/model/prompt, source revision,
  start/end times, attempt/repair identity, outcome/error class, provider request
  ID where available, input/output/cache-read/cache-write/reasoning tokens,
  pricing version and calculated USD. Unknown tokens/cost are null, not zero.
  No full prompts, personal data or API keys in ledger records.
- Use idempotent call identifiers to prevent duplicate local recording. Preserve
  legitimate repeated calls to the same posting; never upsert away their cost.
  Thread workers must write safely through the chosen shared accounting path.
  Record provider errors even when usage is unavailable and distinguish API
  success from validation/repair outcome.
- Instrument schema, infer and occupation calls, including retries and repair
  requests. Cost calculations must reflect each provider's actual usage shape:
  account for cache writes and reasoning/output inclusion without double billing
  token fields. Capture prices used at call time; changing today's config cannot
  rewrite historical costs.
- Export by-date/by-step totals from the ledger in Europe/Bucharest and fold
  per-run totals into FIX-04 records. Explain what is measured, estimated and
  unknown. Keep the previous variant-based historical series as explicitly
  incomplete legacy estimates; do not invent missing past calls or call them an
  exact imported ledger. Ensure aggregation does not count both sources twice.
- Implement an optional low-balance check/alert if the chosen provider exposes
  one; failures in that check are visible and cannot falsely imply free spend.
  Budget safeguards must not leak account details onto the public stats page.

## Acceptance and tests

Two force re-extractions create two ledger entries and retain both days' costs.
A failed call plus a successful repair retains both attempts. Test duplicate
local delivery, cache-read/write pricing, reasoning token semantics, null usage,
parallel execution, provider exceptions and Bucharest midnight. Reconcile the
three instrumented steps to summaries and SQLite totals with fake SDK responses.
Never run paid calls in the normal suite.

## Migration and completion

Use an additive migration and clearly labelled legacy data. Keep old public
export readers functional. Document the unavoidable gap if a process dies after
provider acceptance but before durable local recording; do not claim the ledger
is a provider invoice. Completion includes per-step accounting and truthful
public totals, not just a new schema table.

# FIX-11 — Share inference rules and controlled vocabulary

Priority: P2. Dependencies: FIX-07 PHP checks; coordinate source invalidation with
FIX-03. Historical evidence: several fixes had to be duplicated in quality checks
and Django inference, and Python/PHP vocabularies are separately maintained.

## Problem and scope

`quality_check.py` and `infer_postings.py` duplicate normalization, family
matching, provider dispatch and anomaly logic. Vocabulary drift changes filters
without a corresponding schema change. Own shared inference/label modules and
tests, with minimal importable boundaries that work from repo-root scripts and
Django management commands. Do not rewrite the pipeline or replace schemas.

## Required behavior

- Extract shared pure functions/constants for title normalization, word-boundary
  keyword matching, family selection, experience/skill detection and anomalies.
  Keep Django query access and command behavior in their adapters. Quality checks
  use the same core rules but retain independent source-ground-truth evaluation;
  sharing a function does not prove its output is correct.
- Preserve prior LLM results on `--no-llm`, confidence rules and cancellation/
  revision semantics. Add feminine/plural title forms using curated fixtures;
  do not introduce substring matching that revives IT/SAR false positives.
- Normalize recurring skill, field-of-study and language aliases deterministically
  after extraction while preserving original labels/evidence. Keep open tail
  values; do not silently map all rare domains to `altele`. Define a versioned
  alias table and re-normalize old data without paid re-extraction.
- Generate PHP vocabulary/label data from a single versioned source shared with
  `schema_models.py` or export metadata. Include v3/v4 enums and Romanian display
  labels; safely fall back for unknown future values. PHP must still run on its
  own without a Python process. Add parity tests that detect missing/mismatched
  enum labels, rather than requiring manual editing in two languages.
- Use provider/model resolution consistently in the adapters. For the unused
  `infer_conditions_llm` command, either support shared provider dispatch or mark
  it explicitly obsolete; do not add a production step simply to close an item.

## Acceptance and tests

Golden examples cover IT substrings, SAR, female/plural care roles, unsupported
family responses, explicit zero experience, age vs experience and context-specific
care roles. Compare before/after output on representative copied records and
explain intentional differences. Test imports from both entry points without
initializing Django for pure helpers, preserved LLM results and Python/PHP enum
label parity. Run relevant existing inference/export tests.

## Rollout and completion

Separate mechanical extraction from intentional classification changes in review.
Backfill deterministic label/rule changes only after measuring their effects.
Avoid forced paid re-extraction. Record normalization version and affected counts.
ESCO URI resolution, institution typing and multi-role occupation redesign stay
separate backlog work.

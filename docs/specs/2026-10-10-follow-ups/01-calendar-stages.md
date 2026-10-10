# FIX-05-STAGES — Preserve useful extraction across calendar-stage gaps

Status: specified; stage policy requires a recorded decision. Parent: FIX-05.
Evidence: October 5 backfill and October 7 backlog observations; counts are dated.

## Outcome and scope

An unlisted stage must not repeatedly discard every otherwise useful field or
silently turn a manager competition into an ordinary written-test schedule.
Own CalendarStage/prompt validation, diagnostic persistence and bounded retry.
Read schema_models.py, models_config.json, llm-schema.py and the existing test
that deliberately rejects unknown stages. Do not weaken unrelated validation.

## Required behavior and decision

1. Capture rejected raw stage values from existing retained responses/logs where
   available. If unavailable, prepare an explicit bounded compare-only sample
   with selection, cost estimate and no production promotion. Do not infer exact
   failed values from posting titles.
2. Present evidence for two policies: add genuine recurring stages to the enum
   and prompt, or map unknown stages to `altele` while preserving raw stage and
   verbatim evidence and recording the normalization. Recommendation: add known
   legitimate stages first; fallback needs an explicit decision because an
   existing test encodes strict rejection. Neither policy is selected by this spec.
3. Keep normalized and raw stage values distinct. Unknown-stage events must not
   supply a submission deadline solely because a fallback label was assigned.
   Preserve event dates and source sentences only when independently valid.
4. Persist per-posting failure diagnostics and source/prompt revision. A failed
   attempt retains previous production extraction/provenance. A policy/prompt
   revision must have a dry-run selection showing whether it would select the
   entire active corpus; prefer an explicit affected-ID retry over global repin.
5. After the policy is accepted, provide bounded retry/promotion commands and
   residual counts. A compare-only variant is not production success.

## Acceptance

Fixtures cover management-plan stages, known submission stages, multiple events,
unknown stage plus valid deadline, invalid date, failed repair and old extraction.
Prove no unrelated field validation is bypassed, raw evidence survives the chosen
policy, and unchanged successful rows are not re-paid. Reconcile affected IDs,
API attempts, promoted rows and residual errors separately.

## Delivery and validation

Read AGENTS.md, this package, relevant existing specs and current source before
implementation. Confirm actual field shapes rather than treating proposed
contracts as existing APIs. Keep PHP/SQLite public delivery and Django/PostgreSQL
processing. Use token-based styles and deterministic fixtures; run relevant PHP,
Python and browser suites. Document additive migrations, old-export behavior,
before/after metrics on copied data, and rollback where applicable.

This specification authorizes no paid calls, broad backfill, deployment, email
delivery or host changes. Prepare bounded commands and expected evidence for
those later steps. Record implementation separately from rollout in the backlog
and activity log. Writing this spec does not complete its backlog item.

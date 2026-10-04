# Audit remediation specifications

Status: per-FIX status lives in [backlog](../../backlog.md), the source of truth. As of 2026-10-04: FIX-02/03/06/08 done; FIX-01/04/05/07 code done, rollout open; FIX-09..12 not started. Originally proposed 2026-10-03. Baseline: `2c5e480`.
Evidence: [initial audit](../../audits/2026-10-03-project-audit.md).
Queue and remaining product work: [backlog](../../backlog.md).

## Assignment index

Each specification is a bounded assignment for a coding agent. An unchecked task
is not implemented merely because this documentation exists.

| ID | Priority | Assignment | Prerequisites |
|---|---|---|---|
| FIX-01 | P0 | [Sanitize PHP-rendered Markdown](01-markdown-sanitization.md) | None |
| FIX-02 | P1 | [Make deadline presentation consistent](02-deadline-presentation.md) | None; coordinate copy with FIX-05 |
| FIX-03 | P1 | [Refresh details, detect cancellation, invalidate enrichment](03-source-refresh.md) | None; coordinate metadata with FIX-06 |
| FIX-04 | P1 | [Fail on unusable pipeline output](04-pipeline-health.md) | None; summaries consumed by FIX-06/10 |
| FIX-05 | P1 | [Roll out reliable application deadlines](05-application-deadlines.md) | FIX-02/04; FIX-03 before production backfill |
| FIX-06 | P1 | [Track truthful freshness and deployed versions](06-provenance-deployment.md) | FIX-03/04 metadata contracts |
| FIX-07 | P1 | [Validate requests and test the PHP app in CI](07-php-request-validation.md) | None; include FIX-01/02 regressions as they land |
| FIX-08 | P1 | [Make attachment downloads atomic](08-attachment-downloads.md) | None; coordinate content hashes with FIX-03 |
| FIX-09 | P2 | [Correct statistics scope](09-statistics-scope.md) | FIX-05 status contract |
| FIX-10 | P2 | [Record an append-only LLM usage ledger](10-llm-cost-ledger.md) | FIX-04 run-summary contract |
| FIX-11 | P2 | [Share inference rules and controlled vocabulary](11-shared-inference.md) | FIX-07 test entry point; preserve FIX-03 invalidation |
| FIX-12 | P2 | [Retire duplicate public UI and correct architecture docs](12-frontend-retirement.md) | FIX-07 PHP coverage first |

P0: security exposure. P1: correctness or unattended reliability. P2:
maintainability and truthful analytics. Priorities are recommendations, not dates.

## Work order and coordination

Start FIX-01, FIX-02 and FIX-04. FIX-07 and FIX-08 can follow independently.
Agree the run-summary and source-metadata contracts before implementing FIX-03,
FIX-06 and FIX-10. Complete bounded refresh before using an old cache for the
FIX-05 production backfill. FIX-09 should first ship accurate current-slice copy;
historical aggregates can follow. Retire the Django public UI only after the PHP
surface has durable coverage.

These packages share files. Independent assignment does not imply conflict-free
edits: FIX-01/02/07/11 touch `helpers.php`; FIX-03/05/06/09/10 touch export/model
code; FIX-04/05/06/10 touch pipeline and operational metadata. Assign separate
branches and reconcile shared contracts before merging. No agents were spawned
as part of preparing these specifications.

## Instructions for the implementing agent

Read repository instructions, the assigned spec and actual source before coding.
Confirm baseline assumptions; dated live measurements are evidence, not fixtures
or targets to hard-code. Limit implementation to the assigned package. Preserve
the PHP + SQLite public deployment and Django/PostgreSQL processing architecture.
Do not replace the stack or add a service to solve a bounded bug.

Use fixture-based tests without network or paid model calls for normal validation.
Explain migrations and old-export compatibility. A change that affects selection,
export shape or pipeline gating must include before/after metrics from a copied
database and a rollback procedure. Keep existing atomic database promotion and
separate code/data deploys. Update the backlog and activity log when the actual
implementation is complete; report code completion separately from production
rollout or backfill completion.

Technical readiness does not require a production deployment. These specs are
instructions for later work, not authorization to run a paid corpus backfill,
change the VPS schedule or push to the shared host. Document the exact commands,
expected costs and evidence needed for those operational steps.

## Common validation baseline

Run relevant targeted tests, then the suite when shared Python behavior changes:

```sh
webapp/.venv/bin/python -m pytest webapp/tests -q --tb=short
```

The suite needs an isolated test PostgreSQL database. Never point tests at a
production database. Add PHP/Playwright checks through FIX-07 rather than relying
on transient audit scripts. Lint changed PHP files with `php -l`. Rebuild CSS and
run `php webapp-php/assets/check-skins.php` only when relevant assets change.

Acceptance requires reproducing the original failure, demonstrating the corrected
behavior, and checking the compatibility cases listed in the assigned spec.
Existing Python tests passing alone do not certify the production PHP surface.

# FIX-07 — Validate requests and test the PHP app in CI

Priority: P1. Dependencies: none; incorporate FIX-01/02 fixtures as they land.
Audit finding: 8. Current CI workflow exists only under `_OBSOLETE/`.

## Problem and scope

Live `/?q[]=medic` returns 500 because scalar request fields are passed to
`trim()` without checking shape. The 446 passing Python tests do not exercise
the production PHP DOM. Own a central query decoder, PHP route integration,
fixture database/test harness and active CI workflow. Keep shared-host PHP and
the existing Python suite; no public database or provider calls in CI.

## Required behavior

- Define each recognized parameter's shape, accepted values and size limits.
  Validate scalar search/sort/status/date/page/employer/calendar-title fields and
  bounded arrays of scalar facet values. Reject nested arrays and array-valued
  scalars with HTTP 400 through one consistent page/feed error path. Unknown
  parameters can be ignored for compatibility; valid old URL forms must remain.
- Normalize once at the front controller/helper boundary; pages, shared filters,
  chips and feeds use the same result. Do not cast arbitrary arrays into strings
  to conceal errors. Handle invalid dates/enums/pages predictably and bound long
  inputs without permitting expensive unbounded query construction.
- Create a small deterministic SQLite fixture with at least future/past/unknown
  deadlines, cancellation, raw/structured bodies, multi-role entries, safe and
  unsafe Markdown, diacritics and multiple employers/counties. No real personal
  contact data is required. Use `POSTURI_DB` and the built-in PHP router locally.
- Add request-level tests for all public routes/feeds plus rendered tests for
  filtering, facet count/result parity, chips, pagination, sort, search, canonical
  redirects and old URLs. Preserve useful HTML/JSON error content types.
- Add Playwright checks for mobile drawer keyboard/focus behavior, HTMX swaps,
  URL/back navigation, filter state and deadline text. Ensure unsafe content
  cannot execute once FIX-01 lands. Test widths 320, 375 and 1280.
- Restore an active `.github/workflows/` workflow after reviewing the archived
  workflow. Run isolated PostgreSQL Python tests/migration checks and PHP
  lint/request/browser checks. Pin the browser/test tooling and supported PHP
  runtime; document setup and commands. Do not simply copy stale lint settings
  without verifying they work on the current tree.

## Acceptance and tests

`q[]=medic`, `sort[]=x`, nested facet arrays and oversized inputs receive a
controlled 400, never 500. A normal scalar query, repeated allowed facets,
punctuation-only search and encoded Unicode still work. Verify JSON/Atom/iCal
filters match browse selection, feeds retain valid formats, and direct database
access stays denied. All checks run from a clean checkout without paid APIs or
production access and are actually wired to pull requests.

## Rollout and completion

Document query compatibility and test-fixture generation. Publish the runnable
test commands and green CI evidence; “22 PHP files lint” is necessary but does
not complete this package. No production fixture database is deployed.

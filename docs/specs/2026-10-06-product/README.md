# Next user-facing packages

Status: specified, not implemented. Prepared 2026-10-06 against `6accc59`.
The [backlog](../../backlog.md) is authoritative for completion.

| Order | Package | Assignment |
|---|---|---|
| 1 | UX-01A | [Simpler filters and clear application status](01-discovery.md) |
| 2 | UX-09 | [Save and hide announcements without an account](02-save-hide.md) |
| 3 | UX-04-FEEDS | [Filtered RSS, Atom and iCal subscriptions](03-filtered-feeds.md) |

Each file is a standalone implementation assignment. Read root AGENTS.md,
the assigned spec and the referenced source. Source and tests take precedence
over stale comments. Implement one package at a time; all three touch list
markup and browser behavior. Apply subsequent packages on top of earlier ones.
Do not spawn agents unless separately requested.

Keep the PHP/SQLite deployment and the Django/PostgreSQL pipeline. No paid model
calls, migrations, VPS changes or deployment are required by these packages.
Use deterministic PHP/browser fixtures. All colors/fonts/radii use the existing
tokens; rebuild committed CSS when markup adds utility classes. Preserve skins.

Common validation, from repository root:

```sh
php webapp-php/tests/request_test.php
php webapp-php/tests/deadline_test.php
php webapp-php/tests/compat_test.php
npx playwright test --config webapp-php/tests/browser/playwright.config.js
```

Also lint changed PHP files with `php -l`. If styles change:

```sh
npm run css
php webapp-php/assets/check-skins.php
```

Add focused tests for the specified failure cases; extend existing fixtures
rather than copying the live database. Check 320, 375 and 1280 pixel widths,
keyboard use, no horizontal overflow, and HTMX/back-forward behavior. Report
checks actually run and limitations. Update activity-log.md and the relevant
backlog item; code readiness and live rollout are separate facts.

Suggested handoff prompt:

> Implement docs/specs/2026-10-06-product/01-discovery.md. Follow AGENTS.md and
> the package README. Read the referenced source first, keep the stated scope,
> run the acceptance checks, and record completion and any remaining gaps.
> Do not deploy or make paid API calls.

Replace the filename to assign the next package.

These assignments intentionally do not complete every UX-01/UX-04 aspiration.
Horizontal filters, card/table modes, locality search, account features and
email notifications remain later work with their own acceptance designs.

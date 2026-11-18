# Brief — Salary filter as a multi-select (BUG-SALARY-MULTI)

Date: 2026-11-18. Author: Opus. Builder: Sonnet. Backlog: BUG-SALARY-MULTI.

## Problem

The filter "Salariu" on `/posturi/` shows checkboxes. `salary_bucket` is a scalar parameter: it is in `QUERY_SCALARS` (`webapp-php/query.php`) and not in `MULTI_PARAMS` (`webapp-php/helpers.php`). Each checkbox has `name="salary_bucket"`. With two boxes checked, the browser sends `salary_bucket=a&salary_bucket=b`, and PHP keeps the last value. "fără salariu estimat" is the last option in the form, so it always wins. The owner found this on 2026-11-18.

## Decision (owner, 2026-11-18)

`salary_bucket` becomes a multi-value parameter. The checked values combine with OR, as the other checkbox groups do. Example: `net-5000-7000` + `net-peste-7000`, or `net-sub-3000` + `fara-estimare`.

## Scope

Change these files. Stop and ask before you change any other file.

- `webapp-php/helpers.php`
  - Add `'salary_bucket'` to `MULTI_PARAMS`.
  - `build_filters()`: read `(array)($p['salary_bucket'] ?? [])`. Drop the values that `salary_bucket_sql()` does not know. For the known values, put each value's fragments in parentheses joined by AND, and join the values with OR. Keep the binds in order. No known value: no clause (as today for an unknown value). Keep the comment about `CAST(? AS REAL)`.
- `webapp-php/query.php`: remove `'salary_bucket'` from `QUERY_SCALARS`. A legacy scalar URL (`?salary_bucket=net-3000-4000`) must still work, as `?eqf=6` does.
- `webapp-php/pages/list.php`
  - `$sal_bucket` becomes an array of strings without blanks.
  - The salary note: show it when the selection has at least one known value and does not contain `SALARY_NONE`. With `SALARY_NONE` only, show the "none" form of the note, as today. With `SALARY_NONE` and a range, show no note. The link "Vezi-le" keeps its target: the other filters with `salary_bucket[]=fara-estimare` only.
- `webapp-php/partials/facets.php`: pass the array to `facet_group('Salariu', …)`.
- `webapp-php/static/searches.js`: add `'salary_bucket'` to its `MULTI_PARAMS` (`tests/searches_test.php` checks that the two lists are the same).
- `webapp-php/inc/alerts.php`: migrate the stored keys (see "Migration").
- `webapp-php/static/searches.js`: re-key the browser records (see "Migration").
- Tests: `webapp-php/tests/fixtures/search_keys.json`, `discovery_test.php`, `searches_test.php`, `alerts_test.php`, `home_test.php`, `llms_test.php` if it checks the example, and `webapp-php/tests/browser/discovery.spec.js` or `chips.spec.js`.
- `webapp-php/pages/llms.php`: update the example URL only if a test needs it.

Do not change `docs/decisions/0001-saved-search-identity.md`. Opus has added the note for this change.

## Migration

ADR 0001, rule 4: a multi-value parameter is written as `name[]`. After this change, the key of `?salary_bucket=x` is `salary_bucket%5B%5D=x`, not `salary_bucket=x`. Two stores hold old keys.

1. **Email alerts** (`subscriptions.search_key`, SQLite, on the VPS). `al_parse_key()` refuses a key that is not canonical, so `bin/alerts.php` would skip each subscription with a salary filter. Add an idempotent data migration to the place where `inc/alerts.php` creates or upgrades its schema (read `al_schema()` first; follow its pattern). The migration rewrites `salary_bucket=` to `salary_bucket%5B%5D=` at the start of a key and after each `&`. The parameter name does not change, so the order of the parameters stays canonical. A second run changes nothing. Check the unique index on (`email_index`, `search_key`): report if a collision is possible. Test it in `alerts_test.php` with an old key: after `al_db()` the key is canonical, and `al_parse_key()` accepts it.
2. **Browser saved searches** (`localStorage` `posturi.searches.v1`). The "Salvează căutarea" toggle compares `searchKey(location.search)` with the stored keys, so an old record shows "not saved", and the reader can save a duplicate. On read, re-key each valid record whose key differs from `searchKey('?' + key)`. Set `key` and `qs` to the new key. Keep `sort`, `label`, `savedAt` and `openedAt`. When two records get the same key, keep the newer `savedAt`. Write back only when the read status is `ok`. Do not change `VERSION`. Add a check for this in the browser tests.

## Acceptance

- On `/posturi/`, check "5.000 – 7.000 lei net" and then "peste 7.000 lei net". Both stay checked after the HTMX update. The result count equals the sum of the two option counts. Add this as a Playwright test.
- Check a range and "fără salariu estimat": both stay checked, and the list has both sets.
- `?salary_bucket=net-3000-4000` (scalar) and `?salary_bucket[]=net-3000-4000` give the same results and the same search key.
- `?salary_bucket[]=net-peste-5000` (legacy, `SALARY_NET_LEGACY`) works and its chip says "peste 5.000 lei net".
- Each facet count is still the result count of its option.
- An old alert key with a salary filter is migrated and selects the same postings as before.
- All PHP suites pass: `for f in webapp-php/tests/*_test.php; do php "$f"; done`. Run the Playwright specs that you touched: `npx playwright test --config webapp-php/tests/browser/playwright.config.js`.

## Report

Write an activity-log entry and mark BUG-SALARY-MULTI `[x]` in `docs/backlog.md`. Report what you measured and what you assumed. Report the number of stored alert keys that the migration would change, if you can count them on the dev data. Do not commit and do not deploy.

## Deploy note (for the owner)

The alert migration runs when the code first opens the alerts database. Deploy the code; the next web request or cron run migrates the keys. No manual step is needed on the VPS. The first open logs "alerts: migrated N salary_bucket key(s)" to the PHP error log (cron output for `bin/alerts.php`). Check N once after the deploy.

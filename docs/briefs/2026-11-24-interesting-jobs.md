# Brief — „Posturi interesante”, phase A and B (UX-INTERESTING)

Date: 2026-11-24. Author: Opus. Builder: Sonnet. Spec: `docs/specs/2026-11-24-interesting-jobs.md` (owner decision 2026-11-24).

## Read first

- The spec, all of it.
- `webapp-php/helpers.php`: `SALARY_EXPR`, `pay_quintiles()`, `pay_level()`, `build_filters()`, the facet and chip label tables (search for `exp_level`).
- `webapp-php/pages/list.php` and `webapp-php/partials/facets.php` (facet groups and counts), `webapp-php/pages/detail.php` (the salary box ends ~line 700), `webapp-php/pages/home.php` (`$combos`, the cards section ~line 228).
- `posting_roles.py` / the `posting_roles` table (study level, per-role vacancies if present), `ops/anomaly-survey.py` R12 (title test), E03 and E05.
- `webapp-php/pages/anomalies_public.php`: `ANOMALY_FP_LIKE` (false-positive exclusion).

## Steps

1. **Measure first** (spec § 6) on `data/_dev/full-2026-11-23.sqlite` (scratch copy with the pay columns; the previous builders' method is in the activity log). Report the counts per value and per threshold variant, and the overlap. Do not stop for this: build with the spec's first thresholds, and put the numbers in the activity log for the owner.
2. **Filter** `interes[]` in `build_filters()` with the five values; phase B values only when `anomaly_signals` exists. Validation as the other array parameters (unknown values: 400 or ignored, as the existing facets do — follow them).
3. **Facet group** „Posturi interesante” with counts, chips with the sentence as `title`, and the parameter in saved searches, alerts and feeds exactly as the other facets (no special case).
4. **Detail page:** the „Interesant: …” lines of spec § 2.1, under the salary box. Keep the existing `data-pay-*` attributes; add `data-interesting="{value}"`. Another session edits the salary box layout: pull `main` first, touch only the lines you add, and do not change the box itself.
5. **Home page:** one card per value after „Primul job: fără experiență cerută” (count > 0; a value under 5 open postings is left out of the home page); all cards at the base font size (spec § 3); the line „Vezi și: anomalii și abateri statistice” after the cards.
6. **Tests:** PHP tests for each value's predicate on the fixture (add fixture rows), the facet count equals the list count, the chip, the detail line, the home cards and the link; Playwright for the home cards at 320, 375 and 1280 px (no horizontal scroll, 44 px targets) and the facet. Run every PHP test, `pytest webapp/tests`, and the specs `home`, `discovery`, `salary_range`, `anomalies`.

## Out of scope; stop and ask

- No change to the anomaly checks, the export, ADR files, DS files, tokens or `.g2-` classes.
- Stop and ask when a step needs a file this brief does not name (tests and fixtures are allowed).

## Done

Tests pass. Backlog item UX-INTERESTING `[x]` for phase A and B with the measured numbers. Activity-log entry with Done / Hard / Decided. Commits on your branch; no merge, push or deploy.

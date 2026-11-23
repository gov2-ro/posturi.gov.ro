# Brief — SALARY-GRAD-FOOD follow-ups: every view on the same figure (SALARY-GRAD-FOOD-FOLLOWUP)

Date: 2026-11-23. Author: Opus. Builder: Sonnet. Parent: SALARY-GRAD-FOOD (merged in `acdc90c`), ADR 0008 rule 11.

## Goal

Each place that shows or counts a salary uses the figure of rule 11 (`pay_min` / `pay_max`, then the old columns), as `SALARY_EXPR` and `list_salary()` already do. No view says „la gradația 0” about a figure that has a range.

## Read first

- `docs/decisions/0008-salary-two-baselines.md`, rule 11.
- `salary_pay.py`, `webapp-php/helpers.php`: `SALARY_EXPR`, `list_salary()`, `pay_range_view()`.
- The backlog item SALARY-GRAD-FOOD-FOLLOWUP.

## Steps

0. **No employer exclusion (SALARY-GRAD-FOOD-EXCLUDED, owner 2026-11-23).** The law excludes *people* with special food rights (mainly military and police staff, OG 26/1994), not employers. All 44 open postings that the name patterns excluded are civilian contract posts. Military, police and penitentiary posts are already out of scope through Anexa VI. So: remove the employer exclusion from `salary_pay.pay_range()` and its callers; in `data/salarii/drepturi-2026.json` replace `angajatori_exclusi_hrana.reguli` with a short `nota` that says why there is no list (the sentence above, in Romanian). Remove `excluded_employer()` if nothing else uses it. Update the Python tests that covered an excluded employer: a civilian post at „Unitatea Militară 01256” now gets the 347 lei. Count the open postings that gain the food allowance on the local copy.
1. **`webapp-php/partials/result_list.php`** (the line „salariu net ≈ X–Y lei, mediana …” above the list, lines ~18 and ~59). Read the low and high ends with the same precedence as `list_salary()`: `pay_min`/`pay_max`, else the stated pay, else the estimate; the project-hire branch stays as it is. Change the `title` text: the figure is no longer „la gradația 0”; say „de la gradația minimă cerută la gradația 5, cu indemnizația de hrană unde legea o dă” (short).
2. **`webapp-php/pages/stats.php`** (lines ~32–33 and every other salary read on the page). Counts of „anunțat” vs „estimat” keep their meaning (the source of the figure). Every *amount* (median, chart, bucket) reads the rule-11 figure through `SALARY_EXPR` or the same precedence. List each query you change in the activity log.
3. **`webapp-php/feeds/_feed.php`** (lines ~230–260). When `pay_min` is set, the estimate lines use the same wording as the detail page (`pay_range_view()`), not „, la gradația 0”. Lines without a range keep today's text.
4. **`.github/workflows/ci.yml`**: add a step for `php tests/salary_range_test.php`, next to the other PHP tests.
5. **Tests.** Extend `salary_range_test.php` (or the stats/feeds tests) so that the list line, one stats amount and one feed item read the range on the fixture. Run every PHP test, `pytest webapp/tests`, and the Playwright specs `salary_range`, `discovery`, `feeds` (if one exists).
6. **Measure** on `data/_dev/full-2026-11-23.sqlite` with the pay columns (use the copy from the parent task, or rebuild it with `ops/measure-pay-range.py`'s method): the list line on `/` and the stats medians, before and after. Write them in the activity log.

## Out of scope; stop and ask

- No change to `salary_pay.py` or `drepturi-2026.json` beyond step 0. No change to the export, ADR 0008 or the detail page.
- Mark both backlog items SALARY-GRAD-FOOD-EXCLUDED and SALARY-GRAD-FOOD-FOLLOWUP `[x]` when done.
- Stop and ask when a step needs a file that this brief does not name (other than tests and fixtures), a token or a `.g2-` class.

## Done

Tests pass. Backlog item SALARY-GRAD-FOOD-FOLLOWUP is `[x]`. Activity-log entry with Done / Hard / Decided and the numbers. Commits on your branch; no merge, push or deploy.

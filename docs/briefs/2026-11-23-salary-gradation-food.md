# Brief — gradation range and food allowance in the salary figure (SALARY-GRAD-FOOD)

Date: 2026-11-23. Author: Opus. Builder: Sonnet. Owner decision: ADR 0008 rule 11, accepted 2026-11-23.

## Goal

A salary figure of the law in force becomes a range: from the floor gradation to gradation 5, plus the food allowance (347 lei) where the law grants it. The list row, the filter, the sort and the detail page use the same range. On the detail page, the extracted section „Salarizare” no longer repeats a stated salary that the top box already shows.

Reference posting: 21359, `impresar-artistic-grad-profesional-ia` (stated 7,870 lei, 3 years of experience). Expected result:

| End | Gradation | Base (gross) | Net without food | Food | Total gross |
|---|---|---|---|---|---|
| low | 1 (3 years) | ceil(7,870 × 1.075) = 8,461 | ≤ 6,000 | +347 | 8,808 |
| high | 5 | ceil(7,870 × 1.2452) ≈ 9,800 | ≈ 5,733 ≤ 6,000 | +347 | ≈ 10,147 |

Check the exact rounding against `salary_grid.row_pay()` and report the numbers you get.

## Read first

- `docs/decisions/0008-salary-two-baselines.md`, rule 11 (the rule), rules 3, 4, 7, 9 and 10 (what rule 11 leaves alone).
- `docs/audits/2026-11-23-salary-law-research.md`: the table „Reguli pentru calcul” and the section „Ambiguități”.
- `salary_grid.py`: `gradations`, `gradation_for_years()`, `multiplier()`, `row_pay()`.
- `salary_net.py` and `net_from_gross()` in `webapp-php/helpers.php` (kept in step by `webapp/tests/test_salary_net.py`).
- `export-to-sqlite.py`: `_stated_salary()`, `_salary_columns()`, the `inf_experience_years` column.
- `webapp-php/helpers.php`: `SALARY_EXPR` (line ~1301), `list_salary()`, `pay_level()`, `render_schema_sections()`.
- `webapp-php/pages/detail.php` lines ~505–700 (the stated box and the estimate box) and `webapp-php/db.php` (the shims for old exports).

## Steps

1. **Data file.** Create `data/salarii/drepturi-2026.json` with: the food amount (347), the net cap (6,000), the gradation table (years → level → percent), and the excluded employers as name patterns (MApN / „unitatea militară”, MAI and its inspectorates, ANP and the penitentiaries, SRI, SIE, SPP, STS, ANRSPS). Give each value its act, article and start date, from the audit. Reuse the gradation table of `salary_grid.py` if it already holds the same values; do not keep two copies. If it differs, stop and report.
2. **Module `salary_pay.py`.** One pure function computes the range of one posting: input = the stated figure or the estimate (with `baza`, the row kind, local range or not), the experience years and the employer name; output = for each end, the gradation, the base, the food (0 or 347) and the total gross. Follow the scope of rule 11 exactly:
   - stated pay (gradation 0, or the one gradation the text names — see step 3) and the law grid (b): the rule applies;
   - declared pay (a2), project hire, Anexa VI, a management row, the draft (c): no range; the module returns nothing, and the old figure stays;
   - local range (rule 3): the low end takes the floor gradation; the high end is the cap, with no gradation, and gets food only if its net is under the cap.
   Test the food condition on `net_from_gross(base)`, without the allowance.
3. **Single named gradation (optional, small).** A regex over the stated-pay text finds a figure tied to one gradation („gradația 3”, „gradația 0”). VPS found 4 of 157 cases. If the regex is not clean on those samples, skip this step and record it in the backlog.
4. **Export.** Add the columns `pay_min`, `pay_max` (total gross), `pay_grad_min`, `pay_grad_max`, `pay_food_min`, `pay_food_max` to `job_postings`. NULL when the module returns nothing. Add a shim in `db.php` for an export without them (NULL).
5. **SQL and PHP.** `SALARY_EXPR` becomes `COALESCE(j.pay_min, j.inf_salary_min, j.sal_min)` inside the existing `CASE` (the project branch stays first). `list_salary()` and `pay_level()` read the same precedence. The `salary_bucket` keys do not change.
6. **Detail page.** In the stated box and in the estimate box, when `pay_min` is set:
   - main line: the net range, „de la X la Y lei net / lună”;
   - gross line: „brut: X–Y lei / lună, gradația A–5, cu indemnizația de hrană de 347 lei” (leave out the food part where it is 0; say „doar la gradațiile mici” when only the low end has it);
   - replace the text „la gradația 0” on these figures;
   - one short note: „Gradația depinde de vechimea în muncă. Minimul vine din experiența cerută în anunț.”
   - Remove the extracted section „Salarizare” (`baseSalary`) when the stated box shows. Keep it when there is no stated box.
   Use the role tokens and the existing classes only.
7. **Tests.** Python unit tests for `salary_pay.py`: posting 21359; unknown experience; 20+ years; the 6,000 lei boundary on each side; an excluded employer; a management row; a local range; declared pay (no range). A PHP test for `SALARY_EXPR` with and without the new columns. Update the detail Playwright spec for the new lines at 320 and 1280 px.
8. **Measure** on `data/_dev/full-2026-11-23.sqlite` after a local re-export:
   - the counts per `salary_bucket` value, before and after (ADR 0008 rule 7);
   - postings per floor gradation (0–5) and per kind (stated / law grid / not in scope);
   - postings with food at the low end, at the high end, at both, at neither; postings excluded by employer;
   - 10 detail pages read by hand, posting 21359 among them.
   Write the numbers in the activity log. Say that they come from the local copy; the owner measures again on the VPS.

## Out of scope; stop and ask

- No change to the draft figure, the declared pay, the estimator page `/estimator-salariu/` or the `/salarii/` hub.
- No holiday vouchers and no bonuses.
- No new LLM extraction and no change to `models_config.json`.
- Stop and ask when a step needs a change to ADR 0008, a `.g2-` class, a token, or a file that this brief does not name.
- Stop and ask when the gradation values of `salary_grid.py` differ from the audit.

## Done

Tests pass (`pytest webapp/tests`, the PHP tests, the Playwright spec). Backlog item SALARY-GRAD-FOOD is `[x]` with the measured numbers. The activity log has an entry with Done / Hard / Decided and what you assumed. Not deployed: the owner deploys after review.

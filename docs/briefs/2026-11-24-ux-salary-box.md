# Brief — the salary box in plain words, and the salary in every list (UX-SALARY-BOX)

Date: 2026-11-24. Author: Opus. Builder: Sonnet. Owner approved the layout on 2026-11-24.

## Goal

1. The salary box of the detail page has one fixed width. Opening „Cum a fost calculat” does not make it wider.
2. The box reads top to bottom: the figure, what makes it go up, the actions, and then the caveat.
3. „Cum a fost calculat” explains the figure in steps that a person with no knowledge of the grid can follow. No internal IDs, no „selector”, no „variantă”.
4. Each list of postings shows the salary line (💰) of the main list. Today the employer page (`/angajator/<slug>/`) does not show it.
5. The posting list of the employer page has the same maximum width as the main list. Today it fills the whole screen, and the date sits at the far right.

## Read first

- `CLAUDE.md` of the project: the palette rule (role tokens only), the DS rules and the cascade layers.
- `~/devbox/gov2/gov2ro-design-system/COMPONENTS.md`. Use a DS component for the box or the footer buttons when one fits.
- `webapp-php/pages/detail.php`, lines ~505–735: the three pay boxes (`data-pay-project`, `data-pay-stated` and the estimate box).
- `webapp-php/helpers.php`: `pay_range_view()`, `list_salary()`, `pay_level()`, `pay_meter()`, `SELECTOR_FIELD_LABELS`, `selector_value_label()`.
- `webapp-php/partials/result_list.php`, lines ~174–195: the 💰 line of a list row.
- `webapp-php/pages/employer.php`: the two posting lists (active and expired).
- `docs/decisions/0008-salary-two-baselines.md`, rule 11 (the range from the minimum gradation to gradation 5, plus the food allowance).

## Coordination

Another session (posturi-dev-2311-2) changes the note under the stated pay („Estimare după Legea 153/2017: … la gradația 0”) in `data-pay-stated` and maybe in `helpers.php`.
- Run `git pull` on main before you start. Before you finish, rebase on main again.
- In the `data-pay-stated` box, change only the container classes and the footer. Do not change its figure lines.

## The law fact (checked by Opus)

Legea 153/2017, art. 10 alin. (4): gradația 1 is for 3 to 5 years of work (*vechime în muncă*), gradația 2 for 5 to 10 years, gradația 3 for 10 to 15 years, gradația 4 for 15 to 20 years and gradația 5 for more than 20 years. Gradația 0 is for less than 3 years.

There are two exceptions. Management posts have the gradation included in the pay. Military staff, police and penitentiary staff (Anexa VI) have 7 gradations. The estimate box does not show these posts with a gradation range, so the text below applies only when `pay_range_view()` gives `grad_min` and `grad_max`.

## Steps

### 1. One width for every pay box

In the three pay boxes, replace `inline-block max-w-full` with a block of fixed width: `block w-full max-w-2xl`, or the DS equivalent. Check the width at 375 px, 768 px and 1280 px. At 375 px, the box keeps the 16 px gutter and does not scroll sideways.

### 2. The layout of the estimate box

The order, top to bottom:

```
┌──────────────────────────────────────────────────────────────┐
│ SALARIU ESTIMAT                                 ▂▃▅▆█ (meter) │
│ 3.846 – 4.977 lei net / lună                                 │
│ Brut 6.575–8.508 lei, cu indemnizația de hrană de 347 lei.   │
│ Salariul crește cu vechimea în muncă: (seniority line, step 3)│
│ Printre cele mai mari: … (pay_level text)                    │
│ ──────────────────────────────────────────────────────────── │
│ Dacă proiectul noii legi ar fi adoptat: ~4.421–5.708 lei net │
│ ──────────────────────────────────────────────────────────── │
│ [Calculează cu altă gradație]       [Cum a fost calculat ▾]  │
│ (the steps of step 4 open here, inside the box)              │
└──────────────────────────────────────────────────────────────┘
  Estimare după Legea 153/2017 …; nu este un calcul salarial oficial.
```

- The meter moves to the right of the heading row. Its text („Salariu printre cele mai mari: treapta 5 din 5 …”) stays as one line below the gross line.
- Show the draft-law line only when it exists today (`$draft_note`). It keeps its link.
- The two actions are in one footer row, with a line (`border-t border-note-line`) above it. „Calculează cu altă gradație” is shown only when it exists today. „Cum a fost calculat” is the `<summary>` of the `<details>`. The open panel spans the full width of the box, under the footer.
- The disclaimer (`$sal['disclaimer']`) moves out of the box, as one small muted line under it.
- The declared-pay variant (`$decl`) and the draft-law variant (`$sbaza === 'proiect'`) use the same frame: heading, figure, lines, footer and disclaimer. Keep their texts and their `data-` attributes.
- Apply the same frame to the stated-pay box (`data-pay-stated`): footer and disclaimer only where it has them. See *Coordination*.
- Keep every existing `data-` attribute, because the tests use them.

### 3. The seniority line

Replace `$range_note` („Gradația depinde de vechimea în muncă. Minimul vine din experiența cerută în anunț.”) with a line built from `grad_min` and `grad_max`. Add one constant in `helpers.php` for the years of each gradation (art. 10 alin. (4)): `0 => 'sub 3 ani'`, `1 => '3–5 ani'`, `2 => '5–10 ani'`, `3 => '10–15 ani'`, `4 => '15–20 ani'`, `5 => 'peste 20 de ani'`.

- Range (`grad_min < grad_max`): „Salariul crește cu vechimea în muncă: de la gradația {min} ({years}) la gradația 5 ({years}). Minimul vine din experiența cerută în anunț.” Leave out the last sentence when `grad_min` is 0.
- One gradation only, or no gradation: keep today's sentence, or no sentence.
- Use the same line in the stated-pay box when it has a range.

### 4. „Cum a fost calculat” in plain words (law branch)

Replace the technical list (the `dl` with „Rânduri din grilă” and „Varianta”) with an ordered list. Show each step only when its data exists. Wording (Romanian, short sentences, active voice):

1. **Funcția: {ocupatie}.** Am dedus-o din titlul anunțului ({incredere label, lower case}).
2. One step for each entry of `selector_provenienta`: **{label}: {value}.** {basis, with a capital letter}. Example: „**Nivel studii: Licență.** Din cerințele anunțului.”
3. **Salariul de bază: Legea 153/2017, Anexa {anexa}.**
   - With more than one grid row, add: „Titlul se potrivește cu {N} funcții din tabelul legii. Intervalul le cuprinde pe toate.”
   - When the estimator link exists, add the link „Vezi-le în estimator →” with the same URL as „Calculează cu altă gradație”.
   - Take the Anexa and N from the data you have. `randuri` holds at most 12 refs, so take N from `motiv` („7 rând(uri) din Anexa II, …”) when you can parse it. Otherwise use `count(randuri)`. Report which source you used.
4. **Gradația:** the seniority sentence of step 3, or „gradația {g}” for a figure without a range.
5. **Indemnizația de hrană:** „{food_text}.” Show it only when `pay_range_view()` has food.
6. **Net:** „Din brut scădem CAS 25 %, CASS 10 % și impozitul de 10 %, cu deducerea personală. Nu am socotit persoane în întreținere.”

After the list, under a small heading „De reținut”, show the remaining `avertismente`.
- Drop the warning that starts with „Selectorul corespunde la”, because step 3 says it in plain words. Filter it in PHP. Do not change `salary_grid.py`.
- Drop the line „Salariul de bază din tabelul Legii 153/2017, la gradația 0.”, because step 3 replaces it.
- Do not show the raw refs (`L153-t6!94`…) or `varianta` as text. Put them in `data-pay-rows` and `data-pay-variant` attributes on the panel, for debugging.
- Coefficient branch (`coef_min` set, the draft law): keep the formula, but in words: „coeficientul {c} × valoarea de referință {vr} lei”.
- The declared-pay explainer (`$decl`) keeps its content. It only moves into the new panel.

### 5. The salary line in the employer lists

- In `employer.php`, add the 💰 line of `result_list.php` (salary, kind, `pay_meter()`) to the active rows and the expired rows. Copy the markup with its classes (`pg-salary`, `pg-salary-value`, `pg-salary-kind`).
- If you can move the markup into a small partial and call it from both files without changing how `result_list.php` renders, do that. Otherwise copy it, and say which one you chose.
- Add the columns that `list_salary()`, `pay_level()` and `pay_meter()` read to the two SELECTs (`$stmt7`, `$stmt8`). Check the main list query to see which columns these functions need.
- Cap the width of the listing column (`flex-1 min-w-0`) to the width of the main list on `/`. Measure that width; do not guess it.
- Check the other lists: `archived.php` already has the line. `new_soon.php` and `anomalies_public.php` are compact lists. Do not change them. Note them in the activity log as checked.

### 6. Tests

- Update the Playwright specs that read the box: `salary.spec.js`, `salary_range.spec.js`, `salary_limits.spec.js`, `declared_pay.spec.js` and `project_pay.spec.js`. Change selectors only where the markup moved.
- Add these checks:
  - the box width is the same before and after „Cum a fost calculat” opens (`boundingBox().width`);
  - the open panel has no text that matches `/L153-|Selectorul|lege153-/`;
  - at 375 px there is no horizontal scroll;
  - an employer page with an estimated posting shows `.pg-salary`.
- Run every PHP test in `webapp-php/tests/`, the specs above, and `ds.spec.js`.

### 7. Screenshots for review

Take screenshots of one law-estimate posting, one declared-pay posting and one stated-pay posting, with the box closed and open, at 1280 px and 375 px. Take one more of an employer page at 1280 px. Save them in `tmp/ux-salary-box/` and list the paths in your report.

## Out of scope; stop and ask

- No change to `salary_grid.py`, `salary_pay.py`, the export, `pay_range_view()`, `list_salary()` or ADR 0008.
- In `helpers.php`, you may add only the gradation-years constant and, if needed, one small helper for the explainer steps.
- No new token, no `.g2-` class change, no raw Tailwind palette class, no hex literal.
- Stop and ask when a step needs a file that this brief does not name (other than tests and fixtures).

## Done

- Tests pass. You report the screenshots.
- The backlog item UX-SALARY-BOX is `[x]`.
- The activity-log entry has Done / Hard / Decided, what you measured (the main list width, N from `motiv` or `randuri`) and what you assumed.
- Commit on your branch. Do not merge, push or deploy.

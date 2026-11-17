# Brief — Net salary, postings with no estimate, salary note

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: branch `ux-05-landing-page` with its uncommitted files (the themed landing work). Do not revert them.

Read first: `docs/decisions/0001-saved-search-identity.md`, `docs/decisions/0005-gov2-ds-only.md`, and in `webapp-php/helpers.php`: `net_from_gross()`, `net_label()`, `SALARY_BUCKETS`, `SALARY_EXPR`, `list_salary()`, `pay_meter()`, and the salary part of `build_filters()`.

## Goal

1. The reader sees net pay first, in each place that shows a salary.
2. The salary filter works in net pay and has an option for the postings with no salary.
3. A result list that a salary filter narrows says what the filter leaves out.

## Decisions (owner, 2026-11-17)

- Salary ranges are net. The gross figure stays on the detail page only.
- The salary filter gets an option for the postings with no salary.
- Each result list with a salary filter shows a note that explains the situation.
- A salary filter does not include the postings with no salary. (Design decision: the opposite makes the filter promise pay that nobody knows.)

## Measured (17 Oct export, 1,197 active postings, net ≈ gross × 0.585)

No salary figure: 202 (17%). Net below 3,000: 357. 3,000–4,000: 396. 4,000–5,000: 85. 5,000 and above: 157. Measure again with `net_from_gross()` and report the exact numbers.

A posting has no estimate when its title matches no function of the salary grid (for example a role of an EU-funded project). In the 17 Oct export, 0 of 371 postings without an occupation match have an estimate.

## Constraints

- This brief may change the salary part of `build_filters()` and the validation of the `salary_bucket` value. Do not change another filter. Do not rename or add a query parameter. `search_key()` does not change.
- **Old values stay valid, with their old meaning.** `salary_bucket=sub-3000`, `3000-4000`, `4000-5000`, `5000-7000` and `peste-7000` continue to select by gross pay, as now, so that a saved search and an email subscription select the same postings as before. The filter list does not offer them. Their chip says "brut" (for example „peste 7.000 lei brut”).
- The net figure comes from `net_from_gross()` only. Do not change its rates. The export has gross figures; compute net in PHP. For the filter, compute each net threshold as a gross threshold once (find the gross value where `net_from_gross()` crosses the threshold), so that the SQL compares the stored gross column and stays index-friendly. A test proves that the filter and the displayed figure agree at the edges.
- Each count uses the predicate of its link. Role tokens only. Use a DS component when one fits. Do not edit the DS copy.
- Romanian copy: use the strings of this brief. Say "net estimat" where the figure is an estimate.

## Work

### 1. Net buckets

New values of `salary_bucket`, offered in the filter "Salariu" in this order:

| Value | Label |
|---|---|
| `net-sub-3000` | sub 3.000 lei net |
| `net-3000-4000` | 3.000 – 4.000 lei net |
| `net-4000-5000` | 4.000 – 5.000 lei net |
| `net-peste-5000` | peste 5.000 lei net |
| `fara-estimare` | fără salariu estimat |

- A net bucket selects by the net of the posting's lower figure (the figure that `SALARY_EXPR` gives).
- `fara-estimare` selects the postings where `SALARY_EXPR` gives no figure.
- The help text of the filter becomes: „Salariul anunțat, altfel cel estimat din grila de salarizare propusă. Net lunar estimat, la gradația 0.”

### 2. Net first, in each place

Find each place that shows a salary figure to a reader (list rows in the three views, the pay meter text, the detail page, the archived page if it shows pay, the feeds, the saved page, the statistics page, the About page). List them in the report.

- List rows and feeds: show the net range, "≈ 3.940–4.150 lei net". Keep the mark that says the figure is an estimate.
- Detail page: the net range is the main figure. The gross range is one smaller line below it („brut: 6.731–7.085 lei / lună, la gradația 0”). Keep the text about the assumptions.
- Detail page, no figure: where the salary block is, show one line: „Fără estimare de salariu. Titlul postului nu corespunde unei funcții din grila de salarizare.” Add „Posturile pe proiecte cu fonduri europene sunt plătite de obicei în afara grilei.” only when the posting has EU or PNRR funding.
- Sort by salary: the order does not change (net is monotonic in gross). Verify it.
- Statistics and About: change the words to net where a figure is shown; where a figure must stay gross (a median of the grid, for example), label it "brut".

### 3. The note on a result list

When a salary filter is active (a net bucket or an old gross bucket), show one note above the results, as a DS inset or notice:

„Filtrul de salariu arată doar anunțurile cu salariu anunțat sau estimat. N anunțuri care se potrivesc cu celelalte filtre nu au o estimare.” followed by a link „Vezi-le” to the same search with `salary_bucket=fara-estimare`.

- N is the count of that link. When N is 0, show only the first sentence.
- When `fara-estimare` is active, the note is: „Aceste anunțuri nu au un salariu anunțat și nu au putut fi estimate din grilă. De obicei titlul postului nu corespunde unei funcții din grila de salarizare.”
- The note is part of the HTMX fragment, so it follows the filter state. It is not in the feeds.

### 4. Landing page and themed page

- Example 1 becomes „Peste 5.000 lei net, în afara sectorului medical” (`salary_bucket=net-peste-5000`, `isced_not[]=09_sanatate_asistenta_sociala`), tag „peste 5.000 lei net”.
- Example 2 becomes „3.000–4.000 lei net, normă parțială, pe proiecte PNRR sau europene” (`salary_bucket=net-3000-4000`, the same other parameters), tag „3.000–4.000 lei net”.
- In "Alte filtre", „Salariu peste 7.000 lei” becomes „Peste 5.000 lei net”.
- Add a starting-point group „După salariu” after „După studii”: the four net buckets with counts (not `fara-estimare`).

### 5. Docs

`docs/backlog.md`: a new open item to extract the stated pay of project posts in the pipeline. `docs/activity-log.md`: one entry with **Done / Hard / Decided** and the measured numbers. `README.md` if it describes the salary filter.

## Tests

- PHP: each net bucket and `fara-estimare` select the right postings on the fixture (add fixture rows at each edge); the five counts add up to the active total; each old gross value selects the same ids as before this change (record the ids on the baseline first); the edge test of the constraint above; the note text and its count for a net bucket, an old bucket, `fara-estimare`, and N = 0; the detail page with a figure and with none; the landing links and counts.
- `tests/fixtures/search_keys.json`: add cases with the new values. The key rule does not change.
- Playwright at 375 and 1280 px: select a net bucket in the filter → the note appears with the count → "Vezi-le" opens the list of postings with no estimate, and its count equals N; a list row shows "lei net"; no horizontal overflow at 320 px on the list with the note.
- Each new check must fail against a named mutation. Restore only the file you mutated.
- Baseline first (every PHP suite and one full Playwright run). Finish with one clean full Playwright run. Report: every PHP suite, the PHP lint, `npm run css`.
- Screenshots (list with a net bucket and the note; list with `fara-estimare`; a detail page with a figure and one with none; the landing page) at 1280 and 375 px in the scratch folder that the prompt names. Look at them.

## Files you may change

`webapp-php/helpers.php`, `webapp-php/query.php` (the `salary_bucket` validation only), `webapp-php/pages/*.php`, `webapp-php/partials/*.php`, `webapp-php/feeds/*.php`, `webapp-php/inc/themes.php`, `webapp-php/static/searches.js` (only if a bucket label lives there), `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`, `webapp-php/tests/**`, `README.md`, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file, another filter, an ADR, or the rates of `net_from_gross()`.

## Done when

- All checks pass, and the report gives each command and its result, the exact counts of the five buckets on the real-data export, what you measured and what you assumed.
- Do not commit. Do not deploy. Opus reviews first.

## Follow-up 1 (2026-11-17, pax) — starting-point groups

The same rules, tests and "Done when" apply.

1. Remove the word "După" from each group label of the starting points on the landing page. The labels, in this order: „Studii”, „Salariu”, „Ocupație”, „Domeniu angajator” (the sector row, was „După angajator”), „Tip instituție” (new), „Alte filtre”.
2. New group „Tip instituție”: each value of the filter "Instituție" (`inst[]`, labels from `EMPLOYER_KIND_LABELS`) with a count above 0, largest first, as a link with its count. Verify the parameter and the values against `build_filters()`.
3. Keep both „Domeniu angajator” and „Tip instituție”. The owner can hide one later.
4. On a themed page, the section title „După studii” becomes „Studii”. Change it in the theme configuration, not in the template.
5. Update the checks that name the old labels, and add checks for the new group (each count equals the result count of its link; a value with 0 is not shown). Each new check must fail against a named mutation.

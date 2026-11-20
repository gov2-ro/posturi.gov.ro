# Brief — Postings with no salary figure (SALARY-UNMATCHED)

Date: 2026-11-20. Author: Opus. Builder: Sonnet. Backlog: SALARY-UNMATCHED (under SALARY-R2). ADR: `docs/decisions/0008-salary-two-baselines.md` (rule 4).

## Why

The salary filter is the main use of the estimate. A posting with no figure drops out of every salary bucket, except „fără salariu estimat”.

Local dry run, 2026-11-20, on the **local copy of 9 Sep 2026** (`estimate-salaries.py --dry-run`): 9,756 postings, 9,750 with an occupation, **1,388 with no figure** from either grid. Of these, 1,254 have no annex in the selector. The top occupations with no figure are common jobs that the grid has:

| Occupation | Postings |
|---|---|
| Medic specialist | 64 |
| Îngrijitor | 58 |
| Brancardier | 38 |
| Infirmier | 38 |
| Consilier școlar | 35 |
| Șofer | 34 |
| Electrician | 29 |
| Manager proiect | 29 |
| Medic | 28 |
| Tehnician asistență socială | 27 |

So most of the gap seems to be a failure to match a grid row, not a gap in the grid. Opus assumes this from the September copy. Step 1 measures it on the VPS data.

## Where the data is

The local Postgres is a copy of 9 Sep 2026. The numbers above come from that copy. **The live data is on the VPS** (`gov2-1`, checkout `/home/pax/g2-dev/posturi.gov.ro`). The Mac has no SSH to it: reach it through the owner's Remote Control session (`posturi-vps`; find it with ListAgents).

- Build and test the code on the Mac, against the local copy.
- Measure on the VPS: ask the VPS session to run the read-only commands (`estimate-salaries.py --dry-run`, the classify script). The VPS session blocks Postgres reads until the owner approves them **in that session**. Approval here does not carry over.
- Each fix to the data (for example, the `grid_selector` of an occupation) is a committed, re-runnable script or a `--flag` of an existing script. It runs on the VPS after a code deploy, then `estimate-salaries.py` runs there. Never edit a VPS row by hand.
- The Mac deploys code only (`./deploy-php.sh --code-only`). The owner runs the deploy. The data reaches the site with the next VPS pipeline run.

## Read first

- `estimate-salaries.py` (`SELECT_POSTINGS`, `build_selector()`, `law_selector()`, `main()`).
- `salary_grid.py` (`selector_to_grid()`, `Grid.estimate()`, the name matcher).
- `normalize-titles.py` and the column `jobs_occupation.grid_selector`: where a selector comes from.
- `ocupatii.py` (`parse_title()`).
- ADR 0008, rule 4. README § Estimated pay.

## Steps

1. **Classify the postings with no figure, by cause, on the VPS data.** Write the script in `tmp/261120/unmatched/` and test it on the local copy. Commit it, then ask the VPS session to run it (read-only). Give each posting one cause, the first that applies:
   - `fara-selector`: the occupation has no `grid_selector`, or the selector has no `anexa` and no `functie`.
   - `fara-rand-functie`: the function name matches no row in either grid.
   - `filtru-gol`: the function matches rows, but a narrowing step (studies, grade, post-type, administrative level, population band, unit type) leaves no row. Name the step.
   - `in-afara-grilei`: the job is not under Legea 153/2017 (EU or PNRR project hire, state company, autonomous authority, for example BNR or ASF). Say how you decided.
   - `altul`: anything else, with a note.
   Report a table: cause × the top 10 occupations, with counts. Add 5 example postings (id, title, employer, selector) for each cause.
2. **Stop and report.** Write `docs/audits/2026-11-20-salary-unmatched.md` with the table, the examples and a list of proposed fixes. Give each fix the number of postings it gains. Opus picks the fixes with the owner.
3. **Fix only after Opus agrees.** The fixes that Opus expects to be allowed:
   - Repair the `grid_selector` of an occupation with no selector, or a wrong one, in the occupation dictionary.
   - Relax one narrowing step when a field is missing (for example, no studies level: use the range over all levels, as the estimator already does for some rows).
   - A test in `webapp/tests/test_salary_grid.py` for each fix.
   Do not re-run the LLM extraction. Do not change a grid file.
4. **Apply and measure on the VPS.** Each fix is a committed script or flag (see § Where the data is). The VPS session runs `estimate-salaries.py --dry-run` before and after the fix. Report the counts: postings with a figure, per `baza`, per `incredere` and per annex. No posting may lose its figure. List each posting whose figure changes by more than 10%.

## Done when

- The audit report holds the cause table, the examples and the measurements.
- The agreed fixes are built, with tests, and the full test suite passes.
- The fixes ran on the VPS, and the report has the VPS before-and-after counts. The local counts alone are not enough.
- The activity log and the backlog are updated.

## Stop and ask

- If a fix changes a grid file, a rule of ADR 0008 or the precedence of figures.
- If a fix changes the figure of a posting that already has one.
- If more than 200 postings fall in `altul`.

# Brief — Military and police pay (SALARY-ANEXA-VI)

Date: 2026-11-20. Author: Opus. Builder: Sonnet. Backlog: SALARY-ANEXA-VI (under SALARY-R2). ADR: `docs/decisions/0008-salary-two-baselines.md`.

## Why

A reader asked on r/RoMunca (2026-11-18): "What is the gross pay of a new border police agent in 2026?" A commenter answered "4000 × 1,49 + 4000 × 0,52 = 8040 lei brut", from the August draft. Our estimator cannot give that answer:

1. Military and police pay has two parts: pay for the post (*soldă de funcție*) and pay for the rank (*soldă de grad*). Our draft grid has only the post part. So it gives 6,109 lei for "agent de poliție debutant", studies PL, and misses about 2,100 lei.
2. The grid of the law in force (`grila-lege153-2026.csv`) has no Anexa VI rows.
3. The full grid label "Funcţii corespunzătoare gradului de agent de poliţie/penitenciare debutant" returns no row. The same label without "debutant" returns the right two rows.

No posting maps to Anexa VI today (local database, 2026-11-20: 0 postings). The police and the army recruit outside posturi.gov.ro. So this work is for `/estimator-salariu/` and the future `/salarii/` hub, not for the list.

## Read first

- `docs/decisions/0008-salary-two-baselines.md`.
- `build-salary-grid.py` (how it reads sheet `VI_Armata`), `salary_grid.py` (`Grid.estimate()`, the name matcher near line 310 and line 618).
- The draft workbook `docs/salarii/perplexity pachet_calculator_salarizare_bugetari/Proiect-COEFICIENTI-1-8-MMFTSS-16.07.2026-1000.xlsx`, sheet `VI_Armata`: rows 10–68 (post pay, already in the grid) and rows 71–95 (rank pay, "2. Coeficienții de salarizare ai soldelor de grad", not in the grid).
- The draft text `docs/salarii/lege/Anexa-VI-Reglementari-specifice-sistemul-de-aparare-ordine-publica-si-securitate-nationala.docx`, Art. 1 (2) and Art. 8.
- The law in force: `docs/salarii/lege-153/legea-153-2017-id298840-2026-11-18.html`, Anexa VI.
- `pages/salary_estimator.php` and the brief `docs/briefs/2026-11-19-salary-estimator-wizard.md`.

## Decisions (Opus, 2026-11-20)

1. **The Anexa VI base is post pay + rank pay.** Draft Art. 8 (4): rank pay = rank coefficient × the reference value. Art. 1 (2): the monthly pay is post pay + rank pay, then bonuses. So `lei_min` / `lei_max` of an Anexa VI row is `ceil((coef_funcție + coef_grad) × VR × gradation)`. The result shows both parts on separate lines.
2. **Map the post to its rank by the rank name.** Each post row is "Funcţii corespunzătoare gradului de X". Find X in the rank table (rows 74–95). A post row for a "debutant" gets the rank of the same name without "debutant" (agent de poliție debutant → "Agent de poliţie", 0,52). Put the map in `salary_grid.py`, in one place. A post row with no rank match gets no rank pay and a warning. Rows 10–12 (heads of institutions) have no rank in the name: give them no rank pay.
3. **Gradation.** Apply the gradation multiplier to both parts, as the draft does for the base pay. If the draft text says otherwise, follow the text and report it.
4. **Bonuses stay out.** Food allowance, equipment, rent, the Art. 10 compensation (up to 25%) and other bonuses are not in the figure. The result says so in one line.
5. **The August draft stays out.** We have no August workbook. Do not compute it from VR 4,000 and the July coefficients (`parametri.json` → `2026-08-20.nota`).

## Steps

1. **Grid.** `build-salary-grid.py` reads rows 71–95 of `VI_Armata` into a new file, `data/salarii/solda-grad-2026-07-17.csv` (columns: `nr_crt`, `grad` (the full label), `grade` (the names split on ";"), `coeficient`). Do not change the rows of `grila-2026-07-17.csv`.
2. **Calculator.** `load_grid("2026-07-17")` loads the rank table. `estimate()` adds the rank pay for an Anexa VI row (decisions 1–3). `SalaryEstimate` gets the two parts (for example `lei_functie`, `lei_grad`). Other annexes do not change: the existing tests must pass with no change.
3. **Matcher bug.** Fix the lookup so that the full label with "debutant" finds its row. Find out why the label fails (the matcher probably reads "debutant" as a professional grade). Fix it only for this case. Do not change the result of any other function. Prove it: run `estimate-salaries.py --dry-run` before and after, and compare the counts.
4. **Estimator page.** On `/estimator-salariu/`, an Anexa VI result shows "Soldă de funcție", "Soldă de grad" and the total, gross and net. Add the line about bonuses (decision 4). Use the DS components and the role tokens.
5. **Law in force: spike only, no build.** Read Anexa VI of the consolidated Legea 153/2017. Answer in the report:
   - Does Anexa VI hold lei values or coefficients for post pay and rank pay? What reference value applies in 2026?
   - Which acts changed military and police pay after 2022 (the equivalent of the factor 1.15 for Anexa VIII)?
   - What is the figure for "agent de poliție", with no seniority, studies PL, post + rank, under the law in force?
   - Is a public figure available to check it (for example, a recruitment notice of MAI or IGPF that states the pay)?
   Stop after the report. Opus decides if the law-in-force rows get built.
6. **Tests.** In `webapp/tests/test_salary_grid.py`: agent de poliție debutant, PL, gradation 0 = `ceil((1.49 + 0.52) × 4100)` = 8,241 lei; one officer row (for example "locotenent" with rank 0,65); one row with no rank (decision 2); the "debutant" label of step 3.

## Where the data is

The local Postgres is a copy of 9 Sep 2026; the live data is on the VPS. Build and test on the Mac. After the code deploy, the owner's VPS session (`posturi-vps`) runs `build-salary-grid.py` and `estimate-salaries.py` there, so that the VPS data gets the new figures. Report the VPS dry-run counts of step 3, not only the local ones.

## Done when

- The tests of step 6 pass, and the whole test suite passes.
- `/estimator-salariu/` shows the two parts and the total for "agent de poliție debutant" (screenshot at 375 px and 1280 px).
- The report `docs/audits/2026-11-20-salary-anexa-vi.md` holds the answers of step 5, the dry-run counts of step 3, and what you assumed.
- The activity log and the backlog are updated.

## Stop and ask

- If the rank names of the post rows do not map one to one to the rank table.
- If a step changes the result of another annex.
- If the law in force needs a new rule in ADR 0008.

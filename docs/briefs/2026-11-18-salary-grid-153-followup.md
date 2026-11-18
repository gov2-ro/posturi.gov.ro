# Brief — Salary grid of the law in force: the local cap, the row review, the „Salarii” section

Date: 2026-11-18. Author: Opus. Builder: Sonnet. Backlog: GRID153-CAP, GRID153-REVIEW, GRID153-SOURCE-TRACK (under SALARY-R2). ADR: `docs/decisions/0008-salary-two-baselines.md`. Follows: `docs/briefs/2026-11-18-salary-grid-153.md` (done, not committed).

## Read first

- The activity-log entry „Salary grid of the law in force” (2026-11-18), and its review.
- `data/salarii/plafon-local.csv`, `data/salarii/grila-lege153-2026.csv` (column `needs_review`), `salary_grid.py`, `estimate-salaries.py`, `uat.py`.
- `tmp/261118/grid153/work/law.txt` (text of the consolidated law). The 13 vice-mayor rows of Anexa IX lit. C start near line 28169.
- `webapp-php/pages/about.php` (the `/despre/` page).

Use `.venv/bin/python`. Do not commit. Do not deploy. Do not edit an ADR.

## Part A — The local cap follows the population (GRID153-CAP)

Today the cap uses 4 population bands, and each band uses its highest vice-mayor coefficient. A small comună therefore gets the cap of a town of 10,000. The law gives one row per type of unit and population:
- comună: up to 3,000; 3,001–5,000; 5,001–10,000; 10,001–20,000; 20,001–50,000;
- oraș: up to 10,000; 10,001–20,000;
- oraș/municipiu: 20,001–50,000; 50,001–100,000; over 100,001;
- municipiu reședință de județ;
- sectors of București;
- the Capital.

1. **The population.** Replace `data/uat-populatie.csv` with `../core-assets/data/geo/uat-populatie.csv`. The two files have the same columns. The core-assets file corrects 12 old values (`core-assets/docs/projects.md`, item 1). Record the core-assets commit in README. Run the tests of `uat.py`.
2. **The table.** `plafon-local.csv` gets one row for each vice-mayor row of Anexa IX lit. C. Each row has: the type of unit, the lower and the upper limit of population, the coefficient, the cap in lei (coefficient × 2,415), and the source row. Add the row for the vice-president of a county council (Anexa IX). Keep the source row, as today.
3. **The match.** For a local post, the cap comes from the UAT of the employer: its type (`tip`, `resedinta_judet`) and its population. Put the rule in `salary_grid.py`. When the UAT is not known, use the widest cap that is possible for the data that we have, and add a warning (today's behaviour).
4. **OUG 53/2024 art. II.** For positions 23–28 of Anexa IX lit. C, the law lets the pay go up to 10% over the cap. The upper end of the range is a legal maximum, so for these positions use the cap × 1.10. Record the act in `sursa`. *(This replaces the earlier decision "ignore it": with the exact position, the rule is cheap and correct.)*
5. **Measure.** Report the change of the median `lei_max` of the local posts, by type of unit. Give 5 examples of comune, with the population, the cap before and the cap after.

## Part B — Review the 552 rows with `needs_review` (GRID153-REVIEW)

The owner wants all of them reviewed. If the work grows too long, review first the rows that the most recent postings use, and report what is left.

For each row:
- Compare the row with the table of the law (`law.txt` or the HTML in `docs/salarii/lege-153/`).
- Check the function, the grade or step, the study level, the lei value and the factor of the annex.
- Write the result to `tmp/261118/grid153/review.csv` with these columns: `row id`, `ok` / `fixed` / `reject`, `note`.
- Fix the parser, not the CSV, when the cause is a parse defect. Then rebuild the grid.
- Set `needs_review = 0` only for a row that you checked.
- For Anexele III, IV and V, the factor 1.15 (OUG 115/2023 + OUG 53/2024 art. I) is an assumption. Look in the notes of those annexes in `law.txt` for an act that changes the factor. If you find one, stop and report it.

Report: the counts of `ok`, `fixed` and `reject`, the parser fixes, and any factor that differs.

## Part C — The section „Salarii” on `/despre/` (GRID153-SOURCE-TRACK)

The HTML files of the law stay local (owner, 2026-11-18). The page names the sources.

Add the section „Salarii” to `pages/about.php`. Add an entry in the table of contents if the page has one. The section has these parts, in Romanian:
- **What the site shows:** the salary that the posting states. When the posting has none, the site shows an estimate of the gross base salary under Legea 153/2017, at gradation 0. The net figure is computed from it.
- **The estimate is not an official calculation.** Sporuri, gradation and the meal allowance are not included.
- **In local administration** the council sets the salary, up to a legal cap. So the site shows a range.
- **The draft law:** the site also shows the figure under the draft law of July 2026. That law is not adopted.
- **The sources,** as links: Legea 153/2017 (forma consolidată, legislatie.just.ro, id 298840), OUG 19/2024 (id 279777), OUG 115/2023, OUG 53/2024, Legea 141/2025 and HG 146/2026. Add the date on which the site read the law.

Write short sentences, in the active voice, with one term for each concept (project `CLAUDE.md`). Use DS components and role tokens only. Opus reviews the Romanian text before it is accepted. Add a test of the section in the PHP test of the page, if that test exists.

## Files

You can change:
- `salary_grid.py`, `estimate-salaries.py`, `build-salary-grid.py`, `uat.py` (only if Part A needs it);
- `data/salarii/plafon-local.csv`, `data/salarii/grila-lege153-2026*.csv`, `data/uat-populatie.csv`;
- `webapp/tests/test_salary_grid.py`, and the tests of `uat.py`;
- `webapp-php/pages/about.php` and its PHP test;
- README § Estimated pay, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file.

## Verify

- Run `test_salary_grid.py`, the tests of `uat.py` and the PHP tests of the page.
- Re-run `estimate-salaries.py --clear` against `posturi_dev`.
- Export to `tmp/261118/grid153/posturi-after2.sqlite`.
- Report the bucket counts for the 1,822 active postings (active = `coalesce(apply_deadline, expires_at) >= '2026-09-09'`), next to the counts of the first grid run.

Add the activity-log entry. Mark GRID153-CAP, GRID153-REVIEW and GRID153-SOURCE-TRACK in the backlog.

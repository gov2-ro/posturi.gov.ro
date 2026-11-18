# Brief — Spike S1: pay under the law in force (Legea 153/2017)

Date: 2026-11-18. Author: Opus. Builder: Sonnet. ADR: `docs/decisions/0008-salary-two-baselines.md` (proposed). Plan: salary estimator, round 2.

## Goal

Find out how to compute the pay of a public post **today**, under Legea 153/2017. Measure how close each method gets to the pay that institutions actually publish. Recommend one method. This is a spike: write a report, not production code.

## Background

- Today the site estimates pay from the July 2026 draft (`salary_grid.py`, `data/salarii/grila-2026-07-17.csv`). The draft is not adopted.
- The annexes of Legea 153/2017 give the target values for 2022. Later ordinances froze the pay at the „salariul de bază aflat în plată” and gave partial increases. In local administration, the council (HCL) sets the pay up to a maximum.
- Read first: `salary_grid.py` (the selector vocabulary, `estimate()`), `estimate-salaries.py` (`EMPLOYER_TO_LEVEL`, how a posting gets its selector), `data/salarii/parametri.json`, and README § Estimated pay.

## Steps

1. **The functions.** From `webapp-php/posturi.sqlite` (the active export), list the 15 grid functions with the most active postings. Cover at least: one function from Anexa VIII funcționari publici (execution and management), one from Anexa VIII personal contractual, one from Anexa II (health), one from Anexa I (education), and one local post in a comună. Record the SQL that you used.
2. **The law text.** Use the consolidated text on legislatie.just.ro (Legea 153/2017, forma consolidată). For each function, record the annex, the table, the row and the value (lei or coefficient), per gradation if the table gives one.
3. **The 2026 rules.** Find the acts that set the pay in 2026: the freeze ordinances, the increases in 2023–2025, and any increase in 2026. Give each act with its number, its date, its article and its link. Explain in 5–10 lines how a reader gets from the annex value to the pay in 2026. Say where the rule is not clear.
4. **Two methods per function.** Compute (a) the annex value and (b) the value after the 2026 rules, at gradation 0 and at gradation 5.
5. **Ground truth.** Find 10 transparency lists (Art. 33) of 30 September 2026 or 31 March 2026, from institutions that have active postings: at least 3 primării of different sizes, 1 county council, 1 ministry or national agency, 1 hospital, 1 school. Record the URL and the pay that each list gives for the functions of step 1.
6. **The gap.** For each function and each list, give the gap of method (a) and method (b) in %. Separate local administration from the rest.
7. **Sporuri.** List the sporuri of 153/2017 that are common in these lists (for example: condiții de muncă, CFP, fonduri europene, indemnizația de hrană). For each one, give the article, the maximum and whether it is part of the base pay.

## Report

Write `docs/audits/2026-11-18-salary-s1-current-law.md`:

- The table of step 6, with sources.
- The recommended method, with the measured gap. Say what you measured and what you assumed.
- For local administration: does "maxim legal" (ADR 0008, rule 3) fit the lists, or do most councils pay the maximum?
- What `build-salary-grid.py` needs as input for this method: a file to download, a table to type by hand, or a workbook.
- The open questions for the owner.

Add an entry to `docs/activity-log.md` (Done / Hard / Decided), and add the follow-up items to `docs/backlog.md`.

## Constraints

- Change no code and no file in `data/`. Save the downloads in `tmp/261118/s1/`. Do not commit that folder.
- Stop and ask if a step would change a spec, an ADR or a file that this brief does not name.
- Use only public sources. Use `random.uniform(0.5, 1.1)` seconds between requests to one site.
- Time box: one session. If the 2026 rules are not clear after step 3, report what you found and stop.

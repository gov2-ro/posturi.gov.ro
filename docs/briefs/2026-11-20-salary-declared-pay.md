# Brief — Pay from the asset declarations (SALARY-DECLARATII, ADR 0008 rule 9)

Date: 2026-11-20. Author: Opus. Builder: Sonnet. Backlog: SALARY-DECLARATII. ADR: `docs/decisions/0008-salary-two-baselines.md`, rule 9 (accepted by the owner, 2026-11-20).

## Why

For administration, police and MAI posts, the grid gives the base pay of the law, or (in local administration) a wide range up to the council cap. The asset declarations give the real net pay of the same post at the same employer. The spike measured the method; this brief builds it.

Read the numbers before you start: `docs/audits/2026-11-20-salary-declaratii-kibana.md`. Do not change the method. The owner accepted it.

## What is fixed (do not change without asking)

- The rule: ADR 0008, rule 9. The families, n ≥ 5, the factor from INS section O, `sal_min` = p25 and `sal_max` = p75, the precedence step (a2).
- The selection of the pay line: `ops/integritate-select.py`.
- The explainer copy: `docs/specs/2026-11-20-salary-declared-explainer.md`.
- The dump scripts `ops/integritate-dump.{py,js}`. The dump stays in `data/integritate/`, out of git.

## Where the data is

- The dump and the selection are on the **Mac**, in `data/integritate/` (out of git): `integritate-salarii-raw-20261120T1229.csv.gz` (raw) and `selected.csv.gz` (one pay line per declaration). The VPS has neither, and must not get them.
- The group table that you build goes in git: `data/salarii/declaratii-grupuri.csv`. That is the only file from the declarations that leaves the Mac.
- The postings: the local Postgres is a copy of 9 Sep 2026. The live data is on the VPS. Measure the match on both: the VPS through the owner's Remote Control session (`posturi-vps`; find it with ListAgents), read-only commands only. The VPS session blocks Postgres reads until the owner approves them **in that session**.
- The INS series: `../tempo-ins-dump/data/corpus/parquet/FOM106D.parquet` and `FOM106G.parquet`, read with `duckdb`, as `ops/build-ins-context.py` does.

## Read first

- ADR 0008 (all rules; rule 9 in full).
- The audit note: the sections „One pay line per declaration”, „Panel”, „The factor from 2023 to 2026”, „Net or gross”.
- `estimate-salaries.py` (`SELECT_POSTINGS`, `law_selector()`, `assemble()`, `main()`).
- `salary_grid.py` (`SalaryEstimate.as_dict()`, `norm()`).
- `ops/integritate-select.py`, `ops/integritate-panel.py` (`family()`, the backtest).
- `ops/build-ins-context.py` (how the project reads TEMPO).
- `webapp-php/helpers.php`: `net_from_gross()`, the inverse search below it, `SALARY_EXPR`.
- `webapp-php/pages/detail.php`: the salary block and its disclosure „Cum a fost calculat”.
- `webapp/tests/test_salary_grid.py` and `test_salary_facet_sql.py`: the style of the salary tests.

## Steps

1. **Net and gross in Python.** Port `net_from_gross()` from `webapp-php/helpers.php` to Python, with the same constants (minimum wage 4,325 lei from 2026-07-01), and add `gross_for_net(net)`: the smallest gross, in whole lei, for which `net_from_gross()` gives at least `net`. Put both in one place that `estimate-salaries.py` can import. Add a test that checks Python against PHP for at least 200 gross values from 3,000 to 30,000 lei (call `php -r`), and that `net_from_gross(gross_for_net(n)) >= n`.

2. **The factor.** Write the SQL of the audit (section „Source 1”) into the build script of step 3: the mean of the 2026 months of FOM106G, section P (Rev.3) ÷ the mean of the 2023 months of FOM106D, section O (Rev.2). Print it with its months. Expect 1.150. If you get another value, stop and report: INS may have published new months, and the owner decides.

3. **The group table.** Write `ops/build-declaratii-grupuri.py`. It reads `data/integritate/selected.csv.gz` and writes `data/salarii/declaratii-grupuri.csv`.
   - Rows: `match` = `inst`, `an` = 2024, yearly amount ≥ 12,000 lei, one row per `person` (the latest `data_completare_iso`). The same rules as the backtest of `ops/integritate-panel.py`.
   - Families: `family()` of `ops/integritate-panel.py`. Keep `family()` in one place: move it to a module that both scripts import. Keep only: `admin-local`, `admin-judetean`, `admin-central`, `politie-locala`, `mai-mapn`.
   - Group: (`institutie_id`, `functie` lower-cased). Keep the groups with n ≥ 5.
   - Columns: `institutie_id, institutie, judet, functie, familie, n, p25_net_2023, mediana_net_2023, p75_net_2023, an_venit, factor, factor_sursa, eroare_tipica`. Monthly lei, rounded to whole lei. `an_venit` = 2023. `eroare_tipica` = the backtest median error of the family (from the audit table, out of sample: local 5.2, central 4.8, county 6.7, police 6.0, MAI 6.1).
   - **Privacy check in the script:** fail when a row has n < 5. Write no person key, no `aid`, no amount of a single declaration.
   - Expected size (spike, before your checks): 6,201 groups at 2,108 institutions. Report your count.

4. **Match a posting to a group.** In `estimate-salaries.py`:
   - Employer: map the posting's employer name and county (`employer_name`, `judet_name`) to one `institutie_id`. Normalise as `ops/integritate-select.py` does („PRIMARIA COMUNEI X” = „COMUNA X”, „PRIMARIA MUNICIPIULUI X” = „MUNICIPIUL X”). The county part of `institutie_id` must equal the posting's county. **Exact match after normalisation only. No fuzzy match in this brief.**
   - Title: map the posting title to one `functie` of that employer's groups. Use `ocupatii.parse_title()` for the grade. The declarations write titles such as „Consilier superior”, „Inspector superior”, „Referent superior”, „Șef serviciu”, „Polițist local”. A posting title is often „Consilier, clasa I, grad profesional superior”. Map the base word and the grade; nothing else.
   - When more than one group fits, or none, the posting gets no declared figure.
   - Report: postings with an employer match, postings with a group match, by family; and 30 random matches (posting title, employer → `institutie_id`, `functie`) for Opus to review. The spike found about 10 % of postings with an exact employer match before the title match (9 Sep copy). Measure on the local copy and on the VPS.

5. **The estimate.** In `assemble()`, add step (a2) of rule 4: when a posting matches a group, the declared figure takes the top level.
   - `baza`: `"declaratii"`. `lei_min` = `gross_for_net(p25 × factor)`, `lei_max` = `gross_for_net(p75 × factor)`: the top level stays gross, as for the other baselines, so `sal_min`, `sal_max`, `SALARY_EXPR` and the filter need no change.
   - Add the key `declaratii`: `{n, an_venit, factor, factor_sursa, mediana_net, p25_net, p75_net, institutie_id, functie, familie, eroare_tipica}`, the net values already × factor and rounded to 50 lei.
   - `incredere`: `"interval"`. `disclaimer`: „Estimare după declarațiile de avere din 2023, aduse la nivelul din 2026 cu creșterea salariilor din administrația publică (INS). Nu este un calcul salarial oficial.”
   - Keep the law figure under the key `lege_in_vigoare`, and the draft under `proiect`, as today.
   - Print counts per `baza` before and after. Rule 7 says the filter keys stay the same; record in the activity log how many postings change their `salary_bucket`.

6. **The pages.** Follow `docs/specs/2026-11-20-salary-declared-explainer.md`.
   - List row: „după declarații de avere”.
   - Detail page: the median as the main net figure, the band p25–p75, the gross line, no „la gradația 0”. The explainer goes in the existing disclosure „Cum a fost calculat ▾”.
   - „Metoda pe larg”: a section with an anchor on `/estimator-salariu/`.
   - Use the role tokens and the DS components only (CLAUDE.md, § Design system). Add a Playwright check for one posting with `baza: "declaratii"`: the figure, the label and the four steps of the explainer.

7. **Docs.** README § Estimated pay: rule 9 in a few lines. Backlog: SALARY-DECLARATII. Activity log: one entry with the measured numbers.

## Out of scope (separate items)

- Wider groups (for example county + type of unit + title). They need their own backtest. If you have time at the end, measure it with the backtest of `ops/integritate-panel.py` and report the numbers. Do not ship it.
- Health and education (they wait for a legal ratio per title).
- A fuzzy employer match.

## Stop and ask when

- a step would change ADR 0008, the families, n ≥ 5, the factor, or the explainer copy;
- the factor of step 2 is not 1.150;
- the match of step 4 would need a fuzzy rule to reach a useful rate;
- a step would put a name, a person key or a single declaration on the site or in git;
- a change touches a file that this brief does not name.

## Deploy

Commit each step on its own before any deploy: other sessions work in this checkout. The owner runs `./deploy-php.sh --code-only`. On the VPS, after a pull, the owner's session runs `estimate-salaries.py --dry-run` first, then the real run after the owner approves. The data reaches the site with the next VPS pipeline run.

## Report

- The factor and its months (step 2).
- The group count and the size of the CSV (step 3).
- The match rates, local and VPS, by family, and the 30 samples (step 4).
- Counts per `baza`, and the postings that change `salary_bucket` (step 5).
- What you measured and what you assumed.

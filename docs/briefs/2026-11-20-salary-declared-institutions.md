# Brief — Declared pay per institution (SALARY-DECLARATII-PAGE, ADR 0008 rule 9)

Date: 2026-11-20. Author: Opus. Builder: Sonnet. Backlog: SALARY-DECLARATII-PAGE (and SALARY-DECLARATII-FEED, step 0). ADR: `docs/decisions/0008-salary-two-baselines.md`, rule 9, bullet „Pages per institution” (owner, 2026-11-20).

## Why

The declared-pay groups match only 5 postings (9 Sep copy), because the people hired through postings seldom file asset declarations (audit note, „Coverage spikes”). But the groups themselves are strong: 6,201 groups at 2,108 institutions, real net pay by employer and job title. The owner chose to show them per institution, independent of the postings:

1. a section on the employer page `/angajator/{slug}`, when the employer matches an institution;
2. a page per institution, `/salarii-declarate/{slug}/`, for all 2,108 institutions;
3. an index, `/salarii-declarate/`, with search and a county filter.

On the 9 Sep copy, 629 of 5,871 employers with postings (11 %) match an institution. About 1,480 institutions have no employer page; they get only the page of point 2.

## What is fixed (do not change without asking)

- The data: `data/salarii/declaratii-grupuri.csv`, as built by `ops/build-declaratii-grupuri.py`. The figures: median, p25, p75 × `factor`, net per month, rounded to 50 lei (as `declaratii.estimate_dict()` does).
- The employer match: `declaratii.Groups.employer()` (exact after normalisation).
- The explainer: `docs/specs/2026-11-20-salary-declared-explainer.md`, and the method section on `/estimator-salariu/#metoda-declaratii`.
- **No comparison** with other employers (for example „X % peste mediana comunelor din județ”). The owner did not choose it.
- The navigation and the footer do not change (step 6 says where to link from).

## Read first

- ADR 0008, rule 9 in full.
- The audit note: „Coverage spikes” (why contract staff are not in the data).
- The spec of the explainer.
- `declaratii.py`, `ops/build-declaratii-grupuri.py`, `data/salarii/declaratii-grupuri.csv` (columns).
- `export-to-sqlite.py`: how `employers` and `job_postings` are written.
- `webapp-php/index.php` (routes), `webapp-php/pages/employer.php`, `webapp-php/pages/employers.php` (a list page with search), `webapp-php/pages/sitemap.php`.
- The DS: `~/devbox/gov2/gov2ro-design-system/COMPONENTS.md`, sections „Data table” (`.g2-table`, `.g2-table-stacked`, `.g2-cell-num`), „Pagination”, „Fields”, „Breadcrumbs”, „Panels”.
- `webapp-php/feeds/_feed.php`, lines 231–255 (step 0).

## Steps

0. **The feeds (SALARY-DECLARATII-FEED).** `webapp-php/feeds/_feed.php` prints „la gradația 0” for every estimate. For `baza` = `declaratii`, print the net median and „după declarațiile de avere”, as the list row does, and no „la gradația 0”. Add a case to the feed test. This step blocks the deploy of the previous build, so commit it first.

1. **Export.** In `export-to-sqlite.py`, read `data/salarii/declaratii-grupuri.csv` and write two tables:
   - `declared_institutions`: `slug` (unique), `institutie_id`, `name` (the column `institutie`), `judet`, `judet_slug` (the site's county slug; use `webapp/apps/jobs/judete.py` to map the county), `n_total`, `groups`, `employer_id` (nullable).
   - `declared_groups`: `institution_slug`, `functie`, `familie`, `n`, `mediana_net`, `p25_net`, `p75_net` (2026 level, net, rounded to 50 lei), `mediana_net_2023`, `factor`, `factor_sursa`, `eroare_tipica`.
   - `employer_id`: for each employer, take the county of most of its postings, then `Groups.employer(name, county)`. One employer per institution at most; when two employers match one institution, keep the one with more postings and report the other.
   - The slug: from the name and the county, lower-case ASCII with hyphens, unique. It must not change between runs for the same `institutie_id`.
   - Report: institutions, groups, employers with a match. Expect 2,108 / 6,201 / about 629 on the 9 Sep copy.

2. **One partial for the table.** A PHP partial that takes an institution slug and renders:
   - the heading „Cât câștigă angajații care depun declarații de avere”;
   - **first, before the table:** „Doar funcționarii și conducerea, care depun declarații de avere. Personalul contractual (de exemplu șoferi, muncitori, asistenți) nu apare aici.”;
   - a `.g2-table.g2-table-stacked` with the columns „Funcția”, „Declaranți” (`g2-cell-num`), „Net pe lună, tipic” (`g2-cell-num`, the median), „Cei mai mulți între” (p25–p75). Sort by `n`, largest first;
   - the line „Venitul din 2023, adus la nivelul din 2026 cu creșterea salariilor din administrația publică (INS, +15 %). Eroare tipică: {eroare} %.” Use the largest `eroare_tipica` of the institution's groups;
   - a `<details>` „Cum am calculat ▾” with the four steps of the explainer, written for the institution (step 1 names the employer and says „la această instituție”, not one title), and a link to `/estimator-salariu/#metoda-declaratii`.
   - Use the role tokens only (CLAUDE.md, palette rule). No new `.g2-` class.

3. **The page per institution.** Route `/salarii-declarate/{slug}/`. Breadcrumbs: „Salarii declarate” → the institution. Heading: the institution name, then the county. The partial of step 2. When `employer_id` is set: a link „Posturi la acest angajator →” to `/angajator/{slug}`. 404 for an unknown slug, as `employer.php` does.

4. **The section on the employer page.** In `employer.php`, when the employer has an institution: the partial of step 2, and a link to the page of step 3. Place it after the counts and before the list of postings. No section when there is no match.

5. **The index.** Route `/salarii-declarate/`.
   - An intro of two or three short sentences: what the page shows, that the data comes from the asset declarations of 2023 brought to 2026, and that contract staff are not included. Link to the method section.
   - A search field (institution name) and a county filter, as GET parameters. Follow `employers.php` and the DS „Fields”.
   - A list of institutions: name, county, number of declarants, number of job titles. 50 per page, with the DS „Pagination”. Sort by name.
   - An empty search says so in one sentence, with a link to clear the filters.

6. **Links and sitemap.** Add the pages of step 3 and the index to `sitemap.php`. Link to the index from the method section on `/estimator-salariu/`, and from the section of step 4. Do not change the navigation or the footer.

7. **Tests.** PHP: the export tables on the fixture (counts, unique slugs, no group with n < 5), the partial, both routes, the 404. Playwright at 320, 375 and 1280 px: the index (search and county filter), one institution page, one employer page with the section, the stacked table at 320 px with no horizontal scroll. Run `ds.spec.js` too.

8. **Docs.** README (a few lines under § Estimated pay), backlog (SALARY-DECLARATII-PAGE and -FEED), activity log with the measured numbers.

## Stop and ask when

- a step would change the figures, the n ≥ 5 rule, the employer match or the explainer copy;
- you want to add the comparison with other employers;
- a step would change the navigation, the footer or a `.g2-` class;
- a page would show a name, a person key or a single declaration;
- a change touches a file that this brief does not name.

## Deploy

Commit each step on its own before any deploy: other sessions work in this checkout. Do not push or deploy. The owner deploys the code (`./deploy-php.sh --code-only`). The data reaches the site with the next VPS export, because `data/salarii/declaratii-grupuri.csv` is in git.

## Report

- The counts of step 1 (institutions, groups, employers with a match, any employer that lost a tie).
- Screenshots or Playwright results of the index, one institution page and one employer page, at 320 and 1280 px.
- Test results, with failures quoted. Known failure not caused by this work: `alerts.spec.js:54` (TEST-ALERTS-PRIVACY-LINK).
- What you measured and what you assumed.

# Brief — Salary estimator page (UX-SALARY-ESTIMATOR)

Date: 2026-11-18. Author: Opus. Builder: Sonnet. Backlog: UX-SALARY-ESTIMATOR.

## Goal

Each posting already has an estimated pay (`estimate-salaries.py`, `salary_grid.py`). A reader
cannot ask the general question: "What does function X, grade Y, with gradation Z pay?" Build
a page `/estimator-salariu/` that answers it, under Legea 153/2017 (in force) and under the
draft law of July 2026 („după noua lege (proiect)”).

Owner decisions, 2026-11-18: a standalone page; the law and the draft both; no sporuri in v1.
ADR 0008 rule 2 already allows the draft figure outside the list, the sort and the filter.

## Decisions (Opus)

1. **Python computes, PHP looks up.** `salary_grid.py` stays the only place with the pay
   formula (ADR 0008). Do not write the formula again in PHP. The export precomputes the pay of
   each grid row at each gradation 0–5. PHP takes the min and max of the rows that the reader
   selects, adds the local cap, and converts gross to net with `net_from_gross()`.
2. **One formula, two callers.** Move the inner `pay()` of `Grid._estimate_law()` into a public
   method, for example `Grid.row_pay(row, gradation, as_of=None) -> tuple[int, int]` (lei_min,
   lei_max). Add the draft case: `ceil(coef × valoare_referinta × multiplier)`, for coef_min and
   coef_max, as `Grid.estimate()` does. `_estimate_law()` and `estimate()` call the new method.
   Behaviour must not change: run the existing salary tests, and re-run `estimate-salaries.py`
   in dry-run mode (or on a sample) and show that the figures are identical.
3. **New export tables** (`export-to-sqlite.py`; read the grids with `load_grid()`):
   - `salary_grid_rows` — one row per grid row of both versions: `version`, `ref` (`GridRow.ref`),
     `anexa`, `sectiune`, `functie`, `sinonime`, `regim`, `tip_post`, `nivel_administrativ`,
     `banda_populatie`, `grad_treapta`, `studii`, `tip_unitate`, `act_sursa`, `needs_review`.
   - `salary_grid_pay` — `version`, `ref`, `gradatie` (0–5), `lei_min`, `lei_max`, from `row_pay()`.
     The minimum-wage floor applies on the export date (law only, as today).
   - `salary_grid_link` — `law_ref`, `draft_ref`: for each draft row, the law rows that
     `selector_for_law()` + `Grid.select()` give. This pairs the two figures. Report the
     coverage: how many law rows have at least one draft row, and the reverse.
   - `salary_cap` — the rows of `plafon-local.csv`: `tip_unitate`, `populatie_min`,
     `populatie_max`, `plafon_maxim_lei`.
   - The parameters that the page names: valoare de referință of each version, the minimum wage
     and its date, the gradation multipliers and their seniority ranges, the version labels and
     `status_juridic` (from `parametri.json`). Use an existing meta table if the export has one.
   The existing tables and columns do not change. An older export has no new tables: the page
   then says „Estimatorul nu este disponibil acum.” and returns HTTP 200 (`tests/compat_test.php`).
4. **The page flow, GET only, works without JavaScript.** Every state has a shareable URL.
   - Step 1 — search: a text field `q`. The server lists the matching functions (on `functie`
     and `sinonime`, accent-insensitive), grouped by anexă and section, from both grids. Each
     result is a link to step 2. Grid sizes for scale: law 1,913 rows / 555 names; draft 2,189
     rows / 731 names. Do not send all names to the browser.
   - Step 2 — variants: show only the choices that exist for the chosen function: grad/treaptă,
     studii, regim, nivel administrativ, tip unitate (hospitals). A choice with one value is
     text, not a control. Gradation: a select 0–5 with the seniority ranges („3–5 ani”).
   - Local administration (Anexa VIII, `nivel_administrativ` local): the reader picks the type
     and the population of the locality from `salary_cap` (14 rows). The figure is a range: the
     lower end is the value of the territorial row, the upper end is the cap — as
     `Grid._estimate_law()` does. Without a choice, use the widest cap that is not the Capital
     and say so.
   - Direct entry: `?rand=<ref>&g=<0-5>` opens step 2 with that law row selected. The detail
     page uses it (decision 7).
5. **The result: two blocks, the law first.**
   - „Legea 153/2017 (în vigoare)”: net first, gross second (as on the detail page), or the
     range for a local post. The source: `act_sursa` and the anexă.
   - „După noua lege (proiect, neadoptat)”: the same, from the linked draft rows. With no
     linked row: „Proiectul nu are o funcție corespunzătoare.” With a draft row and no law row
     (anexe VI, VII, IX are only in the draft): „Nu avem grila legii pentru această funcție.”
   - The disclaimer of `/despre/`: no sporuri, no meal allowance, the employer sets the real pay,
     not an official calculation. One line: „Sporurile nu sunt incluse.”
   - A link „Vezi posturile deschise pentru această funcție” only if the list can filter by it
     exactly. If no existing filter fits, leave the link out and say so in the report.
6. **Net pay:** `net_from_gross()` and `net_label()` from `helpers.php`. Same assumptions as the
   site: no dependants. Say it on the page.
7. **Entry points.** Footer, group „Posturi” (`inc/footer.php`), next to „Pot aplica?”. Home,
   tiles „Instrumente” (`pages/home.php`). `pages/llms.php`, `pages/sitemap.php`. Not the
   masthead (comment in `inc/header.php`). The detail page salary box (`pages/detail.php`, the
   „Salariu estimat” box): a link „Calculează cu altă gradație”, built from the first law row in
   `sal_json` → `/estimator-salariu/?rand=<ref>&g=0`. Show it only when the posting has a law
   estimate.
8. **`/despre/`**, section `salarii`: change only the sentence „Site-ul calculează și cifra după
   proiect, dar nu o afișează încă.” to say that the estimator page shows it, with a link. Keep
   the owner's other text as it is.
   The diagram (`docs/img/salariu-estimare.svg`, copy in `webapp-php/static/img/`) changes in two
   boxes: „După proiect: calculată, dar încă neafișată” → the estimator shows it; „Ce vede
   cititorul” gets „și estimatorul de salariu”. Edit the text only, then run
   `python3 ~/.claude/references/svg-diagram-tool.py verifica <svg>` and `randeaza <svg> --iesire
   docs/img/salariu-estimare.png`, open the PNG, and copy the SVG to the site folder. If the text
   does not fit, stop and ask: the layout belongs to the diagram-designer agent.
9. **Design.** gov2 DS components first (read `COMPONENTS.md` in the DS). Role tokens only; no raw
   Tailwind palette classes, no hex literals. Rules in `@layer app`. Copy the page shape of
   `pages/eligibility_tool.php`. The two result blocks sit side by side from `lg`, stacked below.
   Romanian copy: short sentences, active voice, one term per concept („salariu de bază brut”,
   „salariu net”, „gradație”).

## Files

You can change: `salary_grid.py` (decision 2 only), `export-to-sqlite.py` (new tables only),
`webapp-php/index.php` (one route, before the theme branch; check `inc/themes.php` for a slug
clash), `webapp-php/pages/salary_estimator.php` (new), `webapp-php/helpers.php` (new functions
only), `webapp-php/pages/detail.php` (the link), `webapp-php/pages/about.php` (decision 8),
`webapp-php/inc/footer.php`, `webapp-php/pages/home.php` (tile), `webapp-php/pages/llms.php`,
`webapp-php/pages/sitemap.php`, `webapp-php/assets/app.css` + rebuilt `webapp-php/static/app.css`,
the fixture builder in `webapp-php/tests/fixtures/`, the tests below, `README.md` (export
tables and the page, one short paragraph each), `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change: ADR 0008, an existing export column, `estimate-salaries.py`,
a DS file (`webapp-php/static/gov2/`), a `.g2-` class or a token name, or any other file.

## Tests

- Python: the existing salary tests pass; a new test that `row_pay()` at gradation 0 gives the
  figures that `estimate()` gives for 5 sample selectors (central exact, central range, local,
  hospital `tip_unitate`, management row).
- PHP, new `tests/salary_estimator_test.php`: search finds „Consilier” and „asistent medical”
  (accent-insensitive); a central row gives the exported lei and the net of `net_from_gross()`;
  a local row gives the range up to the chosen cap; a draft-only row gives the law message;
  `?rand=` opens step 2; gradation 3 is higher than gradation 0 (except a management row).
  **Parity:** for each fixture posting with a law estimate, the page at `?rand=<first ref>&g=0`
  (same selector choices) gives the posting's `sal_min`.
- Update: `tests/request_test.php` (route), `tests/ds_test.php` (`$PAGES`), `tests/llms_test.php`,
  `tests/home_test.php` (footer array, tile), `tests/compat_test.php` (older export → message,
  HTTP 200).
- Playwright, new `tests/browser/salary_estimator.spec.js` on the salary fixture server
  (port 8825), modelled on `eligibility_tool.spec.js`: search → pick → change the gradation →
  the figures change; the link from a detail page opens the right row; no horizontal overflow
  at 320, 375 and 1280 px; no page errors.

## Report

What you measured (link coverage, page weight of step 1 for a broad query, test counts), what
you assumed, and screenshots of a central and a local result at 375 and 1280 px.

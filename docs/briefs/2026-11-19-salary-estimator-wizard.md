# Brief — Estimator wizard and INS context (UX-SALARY-WIZARD, SALARY-INS-LINE)

Date: 2026-11-19. Author: Opus. Builder: Sonnet. Backlog: UX-SALARY-WIZARD, SALARY-INS-LINE.
Builds on: `docs/briefs/2026-11-18-salary-estimator.md` (the page `/estimator-salariu/`, done).

## Goal

The estimator starts with a search over the function names of the grid („Consilier, expert,
inspector”). A reader does not know these names. Two changes:

1. **A wizard** (owner decision, 2026-11-19): domain → studies → function → variants → result.
   A search by the **job title from postings** stays at the top as a shortcut.
2. **INS context** (owner decision, 2026-11-19; ADR 0008 rule 8 accepted): the result shows one
   line with the average net earnings from INS for the domain, with the period and the source.
   It is context, never the estimate (ADR 0008 rule 5).

## Part A — The wizard

### Decisions (Opus)

1. **Steps, one URL per state, GET only, no JavaScript needed** (as today):
   - **1 Domeniu** — one choice per annex. Law annexes in the grid: I Învățământ, II Sănătate și
     asistență socială, III Cultură, IV Diplomație, V Justiție, VIII Administrație și alte funcții
     comune. Annexes only in the draft (VI, VII, IX): show them after the others, labelled „doar în
     proiect”. Take the official annex names from the law text (`docs/salarii/`) and the draft
     workbook; the names above are Opus's short labels — check them, and keep them short.
   - **2 Studii** — only the levels that exist in the chosen domain: Superioare (S), Superioare de
     scurtă durată (SSD), Postliceale (PL), Medii (M), Generale (G). Grid values `PL/M`, `M/G`,
     `M.G` belong to each level they name. Rows with no `studii` (85 law rows) appear under every
     level. Show the count of functions next to each level.
   - **3 Funcția** — the list of functions for domain + studies, grouped by `tip_post`
     (execuție, conducere, other). If the list has more than 40 items, add a text filter on the
     same page (a GET field). Each item is a link to step 4.
   - **4 Variante** — the step 2 of today (grade or step, regime, level, locality, hospital type,
     gradation). Keep the code and the URLs `?rand=<ref>&g=<n>` working: the posting pages link
     to them.
   - **Result** — as today.
   - A breadcrumb shows the choices made („Sănătate › Superioare › Asistent medical”); each part
     is a link back to that step.
2. **Title shortcut.** One search field above the wizard: „Caută după titlul postului”. It
   searches (a) the job titles of postings that have a law estimate and (b) the grid names, as
   today. A title result opens step 4 with its grid row, and its grade when the title has one.
   - New export table `salary_title` (`export-to-sqlite.py`): normalised title → first law `ref`
     of the posting's estimate, the grade from the title if any, and the number of postings.
     Build it from the postings in the export (all statuses). Keep titles with at least 2
     postings, or 1 if the title is not a near-duplicate of another. Report the row count.
   - Show the title results first, then the grid names. Show at most 30 of each.
3. **The domain and studies are filters, not facts about the reader.** Do not store them, and do
   not use them anywhere else.
4. **Small screens.** One step per screen below `lg`. The choices are large radio-style links
   (whole row clickable, at least 44 px high). Use DS components (read `COMPONENTS.md`).

## Part B — INS context line (SALARY-INS-LINE)

### Decisions (Opus)

1. **Source.** INS TEMPO, read from `../tempo-ins-dump` (https://ins.gov2.ro) or its MCP server
   `ins.gov2.ro`. Do not copy TEMPO files into this project (`core-assets/docs/projects.md`;
   ADR 0008 rule 8). Do not edit `tempo-ins-dump`; report a defect to its owner.
   - Candidate matrices: FOM106E (average net earnings by CAEN activity and county), FOM106G,
     FOM121A. **FOM121A is under `data/quarantine/` in `tempo-ins-dump`: do not use it** without
     asking Opus. First confirm what each matrix holds (dimensions, unit, last period).
2. **Script.** `ops/build-ins-context.py` writes `data/salarii/ins-context.csv`: one row per
   (CAEN section, county or national, period, measure) that the page needs, with columns
   `matrice, sectiune_caen, judet, perioada, masura, valoare, unitate, extras_la`. A few hundred
   rows at most. The export copies it into a table `salary_ins_context`.
3. **Domain → CAEN section** (Rev. 2): Învățământ → P; Sănătate și asistență socială → Q;
   Cultură → R; Administrație, Justiție, Diplomație → O; Apărare și ordine publică → O. Put the
   mapping in the script, with this comment. If INS has a finer split that fits better, report it.
4. **On the result**, under the two pay boxes, one line in `g2-text-meta` style:
   „Context INS: câștigul salarial mediu net lunar în <activitatea>, <România | județul X>,
   <perioada>: <valoare> lei (TEMPO <matrice>).” A link to the matrix on ins.gov2.ro.
   - The county: add an optional select „Județul (pentru context)” in step 4. Without it, show
     the national figure. It does not change the estimate.
   - Say in one short sentence that an average includes seniority and sporuri, so it is higher
     than a base pay at gradation 0.
   - No INS row for the choice → no line.
5. **Not now:** INS figures by occupation group (ISCO), the `/salarii/` hub, „A vs B”. These stay
   in SALARY-HUB.

## Files

You can change: `webapp-php/pages/salary_estimator.php`, `webapp-php/helpers.php` (the
`salary_est_*` functions and new ones), `export-to-sqlite.py` (new tables `salary_title`,
`salary_ins_context` only), `ops/build-ins-context.py` (new), `data/salarii/ins-context.csv` (new),
`webapp-php/assets/app.css` + rebuilt `webapp-php/static/app.css`, the fixture builder, the tests
below, `README.md` (one paragraph each for the wizard and the INS table), `docs/backlog.md`,
`docs/activity-log.md`.

Stop and ask before you change: ADR 0008, `salary_grid.py`, an existing export table or column,
the URLs `?rand=&g=`, a DS file, a `.g2-` class or a token name, `tempo-ins-dump`, or any other file.

## Tests

- PHP `tests/salary_estimator_test.php`: each step renders; the levels of step 2 are only those of
  the domain; `PL/M` rows appear under PL and M; the breadcrumb links go back; the title search
  finds „asistent medical” and „inspector de specialitate” and opens step 4 with the grade; old
  `?rand=` URLs still work; the INS line shows national and county figures, and hides when no row
  fits; an export without the new tables still works (no title results, no INS line).
- Python: a test for the domain → CAEN mapping and the CSV columns of `build-ins-context.py`.
- Playwright `salary_estimator.spec.js`: the wizard from domain to result at 375 and 1280 px; no
  horizontal overflow; the title shortcut.
- Run the full PHP suite and the full Playwright suite. If the full Playwright run stalls on
  machine load, say so and run it in parts by spec file.

## Report

What you measured (functions per domain × studies, `salary_title` rows, INS rows and periods,
page weight of the largest step-3 list), what you assumed (the annex labels, the CAEN mapping),
and screenshots of each step at 375 px and of a result with the INS line at 1280 px, saved under
the scratchpad folder that Opus gives you.

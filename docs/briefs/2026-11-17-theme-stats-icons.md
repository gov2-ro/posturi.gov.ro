# Brief — Themed page: statistics panel, compact lists, medical icons

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: branch `ux-05-landing-page` with its uncommitted files. Do not revert them.

Read first: `docs/decisions/0006-icon-set.md`, `docs/decisions/0007-health-icons.md`, `docs/decisions/0005-gov2-ds-only.md`, the DS `COMPONENTS.md`, and as they are now: `webapp-php/inc/themes.php`, `webapp-php/pages/theme.php`, `webapp-php/pages/home.php` (the figures and the weekly chart), `home_weekly_postings()` in `webapp-php/helpers.php`.

## Goal

1. On `/sanatate/`, the area below the search form shows statistics of the theme.
2. The lists of the themed page are more compact.
3. Each medical specialty and each medical occupation has a symbol.

## Measured (17 Oct export)

- Health postings, active: 375. In `job_postings` (active and not active): 788. In `archived_postings`: 1,053, published July to October 2026.
- `archived_postings` has `inf_profession_family` and `occ_canonical`. It has no specialty column. Thus the history of occupations is possible; the history of specialties is not.
- The theme has 36 distinct specialties and these top occupations: Medic specialist, Asistent medical, Asistent medical generalist, Infirmier, Asistent medical comunitar.

## Constraints

- Do not rename or add a query parameter. Do not change `build_filters()`, `search_key()` or `validated_query()`.
- The server output is the same for each reader. The county selection does not change the statistics panel.
- Each count that is a link uses the predicate of its link.
- Role tokens only. Use a DS component when one fits. Do not edit the DS copy or change how a `.g2-` class looks.
- The template stays generic. Each new part is a setting of the theme configuration. A theme with no such setting renders as now.
- Keep each `id`, `data-` hook and script behaviour that a test or a script uses.

## Work

### 1. Statistics panel — `inc/themes.php`, `pages/theme.php`, helpers, a partial

Place: the left column of the first screen, below the link to all postings. Below `lg` it follows that link and comes before the map.

A new theme key `history` names the part of the archive that belongs to the theme (for health: the profession family `sănătate`). The archive cannot take the base filter, because it has few columns. A theme with no `history` key shows the figures only.

Content, in this order:

1. **Figures**, in the form of the landing page figures: active postings of the theme; employers with an active posting of the theme; postings of the theme that close in the next 7 days. Reuse the landing code; move shared markup to a partial. A figure is a link only when a list with the same predicate exists.
2. **„Anunțuri noi pe săptămână”**: the weekly chart of the landing page, for the theme, 12 weeks. Give `home_weekly_postings()` an optional family argument; the landing result must not change. Reuse the chart markup as a partial.
3. **„Ocupații, pe luni”**: a small table. Rows: the 5 occupations with the most postings of the theme in the months shown. Columns: the last 3 complete months and the current month. Each cell is the number of postings published in that month (`job_postings` plus `archived_postings`, each posting once). The header of the current month says „(până azi)”. Each occupation name is a link to the active list of that occupation in the theme. Use the DS table. No horizontal scroll at 320 px.
4. One caption line: „Toată țara. Lunile numără anunțurile publicate, și cele expirate.”

Measure and report: the first day with a posting in each month shown. If the first month shown does not start in its first week, say so; do not hide it.

Do not build a history of specialties. Add a backlog item: add the specialty to `archived_postings` in the export, so that the themed page can show specialties over time.

### 2. Compact lists

In the `values` sections of the themed page (specialties, occupations, studies): reduce the line height and the space between rows. Each link keeps a target of at least 24 px high, and two targets do not touch (WCAG 2.5.8). Report the row height before and after, and the height of the section "Specialități medicale" at 1280 px before and after. Do not change the landing page.

### 3. Medical icons — ADR 0007

1. Get `healthicons` 3.1.0 from npm (do not add it to `package.json`). Copy only the outline SVG files that the page uses to `webapp-php/assets/icons/healthicons/`, with the licence text as `LICENSE` and a one-line `README.md` with the version. Do not change a path.
2. Build one sprite partial with a symbol for each icon, id `i-health-<name>`, `viewBox="0 0 48 48"`. The source paths have `fill="currentColor"`; keep that. The themed template includes the sprite only when the theme configuration names it. The sprite is not in `inc/header.php`.
3. A `values` section gets an optional key `icons`: value → symbol id, and an optional `icon_default`. The item shows the icon before the name, 24 px, `aria-hidden="true"`, `focusable="false"`. A value with no icon and no default shows no icon; its text aligns with the other texts.
4. Specialties: map each of the 36 values. Use the icon of the specialty when the set has one (`specialties/radiology`, `cardiology`, `pediatrics`, `nephrology`, …). Else use the nearest icon of the set (for example `symbols/virus` for infectious diseases, a tooth for dental medicine, a microscope for pathology). Else use the default, one general health icon. Two near subjects can share an icon.
5. Occupations: doctor for each "Medic …" occupation, nurse for each "Asistent medical …" occupation, community health worker for „Asistent medical comunitar”, and the nearest icon for the others. The same icon on some rows is accepted.
6. Report the full mapping as a table (value, icon file, own / near / default), and the bytes that the sprite adds to `/sanatate/` (raw and gzip). Stop and ask if the sprite adds more than 40 kB gzip.
7. The `Server-Timing` median of `/sanatate/` on `data/_dev/posturi-new.sqlite` (5 requests): report it; stop and report above 100 ms.

## Tests

- PHP (`theme_test.php`, `home_test.php`): the three figures equal their SQL counts, and a linked figure equals the result count of its link; the weekly chart of the theme counts only the theme (fixture rows in and out of the family, in `job_postings` and in the archive, and a posting in both counts once); the landing chart is the same as before; the month table has the right cells on the fixture and its links carry the base filter; a theme with no `history` renders figures only; a theme with no `icons` renders with no icon and no sprite; each `<use>` of the page points to a symbol that exists; each icon is `aria-hidden`.
- Playwright (`theme.spec.js`) at 320, 375 and 1280 px: the panel is in the left column at 1280 px and above the map at 375 px; no horizontal overflow; a specialty icon renders at 24 px; each list link is at least 24 px high; the county selection does not change the panel.
- Each new check must fail against a named mutation. Restore only the file you mutated.
- Baseline first (every PHP suite and one full Playwright run; do not edit while it runs). Finish with one clean full Playwright run. Report: every PHP suite, the PHP lint, `npm run css`.
- Screenshots of `/sanatate/` at 1280 and 375 px, with `POSTURI_DB=data/_dev/posturi-new.sqlite POSTURI_TODAY=2026-10-17` (real data, not the fixture), in the scratch folder that the prompt names. Look at them.

## Files you may change

`webapp-php/inc/themes.php`, `webapp-php/pages/theme.php`, `webapp-php/pages/home.php` (only to use a shared partial), `webapp-php/partials/` (new and shared partials), `webapp-php/helpers.php` (new helpers, and the optional argument of `home_weekly_postings()`), `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`, `webapp-php/assets/icons/healthicons/` (new), `webapp-php/tests/**`, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file.

## Done when

- All checks pass, and the report gives each command and its result, what you measured and what you assumed.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**. `docs/backlog.md` has the new item.
- Do not commit. Do not deploy. Opus reviews first.

## Follow-up 1 (2026-11-17, pax) — one chart with occupations, selects, layout

The same rules, tests and "Done when" apply. Each new part is a setting of the theme configuration; a theme without it renders as now.

### 1. One weekly chart, with a filter by occupation

1. Remove the table „Ocupații, pe luni”, its helper and its tests. Remove the caption sentence „Arhiva începe în iulie 2026.” and the `note` key of `history`.
2. Keep one chart, „Anunțuri noi pe săptămână”, 12 weeks. Above it, a row of buttons: „Toate” and the 5 occupations with the most postings of the theme in these 12 weeks. A click on a button shows the weekly series of that occupation in the same chart. „Toate” shows the total. One button is active at a time (`aria-pressed`).
3. The server renders the total series, as now. The series of the 5 occupations are in the embedded JSON of the page. Without JavaScript the buttons are hidden and the chart shows the total.
4. When the series changes: the text line below the chart (min, max, last week) shows the values of the active series; a polite live region says the occupation and its total for the 12 weeks; the vertical scale fits the active series.
5. Each series counts a posting once (`job_postings` plus the archive), with the same week rule as the total. A test proves that the 5 series are each at most the total in each week.
6. Below the chart, one caption: „Toată țara. Anunțuri publicate, și cele expirate.”
7. The buttons are DS buttons or DS tags if a fitting variant exists; else a local class with a DS-proposal comment. Each target is at least 24 px high. No horizontal scroll at 320 px (the row wraps).

### 2. Icons and lists

1. Remove the icons from the section „Ocupații” (the `icons`, `icon_prefixes` and `icon_default` settings of that section). Keep the code path for prefixes only if a test still uses it; else remove it. Remove the symbols that no section uses from the sprite file and from the sources.
2. „Specialități medicale”: 3 columns at 1280 px (not 4). The icon is 32 px. The row stays compact; a one-line row can be as high as its icon. Report the section height.

### 3. Search form of a themed page

A theme can replace the text field with selects. New theme key (for example `search_selects`): a list of filters, each with a parameter, a label and the source of its values. For health: „Specialitate” (`medspec`) and „Ocupație” (`occupation`).

- The form of `/sanatate/` has three selects and the button „Caută”: „Toate specialitățile”, „Toate ocupațiile”, „Toate județele”. No text field.
- Each select lists every value with a count above 0 inside the theme, in alphabetical order (Romanian collation), with no count in the option text. The county select keeps its counts, as now.
- Each select has a label (visually hidden is acceptable if the first option names the subject).
- The form sends the base filter and the selected values to the list, with the parameter names that exist. An empty select adds no parameter to the final URL (the existing clean-URL redirect does this; test it).
- At 1280 px the three selects and the button are on one row in the left column, or wrap to two rows without overflow. At 320 px each control is full width.
- A theme with no such key keeps the text field. The landing page does not change.

### 4. Other postings of health employers

1. Move the line from the end of the page to directly before „Noi și pe terminate”.
2. New text in the theme configuration: link „Posturi nemedicale la angajatori din sănătate”, count in brackets outside the link, then one muted sentence: „Economiști, muncitori, șoferi, registratori și alte posturi din spitale și unități sanitare.” The parameters do not change.

### Tests

Update the checks of the removed parts. New checks, each seen failing against a named mutation: the occupation buttons (5 plus „Toate”, the series in the JSON equal SQL counts on the fixture, a click changes the drawn chart and the text line, `aria-pressed`, hidden without JavaScript); no icon in „Ocupații”; 3 columns and a 32 px icon at 1280 px; the three selects, their options equal the values with a count above 0, a submit with one specialty opens a list whose count equals the count of that specialty on the page, an empty submit opens the base list; a theme with no `search_selects` has the text field; the moved line is before „Noi și pe terminate”.

Screenshots at 1280 and 375 px on real data, and one with an occupation button active. Look at them.

Files: as above, plus `webapp-php/static/home.js` or one new small script in `webapp-php/static/` for the chart buttons.

## Follow-up 2 (2026-11-17, pax) — county controls the first screen

The same rules, tests and "Done when" apply. Where this follow-up and Follow-up 1 differ, this one wins.

### 1. Form and chart details

1. At `lg` and above, the three selects are on one row. The button „Caută” is on the same row if it fits; else it is on the next row. You may change the proportion of the two columns of the first screen (the left column up to about 60%) and let a select truncate a long option text. Report the widths at 1280 and 1024 px.
2. The heading „Anunțuri noi pe săptămână” is visually hidden (keep it for assistive technology).
3. The occupation buttons are text only: no border, no background, compact spacing. The active button has a clear mark that is not colour alone (for example bold with an underline), and `aria-pressed`. Each target stays at least 24 px high, and two targets do not touch.
4. Remove the caption „Toată țara. Anunțuri publicate, și cele expirate.”

### 2. The selected county filters the statistics panel

Now the selected county changes only the sections below. Change that: one county selection controls the whole page.

1. With a county selected, the three figures and the chart show that county: active postings, employers, postings that close in 7 days, and each weekly series (the total and each occupation).
2. Put the data in the embedded JSON: for each county, the three figures and the 12 weekly values of the total and of each of the 5 occupations. Use the county of `job_postings` and of the archive. Report the bytes that this adds to the page (raw and gzip); stop and ask above 15 kB gzip.
3. The 5 occupation buttons do not change with the county. A button whose series is 0 in each week for the selected county is hidden; if it was active, „Toate” becomes active.
4. The links of the figures carry `judet[]` when a county is selected, and each figure equals the result count of its link.
5. The server output does not change with the county: without JavaScript the panel shows the whole country.
6. Sum rule, tested on the fixture: for each week and each series, the sum over the counties equals the national value for the postings that have a county. The same for the figures „posturi active” and „se închid în 7 zile” (not for employers: one employer can post in two counties).

### 3. The map and the county select are one control

1. A click on the map sets the county select of the form to that county. A change of the select marks the county on the map and filters the page, as a click does. „Toate județele” (the button under the map or the first option of the select) clears both.
2. The live region announces the selection once, not twice.
3. The form then sends the county that the reader sees selected.
4. The options of the selects „Specialitate” and „Ocupație” do not change with the county.

### Tests

New checks, each seen failing against a named mutation: the three selects share one row at 1280 px; the heading is not visible but is in the accessibility tree; a map click sets the select, the figures and the chart (compare with the JSON values of that county); a select change marks the map tile; clearing restores the national values; a hidden occupation button with a zero series; the sum rule; the figure links with a county selected open lists with the same counts.

## Follow-up 3 (2026-11-17, pax) — one-row search, county in the title

The same rules, tests and "Done when" apply. Where this follow-up and an earlier one differ, this one wins.

1. **The whole search form is one row at `lg` and above**: the three selects and the submit control, with no wrap at 1280 and at 1024 px. The submit control is an icon button: a search icon (Tabler `search`, 3.52.0, in the sprite of `inc/header.php` if it is not there), with the accessible name „Caută” and a target of at least 44 px. The selects share the remaining width; a long option text can truncate. Below `lg` the controls can stack, and the button can show its text.
2. **Occupation buttons of the chart**: they look like normal text. No box, no border, no background, in each state except the focus ring. The function does not change (Follow-up 2, item 1.3).
3. **Remove the line under the map** („Alege un județ pe hartă. Numerele din pagină arată posturile județului.”). The button „Toate județele” stays, shown only when a county is selected.
4. **The title shows the selected county**: „Posturi în sănătate → Suceava”. The arrow and the county name are inside the `<h1>`, added by the script; the arrow is `aria-hidden`, and a visually hidden word („județul”) comes before the name. With no county the title is as now. The live region still announces the selection once. The `<title>` of the document does not change.
5. **Backlog** (`docs/backlog.md`, open, data section): „For fixed-term postings (perioadă determinată), find how long the contract or the project runs, and show it. The export has `v3_contract_duration` and `v5_end_date`; measure their fill rate first.”

Tests, each seen failing against a named mutation: the four controls of the form have the same top at 1280 and 1024 px; the submit button has the accessible name „Caută”; the removed line is absent; the `<h1>` text with and without a county; an occupation button has no border and no background (computed style).

## Follow-up 4 (2026-11-17, pax) — count in the title, county checkbox

The same rules, tests and "Done when" apply. Where this follow-up and an earlier one differ, this one wins.

1. **Remove the figures row** (posturi active, angajatori, se închid în 7 zile) from the themed page, with its per-county data in the JSON and its tests. Keep the shared partial only if the landing page uses it. The chart with its occupation buttons stays.
2. **The count of active postings follows the title, in brackets**: „Posturi în sănătate (375)”. The count is in `ink-muted`, at normal weight and a smaller size than the title, and it is not part of the icon. With a county selected: „Posturi în sănătate → Suceava (12)” (the count is the count of the county). The count equals the result count of the link „Toate anunțurile din sănătate” with the same county.
3. The link „Toate anunțurile din sănătate” stays, with no count after it (the title has it). With a county selected it carries `judet[]`, as now.
4. **Remove the label „Posturi active pe județe”** above the map from view. Keep an accessible name for the map (visually hidden heading or `aria-label`).
5. **Under the map, one checkbox „Arată toate județele”** (DS checkbox), always shown when JavaScript runs. It is checked when no county is selected. When the reader selects a county, it becomes not checked. When the reader checks it, the county selection clears (map, select, title, chart). When no county is selected, a click leaves it checked. Remove the button „Toate județele” and the county name before it.

Tests, each seen failing against a named mutation: the title count without and with a county, equal to the result count of the link; no figures row; the map label is not visible and the map has an accessible name; the checkbox state follows the selection in both directions and a check clears the county in the map, the select, the title and the chart.

## Follow-up 5 (2026-11-17, pax) — occupation groups in the chart, icon size, link place

The same rules, tests and "Done when" apply. Where this follow-up and an earlier one differ, this one wins.

1. **The chart buttons are fixed groups of occupations**, not the 5 top occupations. The groups are a setting of the theme `history` (label, exact values, prefixes, exclusions). For health, in this order:
   - „Toate” — the total;
   - „Medic” — each occupation that starts with „Medic”, but not „Medic rezident”;
   - „Rezident” — „Medic rezident”;
   - „Asistent” — each occupation that starts with „Asistent medical”;
   - „Îngrijitor” — „Îngrijitor”, „Infirmier”, „Infirmieră”, „Brancardier”.
   An occupation is in one group at most. Each series counts a posting once. Report the 12-week total of each group and of the postings in no group, on real data. The rules of Follow-ups 2 and 3 for the buttons stay (text only, hidden when the series of the county is 0, per-county series in the JSON).
2. **Specialty icons are 40 px** (25% larger than 32 px). Keep 3 columns at 1280 px. Report the section height.
3. **Move the link „Toate anunțurile din sănătate”** out of the first screen. It goes directly after the line „Posturi nemedicale la angajatori din sănătate”, with its count in brackets outside the link (this replaces Follow-up 4, item 3). From `lg` the two are two columns of one row: left the non-medical line with its sentence, right the link to all postings. Below `lg` they stack. With a county selected, both links carry `judet[]` and both counts are the counts of the county.

Tests, each seen failing against a named mutation: the group series equal SQL counts on the fixture (a „Medic rezident” row is in „Rezident” and not in „Medic”; an „Infirmier” row is in „Îngrijitor”); the order and the labels of the buttons; the icon is 40 px; the link to all postings is after the non-medical line, not in the first screen, and its count equals the result count of its link, without and with a county.

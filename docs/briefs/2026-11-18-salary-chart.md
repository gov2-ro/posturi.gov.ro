# Brief — Salary chart (UX-SALARY-CHART)

Date: 2026-11-18. Author: Opus. Builder: Sonnet. Backlog: UX-SALARY-CHART.

## Goal

A visitor wants to know what the public sector pays. Show the distribution of the net monthly salary of the active postings, by the ranges of the filter "Salariu". The owner wants it on three pages: the landing page (`/`, lower down), each themed page, and `/statistici/`.

## Decisions (Opus)

1. **One shared partial**, `webapp-php/partials/salary_chart.php`, and one data function in `webapp-php/helpers.php`, for example `salary_distribution(array $params): array`. The three pages use them. Do not copy the markup.
2. **Form: horizontal bars in HTML and CSS, not SVG.** One row for each range of `SALARY_NET_BUCKETS`, in that order (lowest at the top). Each row has the label ("3.000 – 4.000 lei net"), a bar, and the count at the end of the bar. The labels are long and the page must work at 320 px, so horizontal bars with text in HTML are the right form. Load the `dataviz` skill before you write the chart.
3. **The unit is postings (anunțuri), not posts.** Each row is a link to the list with that salary range (and the base filter of the page). The count of a row equals the result count of its link. This is the rule of the landing page (ADR 0004; `home_count_for()`, `home_list_url()`). Use `salary_bucket_sql()` for the counts, or `home_count_for()` with `['salary_bucket' => key] + base`, so that the chart and the filter can never disagree. Do not use `SALARY_NET_LEGACY`.
4. **"fără salariu estimat" is a separate row, below the ranges.** Add a gap or a rule above it, and give its bar a neutral colour (the `ink-faint` or `line-strong` role, or the existing `pg-c-other`). It is not a salary level. It tells the reader how much of the picture is missing. It links to `salary_bucket=fara-estimare` + base.
5. **One colour for the five ranges:** the `gov` role (`bg-gov`). The bar length carries the value. Use no raw Tailwind palette class and no hex literal (CLAUDE.md, palette rule). A new colour goes in `assets/app.css` as a role token.
6. **Bar scale:** the longest bar (the largest count, the "fără" row included) fills the bar track. The others are in proportion. A count above 0 gets a bar of at least 2 px.
7. **Caption and note.** Visible caption: "Salariul net lunar". One short note under the chart (`g2-text-meta`): "Salariul anunțat sau, pentru cele mai multe posturi, cel estimat din grila de salarizare propusă (gradația 0)." Owner may shorten it.
8. **Empty case:** when no active posting of the scope has a salary figure, the partial draws nothing.
9. **Accessibility:** a `<figure>` with a `<figcaption>`, and a `<ul>` of rows. The whole row is the link target, at least 44 px high below `lg` (24 px from `lg` is acceptable if the rows are not adjacent targets; follow the target rules that the page already uses). The bar is `aria-hidden`; the link text is the label and the count ("3.000 – 4.000 lei net: 397 anunțuri").

## Placement

- **Landing page (`pages/home.php`)**: a new section after the domain band (`#domenii`) and before "Sau începe de aici". It shows the whole country and does not follow the county map (the map is inside the band and filters only the band). Remove the "Salariu" row from "Sau începe de aici": the chart has the same links and counts, so the row would repeat them. The server output stays the same for every reader (cacheable).
- **Themed page (`pages/theme.php`, `partials/theme_stats.php`)**: in the statistics panel, under the weekly chart. The base filter of the theme is the scope. It **follows the county selection**, as the weekly chart does: add the counts of each range for each county to the JSON block `#theme-panel-data`, and let `static/theme-panel.js` update the bar widths, the counts and the link URLs on the `posturi:county` event. The links then carry `judet[]=<slug>` too. Without script the chart shows the whole country. A themed page without a `stats` section shows no salary chart.
- **Statistici (`pages/stats.php`)**: a new section "Salarii", with an entry in `$toc` (use `toc_h2()`). Scope: all active postings. Keep the two existing figures "Cu salariu anunțat" and "Doar salariu estimat"; move them into this section if that reads better, and say so in the report.

## Files

You can change: `webapp-php/helpers.php` (one new function, no change to existing functions), `webapp-php/partials/salary_chart.php` (new), `webapp-php/pages/home.php`, `webapp-php/pages/theme.php`, `webapp-php/partials/theme_stats.php`, `webapp-php/static/theme-panel.js`, `webapp-php/pages/stats.php`, `webapp-php/assets/app.css` (layer `components` or `app`, with a dated comment), `webapp-php/static/app.css` (rebuild with `npm run css`), the tests below, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file, a DS file (`webapp-php/static/gov2/`), a `.g2-` class, or a token name.

Another brief (`docs/briefs/2026-11-18-salary-multi-select.md`) changes `salary_bucket` to a multi-value parameter later. Do not do that work here. Build the links with `home_list_url()`, so that the key format follows `MULTI_PARAMS` automatically.

## Tests

- `webapp-php/tests/home_test.php`: the chart has 5 range rows and the "fără" row; each count equals the result count of its link; the "Salariu" row of "Sau începe de aici" is gone (update the test that expects it).
- `webapp-php/tests/theme_test.php`: the chart in the panel of `/sanatate/`; the counts equal the links; the JSON block has the county counts and they sum to the national counts.
- `webapp-php/tests/stats_test.php`: the section and its TOC entry.
- Playwright (`webapp-php/tests/browser/`): no horizontal overflow at 320, 375 and 1280 px on the three pages; on `/sanatate/`, a click on a county on the map changes the counts and the links of the chart, and "Toate județele" restores them.
- Run every PHP suite (`for f in webapp-php/tests/*_test.php; do php "$f"; done`) and the Playwright specs of the three pages (`npx playwright test --config webapp-php/tests/browser/playwright.config.js home theme stats ds`).

## Report

Add an activity-log entry (Done / Measured / Hard / Decided) and mark UX-SALARY-CHART `[x]` in `docs/backlog.md`. Measure on `data/_dev/posturi-new.sqlite` (`POSTURI_DB=…`): the counts of each range on `/`, on `/sanatate/`, and the `Server-Timing` total of `/` and `/sanatate/` before and after (6 requests each). Take screenshots at 390 px and 1280 px of the three charts and give their paths. Report what you assumed. Do not commit and do not deploy.

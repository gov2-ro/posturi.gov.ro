# Brief — Weekly chart: posts, bars, a stacked bar under "Toate"

Owner: Sonnet builds; Opus reviews. Date: 2026-11-18. Baseline: the tree after brief 1 (`2026-11-17-themes-admin-financiar-chips.md`) and the Opus changes of 2026-11-17/18. Those changes are the shared chart axis, the bar prototype behind `POSTURI_CHART_BARS`, the notifications band and the health card.

Status: **ready, not started.** Start after the review of brief 1.

Read first:
- `docs/activity-log.md`, the entry "Themed pages for the professional domains", its lines "One chart axis for all buttons" and "Notifications band"
- `webapp-php/partials/weekly_chart.php`, `webapp-php/partials/theme_stats.php` and `webapp-php/static/theme-panel.js`
- `home_weekly_postings()` and `theme_group_series()` in `webapp-php/helpers.php`
- the chart colour tokens in the DS (`--g2-color-chart-1` to `-4`)

## Goal

The weekly chart of the landing page and of each themed page counts **posts** (*posturi*), not announcements. It shows them as **bars**. On a themed page, the button "Toate" shows a **stacked bar**: one segment for each chart group, and one for the other occupations.

## Decisions (owner, 2026-11-17/18)

- The chart counts posts.
- Bars only. No switch between line and bars.
- Under "Toate", a stacked bar. A group button shows the bars of that group only.
- The owner likes how plain the line looked. Keep the bars plain too:
  - thin bars, with space between them;
  - no grid and no axis labels other than the two dates;
  - the colour of the line for a single series;
  - no border and no gap on the segments: adjacent segments touch (owner, 2026-11-18).
- The axis stays shared: from 0 to the largest week of any button (Opus, 2026-11-17). A stacked bar of "Toate" is as tall as the total.

## Measured (17 Oct export; `job_postings` plus the rows of `archived_postings` that are not in `job_postings`)

`nr_posturi` (the post count of an announcement):

| Value | Announcements |
|---|---|
| 1 | 3,318 |
| 2 to 5 | 651 |
| 6 to 10 | 58 |
| 11 to 20 | 22 |
| 21 to 50 | 27 |
| more than 50 | 12 |
| missing | 2,777 |

- The real values reach about 27. The titles confirm them: "concurs 27 posturi", "24 posturi vacante".
- Clearly wrong values: 339,395 (one "Mediator școlar"), 2,026 (a year, twice), 107 (one "portar"), and 50 four times on titles of one post.
- `v5_vacancies_total` is empty in this export.

## Rule — the posts of an announcement

- Posts = `nr_posturi` when it is between 1 and 30.
- Posts = 1 when `nr_posturi` is missing, below 1 or above 30.

Put the rule in one SQL expression in `helpers.php` (a constant or a helper). Every count of the chart uses it: `job_postings`, `archived_postings`, the total, the groups and the counties.

Report:
- how many announcements the rule changes, in each case (missing, and above 30);
- the total in announcements and in posts, for the 12 weeks.

## Work

### 1. Count posts (`helpers.php`)

- `home_weekly_postings()` and `theme_group_series()` sum the posts of the rule, in place of `COUNT(*)`. This covers the total, each group and each county.
- An announcement is still counted once: an id that is in both tables counts for `job_postings`, as today.
- Do not change the figures of the landing page or the counts of the lists. They count announcements.

### 2. Captions and texts

- Both charts have the caption "Posturi publicate pe săptămână".
- No sentence about the estimate under the chart (owner, 2026-11-18). A code comment explains the rule.
- The line "min · max · ultima", the `aria-label` of the chart and the live region speak of posts:
  - Write "Medic: 25 de posturi în ultimele 12 săptămâni", not "anunțuri".
  - Use the rule of `posturi_label()` (helpers.php). Its JavaScript twin replaces `ads()` in `theme-panel.js`.

### 3. Bars only (`partials/weekly_chart.php`, `static/theme-panel.js`, `assets/app.css`)

- Remove the switch `POSTURI_CHART_BARS`, the polyline and the area, from the PHP and from the script.
- 12 bars. The width of a bar is 50 % of its slot: the line of the chart was plain, so keep the bars thin. One baseline in the colour `line`. No grid.
- A bar starts at 0. Axis:
  - landing page: 0 to the largest week;
  - themed page: 0 to the largest week of any button, in the selected county (as built).
- Colours come from tokens, through classes in `assets/app.css` (`@layer components`). Do not use an inline style. A single series has the colour `--c-chart-1`.
- Each bar (or segment) has a `<title>` with the week and the value, for example "săpt. 7 sept.: 184 de posturi". The `aria-label` of the chart stays the summary of the series.
- The server draws the same bars as the script for the national view. The chart does not jump when the script loads.

### 4. Stacked bar under "Toate" (themed page)

- Segments, from the bottom up: the groups of the theme in configuration order, then "Alte ocupații". "Alte ocupații" is the total minus the sum of the groups of that week and county. Check that it is never below 0, and report it if it is.
- Colours, in group order: `chart-2`, `chart-1`, `chart-3`, `chart-4`. "Alte ocupații" has the fill `chart-1` at 30 % opacity: not white, and different from each group colour (owner, 2026-11-18). No segment has a border, and two segments have no gap between them. A theme with more than 4 groups: stop and ask. Today no theme has more than 4.
- Each group button shows a square of its colour before the text. The button "Toate" has no square. The buttons are the legend. Add a text item "Alte ocupații" with its square after the buttons, as a legend entry and not as a button.
- A group button shows the bars of that group only, in the colour of its segment, on the same axis.
- The colour is not the only cue: each segment has its `<title>` (group, week, value).
- The landing chart has no groups: single bars.
- A county selection (`posturi:county`) redraws the stack and each group for that county, as the line does today.

### 5. Design system

The DS has 4 chart colours: two blues and two greys. Use them as written above. Do not open the DS issue: Opus opens a proposal for a categorical chart palette after the review. Write the text of that proposal (the ds-proposal sections) in your report.

## Tests

- **PHP:**
  - The rule of posts: one case for each branch (2, a missing value, 0, 31, 339395) gives the expected posts.
  - The landing chart sums posts. Mutation: `COUNT(*)`.
  - A themed chart: for each week, the sum of the segments equals the total. Mutation: drop "Alte ocupații".
  - No polyline, and no `POSTURI_CHART_BARS`, in the source.
  - The caption is present.
- **Playwright (`theme.spec.js`, `home.spec.js`), at 375 and 1280 px:**
  - "Toate" shows 12 stacks. The segment heights of a week add up to the height of the total bar.
  - A group button shows one series in its colour. Its tallest bar is at the expected fraction of the axis: update the existing check, Medic at half the axis on the fixture.
  - A county selection changes the bars and changes them back.
  - Each segment has a `<title>`.
  - The live region says "posturi".
  - No horizontal overflow at 320 px.
- Each new check must fail against a named mutation.
- Run and report:
  - every PHP suite
  - the PHP lint
  - the full Playwright run
  - `npm run css`
  - the `Server-Timing` median of `/` and of `/sanatate/` before and after (5 requests each). Stop and report if a median grows by more than 10 ms.
- Screenshots of the landing chart and of the `/sanatate/` chart ("Toate", one group, Cluj) at 1280 and 375 px, in the scratch folder that the prompt names. Look at them.

## Files you may change

- `webapp-php/helpers.php` (new helpers, and the two chart functions)
- `webapp-php/partials/weekly_chart.php`, `webapp-php/partials/theme_stats.php`
- `webapp-php/static/theme-panel.js`
- `webapp-php/pages/home.php` and `webapp-php/pages/theme.php` (the chart caption and the data of the chart only)
- `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`
- `webapp-php/tests/**`
- `docs/backlog.md`, `docs/activity-log.md`

Stop and ask before you change any other file.

## Done when

- All checks pass. The report gives each command and its result, what you measured and what you assumed.
- `docs/backlog.md` has a new open item, **DATA-NR-POSTURI**: validate the post count in the pipeline. Use the number in the title ("27 posturi"), drop impossible values, and fill `v5_vacancies_total`. Then the chart can drop its estimate rule. Include the measurements of this brief.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.

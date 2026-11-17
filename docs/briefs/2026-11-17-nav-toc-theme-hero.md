# Brief — One "Salvate" item, table of contents, themed-page hero

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: branch `ux-05-landing-page` with its uncommitted files (themed landing, net salary). Do not revert them.

Read first: `docs/decisions/0005-gov2-ds-only.md`, `docs/decisions/0006-icon-set.md`, the DS `COMPONENTS.md` (header, navigation, links), and the files named in each step as they are now.

## Goal

1. The navigation has one item for saved things and alerts.
2. The pages Statistici and Despre have a table of contents.
3. The themed page shows the county map in its first screen.

## Constraints

- Do not rename or add a query parameter. Do not change `build_filters()`, `search_key()` or `validated_query()`.
- The server output is the same for each reader.
- Role tokens only. Use a DS component when one fits. Do not edit the DS copy or change how a `.g2-` class looks.
- New icons come from `@tabler/icons` 3.52.0, not modified. An icon next to text is decoration (`aria-hidden`).
- Keep each `id`, `data-` hook and script behaviour that a test or a script uses, unless a step removes the feature.
- Each link target is at least 44 px high in the navigation. No horizontal scroll at 320 px.

## Work

### 1. Navigation — `inc/header.php`, `pages/saved.php`

1. Remove the item "Alerte" from the masthead navigation. The order is: Posturi, Salvate, Statistici, Despre.
2. The item "Salvate" gets a bell icon before its text (the sprite symbol `i-bell`). Keep the `data-saved-count` element and its script.
3. `/alerte/` keeps its URL and its pages. The footer keeps its link "Alerte pe email".
4. On `/salvate/`, add a short block near the top, visible to each reader: a heading „Alerte pe email”, one sentence („Primești pe email posturile noi dintr-o căutare salvată, o dată la 3 zile.”) and a link „Gestionează alertele” to `/alerte/`. Do not change the existing "Alertă pe email" controls of a saved search.
5. On each `/alerte/…` page, the item "Salvate" is the current item of the navigation if the navigation marks a current item; else do nothing.

### 2. Statistici — `pages/stats.php`

1. The line „Fără dată de publicare — incluse în total, în nicio lună” is not shown when its number is 0. Keep it when the number is above 0, because it explains a difference between the total and the months.
2. Add a table of contents (step 4).

### 3. Despre — `pages/about.php`

Add a table of contents (step 4). Keep each existing anchor (for example `#confidentialitate`): other pages link to them.

### 4. Table of contents (one partial, used by both pages)

- A `<nav aria-label="Cuprins">` with one link for each section heading of the page (`<h2>`). Build the list from one array in the page, so that the headings and the list cannot differ. Give each section an `id` if it has none; keep the ids that exist.
- From `lg`: a left column beside the content (about 14rem), sticky below the header while the content scrolls. Below `lg`: a block above the content, not sticky.
- After a click, the section heading is visible below the top edge (use `scroll-margin-top`) and takes the keyboard focus position (`tabindex="-1"`).
- The link of the section in view is marked with `aria-current="true"`. Do this with a small script (IntersectionObserver); without the script the list works and has no mark.
- The DS has no such component. Keep it local in `assets/app.css` (`.pg-toc`), with a comment that it is a DS proposal candidate.

### 5. Themed page — `inc/themes.php`, `pages/theme.php`, partials, `static/home.js`

1. The first screen has two columns from `lg`: left, the title, the search form and the link to all postings; right, the county map with its caption and legend. Below `lg` the map follows the left column.
2. Remove the section „Pe județe” and its county select from the page. The map is the control to select a county. The search form keeps its own county select.
3. Under the map, one short line: „Alege un județ pe hartă. Numerele din pagină arată posturile județului.” When a county is selected, show its name and a button „Toate județele” that clears the selection.
4. The behaviour of the selection does not change: counts and links of the page follow the county; the live region announces it. Without JavaScript the map and its line are hidden.
5. The title has the icon of the theme before the text. Add an `icon` key to the theme configuration (the sprite symbol of the health domain). A theme with no `icon` shows no icon and no gap.
6. The template stays generic: where the map goes is a setting of the theme configuration or the rule "a `counties` section renders in the first screen". Say which you chose.
7. The landing page does not change in this step.

## Tests

- Update the checks that expect the item "Alerte" in the navigation, the section „Pe județe”, or the removed select.
- New checks, each seen failing against a named mutation:
  - the navigation has four items, in order, each visible at 320 px; the bell icon is `aria-hidden`; the saved count still updates;
  - `/salvate/` has the alerts block with a link to `/alerte/`;
  - the statistics line is absent with 0 and present with a number above 0 (fixture);
  - on Statistici and Despre: each table-of-contents link points to an `id` that exists, in the order of the headings; `#confidentialitate` still exists; a click puts the heading in view; `aria-current` follows the scroll; no horizontal overflow at 320 px;
  - on the themed page: the map is in the first screen at 1280 px (its top is above 600 px); no select remains outside the search form; a click on a county updates the counts and the links; „Toate județele” clears it; the title has an icon; a theme with no `icon` renders.
- Baseline first (every PHP suite and one full Playwright run; do not edit while it runs). Finish with one clean full Playwright run. Report: every PHP suite, the PHP lint, `npm run css`.
- Screenshots at 1280 and 375 px: the masthead, `/salvate/`, Statistici, Despre, `/sanatate/` (and `/sanatate/` with a county selected), in the scratch folder that the prompt names. Look at them.

## Files you may change

`webapp-php/inc/header.php`, `webapp-php/inc/themes.php`, `webapp-php/pages/saved.php`, `webapp-php/pages/stats.php`, `webapp-php/pages/about.php`, `webapp-php/pages/theme.php`, `webapp-php/pages/alerts_*.php` (step 1.5 only), `webapp-php/partials/` (new and shared partials), `webapp-php/static/home.js`, one new small script for the table of contents in `webapp-php/static/`, `webapp-php/helpers.php` (new helpers only), `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`, `webapp-php/assets/icons/tabler/`, `webapp-php/tests/**`, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file.

## Done when

- All checks pass, and the report gives each command and its result, what you measured and what you assumed.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**. `docs/backlog.md` is updated where an item changes.
- Do not commit. Do not deploy. Opus reviews first.

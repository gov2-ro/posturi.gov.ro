# Brief — Themed landing page, first theme: `/sanatate/`

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: the working tree after `docs/briefs/2026-11-17-domains-filters-multiselect.md` (not committed; do not revert it).

Status: **ready, not started.** Start it after the review of the baseline brief.

Read first: `docs/decisions/0004-landing-route.md`, `docs/decisions/0005-gov2-ds-only.md`, `docs/decisions/0001-saved-search-identity.md`, and `webapp-php/pages/home.php` as built.

## Goal

A themed landing page shows one part of the postings with the sections that fit that part. One template renders each theme from a small configuration. The first theme is health, at `/sanatate/`.

## Decisions (owner, 2026-11-17)

- The health page shows the medical professions wherever they work (`family[]=sănătate`). A school nurse is on the page. An accountant of a hospital is not.
- The other postings of health employers get one secondary link.
- More themes come later (candidates: local administration, education). Do not build them in this brief.

## Constraints

- Do not rename or add a query parameter. Do not change `build_filters()`, `search_key()` or `validated_query()`.
- A theme is a base filter made of parameters that exist. Each link on a themed page is the base filter plus one more filter. Each count uses the predicate of its link. Hide an item with count 0.
- The server output is the same for each reader (edge cache).
- Role tokens only. Use a DS component when one fits. Do not edit the DS copy.
- Reuse the parts of the landing page (the item with count, the county select and map, the two short lists, the notification cards). Move shared markup to partials; do not copy it.

## Work

### 1. Theme configuration — `webapp-php/inc/themes.php` (new)

One PHP array, keyed by the URL slug. For each theme: the base filter, the page title, the `<h1>`, the meta description, and the list of sections with their settings. The template knows nothing about health.

### 2. Template and route — `webapp-php/pages/theme.php` (new), `webapp-php/index.php`

- `/{slug}/` for a configured slug renders the template. `/{slug}/?…` redirects (301) to `/?…` with the base filter and the given parameters merged, so a filtered list keeps one URL form (ADR 0004).
- The canonical URL is `/{slug}/`. Add each theme to the sitemap.

### 3. The health theme — `/sanatate/`

- Base filter: `family[]=sănătate` (verify the stored spelling).
- `<h1>`: "Posturi în sănătate". Below it: a link "Toate anunțurile din sănătate" to the list with the base filter, with the count in brackets outside the link.
- Search form: `q` and the county select, with the base filter as hidden fields. Each county option shows its count inside the theme.
- Section "Specialități medicale": each value of the filter `medspec` with a count above 0, as a link with its count, largest first. If more than 24 values have a count, show the first 24 and a link to the list.
- Section "Ocupații": the 12 occupations (`occupation[]`) with the most postings inside the theme.
- Section "Pe județe": the county select and the county map of the landing page, with counts inside the theme. A click selects the county, and the links of the page then carry `judet[]` too.
- Section "După studii": the study levels inside the theme.
- Section "Noi și pe terminate": the two short lists, inside the theme.
- Section "Notificări": the three cards. The calendar link and the RSS link are the feeds of the base filter (`feed_url()` with the base filter). The email card links to `/alerte/`.
- One line at the end: "Alte posturi la angajatori din sănătate" → `sector[]=sanatate` with `family_not[]=sănătate`, with its count.

### 4. Links to the theme

- On the landing page, add one line "Pagini tematice" with a link "Sănătate" to `/sanatate/`. Put it below the domain grid.
- In the footer group "Posturi", add "Sănătate".

## Tests

- New PHP suite `tests/theme_test.php`: the route, the redirect of `/sanatate/?…`, the canonical URL, the sitemap entry; each count equals the result count of its link; an item with 0 is not on the page; an unknown slug gives 404; a second theme that the test adds to the configuration renders with no change of the template.
- New Playwright spec `theme.spec.js` at 320, 375 and 1280 px: sections present in order; no horizontal overflow; the county selection updates counts and links; one specialty link opens a list with the same count.
- Each new check must fail against a named mutation.
- Run and report: every PHP suite, the PHP lint, the full Playwright run, `npm run css`, and the `Server-Timing` median of `/sanatate/` on `data/_dev/posturi-new.sqlite` (5 requests; stop and report above 100 ms).
- Screenshots at 1280 and 375 px in the scratch folder that the prompt names. Look at them.

## Files you may change

`webapp-php/inc/themes.php` (new), `webapp-php/pages/theme.php` (new), `webapp-php/index.php`, `webapp-php/pages/home.php`, `webapp-php/pages/sitemap.php`, `webapp-php/partials/` (new shared partials), `webapp-php/static/home.js` (generalise it for both pages), `webapp-php/inc/footer.php`, `webapp-php/helpers.php` (new helpers only), `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`, `webapp-php/tests/**`, `.github/workflows/ci.yml`, `README.md` (route list), `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file.

## Done when

- All checks pass, and the report gives each command and its result, what you measured and what you assumed.
- `docs/backlog.md` lists the candidate themes (local administration, education) as open, with the rule: add a theme when it has enough postings, a distinct audience and a section of its own. `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.

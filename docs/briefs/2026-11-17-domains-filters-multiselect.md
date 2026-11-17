# Brief — Domeniu profesional first, filter order, multi-select fix

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: the working tree after `docs/briefs/2026-11-17-sector-icons.md` and its follow-up (not committed; do not revert it).

Read first: `docs/decisions/0004-landing-route.md`, `docs/decisions/0005-gov2-ds-only.md`, `docs/decisions/0006-icon-set.md`, `docs/decisions/0001-saved-search-identity.md`.

## Goal

1. The landing page offers the professional domain ("domeniu profesional") first. The employer sector becomes a secondary row.
2. The filter list has a new order.
3. A reader can select more than one value in a filter list without a lost selection.

## Decisions (owner, 2026-11-17)

- Professional domain first, on the landing page and in the filter list.
- "Tehnic" is not shown as one group. It is split by the COR major group of the occupation.
- In the filter list: Județ, Localitate, Domeniu profesional, then the rest. "Specialitate medicală" goes directly above "Documente necesare".
- Multi-select stays. Do not limit a list to one value.

## Measured (17 Oct export, 1,197 active postings)

`inf_profession_family` (parameter `family`): sănătate 375, tehnic 346, administrație 206, altele 66, social 65, financiar 55, educație 45, cultură 14, IT 9, juridic 8, ordine publică 8.

Inside `tehnic`, by the first digit of `occ_group` (parameter `cor`, prefix match): 7 → 102, 5 → 75, 8 → 63, 9 → 43, 2 → 40, 3 → 18, others 5.

## Constraints

- Do not rename or add a query parameter. Do not change `build_filters()`, `search_key()` or `validated_query()`. Each landing link uses parameters that exist.
- Do not change the pipeline or the export. The split of "Tehnic" on the landing page is a combination of two filters that exist.
- Each count uses the predicate of its link. Hide an item with count 0.
- Role tokens only. Do not change how a `.g2-` class looks. Do not edit the DS copy.
- Icons come from `@tabler/icons` 3.52.0, not modified (ADR 0006).

## Work

### 0. Two corrections from the review of the icon work

1. On the landing page, when an example title wraps, its count "(N)" drops to a line of its own. The count must follow the last word of the title on the same line. The same rule applies to a domain item. Keep a 44 px target without `inline-block` on the title link (for example with vertical padding on an inline link).
2. The last full Playwright run of the icon work had 3 failures that were then corrected, with no full run after. Run the full Playwright suite first and record it as the baseline.

### 1. Landing page — professional domains (`pages/home.php`, `static/home.js`)

Replace the sector grid with a grid of professional domains. Keep the item form of the icon brief follow-up: icon, name as a link, count in brackets outside the link. Keep the county select and the county map; they now change the counts and the links of these items.

Items, in this order after you sort by count (largest first; "Altele" last):

| Label | Parameters |
|---|---|
| Sănătate | `family[]=sănătate` |
| Administrație | `family[]=administrație` |
| Meseriași și muncitori calificați | `family[]=tehnic`, `cor[]=7` |
| Pază, îngrijire și servicii | `family[]=tehnic`, `cor[]=5` |
| Șoferi și operatori | `family[]=tehnic`, `cor[]=8` |
| Ingineri și tehnicieni | `family[]=tehnic`, `cor[]=2`, `cor[]=3` |
| Muncitori necalificați | `family[]=tehnic`, `cor[]=9` |
| Social | `family[]=social` |
| Financiar | `family[]=financiar` |
| Educație | `family[]=educație` |
| Cultură | `family[]=cultură` |
| IT | `family[]=IT` |
| Juridic | `family[]=juridic` |
| Ordine publică | `family[]=ordine publică` |
| Altele | `family[]=altele` |

- Verify each value against the data and against `build_filters()` (the exact stored spelling of each `family` value, and that `cor[]` matches by prefix). Report the count of "tehnic" postings that no item covers.
- The (county, item) counts in the embedded JSON must follow the same predicates. Build them so that the sum over the counties equals the item count for postings that have a county.
- Icons: reuse the sector icons where the meaning is the same (sănătate, educație, cultură, juridic → the justice icon, ordine publică, social → the social-care icon, altele). Choose Tabler icons for the others (administrație, the four "tehnic" groups, financiar, IT) and add their sources and symbols as the icon brief did. List each choice in the report.

### 2. Landing page — employer sector as a row

In the block of starting points, add a group "După angajator" in the same inline form as the other groups: each sector with a count above 0, as a link with its count (`sector[]=<value>`), in the order of the old grid. No icons in this row. Keep the sector symbols in the sprite only if something uses them.

### 3. Filter order — `partials/facets.php`

New order of the first groups: Județ, Localitate, Domeniu profesional, Instituție, Sector angajator, Nivel angajator, Grupă ocupațională, Ocupație. Move "Specialitate medicală" to directly above "Documente necesare". Do not change another group. Check the two-row filter bar (grid and table views at `lg`) with the new order.

### 4. Multi-select — `pages/list.php`

Cause: `#filter-form` sends a request on each `change`. The response replaces the result list and, out of band, the whole `#facet-list`. A second click during the request is lost, or the response of the first request removes it.

1. Wait before the request: the `change` trigger gets a delay of 500 ms that starts again at each new change. A text search keeps its own delay. A submit sends at once.
2. A new request replaces a request in flight (`hx-sync` on the form with the `replace` strategy). Verify the exact syntax against the vendored htmx 2.0.4.
3. Do not apply a stale response: when the reader changed a control of the form after the request was sent, cancel the swap of that response (results and out-of-band parts). The next request brings the current state.
4. While a request is pending, the controls stay enabled. Show the existing loading indicator.
5. The URL that is pushed is the URL of the response that is applied.
6. Measure before and after with a Playwright test that clicks three checkboxes of one group 150 ms apart, with a slowed response (route delay 800 ms): all three stay checked, the URL has the three values, and the result count is the count of the three. The test must fail on the current code. Report both results.

## Tests

- Update the checks of the landing grid (now professional domains) and add: each item count equals the result count of its link; the county selection updates the counts and the links; the "După angajator" row; the new filter order; the multi-select test of step 4.6 at 375 px (drawer) and 1280 px (sidebar, and the filter bar in grid view).
- Each new check must fail against a named mutation.
- Run and report: every PHP suite, the PHP lint, the full Playwright run, `npm run css`.
- Screenshots (landing at 1280 and 375 px; the list with the filter list open at 1280 px) in the scratch folder that the prompt names. Look at them.

## Files you may change

`webapp-php/pages/home.php`, `webapp-php/static/home.js`, `webapp-php/pages/list.php`, `webapp-php/partials/facets.php`, `webapp-php/inc/header.php` (sprite symbols only), `webapp-php/helpers.php` (new helpers only), `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`, `webapp-php/assets/icons/tabler/`, `webapp-php/tests/**`, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file.

## Done when

- All checks pass, and the report gives each command and its result, what you measured and what you assumed.
- `docs/backlog.md` has a new item: derive the professional domain from the COR code in the pipeline, so that the filter "Domeniu profesional" has no catch-all "Tehnic" (now only the landing page splits it). `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.

## Follow-up 1 (2026-11-17, pax) — landing details

The same rules, tests and "Done when" apply.

### Requirements

1. Examples: the count "(N)" stays with the title. It follows the last word of the title on the same line and never starts a line alone (step 0.1 of this brief; the owner reported it again with a screenshot). Bind the count to the last word so that they wrap together.
2. Figures panel: remove the link "Mai multe statistici" below the chart. Put a link "Statistici →" at the end of the row of the three figures, to `/statistici/`, with a Tabler chart icon before the text (for example `chart-bar`; `@tabler/icons` 3.52.0, not modified, decoration). The row must still fit at 320 px: the link can wrap below the figures.
3. County selects (both): the first option is "Toate județele", with no count. Each county option keeps its count. Check that the hero form keeps the search field, the select and the button on one row at 1280 px.
4. Starting-point groups ("După studii", "După ocupație", "După angajator", "Alte filtre"): the label stays inline before the first links. When the links wrap, each next line starts at the left edge, under the label, not indented. Reduce the line height inside a group, so that a group of two lines is compact. Keep a target height of at least 24 px for each link and enough space between links that two targets do not touch (WCAG 2.5.8); say what you measured.
5. Backlog: add this item to `docs/backlog.md`, open, in the UX section:

   **UX-18 — Hungarian landing page.** A themed landing page for the postings that require the Hungarian language (`lang[]=hu`), with the interface in Hungarian. Each of these postings also gets a Hungarian translation made with an LLM, shown with a label that says it is an automatic translation, and with the Romanian original one click away. Purpose: readers who search in Hungarian, and search engines (`lang="hu"`, `hreflang`). Depends on the themed-landing template (`docs/briefs/2026-11-17-themed-landing-sanatate.md`). Open questions: decision D17 says "no English for beta" and does not mention Hungarian; the translation is pipeline work (cost for each posting, a new export column, a quality check by a Hungarian speaker); 2 active postings required Hungarian in the 17 Oct export and 5 in the 9 Sep export, so measure the volume over time before the build.

### Tests

Add or update checks (each seen failing against a mutation) for: the count never starts a line alone at 320, 375 and 1280 px (compare the top of the count with the top of the last line of the title); the "Statistici →" link is in the figures row and the old link is gone; the first option of each county select has no bracket; a wrapped group line starts at the left edge of the group.

## Follow-up 2 (2026-11-17, pax) — hero and domain grid

The same rules, tests and "Done when" apply.

1. Hero: after "Toate anunțurile (N)", on the same line, add a link "După domeniu" that goes to the section of professional domains on the same page (`#domenii`). The target section must not hide under anything after the jump, and it must take the keyboard focus position (`tabindex="-1"` on the target, or the DS method for a focus target).
2. Domain grid: make the name and the icon of each item a little larger (name about one step up in the type scale; icon 32 px). The count keeps its size. Keep one line for each item at 1280 px; check 320 px for overflow.
3. Main title: "Anunțuri de angajare din administrația publică" fits on one line at 1280 px. Make it a little smaller for that, and let the title use the width that it needs (it can extend over the figures column, or the hero columns can change proportion). At narrow widths it wraps as now. Do not change a `.g2-` class; a local size on this heading is acceptable.

Tests (each seen failing against a mutation): the "După domeniu" link points to an id that exists and the section is at the top of the viewport after a click; the `<h1>` is one line high at 1280 px; a domain icon renders at 32 px.

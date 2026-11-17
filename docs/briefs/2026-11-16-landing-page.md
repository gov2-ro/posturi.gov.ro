# Brief — Landing page, navigation and footer

Owner: Sonnet builds; Opus reviews. Date: 2026-11-16. Baseline: `d7c4c68`.

Read first:

- `docs/decisions/0004-landing-route.md` (the route rule).
- `docs/decisions/0001-saved-search-identity.md` (the search key).
- `docs/specs/2026-11-16-ui-overhaul/landing-wireframe.dc.html` (the design that the owner revised). It is a wireframe: it gives the structure, the order and the Romanian copy. Its colours, its font and its sample numbers are not the target.
- `docs/specs/2026-11-16-ui-overhaul/README.md` §2 (data findings).

## Goal

Bare `/` shows a landing page. The list moves no URL: `/?…` stays the list, and `/posturi/` shows the unfiltered list. The masthead navigation and the footer get the structure of the design.

## Decisions (made by the owner, 2026-11-16)

- The route rule is ADR 0004.
- Remove the redirect to the default saved search on bare `/` (UX-15). Do not add a saved-searches row.
- The chart shows weekly counts only. Do not build the "3 luni / 3 ani" switch.
- The sector tiles have no icon in this brief. A later brief adds the icons.

## Constraints

- The edge caches the HTML. The server output is the same for each reader. Personal state stays in `localStorage`.
- Do not rename a query parameter. Do not add a query parameter.
- No JS build step, no CDN, no new dependency.
- Templates use the role tokens and the five semantic families only. No raw Tailwind palette class and no hex literal in a template or in `app.css` outside the `:root` block.
- Do not add a token. Do not change a skin file. Do not edit `static/skins/gov2.css`.
- Each count on the page uses the predicate of its link: `build_filters()` under the default status, as the `$count_for` closure in `pages/list.php:144` does.
- Hide a tile, a chip or a card when its count is 0.
- Format a count with `number_format($n, 0, ',', '.')` and the plural helpers (`postings_label()`).
- Romanian copy: use the strings of this brief exactly. Typographic quotes are „…”.
- Each link target is at least 44 px high. No horizontal scroll at 320 px.
- Do not implement the wireframe annotations: the section tags ("1 · HERO + CIFRE"), "[date ilustrative]", "[ilustrativ, …]".

## Mapping from the wireframe to the tokens

| Wireframe | Token |
|---|---|
| white ground | `page` / `surface` |
| `#f2f2f2` | `sunken` |
| `#c8c8c8` border | `line` |
| `#8a8a8a` border | `line-strong` |
| `#1c1c1c` text | `ink` |
| `#555555` text | `ink-muted` |
| `#1d4f91` link, chart, map | `gov` |
| dark header | `gov-bar`, `on-bar` |

Use the heading font and the radius that the other pages use. The wireframe uses radius 0 and Inter; ignore both.

## Work

### 1. Routes — `webapp-php/index.php`

1. `/` with no parameter (after `org_htmx_cache-buster` is removed, and when the request is not an HTMX fragment) renders `pages/home.php`.
2. `/` with one or more parameters, or an HTMX fragment request, renders `pages/list.php` as now.
3. `/posturi/` (and `/posturi`) with no parameter renders `pages/list.php`.
4. `/posturi/?…` with one or more parameters redirects with 301 to `/?…` (same query string).
5. When an HTMX fragment request has an empty search key (`search_key()` returns `''`) and no `sort`, `status` or `page` parameter, send the header `HX-Push-Url: /posturi/`. Verify that the vendored `static/htmx.min.js` honours this header. If it does not, stop and report.
6. Add `/posturi/` to `pages/sitemap.php`.

### 2. List page — `pages/list.php`, `partials/result_list.php`

1. Remove the landing extras: `$shortcuts`, `$home_new`, `$home_soon`, their queries (`list.php:128-201`) and their markup (`list.php:759-772`, `result_list.php:219-252`). Move the two lists to the landing page (step 3.5).
2. The canonical path of the unfiltered list is `/posturi/`.
3. "Șterge tot", the empty-state links and each other link that means "all postings" point to `/posturi/`. Search `webapp-php/` for `href="/"` and decide each one: the logo and "home" links stay `/`; "all postings" links become `/posturi/`. List each change in the report.
4. Check the inline script of `list.php` for code that assumes the path `/` (feed link sync, follow group, saved-search toggle). It must work on `/posturi/` too.

### 3. Landing page — `webapp-php/pages/home.php` (new)

Set `$page_title`, `$meta_description`, `$canonical_path = '/'`. Keep the feed `<link>` elements of the unfiltered list in the head. Sections, in this order:

#### 3.1 Hero and figures (two columns from `lg`, one column below)

Left column:

- `<h1>`: "Posturile din sectorul public, mai ușor de găsit"
- Lead: "Adunăm zilnic anunțurile de pe posturi.gov.ro. Le poți filtra după domeniu, județ, studii și salariu."
- A form, `method="get" action="/"`: a search input `name="q"` (visually hidden label "Caută un post", placeholder "Caută: asistent medical, consilier, șofer…"), a select `name="judet[]"` (visually hidden label "Județ", first option "Toate județele" with an empty value, then the 42 counties from `judet_names()` in Romanian alphabetical order), and a submit button "Caută". A submit with both fields empty must show the unfiltered list. Decide how (for example: the script removes empty fields; without a script the server redirects a request whose parameters are all empty to `/posturi/`), and say what you chose.
- A link to `/posturi/`: "Vezi toate cele N posturi active" (use `postings_label()` for the plural form; adapt the words so that the sentence is correct for each N).
- A muted line: "Date actualizate: {`build_stamp_relative()`}. Proiect independent. Nu este un site oficial al Guvernului României."

Right column, one bordered panel, visually hidden `<h2>` "Cifre":

- Three figures, each a link: active postings ("posturi active", to `/posturi/`); employers with an active posting ("angajatori", to `/angajatori/`); published in the last 7 days ("noi în 7 zile"). For the third link, use an existing filter if one selects exactly these postings. If none does, link to `/posturi/`.
- A chart: postings published in each of the last 12 complete ISO weeks. Source: `published_at` in `job_postings` and in `archived_postings`. First measure whether the two tables share ids, and count each posting once. Render it on the server as an inline `<svg role="img" aria-label="…">` with a filled area and a line (`gov` at low opacity, `gov`). Caption: "Posturi publicate pe săptămână". Below the chart: the first week and the last week as dates, and "min {a} · max {b} · ultima {c}". Do not show the chart when fewer than 4 weeks have data.
- A link "Mai multe statistici" to `/statistici/`.

#### 3.2 Căutări gata făcute

`<h2>` "Căutări gata făcute". A grid of link cards. Each card: a title, a row of small tags, and the count ("N posturi" with `postings_label()`). Build each `href` as `'/?' . search_key($params)` so that the URL is the canonical form.

| Title | Parameters | Tags |
|---|---|---|
| Salarii peste 7.000 lei, în afara sectorului medical | `salary_bucket=peste-7000`, `isced_not[]=09_sanatate_asistenta_sociala` | „peste 7.000 lei”, „fără Sănătate și asistență socială” |
| 5.000–7.000 lei, normă parțială, pe proiecte PNRR sau europene | `salary_bucket=5000-7000`, `schedule[]=norma_partiala`, `funding[]=pnrr`, `funding[]=fonduri_europene` | „5.000–7.000 lei”, „normă parțială”, „PNRR”, „fonduri europene” |
| Magyar nyelvtudást igénylő állások | `lang[]=hu` | „necesită limba maghiară” |
| Primul job: fără experiență cerută | `exp_level[]=fara` | „fără experiență” |
| Funcții de conducere | the `level` value that the facet "Funcție" uses for management | „conducere” |
| Proiecte cu fonduri europene, pe perioadă determinată | `funding[]=fonduri_europene`, `duration[]=determinata` | „fonduri europene”, „perioadă determinată” |

- Verify each parameter value against `build_filters()`. If a value is not valid, stop and report.
- The Hungarian card has `lang="hu"` on the title only, and a small flag of Hungary before the title. Put the flag in a new file `webapp-php/static/flags/hu.svg` (three bands: `#CD2A3E`, `#FFFFFF`, `#436F4D`) and show it with `<img alt="" width="30" height="20">`. The file is an asset, so the hex rule for templates does not apply to it.

#### 3.3 Domains and counties (two columns from `lg`)

Visually hidden `<h2>` "Posturi pe domenii și județe". The section has the id `domenii`.

Left: a label "Județ" with a select (first option "Toate județele"), then the grid of sector tiles.

- One tile for each value of `EMPLOYER_SECTOR_LABELS` with a count above 0: the label and "N posturi". Order by the count for all counties, largest first; "Altele" is last. The order does not change when the reader selects a county.
- The tile links to `/?sector%5B%5D=<value>`.

Right: the caption "Posturi active pe județe", a tile map of the 42 counties, and a legend.

- Each county is a `<button type="button">` with its code as text (`B`, `CJ`, …), `aria-label` and `title` "{name}: N posturi", and `aria-pressed`. Take the code, the column and the row of each county from the array `C` in the wireframe script. Map each code to its county slug. Verify that all 42 slugs exist in `judet_names()`.
- Four steps by the count of active postings: 0–19, 20–39, 40–79, 80+. Use `gov` at four opacities for the fill. Use `ink` for the text on the first three steps and `on-gov` on the last. Compute the contrast of the text on each step for each skin (hartie, posturi, govuk, gov2). Each must be at least 4.5:1. Report the measured ratios. If a step fails, change the opacities, not a token.
- The selected county has a visible ring that does not depend on colour alone.

Behaviour (new file `webapp-php/static/home.js`, loaded with `defer` and a `filemtime` version):

- The server embeds one JSON block with the count of active postings for each (county, sector) pair. Use one `GROUP BY` query, under the same open-status rule as the tile counts. Verify on the fixture that the sum for a sector over the counties equals the tile count; if the predicate of `build_filters()` for `sector` differs from a plain column match, use the predicate.
- A click on a map button selects that county. A second click on the same button clears the selection. The select and the map stay in sync.
- With a county selected: each tile shows the count for that county; a tile with 0 is hidden; each tile `href` becomes the canonical URL for `judet[]` and `sector[]` (use `PosturiSearches.searchKey` from `static/searches.js` if it gives the canonical string; else build the same string by hand).
- Announce the change in a polite live region: "{Județ}: N posturi active".
- Without JavaScript the select and the map are hidden (`hidden` attribute that the script removes). The sector tiles work without JavaScript.

#### 3.4 Alte puncte de pornire

`<h2>` "Sau începe de aici". Four groups, each an `<h3>` and a wrapping row of chips. A chip is a link with a label and a count.

- "După studii": each study level in the order of `STUDIES_LABELS`, parameter `studies_level[]`.
- "După ocupație": the 8 `occ_canonical` values with the most active postings, parameter `occupation[]`.
- "După județ": the 6 counties with the most active postings, parameter `judet[]`; then a chip "Toate județele" that links to `#domenii`.
- "Alte filtre": Fără experiență (`exp_level[]=fara`), Salariu peste 7.000 lei (`salary_bucket=peste-7000`), Funcții de conducere, Perioadă determinată (`duration[]=determinata`), Normă parțială (`schedule[]=norma_partiala`), Fonduri europene (`funding[]=fonduri_europene`, `funding[]=pnrr`).

Do not offer "Telemuncă" or "Funcție publică".

#### 3.5 Noi și pe terminate

`<h2>` "Noi și pe terminate". Two columns: "Noi azi" and "Se închid în 3 zile". Move the queries and the row markup from the list page without a change of rule, labels or link targets. Put the shared row markup in one partial if both pages need it; else keep it in `home.php`.

#### 3.6 Notificări (a sunken, bordered block)

- `<h2>`: "Abonează-te la notificări"
- Lead: "Alerte pe email și RSS pentru orice combinație de filtre / căutări."
- Three cards, each with a sprite icon (`i-mail`, `i-calendar`, `i-feed`), an `<h3>`, one sentence and one link:
  - "Email" — "Un rezumat la 3 zile cu posturile noi din căutarea ta." — "Cum funcționează alertele" → `/alerte/`
  - "Calendar" — "Termenele de depunere și probele de concurs, în Google Calendar, Apple sau Outlook." — "Calendarul tuturor posturilor" → the `.ics` feed of the unfiltered list (`feed_url('posturi.ics')`)
  - "RSS" — "Un flux pentru cititorul tău de știri, pentru orice căutare." — "Fluxul tuturor posturilor" → `feed_url('posturi.rss')`
- A last line: "Poți și să salvezi posturi și căutări în browser. Le găsești la Salvate." with "Salvate" as a link to `/salvate/`.

#### 3.7 Instrumente

`<h2>` "Instrumente". Two link tiles:

- "Pot aplica?" — "Verifică dacă îndeplinești condițiile unui post." → `/pot-aplica/`
- "Pentru angajatori" — "Asistent pentru redactarea unui anunț de angajare." → `/pentru-angajatori/anunt/`

### 4. Styles — `webapp-php/assets/app.css`

Add these component classes in `@layer components`, built on the role tokens. Put a comment next to each class with the name of the DS component that replaces it later.

- `.pg-tile` — a bordered box that holds a figure, a link group or a summary (later `.g2-tile`).
- `.pg-chip`, `.pg-chip-count` — a shortcut link with a count (later `.g2-chip`, `.g2-chip-count`).
- `.pg-map` and its cell — the county tile map (local; the DS has no such component).

Use Tailwind utilities with tokens for the rest. Run `npm run css` and include `static/app.css` in the change.

### 5. Chrome — `inc/header.php`, `inc/footer.php`

1. Remove the UX-15 redirect script (`header.php`, the script that tests `location.pathname==='/'`). Search for other code that reads `posturi.searchRedirect` or `posturi.searchApplied`. Remove code that only the redirect used. Report what you removed and what you kept.
2. Navigation, in this order: Posturi (`/posturi/`), Salvate (`/salvate/`, keep the `data-saved-count` element), Alerte (`/alerte/`), Statistici (`/statistici/`), Despre (`/despre/`). All five links are visible at each width; the row wraps at 320 px. Keep the logo, the beta label and the "Bază generată" line.
3. Footer: three groups of links, each with a small heading, then the existing disclaimer paragraph (keep its text, the build time and the source label), then the skin picker. Keep the feedback panel, the live region and the undo notice.
   - "Posturi": Toate posturile (`/posturi/`), Angajatori, Statistici, Pot aplica?
   - "Urmărește": Alerte pe email (`/alerte/`), Flux RSS, Calendar, Salvate
   - "Proiect": Despre și metodologie, Confidențialitate (`/despre/#confidentialitate`), Cod sursă (the GitHub link), Pentru angajatori (`/pentru-angajatori/anunt/`)

## Tests

Each new test must fail against a mutation before you accept it. Name the mutation in the report.

PHP — new suite `webapp-php/tests/home_test.php`:

- `/` with no parameter renders the landing page; `/?sort=deadline` renders the list.
- `/posturi/` renders the unfiltered list; `/posturi/?q=x` gives 301 to `/?q=x`.
- An HTMX fragment request with an empty search key sends `HX-Push-Url: /posturi/`.
- For each sector tile, each chip and each ready-made search: the count on the page equals the number of results of the link.
- An item with count 0 is not on the page.
- The (county, sector) JSON sums to the tile counts.
- The chart is not rendered with fewer than 4 weeks of data.

Update the existing suites that expect the old behaviour of `/` (`discovery_test.php`, `request_test.php`, `searches_test.php`, others that you find). Do not delete a check of list behaviour: point it to `/posturi/`.

Playwright — new spec `webapp-php/tests/browser/home.spec.js`, at 320, 375 and 1280 px:

- The seven sections are present in order; the page has no horizontal overflow.
- A click on a county button updates the tile counts and the tile links; a second click clears it; the select follows.
- A sector tile opens a list whose count equals the tile count.
- On the list, "Șterge tot" leaves the URL `/posturi/`, and a reload shows the list.
- With a saved search in `localStorage`, bare `/` shows the landing page (no redirect).
- The five navigation links are visible at 320 px.

Update the specs that open `/` to get the list (`discovery`, `searches`, `compact`, `chrome`, `browser`, `feeds`, others that you find).

Run, and report the output of each:

- every PHP suite in `webapp-php/tests/` (from inside `webapp-php/`);
- the PHP lint (`find . -name '*.php' -print0 | xargs -0 -n1 php -l`);
- `npx playwright test --config webapp-php/tests/browser/playwright.config.js`;
- `php webapp-php/assets/check-skins.php` (69 known problems for `gov2.css`; report a change of that number);
- the `Server-Timing` total of `/` with `POSTURI_DB=data/_dev/posturi-new.sqlite` (median of 5 requests). If it is above 100 ms, stop and report before you optimise.

Add the new suite to `.github/workflows/ci.yml`.

## Files you may change

- `webapp-php/index.php`, `webapp-php/pages/home.php` (new), `webapp-php/pages/list.php`, `webapp-php/pages/sitemap.php`
- `webapp-php/partials/result_list.php`, and one new partial for the home lists
- `webapp-php/inc/header.php`, `webapp-php/inc/footer.php`
- `webapp-php/helpers.php` (new helpers only; do not change `build_filters()`, `search_key()` or `validated_query()`)
- `webapp-php/assets/app.css`, `webapp-php/static/app.css` (built), `webapp-php/static/home.js` (new), `webapp-php/static/flags/hu.svg` (new)
- other `webapp-php/pages/*.php` and `webapp-php/static/*.js`: only to change an "all postings" link from `/` to `/posturi/`, or to remove code that only the redirect used
- `webapp-php/tests/**` (fixtures included), `.github/workflows/ci.yml`
- `README.md` (the route list, if it has one), `docs/backlog.md`, `docs/activity-log.md`

Stop and ask before you change any other file, a token, a skin, a `.g2-` class, a query parameter or an ADR.

## Out of scope

- Sector icons, the "3 ani" chart range, a saved-searches row.
- The list page beyond step 2. The detail page. The secondary pages.
- DS alignment. The export script. A deploy.

## Done when

- All checks above pass, and the report gives each command and its output.
- The report says what you measured and what you assumed.
- `docs/activity-log.md` has an entry `### 2026-11-16 — Landing page, /posturi/, navigation and footer (not deployed)` with **Done / Hard / Decided** and the measured numbers.
- `docs/backlog.md`: UX-05 says what is done and what is open (sector icons, long chart range after FIX-09, profession hubs); UX-15 notes that the redirect is removed.
- Do not commit. Do not deploy. Opus reviews first.

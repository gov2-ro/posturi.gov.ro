# Brief — Themes `/administratie/` and `/financiar/`, domain links, occupation chips on the list

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: commit `e16f235`.

Status: **ready, not started.** This is brief 1 of 4. Brief 2 (`2026-11-17-pipeline-domain-trade.md`), brief 3 (`2026-11-17-theme-meserii.md`) and brief 4 (`2026-11-17-theme-social.md`) come after it.

Read first: `docs/briefs/2026-11-17-themed-landing-sanatate.md`, `docs/decisions/0004-landing-route.md`, `docs/decisions/0005-gov2-ds-only.md`, `docs/decisions/0006-icon-set.md`, `webapp-php/inc/themes.php` and `webapp-php/pages/theme.php` as built, and the chip section of `COMPONENTS.md` in the DS.

## Goal

1. Two more themed pages: administration (`/administratie/`) and finance and accounting (`/financiar/`). The template gets one new rule.
2. On the landing page, a professional domain links to its themed page when one exists.
3. A list with more than one page of results offers its most frequent occupations as chips above the results.

## Decisions (owner, 2026-11-17)

- Themed pages: Administrație, Financiar, Meserii și muncitori (brief 3), and one page for Social and Educație (brief 4). No page for Ingineri, Tehnicieni, Cultură, IT, Ordine publică, Juridic or Altele: each has too few postings or no section of its own.
- A landing domain item links to its themed page when one exists.
- Order: this brief first, because the data of these two domains is good. The pipeline brief (2) comes before Meserii (3).
- Occupation chips on the list, with the rules of step 5.

## Measured (live site, 2026-11-17, 1,638 active postings)

From the filter counts of the list of each domain:

- **Administrație, 280.** Tip funcție: conducere 57, execuție 219. Instituție: primărie 62, spital 56, școală 39, universitate 26, militar 20, cultură 11, agenție 10. Domeniu: administrație publică 170, educație și cercetare 67, sănătate publică 66, economie și finanțe 44, achiziții publice 30. Ocupații: Referent de specialitate 32, Secretar 23, Referent 23, Registrator medical 21, Inspector de specialitate 19, Administrator 18, Expert 12, Consilier 12, Șef serviciu 10.
- **Financiar, 93.** Tip funcție: conducere 17, execuție 76. Instituție: școală 32, spital 19, cultură 10, militar 9, primărie 8. Ocupații: Administrator financiar 33, Economist 25, Contabil 5, Director financiar-contabil 4, Contabil-șef 4. Skills: "FOREXEBUG" 15 and "Forexebug" 7, EDUSAL 7, "programe de contabilitate" 6.

## Constraints

- Do not rename or add a query parameter. Do not change `build_filters()`, `search_key()`, `validated_query()` or `home_merge_params()`.
- Each link on a themed page is the base filter plus one more filter. Each count uses the predicate of its link. Hide an item with count 0.
- The server output is the same for each reader (edge cache).
- Role tokens only. Use a DS component when one fits (`.g2-chip`, `.g2-chip-count`). Do not edit the DS copy. Do not change how a `.g2-` class looks.
- The template knows no theme by name. A new theme is configuration only, plus the template rule of step 1.

## Work

### 1. Template rules — a label list ordered by count, labels in a select (`pages/theme.php`, `inc/themes.php`)

Today a `values` section with `source => 'labels'` keeps the order of its `labels`. Add the optional key `order => 'count'`: the items then sort by count, largest first, and the optional `limit` applies after the sort. The label text comes from `labels` (value → text). Document the key in the comment block of `inc/themes.php`.

Also allow `labels` (value → text) on an entry of `search_selects`, so that a select shows a readable text and not a stored value. An option without a label shows the value, as today.

### 2. Theme `administratie` (`inc/themes.php`)

- `label` "Administrație", `icon` `i-domain-administratie`, no `icon_file`.
- `base`: `family => ['administrație']`. Verify the stored spelling.
- `title` and `h1`: "Posturi în administrație". `all_label`: "Toate anunțurile din administrație". Write a meta description in the form of the health one.
- `history`: family `administrație`; groups, in this order: "Conducere" (prefixes `Șef`, `Director`, `Manager`, `Secretar șef`), "Referent" (prefix `Referent`), "Inspector" (prefix `Inspector`), "Secretar" (prefix `Secretar`). Check against the data that each group has postings in the 12 weeks, and report the counts.
- `search_selects`: Ocupație (`occupation`, column `occ_canonical`) and Instituție (`inst`, column `employer_kind`, labels `EMPLOYER_KIND_LABELS`).
- Sections, in this order:
  1. `stats`.
  2. "Tip funcție": `level`, source `labels`, `order => 'count'`. The keys of `labels` are the stored values of `job_level`. Verify them in the data: the filter compares `j.job_level IN (…)`, and the live facet shows "Funcții de conducere". The texts are "Funcții de conducere" and "Funcții de execuție".
  3. "Ocupații": `occupation`, column `occ_canonical`, limit 12.
  4. "Angajatori": `inst`, source `labels` with `EMPLOYER_KIND_LABELS`, `order => 'count'`.
  5. "Domenii de activitate": `domain`, source `labels` with `POLICY_DOMAIN_LABELS`, `order => 'count'`, limit 8.
  6. `counties`.
  7. "Studii": `studies_level`, labels `STUDIES_LABELS`.
  8. `recent`, then `follow`.
- No `other` line.

### 3. Theme `financiar` (`inc/themes.php`)

- `label` "Financiar", `icon` `i-domain-financiar`.
- `base`: `family => ['financiar']`.
- `title` and `h1`: "Posturi financiar-contabile". `all_label`: "Toate anunțurile financiar-contabile". Meta description in the same form.
- `history`: family `financiar`; groups in this order: "Conducere" (values `Contabil-șef`, `Contabil șef`; prefixes `Director`, `Șef`, `Manager`), "Administrator financiar" (prefix `Administrator financiar`), "Economist" (prefix `Economist`), "Contabil" (prefix `Contabil`). An occupation is in the first group that holds it, so "Contabil-șef" is in "Conducere".
- `search_selects`: Ocupație, and Instituție as in step 2.
- Sections, in this order: `stats`; "Tip funcție" (as step 2); "Ocupații" (limit 12); "Angajatori" (as step 2); "Programe informatice cerute" (see below); `counties`; "Studii"; `recent`; `follow`.
- "Programe informatice cerute": `skill`, source `labels`, `order => 'count'`. Measure the values of `v3_skills` inside the theme. Propose the list: the values that name a software product or a public IT system, with 3 or more postings. Expected: FOREXEBUG, EDUSAL, SIIIR. Do not include the generic office programs (Excel, Word, Office, "Operare PC"). Verify that the `skill` filter matches "Forexebug" when the value is "FOREXEBUG". SQLite `LIKE` ignores the case of ASCII letters, so it should match. The section count must equal the result count of its link. Report the list before you configure it, with the counts.

### 4. Landing page — a domain links to its theme (`pages/home.php`, `static/home.js`)

- New optional theme key `domains`: a list of `HOME_DOMAINS` keys. A domain item whose key is in the `domains` of a theme links to `/{slug}/`. The count of the item does not change. Other items keep the link to the list. Set `domains => ['sanatate']`, `['administratie']` and `['financiar']`. A domain key must be in one theme at most: a test checks it. Briefs 3 and 4 put more than one key in one theme, so do not match by equal parameters.
- When the reader selects a county, a theme link becomes `/{slug}/#{county}`. The themed page reads the county from the hash (`static/home.js`, `hashCounty()`). A list link keeps the `judet[]` parameter, as today.
- The footer group "Pagini tematice", the sitemap and `/llms.txt` read `themes()`. Check that the two new pages appear, and change nothing there.

### 5. Occupation chips on the list (`pages/list.php`)

Show a row of chips between the row of active filters and the first result.

- **When:** all of these conditions are true:
  - The result count is greater than one page (`$PAGE_SIZE`, 25).
  - No `occupation[]` filter is active.
  - At least 2 occupations have 2 or more postings each.
  - The largest occupation has 90 % of the results or less.
- **What:** the occupations of the filter "Ocupație" (`$occupation_options`, already computed for the filter list), largest first, at most 8, each with 2 or more postings. Each chip is a link with its count: `.g2-chip` with `.g2-chip-count`. The link is the current filter plus `occupation[]=<value>`, with no `page` parameter. Do not add a query.
- **Last item:** the link "Toate ocupațiile" opens the filter "Ocupație" in the filter list. At `lg` and wider the filter list is the sidebar; below `lg` it is the drawer. With no script, the link goes to the anchor of the group.
- **Label:** "Ocupații frecvente", visible, before the chips, as one run of inline text that wraps. Give the row a name that a screen reader announces (for example `aria-labelledby` on the label).
- **Count rule:** a chip count is the count of the filter list, which leaves out the occupation filter. So the chip count equals the result count after the click. Test it.
- **Requests:** a chip click goes through the same request as a filter link of the list (`#filter-form`, `hx-sync`, the stale-response guard). The pushed URL is the URL of the applied response.
- The chip row is part of the result region, so a filter change updates it too.
- Measure the `Server-Timing` median of `/posturi/` and of one filtered list on `data/_dev/posturi-new.sqlite`: 5 requests each, before and after. Stop and report if the median grows by more than 5 ms.

## Tests

- `tests/theme_test.php`:
  - Both themes render.
  - Each count equals the result count of its link, including the "Tip funcție", "Angajatori", "Domenii de activitate" and "Programe" items.
  - `order => 'count'` sorts by count. Mutation: remove the sort.
  - A select with `labels` shows the text.
  - Both slugs are in the sitemap and in `/llms.txt`.
- `tests/home_test.php`:
  - The Administrație and Financiar items link to their themes.
  - Every other domain item links to the list.
  - With a county, the theme link has the hash.
- A new PHP suite for the chips, or an extension of an existing one:
  - Each condition of step 5 has a positive case and a negative case.
  - A chip count equals the result count of its link.
  - The row is absent when `occupation[]` is set.
- Playwright:
  - `theme.spec.js` covers the two new slugs at 320, 375 and 1280 px.
  - A chips spec at 375 and 1280 px:
    - The row is visible on `/?family[]=tehnic&cor[]=5`.
    - A click on a chip gives a list whose count equals the chip count.
    - "Toate ocupațiile" opens the group, both in the drawer and in the sidebar.
    - No horizontal overflow at 320 px.
- Each new check must fail against a named mutation.
- Run and report:
  - every PHP suite
  - the PHP lint
  - the full Playwright run
  - `npm run css`
  - the `Server-Timing` medians of step 5
  - the median of `/administratie/` and `/financiar/` (5 requests each; stop and report above 100 ms)
- Screenshots: both themes at 1280 and 375 px, and a list with chips at 1280 and 375 px. Put them in the scratch folder that the prompt names. Look at them.

## Files you may change

- `webapp-php/inc/themes.php`, `webapp-php/pages/theme.php`, `webapp-php/pages/home.php`, `webapp-php/static/home.js`, `webapp-php/pages/list.php`
- `webapp-php/partials/` (a new partial for the chip row)
- `webapp-php/helpers.php` (new helpers only)
- `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`
- `webapp-php/tests/**`
- `README.md` (route list), `docs/backlog.md`, `docs/activity-log.md`

Stop and ask before you change any other file.

## Done when

- All checks pass. The report gives each command and its result, what you measured and what you assumed.
- The report lists the near-duplicate occupation names that the chips show side by side on the lists of the 16 landing domains (for example "Îngrijitor copii" and "Îngrijitor de copii"). Brief 2 uses that list.
- `docs/backlog.md`:
  - Mark this work done.
  - Mark UX-05-THEME-LOCAL-ADMIN dropped, with the reason: it is replaced by the theme "administrație" of this brief, built on the professional domain, not on the employer.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.

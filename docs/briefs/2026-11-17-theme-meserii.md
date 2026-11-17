# Brief — Theme `/meserii/`: Meserii și muncitori

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: the tree after briefs 1 and 2.

Status: **blocked.** Start after brief 2, Parts A1 and A3, is reviewed.

Base filter: confirmed 2026-11-17 by the owner. The page uses today's filter, `family[]=tehnic` with `cor[]` 5, 7, 8 and 9. The `family` vocabulary does not change now (backlog UX-05-DOMAIN). The four COR groups are the same under the COR-based domain of brief 2, Part B.

Read first: briefs 1 and 2 of 2026-11-17, and `webapp-php/inc/themes.php` and `webapp-php/pages/theme.php` as built after brief 1.

## Goal

One themed page for the four groups of manual and service work: skilled workers, caretaking and services, drivers and operators, unskilled workers. It is the largest domain after health.

## Decisions (owner, 2026-11-17)

- One page, not four. The four groups are a section of the page.
- The four landing items of these groups link to the page (theme key `domains` of brief 1).

## Measured (live site, 2026-11-17)

| Group | Today's filter | Active | Main occupations | Main employers |
|---|---|---|---|---|
| Meseriași și muncitori calificați | `family[]=tehnic&cor[]=7` | 140 | Muncitor calificat 85 (trade lost; brief 2), Muncitor 13, Lăcătuș mecanic 5 | școală 33, spital 31, primărie 23, servicii publice 17 |
| Pază, îngrijire și servicii | `cor[]=5` | 129 | Îngrijitor 100, Bucătar 13 | școală 88 |
| Șoferi și operatori | `cor[]=8` | 59 | Șofer 35, Șofer autosanitară 9 | primărie 26, spital 15 |
| Muncitori necalificați | `cor[]=9` | 55 | Muncitor necalificat 24, Paznic 21, Portar 7 | școală 21, spital 12 |

The four groups have 383 active postings. Studies are mostly primary school or high school.

## Constraints

The constraints of brief 1 apply.

## Work

### 1. Template rule — a section narrows inside the base filter (`pages/theme.php`)

The base filter of this page has `cor` with 4 values. `home_merge_params()` merges the values of one parameter, so the link "COR 7" would give all four groups again.

- In a `values` section whose `param` is a key of the base filter, the link replaces the values of that key: base `cor` [5, 7, 8, 9] plus the item `cor` [7] gives `cor` [7].
- The count of the item uses the same parameters as its link.
- Do this in the template or in a new helper. Do not change `home_merge_params()`: the landing page uses it.
- Check what `theme_redirect_url()` does with `/meserii/?cor[]=7`. Propose a behaviour: replace, as in the section, or merge, as today. Stop and ask before you change it.

### 2. Template rule — a chart by COR group (`helpers.php`, `pages/theme.php`)

- New optional key `history.cor`: a list of COR prefixes. The archive part of the chart then takes the postings of `history.family` whose `occ_group` starts with one of the prefixes. The column comes from brief 2, Part A3.
- New optional key `prefixes_cor` for a group of the chart: the group holds the postings whose `occ_group` starts with one of these prefixes. The rule "the first group that holds it" stays.
- A theme without these keys renders as before. A test shows it with the health theme.

### 3. Theme `meserii` (`inc/themes.php`)

- `label` "Meserii și muncitori". `icon` `i-domain-meseriasi`. `domains => ['meseriasi', 'paza', 'soferi', 'necalificati']`.
- `base`: `family => ['tehnic'], cor => ['5', '7', '8', '9']`.
- `title` and `h1`: "Meserii și muncitori". `all_label`: "Toate anunțurile de meserii și muncitori". Write a meta description in the form of the health one.
- `history`: family `tehnic`, `cor` [5, 7, 8, 9]. Groups by COR, in this order: "Meseriași" (7), "Îngrijire și servicii" (5), "Șoferi" (8), "Necalificați" (9).
- `search_selects`: Ocupație and Instituție, as in brief 1.
- Sections, in this order:
  1. `stats`.
  2. "Categorii": `cor`, source `labels`, `order => 'count'`. The values are 7, 5, 8 and 9, with the labels of the landing items. Each item has the icon of its landing item, if the template can show a sprite icon in a section. If it cannot, show no icons and report.
  3. "Meserii": `occupation`, column `occ_canonical`, limit 16.
  4. "Angajatori": `inst`, labels `EMPLOYER_KIND_LABELS`, `order => 'count'`.
  5. `counties`.
  6. "Studii".
  7. `recent`, then `follow`.
- No `other` line.

## Tests

- The suites of brief 1 cover the new slug.
- Narrowing: the "Categorii" item "COR 7" links to `cor[]=7` and not to the four values, and its count equals the result count of its link. Mutation: use `home_merge_params()`.
- The chart by COR: the total of a week equals a direct count from `job_postings` and `archived_postings` with the same predicate. Mutation: drop the COR clause of the archive.
- The four landing items link to `/meserii/`, with the county hash when a county is selected.
- Each new check must fail against a named mutation.
- Run and report: every PHP suite, the PHP lint, the full Playwright run, `npm run css`, and the median of `/meserii/` (5 requests; stop and report above 100 ms).
- Screenshots at 1280 and 375 px, in the scratch folder that the prompt names. Look at them.

## Files you may change

The files of brief 1, except `pages/list.php`. Stop and ask before you change any other file.

## Done when

- All checks pass. The report gives each command and its result, what you measured and what you assumed.
- `docs/backlog.md`: UX-05-THEME-MESERII is done.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.

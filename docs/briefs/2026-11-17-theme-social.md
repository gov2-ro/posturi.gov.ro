# Brief — Theme `/social/`: Asistență socială și consiliere

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: the tree after brief 1 (`2026-11-17-themes-admin-financiar-chips.md`).

Status: **ready, not started.** Start after the review of brief 1. This brief does not depend on brief 2.

Read first: brief 1 of 2026-11-17, and `webapp-php/inc/themes.php` and `webapp-php/pages/theme.php` as built after it.

## Goal

One themed page for the professional domains `social` and `educație`. Together they are one audience: social workers, school counsellors, school mediators, psychologists, pedagogues and home carers.

## Decisions (owner, 2026-11-17)

- One page for both domains. Name: "Asistență socială și consiliere".
- The landing items "Social" and "Educație" both link to the page (theme key `domains` of brief 1).

## Measured (live site, 2026-11-17)

- **Social, 85.**
  - Ocupații: Asistent social 39, Mediator școlar 17, Psiholog 8, Îngrijitor la domiciliu 6.
  - Instituție: primărie 50, asistență socială 28.
  - Domeniu: asistență socială 81, fonduri europene 27.
- **Educație, 63.** These postings are not teacher posts.
  - Ocupații: Consilier școlar 32, Pedagog școlar 4, Educator 4, Profesor 3.
  - Instituție: primărie 36, asistență socială 8, școală 7.
  - Domeniu: asistență socială 52, educație și cercetare 51, fonduri europene 20.

Together the two domains have 148 active postings. Most of them are at town halls.

## Constraints

The constraints of brief 1 apply.

## Work

### 1. Template rule — a chart for more than one family (`helpers.php`, `pages/theme.php`)

`history.family` takes one family today (`theme_group_series()`). Let it also take a list of families. The archive part takes the postings of any of the families. A theme with one family string renders as before.

### 2. Theme `social` (`inc/themes.php`)

- `label` "Asistență socială și consiliere". Slug `social`. `icon` `i-sector-asistenta_sociala`. `domains => ['social', 'educatie']`.
- `base`: `family => ['social', 'educație']`. Verify the stored spellings. `family` values combine with OR (`IN (…)`).
- `title` and `h1`: "Posturi în asistență socială și consiliere". `all_label`: "Toate anunțurile din asistență socială și consiliere". Write a meta description that names the main occupations.
- `history`: families `social` and `educație`. Groups, in this order:
  - "Asistent social" (prefix `Asistent social`)
  - "Consilier școlar" (prefix `Consilier școlar`)
  - "Mediator" (prefix `Mediator`)
  - "Psiholog" (prefix `Psiholog`)

  Report the counts of each group in the 12 weeks.
- `search_selects`: Ocupație and Instituție, as in brief 1.
- Sections, in this order:
  1. `stats`.
  2. "Ocupații": limit 12.
  3. "Angajatori": `inst`, labels `EMPLOYER_KIND_LABELS`, `order => 'count'`.
  4. "Domenii de activitate": `domain`, labels `POLICY_DOMAIN_LABELS`, `order => 'count'`, limit 6.
  5. `counties`.
  6. "Studii".
  7. `recent`, then `follow`.
- `other` line: "Alte posturi la direcțiile de asistență socială". Its params are `inst => ['asistenta-sociala']` and `family_not => ['social', 'educație']`. Its note: "Infirmieri, îngrijitori, bucătari și alte posturi din centre și cămine." Measure the count first. Leave the line out and report when the count is below 10.

## Tests

- The suites of brief 1 cover the new slug.
- The chart for two families: the total of a week equals a direct count of the two families in `job_postings` and `archived_postings`. Mutation: use the first family only.
- The landing items "Social" and "Educație" link to `/social/`.
- A domain key is in one theme at most (the test of brief 1).
- Each new check must fail against a named mutation.
- Run and report: every PHP suite, the PHP lint, the full Playwright run, `npm run css`, and the median of `/social/` (5 requests; stop and report above 100 ms).
- Screenshots at 1280 and 375 px, in the scratch folder that the prompt names. Look at them.

## Files you may change

The files of brief 1, except `pages/list.php`. Stop and ask before you change any other file.

## Done when

- All checks pass. The report gives each command and its result, what you measured and what you assumed.
- `docs/backlog.md`: mark UX-05-THEME-EDUCATION dropped, with the reason: the "educație" postings are school counsellors and similar posts, not teacher posts, so they are part of this page.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.

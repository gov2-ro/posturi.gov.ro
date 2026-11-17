# Brief — Sector icons on the landing page

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: the working tree after `docs/briefs/2026-11-17-ds-alignment.md` (not committed; do not revert it).

Read first: `docs/decisions/0006-icon-set.md`, `docs/specs/2026-11-16-ui-overhaul/icons/README.md`.

## Goal

Each sector tile on the landing page shows its Tabler icon above the label.

## Constraints

- Use the SVG files in `docs/specs/2026-11-16-ui-overhaul/icons/tabler/` (`@tabler/icons` 3.52.0, not modified). Do not draw or change a path.
- One exception: for `mediu`, use the Tabler icon `trees` and not `leaf`, because `leaf` and the wheat ear of `agricultura` look alike. Get `trees` from the same package version. If the package has no `trees` outline icon, use `tree`, and say so.
- No icon font, no CDN, no new runtime dependency.
- Do not change how a `.g2-` class looks. Do not edit the DS copy.
- The icon is decoration: `aria-hidden="true"`, `focusable="false"`, colour from `currentColor`.

## Work

1. Move the 14 source files to `webapp-php/assets/icons/tabler/` (one file for each sector key), with the Tabler licence text as `LICENSE` in that folder. Record the package version in a one-line `README.md` there. `assets/` is not deployed, so the sources stay out of the web root.
2. Add one symbol for each sector to the inline sprite in `inc/header.php`, with the id `i-sector-<key>`. Keep the Tabler attributes (`viewBox="0 0 24 24"`, `fill="none"`, `stroke="currentColor"`, `stroke-width="2"`, round caps and joins).
3. Add a helper `icon(string $id, string $class = ''): string` in `helpers.php`. It returns the `<svg><use>` markup. Use it for the sector icons. Do not change `pref_controls()` or `follow_group_html()` in this brief.
4. In `pages/home.php`, show the icon above the label in each sector tile, 32 px, in the text colour. The tile must not change height when a county is selected. A sector with no icon in the map shows no icon and no gap error.
5. Check the sprite size: report the bytes that the 14 symbols add to each page.

## Tests

- `home_test.php`: each sector tile on the page has a `<use>` that points to a symbol that exists in the sprite; the icon is `aria-hidden`.
- `home.spec.js`: at 320 px and 1280 px each visible sector tile shows an icon with a rendered size of 32 px; no horizontal overflow.
- Each new check must fail against a named mutation.
- Run and report: every PHP suite, the PHP lint, the full Playwright run, `npm run css`.
- Save one screenshot of the landing page at 1280 px and one at 375 px (with `POSTURI_DB=data/_dev/posturi-new.sqlite POSTURI_TODAY=2026-10-17`) in the scratch folder that the prompt names, and look at them.

## Files you may change

`webapp-php/inc/header.php`, `webapp-php/helpers.php` (the new helper only), `webapp-php/pages/home.php`, `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`, `webapp-php/assets/icons/tabler/` (new), `webapp-php/tests/home_test.php`, `webapp-php/tests/browser/home.spec.js`, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file.

## Done when

- All checks pass, and the report gives each command and its result.
- `docs/backlog.md`: UX-05 says that the sector icons are done. `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.

## Follow-up 1 (2026-11-17, pax) — landing copy and layout

Do this after the icon work. The same rules, tests and "Done when" apply. Keep each `id`, `data-` hook and script behaviour (the county select and the map still update the sector counts and links).

### Requirements

Hero:

1. The `<h1>` becomes "Anunțuri de angajare din administrația publică". Set the page title of the landing page to the same words. Keep a meta description that says what the site does.
2. Remove the lead paragraph ("Adunăm zilnic anunțurile de pe …").
3. Replace "Vezi toate cele N posturi active" with a link "Toate anunțurile" to `/posturi/`, followed by the count in brackets, "(1.197)". The count is outside the link and in `ink-muted`.
4. Remove the update date from the hero (the header shows it). The last line of the hero becomes: "Proiect independent. Preluăm datele de pe posturi.gov.ro. **Nu este un site oficial** al Guvernului României." — "posturi.gov.ro" is a link to `https://posturi.gov.ro`, and "Nu este un site oficial" is bold.

Exemple de căutare:

5. Hide the section heading visually (keep it for assistive technology).
6. Write the count in brackets after the title, in `ink-muted`, outside the link: "Funcții de conducere (81)". Remove the separate count line.
7. Put the tags close below the title. Make the section more compact: less space between the items and inside each item.

Domenii:

8. A sector item has no box and no background. It is: the icon, the sector name as a link, then the count in brackets in `ink-muted`, outside the link: "Sănătate (441)". Remove the words "de posturi" / "posturi". Do not use `.g2-tile` here. Keep the grid and a target height of 44 px. With a county selected, the count in brackets changes and an item with 0 is hidden, as now.

Alte puncte de pornire:

9. Hide the heading "Sau începe de aici" visually.
10. Each group is one wrapping line: the group label ("După studii", "După ocupație", "Alte filtre") first, in bold, then its links with counts on the same line.
11. Remove the group "După județ".

Chrome:

12. Logo: no gap between "posturi" and ".gov2.ro". It reads as one word, "posturi.gov2.ro".

Open Graph image:

13. Update `webapp-php/assets/og-image.html` and render `webapp-php/static/og-image.png` again (1200 × 630) in the gov2 DS look: the logo, the headline "Anunțuri de angajare din administrația publică" and the line "Proiect independent. Nu este un site oficial al Guvernului României." Find how the image was rendered before (README or activity log) and use the same method. Look at the result.

### Tests

Update the checks that name the old strings or the old tile markup. Add or keep checks (each seen failing against a mutation) for: the new `<h1>`; the count outside the link in the three places (all postings, an example, a sector); no "După județ" group; the sector count still updates when a county is selected; no horizontal overflow at 320 px.

### Files

As above, plus `webapp-php/inc/header.php` (the logo), `webapp-php/assets/og-image.html`, `webapp-php/static/og-image.png`, and the tests that name the old strings (`webapp-php/tests/**`).

### Follow-up 1, addition (2026-11-17, pax) — counts in the county select

14. Each option of the two county selects (the hero search form and the "Județ" select of the domains) shows the count of active postings in brackets: "Alba (15)", and "Toate județele (1.197)" for the first option. Use the counts that the map uses, so that a county has one number on the page. Keep the alphabetical order and each option value. A county with 0 stays in the list, as "(0)". Add a check: the count in an option equals the count of that county on the map.

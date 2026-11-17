# Brief — Landing tweaks, remove skins, align to the gov2 DS

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: `d7c4c68` plus the uncommitted work of `docs/briefs/2026-11-16-landing-page.md` (the working tree is not clean; do not revert it).

Read first:

- `docs/decisions/0005-gov2-ds-only.md` (the decision that this brief carries out).
- In the DS repo `~/devbox/gov2/gov2ro-design-system` at tag `v0.17.1`: `README.md` (*Use it*), `COMPONENTS.md` (all of it), `GUIDELINES.md`, `docs/migration-from-0.6.0.md`, and `CHANGELOG.md` from 0.4.0 to 0.17.1 (look for **Breaking**). Do not change a file in that repo.
- `docs/briefs/2026-11-16-landing-page.md` (what the landing page is).

## Goal

The app has one look, the gov2 DS at `v0.17.1`. The skins are gone. The landing page has the small changes that the owner asked for. All tests pass.

## Constraints

- Do not edit the DS copy. Do not change how a `.g2-` class looks.
- Do not rename or add a query parameter. Do not change `build_filters()`, `search_key()` or `validated_query()`.
- No CDN, no new runtime dependency. The CSS build stays `npm run css` (you can change what the script does).
- Templates use role tokens and the semantic families. No raw palette class, no hex.
- The page stays light: do not set `data-g2-scheme`.
- Do not edit `CLAUDE.md`. Opus changes it after the review.
- Do not open a GitHub issue. List DS proposal candidates in the report.

## Work

### 1. Landing page changes (owner, 2026-11-17)

1. The figures panel (hero, right column) has no border.
2. The heading "Căutări gata făcute" becomes "Exemple de căutare".
3. The example cards have no border and no box. Keep the title link, the tags and the count.
4. In "Sau începe de aici", an item has no box: it is a text link followed by its count. The count is not underlined and is not part of the underlined text. Keep a target height of 44 px.
5. Keep the sector tiles as boxes.

### 2. Install the DS

1. Install `v0.17.1` in `webapp-php/static/gov2/` with the `git archive` command of the DS README (`gov2.css`, `fonts.css`, `fonts/`). Verify that the copy is byte-identical to the tag.
2. Link `gov2.css` and `fonts.css` on each page (`inc/header.php`), with a version in the URL.
3. Remove the fonts that only the old looks used (Fraunces, DM Sans, DM Mono, Manrope: files, `@font-face` rules, preloads), if nothing else uses them.

### 3. Remove the skins

Remove: `webapp-php/static/skins/` (all files), `webapp-php/inc/skins.php`, `webapp-php/assets/check-skins.php`, the skin links and the boot script in `inc/header.php`, the skin picker in `inc/footer.php`, the skin code in `static/prefs.js` (remove the file and its `<script>` if nothing else is left), the skin picker styles in `app.css`, each `[data-skin]` rule, the skin tests, the `check-skins` step in `.github/workflows/ci.yml`, and the check in `deploy-php.sh` if it names a skin file (change only that check).

### 4. Tokens and layers — `tailwind.config.js`, `webapp-php/assets/app.css`

1. In the `:root` block, set each role token to a DS token. Start from this table. Where a role token has no DS match, choose the nearest DS token and say why.

| Role token | DS token |
|---|---|
| `page`, `surface`, `sunken` | `--g2-color-page`, `--g2-color-surface`, `--g2-color-surface-sunken` |
| `line`, `line-strong` | `--g2-color-line`, `--g2-color-control-border` |
| `ink`, `ink-muted`, `ink-faint` | `--g2-color-text`, `--g2-color-text-muted`, `--g2-color-text-faint` |
| `gov`, `on-gov` | `--g2-color-link`, `--g2-color-on-link` |
| `gov-bar`, `on-bar`, `on-bar-muted`, `on-bar-accent` | `--g2-color-header-bg`, `--g2-color-on-header`, `--g2-color-on-header-muted`, `--g2-color-header-accent` |
| `focus` | `--g2-color-focus` |
| `info`, `neutral`, `ok`, `note`, `alert` (fill, `-line`, `-ink`) | the tone tokens under *Tags* in `COMPONENTS.md` |
| radii | `--g2-radius` |
| fonts (`display`, `sans`, `mono`) | `--g2-font-sans`, `--g2-font-sans`, `--g2-font-mono` |

2. `--g2-color-text-faint` fails contrast on the sunken surface. Find each use of `ink-faint` on a sunken ground and change it to `ink-muted`.
3. Put the project CSS in the DS layers: the Tailwind reset in `reset`; the project components and the utilities in `app` (or `utilities`). No project rule stays outside a layer. Tailwind 3 has its own `@layer` directive with the names `base`, `components` and `utilities`; the name `utilities` is also a DS layer. Read `docs/migration-from-0.6.0.md` for the verified loading examples, choose one method, and say which.
4. Focus: the DS focus treatment (yellow fill, ink text, bar) must show on each focusable control. A utility in `app` that sets a background or a colour removes it. Remove the project focus rules, then make one rule in `app` that gives the DS focus treatment again, from the DS tokens.
5. The chart uses `--g2-color-chart-1`. The county map keeps four steps of one colour by opacity; compute the text contrast of each step again (at least 4.5:1) and report the ratios.

### 5. DS components

Use the DS component in these places. Keep each `id`, each `data-` attribute and each script hook that a test or a script uses.

- Chrome: `.g2-skip-link`, `.g2-header` with `.g2-wordmark` and `.g2-nav`, `.g2-footer` / `.g2-footer-in`, `.g2-container` for the page width.
- Landing page: `.g2-heading-page`, `.g2-heading-section`, `.g2-heading-item`, `.g2-text-meta`; `.g2-btn` and `.g2-input` in the search form and for the county select; `.g2-tile` for the sector tiles and the two tool tiles; `.g2-inset` or `.g2-notice` for the notifications block if one fits, else a local block.
- Remove `.pg-tile`, `.pg-chip` and `.pg-chip-count` if nothing uses them after this.
- Every other page: keep its markup. Load the DS, and correct only what the DS base styles break. The DS underlines each link: where a link is a whole row, a tile, a card or a navigation item, use the DS link classes (`.g2-link-title`, `.g2-link-quiet`) or remove the underline from the text that is not the title. Check each page type: list (three views, filter bar, follow dialog), detail, archived, employers, employer, statistics, saved, alerts (each alerts page), eligibility tool, employer builder, about, 404 and 400.

### 6. Docs

- `README.md`: replace § Skins with a short § Design system (the rules of ADR 0005, the update procedure). Correct each other mention of skins.
- `docs/backlog.md`: UX-06 and each skin item. `docs/activity-log.md`: one entry with **Done / Hard / Decided** and the measured numbers.

## Tests

- Baseline first: run every PHP suite and the full Playwright run before you change anything. Record the result.
- Update each test that names a skin, the picker, a removed class or the old heading. Do not delete a check of behaviour that still exists.
- New checks (PHP or Playwright, as fits), each seen failing against a named mutation:
  - no page has a `data-skin` attribute, a skin `<link>` or the picker;
  - each page links `gov2.css` and `fonts.css`, and both give status 200, with the font files;
  - the installed DS files are byte-identical to tag `v0.17.1` (skip the check when the DS clone is not on the machine);
  - keyboard focus on a navigation link, a sector tile, the search button, the search input and a list filter control shows the DS focus colours;
  - no horizontal overflow at 320 px on each page type of step 5;
  - the landing changes of step 1 (no border on the figures panel and on the example cards; the count is not underlined).
- Run and report: every PHP suite, the PHP lint, the full Playwright run, `npm run css`.
- Screenshots for the review: each page type of step 5 at 1280 px and at 375 px, full page, with `POSTURI_DB=data/_dev/posturi-new.sqlite POSTURI_TODAY=2026-10-17`, saved in `/private/tmp/claude-501/-Users-pax-devbox-gov2-posturi-gov-ro/69f74aef-c53d-47be-8cad-bb03280d888a/scratchpad/ds-shots/`. Look at each screenshot yourself and correct what is broken before you report.

## Files you may change

- `webapp-php/` (all files, the DS copy excluded after you install it), `tailwind.config.js`, `package.json` (the `css` scripts only), `.github/workflows/ci.yml`, `deploy-php.sh` (the skin check only), `README.md`, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file, an ADR, a query parameter, or the DS copy.

## Out of scope

- Sector icons. DS components on pages other than the chrome and the landing page. Dark scheme. A deploy. A commit.

## Done when

- All checks pass, and the report gives each command and its result.
- The report has: the token mapping table as built; the layer method; the contrast ratios of the map; each place where the DS base styles broke a page and what you did; the DS proposal candidates (component, need, local version); what you measured and what you assumed.
- Do not commit. Do not deploy. Opus reviews first.

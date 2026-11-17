# 0005 — One look: the gov2 design system; skins removed

Date: 2026-11-17. Status: accepted (owner, 2026-11-17). Replaces the skin model of README § Skins and backlog UX-06 ("Keep skins").

## Context

The app had four looks ("skins"): `hartie`, `posturi`, `govuk` and `gov2`. Each skin re-declared the role tokens of `assets/app.css` under `[data-skin="<id>"]`. The reader chose a skin in the footer. The copy of the gov2 design system (DS) was version 0.4.0, used as one of the skins.

DS 0.8.0 removed the `data-skin` activation, renamed each token to `--g2-*` and added cascade layers. The DS owner decided on 2026-10-26 that a consumer adapts to the DS. So the skin model and a current DS cannot live together.

## Decision

1. **The app has one look: the gov2 DS.** The skins, the skin picker, `inc/skins.php` and `assets/check-skins.php` are removed.
2. **The DS copy is an exact tag** (now `v0.17.1`), installed in `webapp-php/static/gov2/` with the command of the DS README: `gov2.css`, `fonts.css` and `fonts/`. Nobody edits the copy.
3. **The role tokens stay, as aliases.** The `:root` block of `assets/app.css` sets each role token to a DS token (`--c-ink: var(--g2-color-text)`). The DS colour tokens are RGB channels, as the role tokens are, so the Tailwind opacity syntax continues to work. Templates continue to use the role tokens and the semantic families. A template has no raw palette class and no hex.
4. **Project CSS is in the cascade layers of the DS.** The Tailwind reset is in `reset`. Project components and utilities are in `app` (or `utilities`). No project rule is outside a layer.
5. **Use a `.g2-` component when one fits.** The chrome and the landing page use DS components now. Each other page gets DS components in its own brief.
6. **The page stays light.** The app does not set `data-g2-scheme` (decision D17: no dark mode for beta).

## Consequences

- One look to test. The contrast contract of the skins (`check-skins.php`) is replaced by the contrast tests of the DS and by the browser tests of the app.
- A reader who chose another skin gets the gov2 look. The stored choice (`pg.skin`) is ignored.
- A DS update is: copy a newer tag, read the DS `CHANGELOG.md` for **Breaking**, run the tests.
- A component that the DS does not have (hero, grid, county map, chart, sector icons) stays local, with a DS proposal when another project can use it.
- Alternative not taken: keep the skins and write a gov2 skin by hand. Cost: the copy differs from the DS at each DS release, and each skin needs its own checks.
- Alternative not taken: replace the role tokens with `--g2-*` names in each template now. Cost: a change of every template with no visible result. The aliases give the same look, and the rename can come later.

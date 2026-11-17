# 0006 — Icon set: Tabler Icons

Date: 2026-11-17. Status: accepted (owner, 2026-11-17). Applies to UX-05 (sector icons) and to each new icon in the app.

## Context

The landing page shows a tile for each employer sector. The owner wants an icon for each sector. The gov2 DS ships no icon set and recommends none. The app has an inline SVG sprite with nine glyphs that were drawn by hand.

`docs/specs/2026-11-16-ui-overhaul/icons/` compares four sets for the 14 sectors: Tabler Icons, Lucide, Phosphor and Material Symbols. `README.md` in that folder has the licence facts and the mapping.

## Decision

1. **New icons come from Tabler Icons, outline style**, pinned to `@tabler/icons` 3.52.0 (MIT).
2. **The app serves the icons itself**, as symbols in the inline sprite of `inc/header.php`. No icon font and no CDN.
3. **Copy only the icons that the app uses.** Keep the Tabler licence text in the repository, next to the source SVG files.
4. **An icon next to a text label is decoration**: `aria-hidden="true"`, colour from `currentColor`.
5. The nine glyphs that exist stay until a page brief replaces them.

## Consequences

- The 2 px stroke of Tabler matches the 2 px control border of the gov2 DS.
- A new icon is a lookup in one set, not a drawing.
- No set has a good icon for veterinary work, water or emergency services. Each sector icon shows the main sense of its label.
- Other gov2 projects have the same sectors. The sector mapping is a candidate for a DS proposal.
- Alternative not taken: Lucide (ISC). It has the same style and a clearer icon for public order, but no icon for defence.
- Alternative not taken: icons from more than one set. Cost: the stroke and the grid differ between sets.

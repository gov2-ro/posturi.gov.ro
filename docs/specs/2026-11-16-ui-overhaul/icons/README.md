# Sector icons: candidate sets

Date: 2026-11-17. Purpose: pick one open-licence icon set for the 14 employer-sector tiles.
Open `compare.html` in a browser to see all sets side by side. It needs no network.

## Sets, licences and versions

All four packages were downloaded from the npm registry at an exact version. The registry tarballs match the GitHub repositories. The LICENSE text was read from each package.

| Set | Version pinned | Licence | LICENSE file |
|---|---|---|---|
| Tabler Icons (outline) | `@tabler/icons` 3.52.0 | MIT, (c) 2020-2026 Paweł Kuna | https://github.com/tabler/tabler-icons/blob/main/LICENSE |
| Lucide | `lucide-static` 1.73.0 | ISC, (c) 2026 Lucide Icons and Contributors | https://github.com/lucide-icons/lucide/blob/main/LICENSE |
| Phosphor (regular) | `@phosphor-icons/core` 2.1.1 | MIT, (c) 2023 Phosphor Icons | https://github.com/phosphor-icons/core/blob/main/LICENSE |
| Material Symbols (outlined, weight 400) | `@material-symbols/svg-400` 0.49.0 | Apache 2.0 (Google) | https://github.com/marella/material-symbols/blob/main/svg/400/LICENSE |

Attribution:
- MIT and ISC: keep the copyright line and licence text in copies of the icons. Put one licence file (for example `THIRD-PARTY.txt`) next to the self-hosted SVG. No visible credit in the user interface is needed.
- Apache 2.0: keep the licence text and say so if you modify the files. The Material README asks for an "about" credit but says it is not required.
- I did not check each icon for third-party trademarks.

Grid and style:
- Tabler: 24px grid, stroke 2px, round caps and joins, `stroke="currentColor"`. Stroke weight matches the 2px control borders of the gov2 design system.
- Lucide: 24px grid, stroke 2px, round caps and joins, `stroke="currentColor"`. Same style as Tabler. Each file starts with a licence comment.
- Phosphor regular: 256px grid, filled outlines, about 1.5px at 24px, `fill="currentColor"`. Rounder and lighter.
- Material Symbols outlined 400: viewBox `0 -960 960 960`, filled outlines, about 2px at 24px. The package SVG has no fill attribute, so the page must set `fill: currentColor`. Material width and height attributes are 48. Tabler, Lucide and Phosphor use 24 or none.

## Mapping (sector to icon name)

| Sector | Tabler | Lucide | Phosphor | Material |
|---|---|---|---|---|
| sanatate | `building-hospital` | `hospital` | `hospital` | `local_hospital` |
| administratie_locala | `building-community` | `building-2` | `building-office` | `location_city` |
| administratie_centrala | `building-bank` | `landmark` | `bank` | `account_balance` |
| educatie | `school` | `school` | `graduation-cap` | `school` |
| cultura | `masks-theater` | `drama` | `mask-happy` | `museum` |
| aparare | `tank` | `shield` | `medal-military` | `military_tech` |
| ordine_publica | `shield` | `siren` | `siren` | `shield` |
| justitie | `scale` | `scale` | `scales` | `balance` |
| asistenta_sociala | `heart-handshake` | `hand-heart` | `hand-heart` | `volunteer_activism` |
| cercetare | `microscope` | `microscope` | `flask` | `biotech` |
| transport | `bus` | `bus` | `bus` | `directions_bus` |
| mediu | `leaf` | `trees` | `tree` | `forest` |
| agricultura | `wheat` | `wheat` | `tractor` | `agriculture` |
| altele | `dots` | `ellipsis` | `dots-three` | `more_horiz` |

Files: `icons/<set>/<sector-key>.svg`, copied unmodified from the pinned packages. The file name is the sector key, not the icon name.

Choices on purpose:
- Justice uses scales, not a gavel. The gavel is a US courtroom symbol.
- Public order avoids the star badge (`shield-star`, Material `local_police`). A star badge looks like a US sheriff.
- Local administration is a group of buildings. Central administration is a columned civic building. The two read differently at 32px.

## Weak spots

- Tabler: `tank` is the only direct defence icon. It is detailed at 20px and reads as "armoured vehicle". `shield` for public order is generic. `leaf` for environment does not cover water. No veterinary icon.
- Lucide: no defence icon. `shield` for defence is generic, and `siren` for public order is the only distinct pair. `building-2` for local administration looks like an office block, not a town hall.
- Phosphor: no `school` icon, so `graduation-cap` stands for education. `flask` is weaker than a microscope for research institutes. The regular weight is lighter than the 2px border style.
- Material: filled outline style, a different look from the 2px strokes of the design system. `local_hospital` is a plus in a box, not a building. `shield` for public order is generic. The package needs a fill rule in CSS.

Not fully covered by any set: veterinary (agriculture), water (environment), emergency services (public order). The chosen icons cover only the main sense of each label.

## Recommendation

Use Tabler Icons (outline, 3.52.0).
Reasons: its 2px stroke matches the 2px borders of the gov2 design system; MIT needs only a licence file, no visible credit; it has the widest coverage (`building-hospital`, `masks-theater`, `tank`, `wheat`, `building-community`), so every sector gets a specific icon; the SVG files are already `currentColor`.
Lucide is the second choice: same style, fewer sector-specific icons (no defence icon).

## What I verified and what I assumed

Verified:
- Version, licence name and LICENSE text of each package, read from the downloaded tarball.
- The LICENSE URLs above return HTTP 200 (raw GitHub, main branch; Material at `google/material-design-icons` master also returns 200).
- All 56 chosen files exist and are copied unmodified. `compare.html` has no external request and no script.
- Icon names exist in each set (the copy would fail otherwise).

Assumed:
- I judged readability from icon names and my knowledge of the shapes. I did not review screenshots of the page in a browser. Check `compare.html` at 20px before the final decision, mainly `tank`, `building-community` and `building-2`.
- I did not look for a fifth set. I know of no set built for civic sectors that has a licence as clear as these four.
- Tabler 3.x and Lucide 1.x names can change in later versions. Pin the version when you copy the files.

# 0007 — Health Icons for medical subjects

Date: 2026-11-17. Status: proposed (the owner named the set, 2026-11-17). Adds an exception to ADR 0006.

## Context

The themed page `/sanatate/` lists medical specialties and medical occupations. The owner wants a symbol for each. Tabler Icons (ADR 0006) has no icon for most specialties (radiology, nephrology, rheumatology, geriatrics).

Health Icons (`healthicons` 3.1.0, healthicons.org) has 45 specialty icons and icons for health workers. The icons are in the public domain (CC0 1.0). Measured on the 17 Oct export: the health theme has 34 distinct specialties among the active postings (39 among all postings of the export).

## Decision

1. **Medical subjects use Health Icons, outline style**, pinned to `healthicons` 3.1.0. A medical subject is a medical specialty or a medical occupation.
2. **Each other icon stays Tabler** (ADR 0006), also on a themed page: the title icon, the navigation, the notification cards.
3. **The app serves the icons itself**, not modified, as symbols with the id `i-health-<name>`. The symbols are in one separate SVG file (`/static/health-icons.svg`) that a page with medical subjects refers to (`<use href="…#i-health-<name>">`). The file is not inline: measured, the 37 symbols of the specialties are 108 kB (40 kB gzip), too much for each page response. The browser caches the file.
4. The sources and the licence text are in `webapp-php/assets/icons/healthicons/`.
5. An icon next to a text label is decoration, as in ADR 0006.

## Consequences

- Two icon styles are on one page. Health Icons has a 48 px grid and filled shapes; Tabler has a 24 px grid and a 2 px stroke. The two sets are not in the same list, so a reader does not compare them side by side.
- Some subjects have no icon of their own. They share an icon with a near subject or use one general health icon. The mapping is in the theme configuration.
- Alternative not taken: Tabler only. Cost: about half of the specialties get a general icon.
- Alternative not taken: no icons in these lists. Cost: none for function; the lists are then plain text.

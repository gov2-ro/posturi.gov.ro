# Landing page and UI overhaul — analysis

Date: 2026-11-16. Author: Opus (design). Status: **draft**. The landing section spec waits for the wireframe review by the owner.

- Wireframe: https://claude.ai/artifact/Qf12FSN1jgbxvkmNZNPaXo (private to the owner).
- Route decision: `docs/decisions/0004-landing-route.md`.
- Briefs: `docs/briefs/` (written after the review; see *Hand-off*).

## 1. Goal and phases

The owner wants a landing page and a general overhaul of the user interface. This phase decides the **structure and the sections**. A later phase aligns the app to the gov2 DS. Skins stay in this phase. The owner can remove them after the DS alignment.

Order of work:

1. Landing page (first).
2. Chrome: masthead, navigation, footer.
3. Sector icons.
4. List page, detail page, secondary pages.

## 2. Findings

Numbers in this document come from `data/_dev/posturi-new.sqlite`. This file is the VPS export of 2026-10-17 (`--active-only`, 2,461 rows). It is one month old. "Active" means `application_status IN ('confirmed_open','unconfirmed','unknown')`, which is `OPEN_STATUS_SQL` in `helpers.php`. Measure again on a current export before you use a number as a regression baseline.

### 2.1 What exists

- `/` is the list (`pages/list.php`). When no filter is set, the list shows a flat row of shortcuts "Începe cu" (`list.php:135-175`). On bare `/` it also shows "Noi azi" and "Se închid în 3 zile" (`list.php:181-200`).
- The navigation has three links: Pot aplica?, Salvate, Despre. Statistici and Angajatori are reachable only from `/despre/`.
- The footer is one paragraph with eight links, then the skin picker.
- The follow features exist: RSS, Atom, iCal, and email alerts (live 2026-11-16, one digest each 3 days, ADR 0003). Each feed belongs to a search. `feed_url()` builds the URL.
- The list work of 2026-11-16 (filter bar with two rows, save + follow group, subscription dialog) is committed but not deployed.

### 2.2 Domains (sector angajator)

The column is `job_postings.v4_employer_sector`. It is a closed list of 14 values (`EmployerSector`, `schema_models.py:597`). The labels are in `EMPLOYER_SECTOR_LABELS` (`helpers.php:1060`). The filter is `?sector[]=<value>`. The value is the sector of the employer, not of the profession: a driver at a hospital is `sanatate`. 97.1% of the active postings have a value.

| Sector | Active postings |
|---|---|
| Sănătate | 441 |
| Administrație locală | 253 |
| Educație | 170 |
| Mediu | 70 |
| Asistență socială | 64 |
| Cultură | 51 |
| Apărare | 40 |
| Cercetare | 37 |
| Administrație centrală | 19 |
| Altele | 6 |
| Justiție | 4 |
| Agricultură | 4 |
| Transport | 3 |
| Ordine publică | 0 |
| (no value) | 35 |

The distribution is very uneven. Three sectors hold 72% of the active postings. Five sectors have fewer than 10.

### 2.3 Statistics

Reliable, and cheap to compute:

| Figure | Value | Source |
|---|---|---|
| Active postings | 1,197 | `count(*)` with the open status |
| Employers with an active posting | 733 | `count(DISTINCT employer_id)` with the open status |
| Active, deadline in the next 7 days | 603 | `apply_deadline` between today and today + 7 |
| Published in the last 7 days | 630 | `published_at`, all rows in the file |
| Confirmed open, deadline in the next 3 days | 195 | the existing "Se închid în 3 zile" rule |
| Counties with an active posting | 42 | |

**Not reliable: the number of seats.** `nr_posturi` sums to 342,534 because of two bad values (339,395 and 2,026). `v3_positions` has a value on 260 postings only. The v5 field `vacancies_total` is empty in this export. The landing page must not show a number of seats.

Note: backlog UX-05 said "rather than rebuilding KPI tiles from an outdated spec". The owner now asks for some statistics on the landing page. This document proposes four figures that are also links, not a dashboard.

### 2.4 Other starting searches

| Dimension | Parameter | Fill rate | Top values (active) |
|---|---|---|---|
| Education level | `studies_level` | 96% | licență 483, liceală 270, generală 186, postliceală 146, profesională 44, master 13, doctorat 7 |
| Occupation | `occupation` | 100% | Îngrijitor 84, Medic specialist 76, Asistent medical generalist 66, Asistent medical 54, Muncitor calificat 49, Infirmier 40, Șofer 38 |
| County | `judet` | 100% | București 184, Cluj 62, Vâlcea 49, Timiș 48, Constanța 48, Prahova 47 |
| Experience | `exp_level` | 46% | under 1 year 89 |
| Salary | `salary_bucket` | 83% | over 7,000 lei: 228 (mostly estimated from the salary grid) |
| Management | `level` | 99% | Funcții de conducere 81 |
| Contract duration | `duration` | 88% | determinată 190 |
| Schedule | `schedule` | 77% | normă parțială 126 |
| Funding | `funding` | 10% | fonduri europene 101, PNRR 3 |

Two cuts are bad starting searches in this export:

- **Telemuncă** counts 0 (`work_mode` is empty). The cause is not known.
- **Funcție publică** counts 4 (`job_regime`); the source category counts 7. The cut exists, but it is almost empty.

Rule: the landing page hides a tile or a chip when its count is 0.

### 2.5 Icons

The DS ships no icon set and recommends none. The app has an inline sprite with 9 hand-drawn glyphs (`inc/header.php:95`) and no general icon helper. Backlog UX-05 says: add icons only after a consistent accessible design is agreed. `static/steme-judete/` holds 42 county crests (PNG); no template uses them.

### 2.6 Constraints

- The edge caches the HTML for one hour. The server output must be the same for each reader. Personal state lives in `localStorage`.
- ADR 0001 fixes the search key and the parameter names.
- No JS build step. `npm run css` builds the stylesheet, and the output is committed.
- Templates use role tokens and the five semantic families only. No raw palette class, no hex.
- The project pins DS 0.4.0. The DS is at 0.17.1. DS 0.8.0 removed the `[data-skin]` activation and renamed all tokens. No template uses a `.g2-` class.

## 3. Landing page — section spec (first draft, replaced)

**The owner revised the design on 2026-11-16.** The current section spec is `docs/briefs/2026-11-16-landing-page.md`, and the design source is `landing-wireframe.dc.html` in this folder. The revision adds a county select in the hero, a chart of weekly postings, a section of ready-made searches, and a county tile map next to the domains. The table below is the first draft; keep it as the record of the starting point. The open points 2 and 5 are decided (ADR 0004; the brief).

The route is in ADR 0004. The landing page is `pages/home.php`. Each count uses the predicate of its link (`build_filters()`, as the `$count_for` closure in `list.php:144` does), so a count is always equal to the number of results.

| # | Section | Content | Data |
|---|---|---|---|
| 1 | Hero | Headline. Explainer of two sentences. Search box that sends `q` to `/`. Link "Vezi toate cele N posturi active" to `/posturi/`. Data freshness and the "not official" line. | `build_stamp_relative()`, active count |
| 2 | Cifre | Four figures. Each figure is a link to its list cut. One link to `/statistici/`. | Section 2.3, first four rows |
| 3 | Domenii | A grid of tiles: icon slot, label, count. Order by count; "Altele" last; hide a sector with count 0. One sentence explains that the domain is of the employer. | Section 2.2 |
| 4 | Alte puncte de pornire | Four labelled rows of chips: După studii, După ocupație (top 8), După județ (top 6 and a link to all 42), Alte filtre. | Section 2.4 |
| 5 | Noi și urgente | The two existing lists, 6 rows each, moved from the list page. | `list.php:181-200` |
| 6 | Urmărește | One sentence: choose filters, then press "Urmărește". Three cards: Email, Calendar, RSS. Each card links to the page or to the all-postings feed. A line about Salvate. | `feed_url()`, `/alerte/` |
| 7 | Instrumente | Two links: Pot aplica?, and the employer posting assistant. | |

Design rules:

- The grid of domains works without icons. The icon set can arrive later without a layout change.
- The page has no personal content from the server. A script can add a row from `localStorage` (see the open point in ADR 0004).
- Each link target is at least 44 px high. The page has no horizontal scroll at 320 px.
- The Romanian copy uses short sentences, the active voice and one term for each concept. The term for the sector is "domeniu".

Open points for the owner (wireframe review):

1. The Romanian copy of the hero and of section 6.
2. The redirect to the default saved search on bare `/` (ADR 0004, *Open point*).
3. The uneven domains: one grid for all 13, or large tiles for the first 8 and a chip row for the rest.
4. County crests in the county row: use them, or leave them out.
5. "Funcție publică" (4 postings): keep it as a starting search or drop it.

## 4. Icons — recommendation

1. Adopt one outline icon set with an open licence. Candidates: Tabler Icons (MIT), Lucide (ISC), Phosphor (MIT). Verify the licence text of the chosen set before you copy a glyph.
2. Copy only the needed glyphs into the existing sprite. Do not load a font or a CDN file.
3. Add one helper, `icon(string $id, string $class = '')`, which replaces the two local closures in `pref_controls()` and `follow_group_html()`.
4. A sector icon is decoration: `aria-hidden="true"`, with the label as text next to it.
5. Record the choice as an ADR. Open a DS proposal (`ds-proposal`, type "component") for the icon set and the sector mapping, because other gov2 projects have the same sectors.

The wireframe shows an empty icon slot. It does not compare the candidate sets: this session did not download them. The comparison for the 14 sectors is the first step of the icon brief.

## 5. Layout primitives

Add a small set of component classes to `webapp-php/assets/app.css`, built on the role tokens. Each class has the shape of the DS component that replaces it later, so that the DS alignment is a rename and not a new design.

| Local primitive | Use | DS component later |
|---|---|---|
| Page header | Title, one lead paragraph, optional actions | `.g2-heading-page` |
| Section | Section title with an optional mark and a "more" link | `.g2-heading-section`, `.g2-dash` |
| Tile | A figure, a link group or a summary | `.g2-tile` |
| Chip | A shortcut link with a count | `.g2-chip`, `.g2-chip-count` |
| Container | Page width and gutter | `.g2-container` |

The DS has no grid, no hero and no card. These stay local. Open a DS proposal when a second project needs one.

## 6. Audit of the other areas

### 6.1 Chrome

- Navigation: Posturi (`/posturi/`), Salvate, Alerte, Statistici, Despre. "Pot aplica?" and Angajatori move to the landing page and the footer. Five links must wrap at 320 px without a menu button.
- Footer: three groups of links (Posturi, Urmărește, Proiect), then the disclaimer, then the skin picker.
- Keep: the beta label, the "Bază generată" line, the welcome box, the `[?!]` panel.

### 6.2 List page

- Deploy and verify the work of 2026-11-16 before any other change to this page.
- Add a page heading that names the active cut when the search has one filter (for example "Sănătate — 441 de posturi"). Reuse the words of `active_filter_chips()`, as the calendar name does.
- "Șterge tot" goes to `/posturi/`.
- The 26 facet groups stay in the scope of UX-01. This overhaul does not consolidate them.

### 6.3 Detail page

- Apply the page header and the section primitives. Do not change the content or the order of the facts without a separate decision: the layout of 2026-10-19 records three variants that were tried and dropped.

### 6.4 Secondary pages

- Despre, Statistici, Angajatori, Salvate, Alerte: one page header pattern and one section pattern.
- Statistici waits for FIX-09 (statistics with an explicit scope). Do not restyle it before FIX-09 is deployed.

## 7. Hand-off

| Brief | State |
|---|---|
| `docs/briefs/2026-11-16-landing-page.md` | Written after the wireframe review. |
| `docs/briefs/2026-11-16-chrome-nav-footer.md` | Written after the wireframe review (the wireframe shows the navigation and the footer). |
| `docs/briefs/2026-11-16-sector-icons.md` | Written after the owner picks the icon set. |
| List, detail, secondary pages | Written after the owner reviews section 6. |

Each brief follows the format of `docs/briefs/2026-11-02-saved-searches.md`.

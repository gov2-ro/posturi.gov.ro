# Spec — Alternative UI/Explorer for posturi.gov.ro

## Context

`posturi.gov.ro` is the official Romanian government job portal. The existing scraper (this repo) already produces a clean dataset of ~4,400 postings with structured fields (title, employer, location, level, type, deadlines, calendar events, full body markdown, attachments) and tracks field-level changes over time.

The official site offers poor discovery: no profession taxonomy, no city-level geo, no saved searches, no notifications, no calendar export, no transparency view, no map. Job seekers struggle to find roles relevant to them; researchers and journalists have no way to study patterns (anomalies, re-postings, employer hiring behavior).

This spec defines an alternative UI/app on top of the scraped + enriched dataset. **Primary audience: job seekers**, with a layered analytics surface for researchers/journalists. Goal: a useful, robust gov-jobs board alternative built entirely from public data, with inferred taxonomies and feeds first-class.

Stack/deployment decisions are deferred to the implementation phase.

## Scope summary

The spec is a "go bold" feature set across the surface area, to be phased during implementation planning. v1 inferred-attribute scope (must-haves):

- Profession family / job category (normalized)
- Seniority / grade (normalized)
- Required studies & experience
- Geo precision (city/commune, not just județ)
- Anomaly / red-flag detection
- Skills / certifications / languages

Deferred from v1 (still in the spec, but not required for first release): salary band extraction, full institution hierarchy (CUI tree).

## Top-level sitemap

1. **Landing / Home** — curated entry points
2. **Browse / Search** — faceted list (workhorse)
3. **Job Detail** — full posting view
4. **Map View** — geographic exploration
5. **Calendar View** — competition events across postings
6. **Employer Profile** — per-employer page
7. **Category / Profession Hub** — auto-generated per profession family
8. **Stats / Transparency Dashboard** — public analytics
9. **My Account** — saved searches, alerts, application tracker
10. **About / Methodology** — data sources, inference, limitations

---

## 1. Landing / Home

Orient a casual visitor in under 5 seconds; give a serious seeker three fast paths in.

**Above the fold:**
- Keyword search with autocomplete (titles, employers, cities).
- Quick-filter chips: "Lângă mine", "Expiră în 7 zile", "Publicate azi", "Funcții publice", "Funcții contractuale".
- "Total active postings: N" + last-updated timestamp (trust signal vs source).

**Below the fold:**
- New today / this week — horizontal card scroller (8-10 jobs).
- Expiring soon — urgency band, sorted by deadline.
- Category tiles — 8-12 profession families with counts → category hub.
- Map snippet — RO mini-map of active-post counts per județ → full map view.
- Top employers hiring now — 6-10 cards.
- Stats teaser — 3 KPI tiles → dashboard.
- Anomaly spotlight — "N postings this week flagged" → filtered browse.

## 2. Browse / Search

**Layout:** left facet sidebar, center result list, right preview pane (collapsible; stacks on mobile). Sticky top bar with active-filter chips, sort, result count, view toggle (list / cards / map / table).

**Facets (sidebar):**
- Keyword (title + body + employer)
- Location: județ (tree) → city/commune (when inferred)
- Profession family (inferred)
- Job Level — execuție / conducere
- Job Type — Permanent / Temporar
- Categorie — publică / contractuală
- Employer Category — Primării, Instituții locale/naționale, Guvern și ministere, etc.
- Employer (typeahead, canonical)
- Seniority / grade (inferred)
- Studies required (inferred)
- Experience required (inferred buckets: 0, 1-3, 3-5, 5+)
- Skills / languages / certifications (inferred tag cloud)
- Deadline window (date range; presets azi/săpt./luna)
- Publication date range
- Nr posturi (1, 2-5, 5+)
- Has attachments (bool)
- Anomaly flags (collapsed by default)
- Status — active / expired / coming-up

**Sort:** relevance (when keyword set), newest, deadline soonest, employer A-Z, nr posturi desc, anomaly score desc.

**Result row:** title • employer (logo placeholder/initials) • location • category badge • level/type badges • deadline countdown • anomaly badge • "modificat recent" badge • save (★) • share.

**Preview pane:** structured summary loads on row click without leaving the list — sidebar fields, calendar timeline, body excerpt, attachments, "Open full page". Keyboard nav (j/k/enter/s).

**URL state:** every facet combo is a shareable URL; basis for saved-search / RSS / iCal feeds.

## 3. Job Detail

**Header:** title, employer (linked), status pill (Active / Expiră în N zile / Expired / Suspendat / Reluat), badges row (category, level, type, anomaly, modificat recent), actions (Save, Set deadline reminder, Apply / Open source, Share, Export iCal).

**Two-column body — left sticky sidebar (structured):**
- Location (mini-map if city-precise)
- Nr Posturi
- Profession family + seniority/grade
- Studies & experience required
- Skills / certifications / languages (chips)
- Salary (if extractable; otherwise "nespecificat")
- Contract type & duration
- Contact: person, phone, email (click-to-copy)
- Source links: original URL, attachments (with size + extracted-text toggle)

**Right column — main content:**
- Competition timeline — horizontal timeline (publication → deadline → proba scrisă → interviu → rezultate), from `calendar.csv`; today-marker; click event → add to calendar.
- Description tabs: *Sumar* (LLM/extractive bullets) / *Text integral* / *Atașamente* (rendered).
- Requirements & documents — extracted into structured lists (conditions, documents to submit, bibliography).
- Anomaly notes — when flagged, explain *why* in plain language.
- Change history — render `updates` column as audit log ("data limită modificată: 12.05 → 19.05, on 2026-04-30").
- Related jobs — same employer (12mo), same profession family in same județ, same role across country.

**Footer:** data-source disclaimer + source link, report-an-issue, methodology link.

## 4. Map View

- Default: choropleth by județ; color = active-post count; tooltip = count + top 3 categories.
- Toggle layers: count, freshness, anomaly density, per-capita (per 10k residents).
- Drill-in: click județ → zoom to localities (pin clusters). Pin click → detail card → "Show in list" jumps to filtered browse.
- Sidebar filters mirror browse facets (narrower).
- Saveable view — URL with zoom + filters; can become saved search / alert.

## 5. Calendar View

- Modes: *Month* (grid), *Agenda* (chronological by week).
- Event types color-coded: Termen depunere, Proba scrisă, Proba practică, Interviu, Rezultate (from `calendar.csv` + `Data Limita Depunere`).
- Filters: category, județ, employer, event type.
- Click event → job preview drawer; "Add to my calendar" emits iCal.
- "Heat" overlay — days with unusually many deadlines.
- Subscribe — every filter combo has an iCal feed URL → live calendar subscription.

## 6. Employer Profile

- Header: canonical name, CUI (when resolved), category, județ(e), active-postings badge.
- Tabs:
  - *Posturi active* — current postings mini-browse
  - *Istoric* — historical postings + monthly timeline chart
  - *Tipare* — profession mix donut, publică vs contractuală, grades, avg deadline window, % re-posted, % anomalous
  - *Contact* — aggregated addresses/phones/emails from postings
- Compare — side-by-side stats for 2-4 employers.
- Follow — saved-search shortcut for any new posting from this employer.

## 7. Category / Profession Hub

Auto-generated per profession family (`/categorie/it`, `/categorie/sanatate`, …):

- Intro + active-post count.
- Subcategory chips.
- Embedded mini-views: new this week, expiring soon, by județ (mini map), top employers, typical requirements (aggregated skills/studies/grades).
- Link to pre-filtered browse.

## 8. Stats / Transparency Dashboard

- Headline KPIs: active postings, new this month, expired this month, % publică vs contractuală, median days-to-deadline, % anomalous.
- Time series: postings/month over full history; stacked by category / employer category / județ; toggle absolute vs share.
- Geographic: choropleth + top-20 tables (employers, județe absolute and per-capita).
- Profession mix: treemap.
- Hiring "weather": weekday × month seasonality heatmap.
- Anomaly index: % flagged broken down by employer category and județ; watchlist of elevated-rate employers.
- Re-posting tracker.
- Data freshness panel: last scrape, error rate, coverage vs source.
- Every chart exports CSV + PNG; methodology link per chart.

## 9. My Account & Personalization

- Auth: email magic-link (passwordless) + optional OAuth (Google).
- Saved searches — name, filters snapshot, alert cadence (instant / daily / weekly), channel (email / RSS / iCal); edit/pause/delete.
- Saved jobs — starred list with notes; auto-archive on expiry.
- Deadline calendar — personal iCal feed combining saved-jobs events; reminders 7d / 3d / 1d before.
- Application tracker (lightweight) — per job status (interesat / pregătit / aplicat / interviu / rezultat) + free-text notes + auto-derived document checklist.
- Notification log.
- Privacy: export & delete buttons; clear data-handling note.

## 10. About / Methodology

- Data sources & scraping cadence.
- Taxonomy inference (dictionary vs LLM split, confidence levels).
- Anomaly heuristics.
- Known limitations.
- Source code link; affiliation disclaimer (not government-affiliated).

---

## Cross-cutting features

- **Bilingual UI** — RO default, EN toggle (diaspora + foreign researchers).
- **Mobile-first** — preview pane collapses; map and calendar adapt.
- **Accessibility (WCAG AA)** — keyboard navigation across browse, semantic landmarks, ARIA on facet groups, high-contrast mode.
- **Dark mode.**
- **Permalinks everywhere** — every filter combo, map view, calendar view, hub has a stable URL.
- **Feeds everywhere** — every filter combo exposes RSS, iCal, JSON endpoints.
- **Change-tracking display** — surface `updates` field wherever a posting is shown (badge in list, audit log on detail).
- **Trust banners** — "data scraped from posturi.gov.ro; last sync: …; not affiliated".
- **Report an issue** — one-click per posting (wrong category, missing field, suspected error) → improves taxonomy ground truth.
- **Methodology page** open about data and inference.

## Derived data model (powers the UI)

These fields must be added to the dataset beyond what `anunturi.csv` provides today. Nightly job re-runs inference on changed/new postings; low-confidence cases go to a manual-review queue.

| Field | Source | Notes |
|---|---|---|
| `profession_family`, `profession_subfamily` | title + body | Dictionary mapping with LLM fallback; manual review queue |
| `seniority_normalized`, `grade_normalized` | title + body regex/dictionary | debutant → superior, gradul I/IA/II |
| `studies_required`, `studies_field`, `experience_years` | "Condiții" section | Extractive |
| `skills`, `languages`, `certifications` | body + attachments | Tag extraction |
| `locality`, `lat`, `lng` | body gazetteer | Fallback to județ centroid |
| `anomaly_flags[]`, `anomaly_score` | rules + LLM | Short deadline, narrow criteria, re-posting, missing contact, etc. |
| `is_repost_of` | title/body similarity | Link to prior posting at same employer |
| `employer_canonical_id`, `employer_cui` | entity resolution | Powers employer profile + hierarchy |
| `summary_bullets[]` | LLM, cached | 6-bullet summary used on cards & detail tab |

## Feeds & integration surface

- Every filter combination → `?format=rss`, `?format=ical`, `?format=json` endpoints.
- Per-employer feed, per-category feed, per-județ feed.
- Personal saved-jobs feed (signed URL).
- Public JSON dumps (nightly) for researchers — anunturi, calendar, derived fields.

## Out of scope (v1)

- Salary band extraction (data too sparse / unreliable).
- Full institution-hierarchy tree (CUI → ministry → agency → local office).
- In-app application submission (we link out to source; no form proxying).
- Account-bound document storage for applications.
- Multi-language body machine translation (UI is bilingual; postings stay RO).

## Verification (how to validate the spec is met)

- Walk each top-level view in a browser; confirm the listed components render with real data.
- Verify every facet listed in §2 filters the result count visibly and updates the URL.
- Confirm each derived field in the data model is populated for at least 80% of active postings (manual sampling against source).
- Open at least one saved search → verify it produces working RSS and iCal feed URLs; subscribe in an external calendar.
- Submit a "report an issue" → confirm it lands in the moderation queue.
- Load detail page for a posting with a non-empty `updates` field → confirm change log renders.
- Load map → toggle each layer → confirm legend updates and choropleth recolors.
- Open methodology page → confirm every inferred field listed in §"Derived data model" has a corresponding explanation.

## Open decisions deferred to planning

- Stack & hosting (server-rendered vs static + client search vs hybrid).
- Auth provider details.
- Map tile provider.
- LLM provider/model choice for inference pipeline (already prototyped in the scraper's `llm-schema.py`).
- Phasing: which sections ship in v1 vs v2.

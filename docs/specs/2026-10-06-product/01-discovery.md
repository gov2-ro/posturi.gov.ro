# UX-01A — Simpler filters and clear application status

Status: ready for implementation; no implementation claimed.
Parent: UX-01, with the visible uncertainty part of REV-10.
Baseline: `6accc59`. No data migration or extraction backfill.

## Problem and outcome

The sidebar opens seven groups by default and presents source labels beside
inferred/structured labels as if they were interchangeable. “Active” includes
confirmed-open, unconfirmed and unknown postings; “Expiră în 7 zile” conflates
announcement expiry with application deadlines. A bare “estimat” does not explain
why the displayed date may not be the submission deadline.

Make the initial screen easier to scan, explain status and automated fields,
and preserve the result set of every existing filter URL. This first package
groups overlapping controls; it does not merge different data fields or silently
change exact-level filters into eligibility matching.

## Read these files first

- `webapp-php/partials/facets.php`: facet_group(), field names and defaults.
- `webapp-php/pages/list.php`: selection arrays, facet_scope(), count queries,
  status_counts, shortcuts, form, OOB facet swaps and inline JavaScript.
- `webapp-php/helpers.php`: STATUS_LABELS, OPEN_STATUS_SQL, posting_deadline(),
  label maps, FILTER_CHIP_GROUPS, facet_mode(), build_filters(), feed_url().
- `webapp-php/query.php`: normalized scalar/facet query shapes.
- `webapp-php/partials/result_list.php`, `pages/detail.php`, `pages/employer.php`:
  deadline/status rendering; employer rows must receive the same explanation.
- `webapp-php/tests/request_test.php`, `deadline_test.php`,
  `tests/fixtures/build_db.php`, `tests/browser/browser.spec.js`.

## Fixed design and scope

Keep the search field, desktop sidebar and mobile drawer. Initially open only
“Județ” and “Domeniu profesional” (`family`); selected groups and their ancestors
are always open. Preserve per-group preferences in `posturi.facets`, but old
preferences must not force newly introduced parent groups open indiscriminately.
Use semantic details/summary disclosures and retain stable `data-facet` keys.

Arrange controls in this order:

| Section | Existing query parameters | Presentation |
|---|---|---|
| Location/profession | `judet`, `family`, `occupation` | Județ and Domeniu profesional open; Ocupație closed |
| Education | `eqf`, `isced`, `studies_level` | Parent “Studii”; Nivel de studii (EQF), Domeniu de studii, Studii identificate în text |
| Experience/skills | `exp_level`, `skill`, `lang`, `credential` | Parent “Experiență și cerințe”; all children initially closed |
| Work arrangement | `duration`, `schedule`, `shift`, `remote` | Parent “Contract și program”; all children initially closed |
| Additional choices | `salary_bucket`, `sector`, `funding`, `domain`, `stage`, date bounds | “Mai multe filtre”; label `domain` as Domeniu de activitate |
| Source/inferred legacy labels | `level`, `type`, `categorie`, `seniority`, `work_type`, `employer_cat` | Nested “Clasificări din sursă și text” under Mai multe filtre; retain all controls |
| Diagnostics | `anomaly`, `schema` | Separate “Diagnostic date” disclosure under Mai multe filtre |

Do not duplicate the same form input under two headings. Retain other currently
supported parameters, including `computer`, and expose an active legacy
selection so an unrelated form change cannot erase it. Keep orphan selections,
zero-count disabling and OR/AND switches. Selected ancestors must open for all
parameters, not just the current advanced group's limited condition.

Show this short education explanation: “Nivelurile filtrează cerințele anunțului,
nu stabilesc dacă te califici.” EQF remains nominal exact matching, with existing
OR behavior for multiple levels. Groups combine with AND. Do not unite
`studies_level` and `eqf`, `type` and `duration`, or `level` and `seniority` in SQL.
If legacy experience buckets include unknown experience, say so next to the
control: “Unele intervale includ anunțuri fără experiență precizată.” Do not
reclassify missing experience as an explicit zero.

Use Romanian labels from existing maps for known enum values in options and
chips. Add missing mappings for known values; preserve unfamiliar values visibly
without guessing their meaning. Short help beside family/occupation/experience
controls must explain automatic classification and point to the official
announcement for requirements. Salary remains explicitly estimated with its
existing provenance. Full vocabulary normalization remains FIX-11.

### Status and dates

Keep URL values and SQL unchanged: `active`, `soon`, `closed`, `unknown`, and
legacy `all`. Use “Active”, “Termen în 7 zile”, “Înscrieri închise”, “Termen
neprecizat”. Wrap tabs on small screens rather than introducing overflow.

Immediately below the status control, show persistent visible copy:
“Active include anunțuri cu înscrieri deschise și anunțuri al căror termen nu
este confirmat. Verifică termenul în anunțul oficial.” For `soon`, add
“Include și date estimate din expirarea anunțului.” Counts remain counts of
announcements, not summed vacancies; change result-count wording to “anunț
găsit” / “anunțuri găsite” consistently on this discovery surface.

Beside each expiry fallback date, replace the bare qualification with visible
“Expirarea anunțului; înscriere neconfirmată”. A tooltip may supplement it.
Confirmed past application deadlines say “Înscrieri închise”; no-date records
say “Termen neprecizat”. Preserve the resolver's date and Bucharest countdown;
do not label every date source as confirmed and do not infer cancellation from
absence. Detail and employer listings must not contradict list wording.

Do not add a source-counter comparison or hard-code the screenshot counts.
That investigation is OPS-05.

### Navigation and subscriptions

Selected controls survive full reload, HTMX filter/pagination swaps, chip
removal and browser history restoration. Preserve focus, drawer Escape behavior
and facet scroll state. Preserve selected hidden/advanced controls in form
serialization. No-JavaScript GET submission remains usable.

Keep shortcuts as shipped; no new category pages or KPI section in this package.
Keep feed links synchronized with all active server filters, including arrays,
OR/AND modes and employer scope; omit `page` and `sort`. Calendar `title` remains
a calendar label. Feed work beyond preservation belongs to UX-04-FEEDS.

## Acceptance evidence

1. On a fresh browser, only Județ and Domeniu profesional open. A bookmarked
   advanced/diagnostic selection opens every ancestor and remains removable.
2. Fixture ID sets for representative old URLs match before/after: scalar and
   multi-county, exact EQF, legacy studies, mixed source/structured fields,
   skills any/all, employer scope, date bounds and all status values.
3. Counts, chip labels and result ID sets agree after checking/removing filters.
   A selected value outside the displayed cap survives an unrelated change.
4. Browser tests cover nested disclosures after OOB swap, focus, reload and
   history. No-JS fixture requests retain valid forms and selected values.
5. Confirmed, expiry fallback, unknown and closed fixtures show the prescribed
   visible wording. No tooltip is required to understand uncertain dates.
6. Atom/JSON/iCal URLs retain server filters after HTMX changes, chip removal,
   pagination and history; `page`/`sort` are absent. Calendar title still works.
7. Common package checks pass at all configured widths; record screenshots for
   default list, selected advanced filters, mobile drawer and fallback row.

## Completion and limits

Implement in small steps: regroup markup, align labels/help, align status/date
copy, then add focused regression coverage. No changes to filter predicates,
export fields or extraction prompts are needed. If a predicate must change to
fix a discovered bug, document it as a separate follow-up rather than altering
the URL contract inside this task.

Mark UX-01A complete when these criteria pass. Parent UX-01 stays open for true
field consolidation, shortcut evaluation and remaining inference explanations.
The horizontal filter switch and compact/table listing modes remain separate.

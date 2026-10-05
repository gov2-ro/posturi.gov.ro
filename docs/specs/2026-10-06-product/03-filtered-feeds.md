# UX-04-FEEDS — Filtered RSS, Atom and iCal subscriptions

Status: ready for implementation after UX-01A. Baseline: `6accc59`.
Parent: UX-04; includes the iCal encoding portion of REV-10.

## Existing behavior and outcome

`/posturi.atom`, `/posturi.ics` and `/posturi.json` already call build_filters()
and support query parameters. feed_url() and syncFeedLinks() omit page/sort and
keep subscriptions in step with HTMX filters. Google Calendar, webcal and a
custom calendar title are already present. Atom currently limits to the newest
50 postings, iCal to the earliest 200 dated postings. There is no RSS 2.0 route;
the existing link says “Atom (RSS)”.

Keep those contracts, add actual RSS 2.0, make subscriptions easier to find,
and prove their filter/uncertainty behavior. No paid APIs or new scheduler.

## Source map

Read `index.php`, `query.php`, `helpers.php` (build_filters(), feed_url(),
current_qs(), posting_deadline(), job_url(), site_origin()),
`feeds/jobs.atom.php`, `jobs.ics.php`, `jobs.json.php`,
`pages/list.php` (export/calendar UI and syncFeedLinks()), `pages/employer.php`,
and PHP request/deadline/browser fixture suites. Keep existing compatibility
shims and sanitized text handling.

## Required behavior

1. Add `/posturi.rss`, content type `application/rss+xml; charset=utf-8`,
   using RSS 2.0 channel/items. Label links “RSS”, “Atom”, “Calendar iCal” and
   “JSON API”; retain all old endpoints. Include the new route in the front
   controller's machine-readable invalid-query path.
2. RSS and Atom share query selection, newest-first order and cap of 50.
   Extract shared selection/summary helpers as needed without a generic feed
   framework. Preserve Atom entry IDs (official URL); RSS guid uses official
   URL with isPermaLink="false". Stable identity survives local slug changes.
   Item links go to local canonical detail; summaries include a separate
   official-source link. Atom alternate links move to local detail, but IDs
   remain unchanged so existing readers do not see every item as new.
3. Feed links carry all active server filters, including multi-valued facets,
   skill modes, keyword, status, employer and date bounds. Only page/sort are
   stripped as view controls. Calendar title stays calendar-only in generated
   links; existing direct URLs remain accepted. Do not serialize local saved/
   hidden state into feed URLs or imply those preferences affect subscriptions.
4. Add “Urmărește această căutare” near result controls, opening an inline
   subscription disclosure with RSS/Atom/iCal links, copy-link actions and
   the existing Google/webcal/title UI. JSON remains in the export area. Reuse
   one subscription block, avoid duplicate IDs, and preserve usable anchors
   without JavaScript. Links remain correct after filters, chips, pagination,
   empty results and browser history. Copy uses the current absolute URL,
   including encoded filters; provide a selectable URL if clipboard fails.
5. State visible limits: “RSS/Atom: cele mai noi 50 de anunțuri care corespund
   filtrelor. Calendar: primele 200 cu dată disponibilă, în ordinea termenului.”
   Explain that live subscription refresh is controlled by the reader/calendar
   client; downloading/importing an ICS file is a snapshot. These feeds are
   not a complete archive and high-volume searches may miss intervening items.
   Do not claim immediate notifications or guaranteed complete coverage.
6. RSS/Atom summaries expose application status and resolved deadline, visibly
   qualify expiry-only dates and name the official source. Closed/unknown rows
   are included only when selected by existing status filters. Preserve query
   semantics; no assumptions about vacancy counters or eligibility.
7. iCal retains stable UIDs, all-day date-only events and exclusive DTEND.
   Keep earliest-200 scope and clearly qualify estimated events in SUMMARY
   as well as DESCRIPTION. Missing dates produce no invented events. Do not
   add exact-time reminders, VALARM or every competition stage in this package.
8. Fix iCal serialization: escape TEXT properties only, encode URI properties
   correctly, and fold complete content lines at <=75 octets without splitting
   UTF-8 characters; continuation starts with one space and counts toward the
   limit. CRLF output. Test long Romanian titles and URLs containing commas,
   semicolons, query parameters and encoded characters. Preserve existing
   custom-title control-character stripping and length cap.
9. Keep valid XML and escaping for titles, names, summaries and URLs. Empty
   filtered feeds remain valid, with truthful titles. Do not fabricate update
   timestamps: use existing row update/publication facts with valid timezone
   representation. No feed format may execute source markup.

## Acceptance evidence

- Parse RSS and Atom fixtures as XML; required metadata, stable IDs, local
  item links and official-source links survive Romanian and hostile text.
- Fixture ID sets for equivalent RSS/Atom/JSON/HTML queries agree before
  format caps; iCal agrees for the subset with dates, ordered by deadline.
  Cover multi-county, keyword, EQF, skills any/all, employer, status and bounds.
- Assert newest-50 and earliest-200 behavior with fixtures exceeding limits;
  visible scope text does not promise full results.
- Malformed scalar arrays and nested facets yield controlled machine-readable
  400 responses on all feed routes, including RSS.
- Browser tests change filters via HTMX, remove chips, page and go back;
  parse generated subscription URLs and assert exact filter values, no page/
  sort, and calendar-only title. Google/webcal URLs encode the same ICS URL.
- Clipboard failure shows selectable URL. Keyboard and no-JS links work.
- Parse/unfold ICS and verify UTF-8, octet lengths, CRLF, URI fidelity,
  stable UID, exclusive DTEND and visible estimated dates. Existing tests pass.

## Later work

Do not silently increase/remove caps or add feed pagination here. Complete
history/catch-up feeds need a retention design (UX-03/UX-04). Open Graph cards
remain UX-04. Email notifications are UX-14, a separate later package requiring
confirmed subscriptions, unsubscribe/retention rules, delivery infrastructure,
deduplication and reliable change evidence for saved-job alerts.

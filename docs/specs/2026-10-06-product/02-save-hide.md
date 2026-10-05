# UX-09 — Save and hide announcements without an account

Status: ready for implementation. Baseline: `6accc59`; preferably implement
after UX-01A. No pipeline/export changes, account service or paid calls.

## Outcome and entry points

Readers can save announcements, return to a shortlist and hide irrelevant
results reversibly. The state stays in their browser. Public filtered URLs,
counts and feeds retain their existing server meaning.

Read `webapp-php/index.php`, `query.php`, `helpers.php` (job_url(),
posting_deadline(), display_title()), `partials/result_list.php`,
`pages/detail.php`, `pages/employer.php`, `inc/header.php`, `inc/footer.php`,
`pages/list.php` (HTMX lifecycle) and existing PHP/browser fixture tests.
There is no `assets/app.js` today: list behavior is inline. Introduce one
small shared script under `static/` for this feature, loaded once by the shared
layout, rather than copying logic into list and detail scripts.

## User behavior

- List, employer and detail announcements have text-labelled “Salvează” /
  “Salvat” toggle buttons, with `aria-pressed`. Add “Ascunde” on list/employer
  rows; an undo action remains reachable outside the disappearing row.
- Shared navigation links to `/salvate/`, labelled “Salvate” with the local
  saved count. The count includes saved snapshots absent from today's export.
- `/salvate/` has “Salvate” and “Ascunse” views. Saved items sort by saved time
  descending; hidden items by hidden time descending. Page locally in batches
  of 25. Each item can be unsaved or restored without reloading the whole site.
- Saved and hidden are independent: hiding a saved item does not unsave it.
  Saved view still shows it with “Ascuns din rezultate” and a restore action.
- On normal result pages, hide only matching rendered rows locally. Do not
  fetch extra pages to fill gaps. Show “X anunțuri ascunse pe această pagină”
  with “Arată” toggle. If all rendered rows are hidden, retain pagination and
  say so rather than claiming the server search has zero results.
- Server result/facet counts remain unchanged. Visible help says “Numărul
  rezultatelor include anunțurile ascunse în acest browser.” Server feeds do
  not exclude hidden items or become shortlist subscriptions.
- On the first save/hide and on `/salvate/`, show: “Salvările și anunțurile
  ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se
  pot pierde dacă ștergi datele browserului.” No first-visit modal or cookies.
- Add “Șterge lista de salvate” and “Restabilește toate anunțurile ascunse”
  with an in-page confirm/cancel step. These clear only their respective state,
  leaving skins and `posturi.facets` preferences intact.

## Local state contract

Use one namespaced localStorage key, `posturi.preferences.v1`, holding a version
and records keyed by the official source URL. Each record contains the positive
numeric posting ID, source URL, last-known local canonical path, title, employer,
location label, last displayed date and its provenance, savedAt/hiddenAt
(independent nullable ISO timestamps) and snapshotAt. Keep snapshots minimal:
no body HTML, attachment contents or candidate/profile information.

Official source URL is the identity; numeric ID is a lookup hint. Changed
slug/canonical local paths update without losing state. Compare the returned
source URL before using an ID lookup result so a rebuilt dataset cannot attach
preferences to another posting. Do not merge different source URLs by title.

Limit to 500 distinct retained records, and never silently evict an existing
save. At the limit, show a message asking the reader to remove records. Delete
records with neither savedAt nor hiddenAt. Validate parsed records and ignore
invalid entries; unsupported versions or wholly corrupt storage show a
recoverable message and explicit reset, rather than silently overwriting data.
Use textContent/escaped server attributes for snapshots, never innerHTML.
Accept source links only on HTTPS `posturi.gov.ro`; local paths must match the
local job route. Render invalid links as plain text, not executable URLs.

Catch unavailable storage, quota errors and failed writes. Keep a working
in-memory session where possible, with visible “Disponibil doar în această
sesiune; browserul nu a putut salva preferințele.” Do not report persistence
after a failed write. Listen for `storage` events for other-tab changes and
read current state before each mutation; sequential edits must not overwrite
other-tab changes. Simultaneous writes may remain last-write-wins in v1.

## Resolving today's data and missing records

Create `/salvate/` as a normal PHP page using the shared layout, with empty,
loading, failure and no-JavaScript states. Do not render the whole export into
HTML. Add a read-only POST JSON endpoint `/preferinte-posturi.json`, receiving
`{"ids":[1001,1002]}`. This endpoint resolves public announcement data only;
it does not save preferences or distinguish saved from hidden IDs.

Accept `application/json`, a body up to 16 KiB and up to 500 unique positive
integer IDs; reject malformed JSON, nested values, booleans, floats, strings,
oversized lists/bodies with controlled 400/413 responses; other methods return
405 and Allow: POST. Use bound SQL placeholders and no default active/status
predicate. Missing IDs are normal and yield no match. JSON response carries
only ID, source URL, canonical path, title, employer, location, current status,
resolved deadline and its provenance. Use `Cache-Control: no-store`; don't log
request bodies, add tracking or enable cross-origin access. Route with explicit
machine-readable errors before shared HTML output. A read-only lookup requires
no account or server-side session.

Resolve only IDs required by the current local page, up to 25 at a time. Render
current data for verified matching identities. On request failure, show cached
snapshot with “Date salvate; actualizarea nu a reușit” and a retry action;
do not interpret the failure as missing/closed.

When an ID is absent or its source URL differs, retain the cached snapshot,
show “Nu mai este disponibil în baza curentă” and its snapshot time, and link
to the validated official source. Do not assert expired/cancelled status or
delete the record automatically. This lightweight saved snapshot does not
replace UX-03's public archive or UX-12's historical change notices.

## Browser lifecycle and accessibility

Use delegated click handlers that continue working after HTMX swaps. Reapply
save/hidden state after swap and history restoration, including browser back
to a list. Do not register duplicate listeners. After hiding, move focus to
the next available action or undo notice. Announce changes through a polite
live region. Controls are actual buttons, usable by keyboard and touch; avoid
nesting them inside the title link. Graceful no-JS rendering preserves normal
job links and shows why the local preference features require JavaScript.

## Acceptance checks

1. Save on list; reload; detail and `/salvate/` agree. Unsave in another tab
   and verify counts/actions update. HTMX pagination/filter/back-forward works.
2. Hide/undo, show hidden, restore all and hide a saved item. Counts and feeds
   retain server scope; all-hidden page still offers recovery and pagination.
3. Update fixture slug and deadline; verified ID/source identity refreshes.
   Remove fixture ID or reuse it for a different source: cached unavailable
   snapshot remains and is never labelled expired by inference.
4. Empty list, 26 records (local pagination), 500-record boundary, storage
   unavailable/quota failure, corrupt JSON, invalid entry and unknown version.
5. A failed lookup shows stale snapshot and retry, without silently clearing
   state. Hostile snapshot text/URLs cannot create markup or executable links.
6. Endpoint tests cover valid lookup, missing and closed rows, wrong method,
   malformed/oversized JSON, nested IDs and SQL-like strings, with no PHP
   warnings. Do not reuse the filtered `/posturi.json` limit/default-active
   behavior for this lookup.
7. Keyboard focus/announcements, other-tab sequential edits, mobile widths
   and no-JS fallback pass. Existing request/deadline/compat suites pass.

## Completion

Keep changes confined to public PHP/static code and fixtures. No schema migration
is expected. Record implementation and checks under UX-09; keep live rollout
separate. Later features: export/import preferences, cross-device sync, account
storage, saved-search subscriptions, change alerts and email delivery.

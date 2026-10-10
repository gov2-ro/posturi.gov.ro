# UX-11 — Browser-local application preparation checklist

Status: specified. Dependencies: UX-13 evidence; DATA-04 role identity; UX-09 state.

## Outcome and scope

Show source-derived required documents and competition stages with checkable
preparation items, official links and extraction uncertainty. Keep document
preparation distinct from submission and stage completion. No uploads, document
contents, email, application submission or server-side progress storage.

## Contract and interaction

Each item has announcement source URL, optional role key, stable item identity,
source revision, label, source/evidence reference and kind (document or stage).
Do not use label text or array index as durable identity. Source facts and
reader-added items are visually distinct; unknown completeness stays visible.
An empty extraction must not say no documents are required.

Persist minimal item IDs/check states in a separate versioned localStorage key,
leaving `posturi.preferences.v1`, facets and layout intact. Reuse UX-09 validation,
quota/session-fallback patterns and explicit local-storage disclosure. Clear
progress with in-page confirmation; saving/hiding and checklist progress are
independent. Do not collect candidate documents or profile attributes.

On a source revision change, retain checked state only for provably identical
items and roles. Show changed/new/removed items for review; retain unresolved
previous progress with its revision rather than silently applying it elsewhere.
Missing current export records or lookup failure preserve cached progress and
show unavailable/stale state, not completed/expired/submitted status.

Checking a document means prepared, never submitted. A passed deadline remains
visible; ticking a stage cannot move or reopen it. Display date precision and
estimated fallback qualification consistently with FIX-02/05.

## Acceptance

Cover reload, cross-tab updates, role reorder, revised/removed requirement,
unknown documents, failed lookup, unavailable record, corrupt/quota storage,
clear/cancel and hostile labels. Verify keyboard/live announcements, mobile and
no-JS source links. Assert no candidate contents or progress in server requests.

## Delivery and validation

Read AGENTS.md, this package, relevant existing specs and current source before
implementation. Confirm actual field shapes rather than treating proposed
contracts as existing APIs. Keep PHP/SQLite public delivery and Django/PostgreSQL
processing. Use token-based styles and deterministic fixtures; run relevant PHP,
Python and browser suites. Document additive migrations, old-export behavior,
before/after metrics on copied data, and rollback where applicable.

This specification authorizes no paid calls, broad backfill, deployment, email
delivery or host changes. Prepare bounded commands and expected evidence for
those later steps. Record implementation separately from rollout in the backlog
and activity log. Writing this spec does not complete its backlog item.

## Implementation notes (first slice)

Status of this slice: implemented locally, not deployed. Server-rendered, no-JavaScript checklist on the
detail page plus a small browser script for check boxes. No export, pipeline, Django or API change; no LLM
or network call. It delivers documents and stages for the posting as a whole; role-scoped documents and
the "unavailable record" listing are not in it (see "What remains").

### Files

`webapp-php/inc/checklist.php` (pure functions, the `UX-11 checklist` section), `partials/checklist.php`
(markup; included by `pages/detail.php` after the calendar section with one `require`), `static/checklist.js`
(the only script; loaded by the partial, not by the shared layout), `tests/checklist_test.php` (CI step),
`tests/browser/checklist.spec.js` (own fixture server), `tests/fixtures/checklist.php` + `make_checklist.php`
(an additive fixture: postings 1101-1107 added to a database of its own, so no count pinned by another suite
moves; `build_db.php` is untouched).

### Item contract

The server builds the list (`checklist_model()`) and embeds it as
`<script type="application/json" id="checklist-data">`, written with `JSON_HEX_TAG | _AMP | _APOS | _QUOT`
so no label can close the element (tested with `</script>` in a document and a stage label).

| field | meaning |
| --- | --- |
| `source_url` | the official announcement URL (`job_postings.url`): the posting's identity, as in UX-09 |
| `source_revision` | `detail_content_hash`; else `pay:` + first 12 hex of sha256(`schema_json`); else `""` (unknown). A hash with unexpected characters is re-hashed (`h:`...), never stored as it came |
| `role_scope` | always `posting` in this slice |
| `items[].id` | `<rev12>:<kind>:<n>`: identity inside ONE revision. Not durable across revisions (n is a position, rev12 changes with the page) |
| `items[].fp` | first 16 hex of sha256 of `kind US role_key US normalised label` (+ for stages `US date US time US est`). Normalised = lower-case, Romanian diacritics and punctuation folded, whitespace collapsed |
| `kind` | `document` or `stage`. `role_key` is `null` on every item |
| `label`, stages: `date`, `time`, `precision` (`date`/`datetime`/`none`), `passed`, `estimated` | as the page shows them; `time` only when the source states one (`00:00` is the date-only placeholder, like the sidebar) |
| `evidence` | `{basis: extracted}` for documents (the model's list; `requirement_evidence` has no document kind, so there is no quote), `{basis: scraped, quote: <calendar row label as scraped>}` for calendar stages, `{basis: resolved, deadline_source}` for the deadline |

**Identity versus fingerprint.** Inside a revision an item is its `id`. The `fp` is never an identity: it is
only the proof that an item in another revision (or an item whose id now points at different content) is the
same. Duplicates (three identical "Copie CI" lines) get three ids and one fp; they are matched one to one.

**Where items come from.** Documents: `schema_json.application_docs`, a markdown bullet string (v2+). Parsing:
top-level `-`, `*`, `+`, `•`, `1.`, `1)`; items indented two columns past the first marker fold into their parent
("acte de studii: diplomă; foaie matricolă"); an unmarked line continues the item above; text before the first
marker is an introduction; with no marker anywhere each line is an item; at most 60. Markdown syntax is removed
(emphasis pairs, link targets), HTML is NOT interpreted: `<script>` stays literal text and is escaped on output.
Stages: the same `calendar_events` rows the page's "Calendar concurs" table already uses (the v4
`competition_calendar.events` are not exported with their quotes, and the table is the page's source), plus the
resolved deadline from `posting_deadline()` as a stage "Termen de depunere a dosarului". The calendar row that is
the submission itself (same date as the deadline, label matching depunere/înscri) is merged into the deadline row
(its hour is used when the deadline carries none) rather than listed twice. Chronological; at most 40.
`passed` uses `days_until()` (Bucharest day, `POSTURI_TODAY` seam). An estimated (`expirare`) deadline is labelled
"Termen estimat pentru depunerea dosarului" and carries the page's own `DEADLINE_FALLBACK_NOTE` and provenance
line; a deadline with no stated hour says "doar data; ora nu este precizată în date".

**Why every role key is null.** `application_docs` and the calendar are posting-level facts: nothing in the
export says a document or a stage applies to one `positions[]` entry, and `posting_roles.role_key` is
revision-scoped and unstable (DATA-04). Attaching a role would claim scoping the source does not make, and a
role key must never carry progress across extractions. The contract keeps the field so a later slice can fill it
when role-scoped documents exist; a null key means "the posting".

### What the page says

Heading "Pregătirea dosarului". Always: "Lista provine din extragerea automată a anunțului și poate fi
incompletă." and the official link (`evidence_safe_url`, `noopener noreferrer`). An empty, missing or non-text
`application_docs` renders exactly "Lista documentelor nu a fost extrasă automat. Consultați anunțul oficial
pentru documentele cerute." (never a list that could read as "none required"; asserted against the phrases
"nu sunt necesare", "fără documente"). No stages: "Etapele concursului nu au fost extrase automat..."; no
deadline: "Termenul de depunere nu este precizat în datele extrase...". A passed stage keeps its date and gets
"Termen trecut" ("Termen estimat trecut" for the fallback). Without JavaScript the section is that read-only list
plus a `<noscript>` note; there is no form, and the one input exists only inside a region that stays `hidden`
until the script runs.

### Browser storage

Separate key `posturi.checklist.v1`; `posturi.preferences.v1` is never read or written by it (guarded by a test
that scans the script).

```
{version: 1, postings: {<source_url>: {
    rev, updatedAt, seen: [fp...],                        // the revision the ids belong to, and the fps of that revision's items
    items:  {<id>: {fp, kind, label (<=120), checked: true, at}},   // checked source items only
    custom: [{id, label (<=120), checked, at}],           // reader-added items
    prev?:  {seen: [fp...], items: [{fp, kind, label, checked: true, rev, at}]}   // unresolved progress, see below
}}}
```

Caps: 200 postings (the oldest by `updatedAt` leaves first, never the current one), 100 entries per posting
(checked items + custom items; the server caps documents at 60 and stages at 40 so the list itself always fits).
Over a cap the action is refused with a message, nothing is silently dropped. Validation is saved.js's: parse,
check shape, types and lengths; wholly corrupt or wrong-version storage is never overwritten (message "Progresul
salvat nu poate fi citit; nu a fost modificat.", session-only); one invalid posting entry is written back
untouched and only that posting goes session-only; each mutation re-reads storage first so sequential edits from
two tabs do not overwrite each other; `storage` events re-render; a failed write or a write that read-back does
not confirm shows "Disponibil doar în această sesiune; browserul nu a putut salva progresul." and the session copy
keeps working. Always visible: "Progresul se păstrează doar în acest browser. Nu se trimite nicăieri, nu se
sincronizează între dispozitive și se poate pierde dacă ștergi datele browserului." Looking at a page writes
nothing; the first action does.

Controls: documents "Pregătit" (prepared, never submitted), stages "Notat" (noted; ticking never moves, reopens
or completes a stage, and dates and "Termen trecut" are server markup the script does not touch). The accessible
name of a box starts with its visible word ("Pregătit: <label>"). Reader-added items: text input (maxlength 120,
no `name`, Enter handled by the script, no `<form>`), shown under "Elementele dvs." in the info colour family with
an "Adăugat de dvs." tag, so they never look like source facts; removable; labels reach the page through
`textContent` only. "Șterge progresul" asks in the page (Șterge / Anulează, Escape cancels, focus goes to
Anulează and back) and removes only this posting's entry. A polite `role="status"` region announces check,
uncheck, add, remove, dismiss and clear.

### Revision change

The record's `rev` differs from the current `source_revision` (or an id now points at an item with a different
fp). Nothing is applied by looking; the script computes, for each current item, the stored checked entry with
the same id AND fp (same revision), else the same fp and kind (one to one). Those states carry over; nothing else
does. The review box "Anunțul s-a modificat de la ultima vizită" then lists: items with no fp among the
fingerprints the reader had last seen (new or modified; a stage shows its date) and stored checked items that
matched nothing, each with its stored label, "din versiunea anterioară" and the date it was ticked. On the first
action the record moves to the current revision; the unmatched checked entries stay in `prev` with their own
`rev` until "Am înțeles". They can still re-match by fp if the page flips back. Unknown current revision (`""`):
no identity is claimed, source boxes are disabled, stored progress is shown as "Progres salvat, neconfirmat
pentru versiunea curentă" and left untouched; reader-added items still work.

### Old exports and rollback

Reads `calendar_events`, `schema_json`, `url`, `data_limita_depunere` and, when present, `detail_content_hash`;
exports missing the deadline or hash columns fall back (`posting_deadline()`'s expiry estimate, labelled as one;
the payload hash) and render without a warning (asserted on a copy with `apply_deadline`, `deadline_source`,
`application_status`, `detail_content_hash`, `schema_source_revision` dropped; `compat_test.php` renders the page on
every older shape too). Rollback: delete the `require` line in `pages/detail.php`; the partial, script, helper
file and the browser key are inert without it (stored progress simply stays unread).

### Tests

`tests/checklist_test.php` (273 assertions: parsing, plain text, fingerprint, revision, ids across revisions,
stages/passed/estimated, caps, duplicates, no-JS markup, hostile text and JSON embedding, token-only styles,
script guards, old export) and `tests/browser/checklist.spec.js` (22 tests at 320/375/1280: reload, cross-tab,
revision with identical/changed/new/removed and dismiss, identical list under a new hash, duplicates, unknown
documents, passed deadline, estimated deadline, unknown revision, corrupt / wrong version / invalid entry /
unreadable / quota / unconfirmed write, clear with cancel and confirm, hostile reader and source text, caps,
keyboard and announcements, privacy: no request, no `fetch`/XHR/beacon, no form, URL and preferences key
unchanged, no-JS, overflow and 44px touch targets). `UX11_SHOTS=<dir>` writes screenshots.

### What remains

* Role-scoped documents: needs the extraction to say which role a document belongs to (a prompt change and a paid,
  separately authorised re-extraction); until then every item is posting-level and `role_key` is null.
* "Unavailable record" state: the detail page for a missing posting is a 404, so progress is only reachable while
  the posting exists. Listing progress outside a detail page (and showing it as unavailable/stale, never
  completed or expired) belongs with UX-12's read surface (`/salvate/`-style lookup); this slice builds no listing.
* UX-07 tracking of applications is not started: nothing here records that a dossier was submitted.
* Evidence quotes for documents (no document kind in `requirement_evidence`); a reset for wholly unreadable
  storage (saved.js has one; here it stays session-only and untouched); real-device and assistive-technology
  checks (Safari, Firefox, screen readers) are acceptance gaps until exercised.

# UX-10 — A compact role-specific “Can I apply?” panel

Status: specified. Dependencies: DATA-04 and UX-13; supports later UX-07 matching.

## Scope and state

On a detail page, a reader chooses a role and supplies education, relevant years
of experience and licences needed for that role. No CV upload, account, ranking
service or server-side candidate storage. Candidate inputs stay in page memory
in this first slice; a reset clears them. Do not put inputs in URLs, analytics or
lookup requests. No automatic persistence of sensitive candidate attributes.

## Comparison contract

Use a deterministic comparison over the same role's verified requirements.
Return per-requirement matches / missing / unknown with a concrete explanation
and source-evidence disclosure. Unknown candidate input, ambiguous equivalence,
inferred requirements or unsupported evidence produces unknown. An explicit
zero-experience requirement is distinct from an absent requirement.

Compare education only under reviewed qualification/equivalence rules; an EQF
level alone does not establish a required field of study or accepted diploma.
Experience must distinguish total from specialty experience. Licence aliases
come from a reviewed vocabulary; string similarity cannot establish equivalence.
Alternative requirements preserve their AND/OR grouping; if grouping was lost,
show unknown. General source conditions outside supported fields remain visible
as conditions the reader must verify.

Do not issue an overall eligible/ineligible verdict, recommend falsifying facts,
or merge qualifications from different roles. Present this as preparation aid
with the official announcement and employer as the decision authority. A role
change clears or explicitly reconfirms role-specific answers.

## Acceptance

Fixtures: matching/missing/unknown education, specialty mismatch at equal EQF,
zero versus absent experience, partial licences, alternatives, ambiguous evidence
and two contrasting roles. Assert no cross-role combination and no candidate data
in network requests/storage/URLs. Check keyboard, mobile, reset and no-JS source
requirements. Normal tests make no model calls. Record supported comparison rules
and unresolved rule classes before release.

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

Status of this slice: implemented locally, not deployed, no LLM or network call, **not released**. The comparison rules
below are a product decision as much as an engineering one; release needs pax's review of "Supported comparison rules (v1)"
and "Unresolved rule classes", and of the evidence-strictness switch.
The panel therefore ships gated off: `ELIGIBILITY_PANEL_ENABLED = false` in `webapp-php/inc/eligibility.php`
(env `POSTURI_ELIGIBILITY_PANEL=1`/`0` overrides per process; the tests turn it on). Releasing is flipping
that constant after the review.

### What it is

A native `<details>` on the detail page, collapsed by default, "Pot aplica? — verificare orientativă", placed after
"Cerințe structurate". Its body opens with "Un ajutor de pregătire, nu o decizie. Doar angajatorul, pe baza anunțului
oficial, stabilește cine este admis." and the official link. Without JavaScript the body shows "Comparația funcționează în
browser, cu JavaScript activat. Cerințele din sursă sunt listate mai sus." (the requirements are on the page anyway); the
script hides that note and reveals the form.

| piece | where |
| --- | --- |
| data for the browser (per role, from `posting_roles`, `requirement_evidence`, and the same role's entry of `schema_json`) | `webapp-php/inc/eligibility.php` (`eligibility_panel_data()`) |
| markup, row templates, embedded JSON (`<script type="application/json" id="eligibility-data">`) | `webapp-php/partials/eligibility_panel.php`, one `require` in `pages/detail.php` |
| comparison and wiring (pure `compare()`, exported through `module.exports` for Node tests; DOM code runs only in a browser) | `webapp-php/static/eligibility.js` |
| stable ids for "vezi sursa" | `evidence_anchor_id()` and an optional `$id` of `evidence_disclosure()` in `helpers.php` (adds `id="ev-<ordinal>-<kind>-<index>"`; no text changed) |
| fixtures | `tests/fixtures/eligibility_db.php` (postings 1040-1044 added to the evidence fixture), `tests/fixtures/make_eligibility.php` |
| tests | `tests/eligibility_test.php` (server side, CI), `tests/browser/eligibility.spec.js` (rule matrix in Node + panel at 320/375/1280) |

### Privacy contract

The reader's answers live in the form controls only. `eligibility.js` makes no request, writes no localStorage, sessionStorage
or cookie, and never touches the URL (the "vezi sursa" link is handled in script so no `#fragment` appears). The controls sit in
a plain group, **not** in a `<form>`: a control with no form owner cannot be submitted whatever its attributes. Only the
credential radios carry a `name`, because a native radio group needs one (arrow keys, one tab stop per group); no other control
has any, and every control has `autocomplete="off"`. `eligibility_test.php` greps the script for storage / network / HTML-injection
APIs and `eligibility.spec.js` records every request, the storage, the cookies and `location.href` while the form is used. Text
reaches the page through `textContent` only; row markup and its classes are cloned from `<template>`s the server renders, so the
styles are role/semantic tokens (`ok`, `alert`, `note`) and Tailwind sees every class in the PHP file.

### Supported comparison rules (v1)

Each requirement of the SELECTED role becomes one row: `match` ("Îndeplinit"), `missing` ("Lipsește") or `unknown` ("De verificat"),
with a concrete explanation, and a "vezi sursa" link to the evidence disclosure on the page when the row has one. The rows never
use another role's data. Rows, in order: education level, field of study (only when the role lists fields), experience, one row
per document, and a fixed final row "Condiții generale (cetățenie, vârstă, stare de sănătate, cazier etc.) — verificați în anunț."
(always unknown). A neutral tally ("N îndeplinite · M lipsă · K de verificat") is the only summary; there is no overall verdict,
and no text suggests changing an answer to qualify.

Before a row is compared it passes three gates, in this order; the first that applies makes it `unknown`:

1. **Evidence strictness.** The requirement's evidence status is not `verified` (unverified, absent, or no row at all) ->
   "Cerința nu are un citat verificat în sursă; verificați anunțul." This is one constant, `REQUIRE_VERIFIED_EVIDENCE = true`
   in `eligibility.js`, a policy switch; `compare()` takes `cfg.requireVerified` so tests exercise both settings. Today every
   document is `absent` (the schema has no evidence field for credentials), so **documents are always "De verificat"**: the
   explanation still shows what the reader answered ("Ați răspuns: Da."). Relaxing the switch compares on the extracted value; for an
   undecomposed posting that value can be a regex inference (`inf_studies_required`, `inf_experience_years`), which is why it ships strict.
2. **Alternatives.** The education / experience sentence (the evidence quote, and the schema's `verbatim`) contains a standalone
   "sau" or "ori" (any case, whole word), or a document label does -> "Anunțul indică variante alternative; verificați care vi se
   aplică." The extraction keeps one level / one number per block, so the AND/OR grouping is lost. Coarse on purpose ("de două ori"
   also trips it); that can only produce "De verificat".
3. **Advantage.** The extraction marked the education or the document `required: false` ("avantaj") -> unknown, never "Lipsește".

Then:

- **Education level.** Order `generala < profesionala < liceala < postliceala < licenta < master < doctorat` (the order of
  `schema_models.StudyLevel`, asserted in `eligibility_test.php`). Required level NULL -> "Nivelul de studii nu a fost identificat."
  (checked before the gates: with no requirement there is nothing to verify). Reader "Nu știu" -> unknown. Reader >= required ->
  match, "Nivel: <reader> — cel puțin <required>"; else missing, "Nivel: <reader> — se cere cel puțin <required>".
- **Field of study** is a separate row and always unknown: "Domeniul cerut: <labels>. Echivalența diplomei nu este verificată automat."
  An EQF level never establishes a field or an accepted diploma (the data embedded has no EQF number at all).
- **Experience.** An explicit zero (`exp_none_required = 1`, or `exp_years = 0`) is a match, "Nu se cere experiență.", distinct from
  `exp_years` NULL, which is unknown, "Cerința de experiență nu a fost identificată." (checked before the gates, like education).
  A minimum together with `none_required = 1` is contradictory -> unknown. A positive minimum with `in_specialty = 1` compares the
  reader's specialty years (empty -> "Completați vechimea în specialitate."); with `in_specialty = 0` it compares total years (empty
  -> "Completați vechimea totală."); with `in_specialty` NULL it is a match only if specialty years >= the minimum, missing only if total
  years < the minimum, otherwise "Nu reiese dacă se cere vechime în specialitate.". Years are 0 to 80, steps of 0.5, comma or dot;
  anything else, or specialty years above total years, is unknown with its own message. Added rule: when `in_specialty` is 0 but the
  sentence itself speaks of "specialitate" / "domeniu" (the schema's default for a silent sentence is `false`, so the flag cannot be
  trusted) the row is unknown ("Anunțul vorbește de specialitate sau domeniu, dar extragerea nu a marcat ...").
- **Documents.** The reader answers Da / Nu / Nu știu about the EXACT label the source extraction gave ("Dețin: <label>"); there is
  no aliasing and no similarity. Under the shipped policy every row is unknown (gate 1). Relaxed: Da -> match, Nu -> missing, Nu știu
  -> unknown.
- **Role change** clears the document answers (their list belongs to the previous role) and says "Răspunsurile pentru documente au fost
  resetate pentru noul post."; education and experience are facts about the reader and stay. Reset clears everything.

### Unresolved rule classes

None of these is guessed; each shows "De verificat" or is not offered.

- **Field equivalence.** No reviewed table says which diplomas / specialisations satisfy which field; the field row is therefore always
  unknown. Needs a reviewed vocabulary of fields (ISCED-F broad fields are too coarse to accept or refuse a diploma).
- **Licence / certificate aliases.** "Permis categoria B" vs "permis de conducere" vs "categoria B" are not equated; the reader
  answers about the source's own label. A reviewed alias vocabulary (and credential evidence, below) is needed before a document can
  ever be "Îndeplinit" on strength of a different spelling.
- **Credential evidence.** Documents have no evidence field in the schema, so gate 1 makes them all unknown today. Lifting that needs a
  prompt change and a separately authorised re-extraction (UX-13 "What remains").
- **AND/OR grouping.** Alternatives are detected, not modelled. A reviewed representation of "A sau B" / "A și B" per requirement would
  let some alternatives resolve.
- **Several qualifications in one posting-level sentence** and requirements that apply to all roles are not shared across roles (DATA-04's
  no-inheritance rule); a decomposed posting's role shows only what its own entry states.
- **Skills, languages, age, citizenship, health, criminal record, exam** are not compared (the general-conditions row says so). Skills
  have evidence rows but the reader is not asked about them in this slice.
- **Experience qualifiers** (a named domain, "în domeniul ...", seniority of the post held, public vs private sector) are not modelled; a
  specialty year count is a number, nothing more.
- **Seniority / grade / tenure rules of public-service law** (gradație, vechime în funcții publice) are not modelled.

### Where the panel does not appear

- An export without `posting_roles` (a temp-view stand-in with `role_format = 0`, whose rows are the posting's flat columns and carry no
  evidence): omitted. For a posting with `positions[]` those columns blend roles, which this panel must never do.
- A posting whose roles state nothing comparable (no level, no experience, no field, no document).
- An export without `requirement_evidence`: the panel appears and every requirement reads "no verified quote" (status `none`), which the
  strict policy turns into unknown. No row is invented.

Postings with no schema (regex-inferred flat values only) also get a panel under the same rules; all its rows are then unknown by gate 1.
Whether to hide the panel when no requirement of any role has verified evidence is an open question (below).

### Rollback

Remove the `require` of `partials/eligibility_panel.php` from `pages/detail.php`. `inc/eligibility.php`, `static/eligibility.js`, the
partial, the fixtures and the optional `$id` of `evidence_disclosure()` can stay (the id only adds an attribute). No table, export or
data change was made.

### Tests

`tests/eligibility_test.php` (389 assertions; CI): study-level order against `schema_models.StudyLevel`, alternatives detection, evidence
state, anchors, panel markup and placement, per-role embedded values and no cross-role text, hostile titles and labels, no form / no
submittable control / names only on radio groups, implicit role, per-role evidence / alternatives / advantage / specialty-flag data, a
posting with nothing comparable, exports without `requirement_evidence` and without `posting_roles`, static guards on the script, token
styles. `tests/browser/eligibility.spec.js`: the rule matrix in Node (education pairs, evidence strictness for every kind, the policy
switch both ways, alternatives, advantages, field row, explicit zero vs absent, specialty vs total vs not stated, bad input, documents,
tally, no verdict wording), and in the browser at 320/375/1280: collapsed by default and keyboard open/close, education match / missing /
unknown, equal level in different fields, zero vs absent experience, unverified evidence, alternatives, partial licences, two contrasting
roles (switching changes rows and clears document answers), reset, the whole panel from the keyboard (Tab order, type-ahead, arrows,
Space, Enter), "vezi sursa" opening the same role's disclosure with the URL untouched, an implicit role, hostile text, no overflow,
privacy (no request, storage, cookie or URL change), and no JavaScript.

# UX-13 — Source evidence beside important requirements

Status: specified. Dependencies: DATA-04 for role scope; coordinates with DATA-03.

## Outcome and contract

Education, experience and licence requirements can reveal their source sentence
and document location. Read current positions/schema evidence, cached attachment
extraction, export shape and PHP detail sections. First slice is read-only detail
UI and a verified evidence contract, not an in-site document viewer or backfill.

For each requirement retain role key, normalized fact, evidence text, official
source URL, source revision/content hash and locator. HTML may use a section/text
locator; attachment page numbers require extraction that actually preserves pages.
Never fabricate a page from flattened text. Separate stated, inferred, estimated,
missing and unverifiable evidence; absence of evidence does not prove absence of
an eligibility requirement.

Validate quotes against the exact normalized source revision with a documented
normalization rule. Keep raw text where needed to explain normalization. A quote
that cannot be located must show unavailable/unverified evidence; it cannot render
as a verified quotation. Changed source bytes invalidate evidence verification.
No paid re-extraction is needed to display honest missing-evidence states.

## Interaction

Use a keyboard-accessible disclosure next to the fact, labelled in Romanian.
Show quoted text, source/document label, verified location when available and
source link. Escape source text and validate links; no arbitrary HTML. Preserve
main requirement visibility with no JavaScript. Do not present inferred facts as
source quotations, or an official link as proof the quoted sentence was verified.

## Acceptance

Fixtures cover HTML quotes, paginated PDF text, DOCX with no page locator,
Romanian diacritics, whitespace normalization, duplicate sentences, altered
source revision, missing evidence and hostile text/URLs. Verify role association,
source/inference labels, keyboard/no-JS behavior and old exports. Report verified,
unverified and absent evidence with denominators on a copied sample.

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

Status of this slice: implemented locally, not deployed, not backfilled, no LLM or network call.
It delivers a verified evidence contract (`requirement_evidence`), the detail-page disclosure and
honest missing-evidence states. Pages, per-file documents and evidence for languages/credentials
are not in it (see "What remains").

### Contract: the `requirement_evidence` table

New additive table in the SQLite export (`export-to-sqlite.py`). The rules live in the repo-root
module `evidence.py` (`evidence_rows(posting, schema, roles, body, attachments)`, `locate()`,
`source_hash()`; pure, no Django/psycopg), so the export, the tests and a later history command
share one definition. One row per extracted fact of one role:

| column | meaning |
| --- | --- |
| `posting_id`, `ordinal`, `fact_kind`, `fact_index` | primary key (its prefix serves the page's `WHERE posting_id = ?` lookup, so no separate index). `ordinal` is the `posting_roles` ordinal; `fact_kind` is `education`, `experience`, `skill` or `credential`; `fact_index` is the index in the role's list (0 for education/experience) |
| `role_key` | the role's `posting_roles.role_key` (revision-scoped, not stable across revisions, see DATA-04) |
| `fact_label` | short normalized fact: `minimum_level`, "3 ani în specialitate", the skill label, the credential label |
| `basis` | always `extracted` in this slice: every fact is a model extraction. Nothing is derived (EQF is a function of the study level and is not emitted). A future `inferred` row must never carry a quote |
| `quote` | the model's own text (`education.verbatim`, `experience.verbatim`, `skill_list[].evidence`), at most 600 characters, NULL when absent. NOT the source |
| `status`, `reason` | see below |
| `part`, `section`, `occurrences` | verified rows only: `anunt` (the page) or `anexe` (the attachments); the nearest markdown heading above the match (page text only, at most 120 characters); how many times the whole quote occurs in that part |
| `source_revision` | `detail_content_hash` of the page now held (NULL = unknown) |
| `extraction_revision` | `schema_source_revision`, the page hash the extraction ran against (NULL = unknown) |
| `text_hash` | sha256 of the exact raw texts the check ran against: `body_markdown`, NUL, `attachment_text` |
| `normalization` | `fold-alnum-v1` |

Rows exist for the education and experience blocks (only when the block states a fact), every
skill and every credential, for every role in `posting_roles`. A decomposed posting has rows for
its `positions[]` entries only, read from that entry alone; its posting-level blocks get no rows and
the page shows no evidence beside them (they are not a role's facts). An undecomposed posting has
ordinal-0 rows from the flat fields. A v2 payload, a posting with no schema and a block that states
nothing have no rows. `attachment_text` is selected to run the check (for postings that have a
schema only) and is never written to `job_postings` or the file.

### Normalization rule

`fold-alnum-v1` is `grounding.normalise()`, reused on purpose: NFKD, combining marks dropped
(so `ş ș ţ ț ă â î` equal `s s t t a a i`), lowercase, everything outside `[0-9a-z]` is a separator,
runs of separators collapse to one space. Quote and source are folded the same way and compared as
substrings on whole words (a quote starting mid-word does not match). Case, markdown, punctuation
and whitespace therefore never decide the result. A quote is split on ellipses (`…`, `...`, `[...]`)
and every segment must occur in the SAME part, in order. `evidence.fold_with_map()` is a second
implementation that also records the raw offset of each folded character (used to find the section);
the tests assert it returns the same string as `grounding.normalise` on fixed and random input.
grounding.py's lenient word-coverage screen is not used: it is for hallucination screening and would
mark a paraphrase as supported.

A quote, or any of its segments, shorter than 12 folded characters or 3 words is too short for a
match to mean anything and is `unverified/too_short`.

### Status and reason

| status | reason | meaning | page label |
| --- | --- | --- | --- |
| `verified` | NULL | the quote was located in `part` (the page wins when both contain it) | "Sursa", with the quote in a `<blockquote>` |
| `unverified` | `not_found` | a quote exists, no part contains it | "Sursă neverificată": plain "Text extras automat (neverificat)" |
| `unverified` | `too_short` | too short to verify | same label; "Citatul este prea scurt pentru a fi verificat." |
| `unverified` | `stale_revision` | not found, and the extraction ran against an older page revision than the one held (both known and different) | same label, plus "versiune mai veche a anunțului" |
| `unverified` | `no_source_text` | there is no page and no attachment text to check against | same label |
| `absent` | NULL | the schema has the field but the model left it empty | "Fără citat": "Lipsa unui citat nu înseamnă că cerința nu există — verificați anunțul oficial." |
| `absent` | `no_evidence_field` | the schema has no evidence field for this kind (every credential) | same |

Verification is always against the current exported text; a quote that is still found is `verified`
even when the extraction is stale, and `source_revision`/`extraction_revision`/`text_hash` record what
was compared. Changed source bytes change `text_hash`, and the next export re-runs the check (a
previously found quote can become `not_found`; tests cover both). The official link is shown with every
state and is worded "Anunțul oficial": it is a convenience, not a claim that the sentence was verified.

### Locator limits

* No page number is ever produced. `attachment_text` is every attachment's text joined with a blank
  line, PDF page breaks discarded, so a hit in the attachments is reported as "documentele atașate
  (documentul exact nu este identificat)". The page links to the posting and, for an attachment hit,
  to `announcement_url` when it is an absolute http(s) URL.
* To report a document and a page the pipeline would need per-file text with page boundaries kept by
  `extract_attachments` (an ordered list of `{file, page, text}`), stored beside `attachment_text`, and
  a locator `{document, page, char_start, char_end}` in this table. That is a data-model change and a
  re-extraction of attachments (no LLM cost, but not free), so it is out of this slice.
* The section is the nearest markdown heading (an `#` line or a bold-only line) above the first
  occurrence. When the same sentence occurs more than once under different headings, no section is named
  and the page says how many times it occurs.
* The extraction input was the page plus attachments with the HG 1.336/2022 boilerplate stripped and
  cut at 100,000 characters; the check runs on the full, unstripped texts, so it can also find a quote
  in text the model was not shown (harmless: it is still a sentence of the source).

### Page

`pages/detail.php` loads the posting's rows once (`load_requirement_evidence()`) and renders, with
`evidence_fact_list()` / `evidence_disclosure()` in `helpers.php`, a native `<details>` beside each fact:
in the flat "Cerințe structurate" block (education, experience, skills, credentials; ordinal 0 only) and
in each role block (that role's ordinal only). The fact stays visible outside the disclosure; it works
with no JavaScript and from the keyboard (Tab, Enter, Space); every piece of text goes through `e()`;
links must be absolute http(s) (`evidence_safe_url()`), anything else (`javascript:`, `data:`, relative)
renders no link. Styles use role tokens and the info / note / neutral families only (verified: info,
unverified: note, absent: neutral); the CSS gained `border-l-2`, `max-w-full`, `max-w-prose`,
`opacity-80`, `whitespace-pre-line` and `open:basis-full` (the rebuild also emits a stray `.ordinal`
utility: Tailwind scans that word in the PHP test fixtures).

Two small changes outside the disclosure, both to give evidence an honest anchor:

* The flat block now shows an "Experiență" line (from `experience`, via `role_experience_label()`) for
  a posting that is not split into roles. It was not shown there before; a decomposed posting still
  shows each role's own experience only.
* A skill chip's hover text (`title`) used to print the model's evidence unlabelled. Where the
  posting has an evidence row for that skill the disclosure replaces it; elsewhere it is unchanged.

### Old exports and rollback

An export without the table is answered by an EMPTY temp view of the same name
(`shim_requirement_evidence()` in `webapp-php/db.php`; same columns, `WHERE 0`), so the page queries
unconditionally and renders as before: no disclosures, no error, no label that would read as "we checked
and found nothing". Asserted against a copy of the fixture with the table dropped
(`tests/evidence_test.php`) and against the compat shapes (`compat_test.php`: every column but no
evidence table; the oldest shape with no roles and no evidence). Old PHP against a new export is
unaffected: the table is additive. Rollback is reverting the `detail.php` blocks and the helpers (the
CSS classes can stay); the table and the export code can stay too. The export's `verify()` refuses a
build with evidence rows for postings that are not exported or a `verified` row without a quote or part.

### Metrics on a copied sample

Read-only run of `evidence_rows` over the local `posturi_dev` (the stale 2026-09-09 copy): every
posting with a `schema_json`, 2,836 postings (49.3 MB of text), all of v3 shape. 1,539 of them have at
least one fact row, 1,332 at least one verified row. The copy has no `schema_source_revision`, so
`stale_revision` could not occur and is not measured here. Rows by kind (denominator = rows of that kind):

| | verified | unverified (not_found / too_short) | absent |
| --- | --- | --- | --- |
| education | 1,728 / 2,969 (58%) | 299 (138 / 161) | 942 |
| experience | 1,682 / 2,621 (64%) | 90 (43 / 47) | 849 |
| skill | 5,220 / 9,467 (55%) | 1,616 (338 / 1,278) | 2,631 |
| credential | 0 / 6,456 | 0 | 6,456 (`no_evidence_field`) |

Undecomposed postings (11,902 rows): education 1,049 / 1,211 (87%) verified, experience 966 / 1,052
(92%), skills 4,633 / 6,184 (75%); absent: 1, 20 and 0. Decomposed postings, role rows (9,611 rows):
education 679 / 1,758 (39%), experience 716 / 1,569 (46%), skills 587 / 3,283 (18%); absent 941, 829 and
2,631, nearly all because the model leaves `verbatim`/`evidence` empty inside `positions[]` entries (the
quote is missing, not the requirement; a prompt matter, not a re-extraction this slice may pay for).

Of the 8,630 verified rows 6,999 are in the page text (4,822 of them, 69%, with a section) and 1,631 in
the attachments only; 1,114 (13%) occur more than once. Of the 519 `not_found` rows 514 would pass
grounding.py's lenient screen (education 138 / 138, skills 334 / 338, experience 42 / 43): the model
mostly paraphrases or joins two lines, which a lenient check cannot tell from a quotation, so the strict
check is what makes "verified" mean something. Matching without word boundaries would have recovered only
10 of the 519. The 1,278 too-short skills are short tags such as "GIS" (a one-word quote verifies
nothing).

Cost: the full export (9,756 postings) took 3.3 s without evidence and 8.8 s with it on this machine
(attachments are fetched only for postings that have a schema).

Reproduce on a current export:

```sql
SELECT fact_kind, status, COALESCE(reason, '-'), COUNT(*) FROM requirement_evidence GROUP BY 1, 2, 3;
SELECT part, COUNT(*), SUM(section IS NOT NULL), SUM(occurrences > 1) FROM requirement_evidence WHERE status = 'verified' GROUP BY 1;
SELECT COUNT(*) FROM requirement_evidence WHERE reason = 'stale_revision';
SELECT COUNT(*) FROM requirement_evidence e JOIN posting_roles r USING (posting_id, ordinal) WHERE e.role_key <> r.role_key;  -- 0
```

### Tests

`webapp/tests/test_evidence.py` (120 tests: HTML/markdown quote with section, attachment hit, diacritic and
cedilla/comma variants, whitespace and markdown, ellipsis order, duplicates, too short, not found, absent,
changed text and hash, stale revision, credentials, decomposed role isolation, normaliser parity,
hostile text kept raw, and the real `export()` against a fake cursor including `verify()` and the coverage
lines). `webapp-php/tests/evidence_test.php` (the three states and their Romanian labels, quotation only for
verified rows, hostile text and links, role isolation, flat block of a decomposed posting shows nothing,
old export); `compat_test.php` gained the "no requirement_evidence" shapes; `browser/evidence.spec.js` opens
disclosures with Enter and Space at 320, 375 and 1280 (it serves its own fixture, built with
`make.php <path> evidence`). The PHP fixture builds the table always and fills it for postings 1009 and 1013
with `build_fixture_db($path, true, true)`. CI runs `evidence_test.php`.

### What remains

* Document and page locators (see "Locator limits"); fixtures for paginated PDF text and DOCX with no page
  locator need that data model. Today every attachment hit is "no page locator", as DOCX would be.
* Evidence for languages and credentials, and for `positions[]` entries that state no quote: needs a
  prompt change and therefore a paid, separately authorized re-extraction. Credentials are always `absent`.
* An `inferred` basis with a stated derivation (EQF from study level) if a surface ever needs one.
* Reproduce the metrics on a current export (with `schema_source_revision`, so `stale_revision` is
  measured) and decide whether one-word skill tags should be allowed to verify when the word is
  distinctive (today 14% of skill quotes are `too_short`).
* A deploy-gate check in `ops/check-export.py` (coverage floor, no orphan rows) beyond the export's own
  `verify()`.
* UX-12 (`posting_facts.py`) also records evidence sentences; it should read this table or call
  `evidence.locate` so the saved-job change view and the page cannot disagree.
* Found while testing hostile fixtures: the detail sidebar's "Anunț oficial" file link prints
  `announcement_url` without checking the scheme (a `javascript:` value becomes a live href). The data comes
  from the scraper, so it is a hardening item, outside this slice; the evidence links do validate.
  *Fixed 2026-10-10:* `attachment_list()` now drops any attachment URL that `evidence_safe_url()` rejects,
  and `evidence_test.php` asserts no `javascript:` href anywhere on the page.

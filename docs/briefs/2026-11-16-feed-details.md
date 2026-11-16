# Brief — Richer item details in the feeds (RSS, Atom, iCal)

Owner: Sonnet builds; Opus reviews. Date: 2026-11-16. Baseline: `f8220d5`.

## Goal

Each feed item now carries one line: județ, tip, stare, termen, and the source link. The detail page shows much more. Each RSS, Atom and iCal item must carry most of the facts that the detail page shows. Each item must say that the extracted data comes from an automatic process. When the LLM extraction did not run for a posting, the item carries only the parsed data and a note that says so.

## Decisions (made by the owner, 2026-11-16)

- Depth: a short facts block first, then the long text sections. Cap each text section at about 600 characters, on a word boundary, and link to the full page.
- iCal: keep one event per posting (the deadline). Put the exam dates in DESCRIPTION as lines.
- Reuse: move the detail page's inline fact building into shared helpers. The detail page HTML must not change.

## Out of scope

- `feeds/jobs.json.php`.
- Item identity: the RSS `guid`, the Atom `id` and the iCal `UID` stay the same.
- The selection (`build_filters()`, FTS), the order and the caps (`FEED_ITEM_LIMIT`, `FEED_CAL_LIMIT`).
- Any file outside `webapp-php/helpers.php`, `webapp-php/pages/detail.php`, `webapp-php/feeds/`, `webapp-php/tests/` (feeds test and its fixture), `docs/backlog.md` and `docs/activity-log.md`. Stop and ask before you change another file.

## Work

### 1. Shared helpers — `webapp-php/helpers.php`

Move the inline block at `pages/detail.php` (about lines 492–533) into two functions:

- `posting_inferred_conditions(array $p): array` returns the `$cond` map (label ⇒ value): `Program`, `Telemuncă`, `Calculator`, `Experiență`, `Studii`, `Salariu`. It reads `inferred_of($p)` or the `inferred` JSON, as the page does now.
- `posting_contract_terms(array $schema): array` returns the `$terms` map: contract, program, ore/săptămână, perioadă de probă, ture, grad, then `v5_facts($schema)`.

`detail.php` calls the two functions. Its HTML output must stay byte-identical. Prove this: save the HTML of 3 fixture postings before the change, then diff after the change.

### 2. The content builder — `webapp-php/feeds/_feed.php`

- `feed_rows()`: add `LEFT JOIN posting_text t ON t.posting_id = j.id` and select `t.schema_json, t.sal_json, t.attachment_meta, t.v3_bibliography` (the columns the builder needs). Do not change the WHERE, the ORDER or the LIMIT.
- Load `calendar_events` for all selected rows in one query (`WHERE posting_id IN (…)`). Do not query once per row.
- `feed_details(array $r, array $events): array` returns ordered groups, as plain text only. Each group is `['heading' => …, 'facts' => [label => value]]` or `['heading' => …, 'text' => …]`. The groups, in order:
  1. **Din pagina oficială** (parsed, no LLM): the facts of `feed_summary_lines()`, plus localitate, nr. posturi, ocupație (`occ_canonical`), contact (persoană, telefon, email), publicat, expiră, and the attachment names (`attachment_list()`).
  2. **Calendarul concursului**: the `calendar_events` rows. When there are none, use `llm_calendar_rows($schema)` from `inc/v5.php`, and mark the group as extracted.
  3. **Extras automat** (only when the row has a schema): `posting_inferred_conditions()`, `inferred_tags()`, `posting_contract_terms()`, the estimated salary from `sal_json` (range, `net_label()`, and the `disclaimer`), the structured education (`EQF_LABELS`, fields of study) and experience (`role_experience_label()`), and the exam topics (bibliography, at most 15 topics).
  4. **Text sections**: the sections of `render_schema_sections()` (atribuții, studii, condiții specifice, dosar, contact pentru depunere…). Convert each to plain text. Cap each at about 600 characters on a word boundary and add „… (continuarea pe pagina anunțului)”.
  Leave out a fact that has no value. Never show „—” or an empty label.
- `feed_extraction_note(array $r): string` returns one of three notes:
  - No `schema_json`: „Extragerea automată nu a rulat încă pentru acest anunț. Afișăm doar datele din pagina oficială.”
  - `schema_source_revision` is set and is not equal to `detail_content_hash`: „Datele extrase automat provin dintr-o versiune anterioară a anunțului. Pot fi incomplete sau greșite.”
  - Otherwise: „Datele marcate «extras automat» sunt deduse de un program din textul anunțului. Pot fi incomplete sau greșite. Textul oficial este pe posturi.gov.ro.”
- Two renderers:
  - `feed_details_html(array $groups, string $note): string`: `<p><em>note</em></p>`, then for each group an `<h3>`, then `<ul><li><b>label:</b> value</li></ul>` or `<p>text</p>`. Escape every value with `htmlspecialchars(…, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Do not pass model HTML through.
  - `feed_details_text(array $groups, string $note, int $text_cap): array`: plain lines for iCal.

### 3. The formats

- `jobs.rss.php`: `<description>` = `feed_details_html()` + the official source link + a link to the local page. `xml_text()` still escapes it once more.
- `jobs.atom.php`: keep `<summary type="text">` as it is now (the one line, for list views). Add `<content type="html">` with the same HTML as RSS.
- `jobs.ics.php`: DESCRIPTION = the current lines, then the calendar lines, the facts and the note, from `feed_details_text()`. Cap the text sections at about 300 characters, because calendar clients show DESCRIPTION in a small pane. Do not change `ical_text()`, `ical_uri()` or `ical_fold()`.

### 4. Tests — `webapp-php/tests/feeds_test.php`

- In the test's own copy of the fixture, give one row a current v4-shaped `schema_json` and a `sal_json`, give one row a v5 `schema_json` with a stale `schema_source_revision`, and leave one row with no schema.
- Assert, for each format:
  - each of the three notes appears on the correct row;
  - a parsed fact (contact email) and an extracted fact (studii, salariu estimat) appear in RSS, in the Atom `<content>` and in the ICS;
  - a section over the cap is cut and ends with the continuation text;
  - the hostile title row: no `<script>` element after the XML is parsed, and the HTML in `<description>` / `<content>` has no unescaped markup from data;
  - the XML parses; every ICS line is at most 75 octets.
- All the current assertions (identities, caps, selection agreement, 400 path) must stay green.

## Done when

- `php webapp-php/tests/feeds_test.php` passes, and every other test in `webapp-php/tests/` that passed at the baseline still passes.
- The detail HTML diff of 3 postings is empty.
- `xmllint --noout` accepts `/posturi.rss` and `/posturi.atom` from the fixture server.
- `docs/backlog.md` has the item, marked `[x]`. `docs/activity-log.md` has an entry with **Done / Hard / Decided** and these measurements: bytes of `/posturi.rss`, `/posturi.atom` and `/posturi.ics`, before and after, on the fixture and on a local export when one is available.

## Report to Opus

What you measured, what you assumed, and every place where you departed from this brief, with the reason.

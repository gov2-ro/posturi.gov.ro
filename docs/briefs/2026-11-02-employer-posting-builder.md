# Brief — UX-EMP-01 Employer posting builder („Anunț structurat”)

Owner: Sonnet builds; Opus reviews. Date: 2026-11-02. Baseline: `7cbe836`.
Payload rule: [ADR 0002](../decisions/0002-employer-posting-payload.md). Read it first.
Plan: `~/.claude/plans/let-s-also-offer-a-rustling-stearns.md`.

## Goal

An employer opens `/pentru-angajatori/anunt/`, fills in a structured form and downloads a .docx anunț. The browser remembers the institution data and the drafts. Nothing goes to the server. The .docx carries the structured data as described in ADR 0002.

v1 covers **one post per anunț** and **contractual staff only** (HG 1336/2022). It does not cover civil servants (OUG 57/2019 has other intervals). The page says this.

## Constraints

- HTML pages are cached publicly (`s-maxage=3600`). The PHP page is a static shell. All state is in `localStorage`.
- JS has no build step. Add plain files in `webapp-php/static/` and load them with `defer` and `?v=filemtime`, like `saved.js`. Load them only on this page.
- Read `~/devbox/gov2/gov2ro-design-system/COMPONENTS.md` first. Use a DS component when one fits. Use the role tokens and the five semantic families. Do not add raw palette classes or hex values. Do not edit `static/skins/gov2.css`.
- Romanian UI copy: short sentences, active voice, one term per concept. The terms are **„anunț”**, **„ciornă”**, **„profilul instituției”**.
- Do not change `posturi.preferences.v1`, `posturi.profile.v1`, `posturi.searches.v1` or `posturi.checklist.v1`.
- Stop and ask before you change a spec, an ADR, a token, a `.g2-` class or a file that this brief does not name.

## Files

| File | Change |
|---|---|
| `webapp-php/index.php` | route `/pentru-angajatori/anunt/` (with and without the slash) → `pages/employer_builder.php` |
| `webapp-php/pages/employer_builder.php` | new; copy the shape of `pages/eligibility_tool.php` |
| `webapp-php/static/employer-builder.js` | new: form, stores, meter, calendar, preview, export |
| `webapp-php/static/employer-docx.js` | new: builds the .docx and the payload (ADR 0002) |
| `webapp-php/static/vendor/docx.min.js` | docx **9.11.0** `dist/index.iife.js`, minified once with terser; write the version and the command in a header comment |
| `webapp-php/static/ro-holidays.js` | new: the holiday list (below) |
| `webapp-php/inc/footer.php`, `pages/employers.php`, `pages/employer.php` | one link each: „Pentru angajatori: scrie un anunț structurat” |
| `webapp-php/tests/browser/employer_builder.spec.js` | new Playwright test |
| `webapp-php/tests/fixtures/employer_payloads/*.json` | new: at least 4 payloads (full, minimal, temporar vacant, diacritics and `& <`) |
| `webapp/tests/test_employer_payload.py` | new: Python checks (below) |
| `docs/backlog.md`, `docs/activity-log.md` | your entries |

## Work

### 1. Form

Sections, in this order. Labels are Romanian. The stored field names are the v5 names (`schema_models.JobPostingExtractionV5`), so that the payload `data` needs no mapping.

1. **Instituția** — name, CUI, address, locality, county, phone, e-mail. These come from the institution profile.
2. **Postul** — title, number of posts (`vacancies_total`), department, `vacant` / `temporar_vacant`, contract duration and norm (`contract`), work schedule, place of work (`jobLocation`, `work_localities`).
3. **Cerințe** — study level and field (`education`), experience years or „fără experiență” (`experience`), credentials (`credentials`), languages (`language_list`), other specific conditions. Show a short note: the general conditions of art. 15 apply to everyone; do not copy them.
4. **Salariul** — amount or range, gross, lei/month (`baseSalary`). The field is not required, but an empty field shows the nudge: „Candidații văd salariul înainte să aplice. Anunțurile fără salariu primesc mai puține dosare.” Do not claim a legal obligation.
5. **Atribuții** — one item per line (`responsibilities`).
6. **Dosarul** — the 11 `DOCUMENT_CODES` as checkboxes, with the labels of `inc/v5.php:50`. Copy the labels into JS; a test checks that the two lists are equal. Then free items (`documents.specific`).
7. **Bibliografie și tematică** — one item per line (`bibliography_topics`).
8. **Depunere** — channels (`application.channels`: sediu, poștă, e-mail, online), address or e-mail, fee (`application_fee`), contact person (`application_contact`).
9. **Calendar** — see section 4.

Art. 18(2) lists the mandatory content of an anunț. Mark the fields that cover art. 18(2) a)–e) as „obligatoriu (art. 18 alin. 2)”. Download stays possible with empty mandatory fields. The meter shows them.

### 2. Stores

Copy the defensive pattern of `static/saved.js` (`readStore` `:117`, verified write `:148`). When a stored value is corrupt or has another version, do not write over it. Show the feature as unavailable.

- `posturi.employer.v1` — `{version:1, profile:{…section 1 fields, default channels, default document codes}}`.
- `posturi.employer-drafts.v1` — `{version:1, currentId, drafts:{<id>:{id, title, updatedAt, data:{…}}}}`. Limit: 30 drafts.
- Autosave each draft on `input`, at most once each 500 ms.
- Actions: „Ciornă nouă” (prefilled from the profile), „Duplică”, „Șterge”, a list of the drafts with their titles and dates.
- „Salvează ca profil” copies section 1 and the defaults into the profile.
- „Exportă datele” downloads one JSON file with the profile and the drafts. „Importă datele” reads that file. On import, a draft with the same id is kept and the imported one gets a new id. Nothing is overwritten.
- A notice beside the form: „Datele rămân doar în acest browser. Pe un calculator comun, șterge-le când termini.”

### 3. Completeness meter

Use the 15 keys, labels and weights of `classify.QUALITY_CHECKS`. Compute them from the form:

- `text` and `attachment` are always 1 for builder output, because the .docx is the complete anunț. This is an assumption. Write it in a code comment.
- `confirmed` is 1 when the calendar has a deadline for submission. `window` follows `classify.quality` (≥10 days → 1, ≥7 → 0.5), measured from the publication date to that deadline.
- `specific` follows `classify.quality`: no skills, or at most half transversal.
- All other checks read the matching v5 field.

Show the score out of 100 and a list of the missing items in Romanian. Each item links to its form section.

**Parity test** (`webapp/tests/test_employer_payload.py`): for each fixture payload, build the `p` dict for `classify.quality()` (schema = `data`; contact, dates, `deadline_source='anunt'`, `v3_contract_duration` and `v3_schedule` from `data.contract`; `body` = 800 × "x", so that `text` and `attachment` are 1). Compare its `checks` with the JS result. The Playwright test writes the JS results for the same fixtures to `test-results/employer_quality.json`. If the comparison needs a value that `data` does not hold, stop and report it.

### 4. Calendar helper

The user enters the publication date and chooses `vacant` or `temporar_vacant`. The helper proposes the stages below. Every date stays editable. Beside each date, show the article and „propunere”. When an edited date breaks a minimum, show a warning (`alert` family). Do not block the download.

Source: HG 1336/2022, consolidated text of 2026-03-26 (a copy on arcub.ro). Verify each article against legislatie.just.ro before you ship. Report any difference.

| Stage | Vacant | Temporar vacant | Article |
|---|---|---|---|
| Last day for dosare | 10 working days from publication | 5 | art. 34 |
| Selection of dosare | ≤ 2 working days after the last day | ≤ 1 | art. 36(1) |
| Results of the selection | ≤ 1 working day after the selection | same | art. 37(1) |
| Contestații on the selection | ≤ 1 working day after the results | same | art. 53 |
| Results of those contestații | ≤ 1 working day after the contestații deadline | same | art. 54(1), 56(2) |
| Written / practical test | ≥ 15 working days after publication, and after the stages above | ≥ 10 | art. 18(1) |
| Results of the test | ≤ 1 working day after the test | same | art. 43(2) |
| Contestații and their results | 1 + 1 working days | same | art. 53, 54(3) |
| Interview | ≤ 4 working days after the written test | same | art. 41(5) |
| Interview results, contestații | as for the test | same | art. 53, 54(3) |
| Final results | ≤ 1 working day after the last contestații results | same | art. 56(3) |

Counting: art. 98(1) counts the first and the last day. Art. 98(3) moves a start on a non-working day to the next working day. **Assumption:** the publication day is day 1 of the art. 34 window. Write this in the UI help text („Am numărat ziua publicării ca prima zi”). Put the counting in one function with unit tests in the Playwright spec (or a small Node test), so that Opus can change the rule in one place.

**Holidays** (`ro-holidays.js`): the legal holidays of Codul muncii art. 139 for 2026 and 2027, including Orthodox Easter (2026-04-10/12/13, 2027-04-30, 2027-05-02/03) and Rusalii (2026-05-31, 2026-06-01, 2027-06-20/21). Check the current list of art. 139 before you write the file. Report the source and the date that you checked. Outside 2026–2027, the helper counts only weekends and shows „zilele libere legale nu sunt cunoscute pentru acest an”.

### 5. Preview and download

- The preview looks like the content of `pages/detail.php`, in the same order as the .docx.
- „Descarcă anunțul (.docx)”: `employer-docx.js` builds the file with docx.js. It uses headings, bullet lists and one table for the calendar. File name: `anunt-<slug of the title>-<YYYY-MM-DD>.docx`.
- Embed the payload exactly as ADR 0002 says: Custom XML part, the patched rels and content types, the `posturi.posting` custom property with the sha256. Use `docx.Packer.compiler.compile()` and `crypto.subtle.digest`.
- „Descarcă datele (.json)”: the payload alone.
- Next to the buttons: „Datele structurate se pierd dacă salvezi anunțul ca PDF. Publică fișierul .docx.”

## Verification (report each result)

1. Playwright `employer_builder.spec.js` at 375 and 1280 px:
   - fill each section; reload; all values remain;
   - new draft, duplicate, delete; export, clear storage, import; the drafts come back;
   - a corrupt `posturi.employer-drafts.v1` value is not overwritten, and the page says the feature is unavailable;
   - the calendar for publication on 2026-04-07 (vacant) skips 2026-04-10 and 2026-04-13;
   - download the .docx; unzip it; `customXml/item1.xml` has the namespace; the hash in `docProps/custom.xml` matches the payload.
2. `test_employer_payload.py`: each fixture validates with `JobPostingExtractionV5.model_validate`; the payload read back from a downloaded .docx equals the fixture; the quality parity holds.
3. Round trip: `/Applications/LibreOffice.app/Contents/MacOS/soffice --headless --convert-to docx` on a downloaded file; the payload survives. Use `-env:UserInstallation=` with a scratch profile.
4. All PHP suites pass. `php webapp-php/assets/check-skins.php` reports no new problems (baseline: 69 for `gov2.css`).
5. `npm run css` after template changes; commit the built `static/app.css`.

**Not in your scope:** a test in Microsoft Word (pax does it, see ADR 0002), multi-role postings, a pipeline reader for the payload, prefill from a past posting, a salary hint from `salary_grid.py`.

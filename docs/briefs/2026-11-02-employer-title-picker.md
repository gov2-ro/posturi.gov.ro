# Brief — UX-EMP-04 Title picker in the employer assistant

Owner: Sonnet builds; Opus reviews. Date: 2026-11-02. Baseline: `687f31f` + this brief's commit.
Read first: [ADR 0002](../decisions/0002-employer-posting-payload.md) (the `occupation` block, amended today), the [UX-EMP-03 brief](2026-11-02-employer-assistant-tuning.md), `webapp-php/pages/employer_builder.php`, `webapp-php/static/employer-builder.js`, `webapp-php/static/employer-docx.js`, `ops/build-institutii-snapshot.py`, `ocupatii.py` (`parse_title`, `GRADE_TOKENS`), `classify.py` (`employer_kind`, `EMPLOYER_KIND_LABELS`).

## Goal

The employer chooses the post title from the standard occupations that our title normalisation uses. The dialog first shows the occupations that are most common for the employer's kind of institution. When the employer picks one, the payload carries its COR code (ADR 0002 `occupation` block). Free text stays possible.

## Measured (Opus, local `posturi_dev`, postings to 2026-09-09)

- `jobs_occupation`: 3,767 normalised titles → 821 distinct `canonical` labels, each with a `cor_code`. 9,750 postings have an `occupation_id`.
- Top 10 occupations for each `classify.employer_kind()`, and the share of that kind's postings they cover:
  - primărie: 2,376 postings, 61%
  - spital: 2,285, 59%
  - școală: 1,908, 74%
  - asistență socială: 481, 60%
  - militar: 447, 65%
  - kind `""` (unclassified): 476, 39%

## Constraints

The constraints of the UX-EMP-01 and UX-EMP-03 briefs stay. Port 8823 is shared: check that it is free before each Playwright run. Do not change the storage keys or their versions. Do not change the payload format beyond the `occupation` block of ADR 0002.

## Work

### 1. Data — `ops/build-title-suggestions.py` (new)

- It reads Postgres (`DATABASE_URL`, default `postgres://localhost/posturi_dev`), like `normalize-titles.py`.
- It writes `webapp-php/static/ocupatii.json`:

  ```json
  {"built_at": "…", "source": "posturi_dev, postings to <max published_at>",
   "occupations": [{"id": 0, "label": "Referent de specialitate", "cor": "<COR code>", "n": 412,
                    "grades": ["gradul I A", "gradul I", "gradul II", "debutant"]}],
   "by_kind": {"primarie": [[<occupation id>, <count>], …], "": […], "*": […]}}
  ```

- `occupations` holds one entry per distinct `canonical`. When a canonical has several COR codes, take the most frequent one. `n` is its posting count. Sort by `n`, descending.
- `grades` is the list of `parse_title(p.title).grade_token` values seen with that occupation in at least 2 postings, ordered by frequency. Keep the empty token out.
- `by_kind`: for each `classify.employer_kind(employer name)`, the top 15 occupations with counts. `"*"` is the top 15 over all postings.
- Print the totals and the file size. Commit the generated file.

### 2. Kind in the institution snapshot

`ops/build-institutii-snapshot.py` adds `kind` = `classify.employer_kind(name)` to each row. Rebuild the 42 county files, report the kind distribution and the size change, and commit them.

### 3. The dialog

- Next to „Denumirea postului”, add a button „Alege din listă”. It opens a dialog. Use the DS dialog if `COMPONENTS.md` has one. Otherwise use a native `<dialog>` with a focus trap, Escape to close, and the focus returning to the button.
- The dialog has three parts:
  1. **„Cele mai frecvente la <kind label>”**: the `by_kind` list of the institution's kind, at most 12 rows. Each row shows the label, the COR code and „în N anunțuri”.
     - The kind comes from the `kind` of the institution picked in the autocomplete (stored as `meta.institution.kind`).
     - Without a kind, show the `"*"` list with the heading „Cele mai frecvente”.
  2. **Search** over all 821 labels: fold diacritics and case, match all words, show at most 20 results.
  3. **Grade**: after a pick, a select of that occupation's `grades`, plus „fără grad / treaptă”. Hide it when `grades` is empty.
- A preview line shows the composed title: `label` + `, ` + grade, for example „Referent de specialitate, gradul IA”. Display the grade token with its compact Romanian spelling: `gradul I A` → „gradul IA”, `treapta I A` → „treapta IA”.
- „Folosește denumirea” writes the composed title into `occupation_title` and stores `meta.occupation = {id, cor, label, grade}`. „Renunță” changes nothing.
- The title field stays editable.
- Load `ocupatii.json` on the first opening of the dialog only.

### 4. Payload

- Build the ADR 0002 `occupation` block from `meta.occupation`: `{cor_code, label, grade?}`.
- Leave the block out when `occupation_title` no longer starts with `label` (fold diacritics and case), or when `meta.occupation` is absent.
- The preview and the .docx show the title only, not the COR code.
- Add fixtures:
  - one with an `occupation` block and a grade;
  - one where the title was edited after the pick, so the block is absent.
- Python checks that `cor_code` exists in `jobs_occupation`. When Postgres is not available, read the COR code from `ocupatii.json` instead.

## Verification (report each result)

1. Playwright at 320, 375 and 1280 px:
   - pick „Comuna Ciugud” in Alba; open the dialog; the heading names the primării and the first row is the top occupation of `by_kind.primarie`;
   - search „referent spec” and pick it; the grade select appears; pick „gradul I A”; the title is „Referent de specialitate, gradul IA”; the payload has the `occupation` block;
   - edit the title to „Consilier”; the payload has no `occupation` block;
   - keyboard: open, Tab inside the dialog, Escape, focus back on the button;
   - no institution picked: the dialog shows the `"*"` list;
   - no horizontal overflow; the dialog fits at 320 px.
2. `test_employer_payload.py` passes with the new fixtures.
3. The two scripts: their totals, the sizes, and the kind distribution.
4. The whole browser suite, the PHP suites and `check-skins.php` pass. Run `npm run css` and commit `app.css`.

Do not edit `docs/backlog.md` or `docs/activity-log.md`. Put the text in your report.

**Not in scope:** templates of common choices (UX-EMP-03a); a pipeline reader that uses the `occupation` block (UX-EMP-02).

# Brief — Trade from the title, one name per occupation, COR in the archive; spike: the domain from COR

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: the tree after brief 1 (`2026-11-17-themes-admin-financiar-chips.md`).

Status: **ready, not started.** This is brief 2 of 4. Brief 3 (`2026-11-17-theme-meserii.md`) depends on it.

Read first:
- `README.md` (pipeline)
- `docs/backlog.md` items UX-05-DOMAIN and UX-05-THEME-SPECIALTY-HISTORY
- `ocupatii.py`, `normalize-titles.py`, and the export of `archived_postings` in `export-to-sqlite.py`
- the memory notes on the LLM providers: only DeepSeek works locally
- the memory notes on the VPS: no SSH from the Mac

## Goal

The themed pages and the occupation chips (brief 1) show the occupation (`occ_canonical`) as the main label. Three defects of that label and of the archive hurt them. Part A corrects them. Part B measures a COR-based professional domain, so that Opus can write an ADR before the vocabulary of `family` changes.

## Measured

On the live site (2026-11-17) and on the 17 Oct export:

- **The trade is lost.** 98 of 140 skilled-worker postings (family `tehnic`, COR 7) have the occupation "Muncitor calificat" (85) or "Muncitor" (13). The titles name the trade: "Muncitor calificat – electrician", "muncitor calificat treaptă profesională I (electrician)", "Muncitor calificat IV fochist". Likely cause: `ocupatii.parse_title()` drops the text after a dash unless it is a grade, so "– electrician" goes as if it were a department. Verify the cause. The same loss can hit "Inginer" (9 of 42 engineers on the live site) and "Tehnician".
- **One occupation has two names.** Examples:
  - "Îngrijitor copii" / "Îngrijitor de copii"
  - "Infirmier" (40) / "Infirmieră" (3)
  - "Asistent medical radiologie" / "Asistent medical de radiologie"
  - "Muncitor întreținere" / "Muncitor de întreținere"
  - "Contabil-șef" / "Contabil șef"
  - "Supraveghetor noapte" / "Supraveghetor de noapte"
  - "Instructor ergoterapie" / "Instructor de ergoterapie"

  Brief 1 reports more of them.
- **The archive has no COR group and no specialty.** `archived_postings` has `occ_canonical` and `inf_profession_family` only. So the weekly chart of a themed page cannot filter by COR group (brief 3) or by specialty.
- **The domain field is noisy.**
  - "Îngrijitor" is in 5 families: 100 in `tehnic` COR 5, 23 in `altele`, 12 in `sănătate`, and some in `social` and `cultură`.
  - "Bucătar" is in `tehnic` and in `altele`.
  - "Paznic" has COR group 9 (COR 5414 is the security guard).
  - Under `tehnic` COR 2 ("Ingineri") there are 2 nurses and 1 physiotherapist.
  - In the export, 63 of 102 postings of `tehnic` COR 7 have the bare group "7", with no unit group.

## Constraints

- Run every measurement and every re-run against the local database. Do not run anything against production, and do not deploy. If the local database is not available, stop and ask.
- LLM calls: count the titles that need a new normalisation and estimate the cost first (`llm_ledger`). Stop and ask before a run that costs more than USD 2 or touches more than 2,000 titles. Use DeepSeek.
- Do not change the grade or the study hint that `parse_title()` gives: the salary grid uses them. The existing tests and doctests of `ocupatii.py` must pass unchanged.
- Part B changes nothing in the pipeline, the export or the webapp. It is a measurement.
- Do not change `build_filters()` or a query parameter.

## Work

### Part A1 — Keep the trade (`ocupatii.py`, `normalize-titles.py`)

1. Confirm the cause on 20 real titles of COR 7 with the occupation "Muncitor calificat" or "Muncitor". For each title, show what `parse_title()` returns.
2. When the base occupation is generic ("Muncitor calificat", "Muncitor", "Inginer", "Tehnician"), keep a tail after a dash or in brackets when it names an occupation. Check the tail against the COR names (`Cor.candidates()`), not against a hand-made list.
3. When a title names more than one post ("muncitor calificat fochist, muncitor calificat intretinere, sofer"), keep the first. Report how many titles have this form.
4. Re-normalise only the titles whose parse changes (`--force` for those titles). Respect the cost limit of the constraints.
5. Measure before and after, on the active postings and on all postings:
   - the share of "Muncitor calificat" + "Muncitor" in COR 7
   - the share of "Inginer" in COR 2
   - the share of "Tehnician" in COR 3
   - the number of distinct occupations
   - the grade and the grid row of the changed titles: they must not change, or report each change

### Part A2 — One name per occupation (`ocupatii.canonical_label()` or the normalisation cache)

1. Group the canonical labels by COR code (`occ_cor_code`). List each code that has more than one label, with the counts.
2. Propose a rule. For example: one label for each COR code, the most frequent one or the COR name. Also say what happens when a code is missing. Show the merges that the rule makes, then stop and wait for an answer from Opus. A merge across two different COR codes needs an explicit decision.
3. After the answer: apply the rule in the pipeline, not in the webapp, so that the filter, the chips and the pages read one name.

### Part A3 — COR group and specialty in the archive (`export-to-sqlite.py`)

1. Add `occ_group` and `med_specialty` to `archived_postings`, with the same values and the same derivation as in `job_postings`.
2. Run a local export. Report the row counts and the share of archived rows with a COR group and with a specialty.

Brief 3 uses `occ_group`. UX-05-THEME-SPECIALTY-HISTORY uses `med_specialty`.

### Part B — Spike: the professional domain from the COR code (measure only)

1. Propose a table from COR codes to the 15 landing domains (`HOME_DOMAINS`). Use the ISCO sub-major group (2 digits) or minor group (3 digits), and the unit group (4 digits) where the major group is not enough. For example, 2221 nurse → Sănătate, 5153 caretaker → Pază, îngrijire și servicii, 5414 guard → Pază, îngrijire și servicii.
2. Compare it with today (`inf_profession_family` plus the COR split of `tehnic`), on the active postings and on all postings:
   - a matrix: today's domain × proposed domain
   - the 30 largest moves, as occupation, count, from and to
   - the postings with no COR code, or with only a 1-digit group
3. Say which postings need the LLM family as a fallback, and how many there are.
4. List what a change of the `family` vocabulary breaks: saved searches (ADR 0001), alerts, feeds, old URLs with `family[]=tehnic`, the landing items, the themed pages, `quality_check.py`.

Report the table and the numbers. Opus writes the ADR. Do not build Part B.

## Tests

- The doctests of `ocupatii.py`, and the Python tests that cover `parse_title()`, `canonical_label()` and the export. Add tests:
  - a title with a dash and a trade keeps the trade
  - a dash with a department still drops the department
  - a dash with a grade still keeps the grade
  - two spellings of one COR code give one label (after A2.3)
  - the archive has `occ_group` and `med_specialty`
- Each new check must fail against a named mutation.
- Run the PHP suites and the Playwright run on the new local export. The site must not change except for the occupation names.

## Files you may change

- `ocupatii.py`, `normalize-titles.py`, `export-to-sqlite.py`
- their tests
- `docs/backlog.md`, `docs/activity-log.md`
- a new report in `docs/audits/`, for the measurements of Part A and Part B

Stop and ask before you change any other file, a prompt in `models_config.json`, or a schema in `schema_models.py`.

## Done when

- Part A1 and Part A3 are done. Part A2 is done after the answer of Opus.
- The Part B report is in `docs/audits/`.
- The report gives each command and its result, what you measured and what you assumed.
- `docs/backlog.md`:
  - Add the items of Part A.
  - Leave UX-05-DOMAIN open, with a link to the Part B report.
  - Close UX-05-THEME-SPECIALTY-HISTORY only for the export part. The use on the page stays open.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first. The VPS gets the change through the normal deploy after the review.

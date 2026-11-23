# Brief: CUI and org.gov2.ro link on the employer pages (DATA-ORG-06)

Date: 2026-11-23. Author: Opus. Builder: Sonnet.
Backlog: DATA-ORG-06. Source: the ciulei crosswalk (`../ciulei/data/posturi-org/crosswalk.csv`).

## Goal

An employer page shows the CUI of the institution and a link to its record on org.gov2.ro.
The page shows nothing new when the employer has no verified link.

## Decisions (Opus, 2026-11-23)

1. **Source: the ciulei crosswalk, not org `institutii.csv`.** The owner made ciulei to hold
   the crosswalks between gov2 projects. The crosswalk already has one row per employer slug,
   with `status`, `cif` and `org_url`. Reading `posturi_slugs` from `institutii.csv` means
   that we split a list column and repeat the join that ciulei already does.
2. **A committed snapshot, not a live path.** The pipeline runs on the VPS. `../ciulei` is
   on the Mac only (ciulei confirmed this on 2026-11-23; it has no git remote yet). So an ops script copies the needed columns into
   this repo, and the export reads the copy. This is the pattern of `declaratii.GROUPS_CSV`.
3. **Show only verified links.**
   - `status = linked`: show the CUI and the link.
   - `status = linked_no_cif`: show the link only.
   - `status = unmatched`: show nothing. Its `org_url` points to a proposed `P` record,
     which nobody has reviewed.
   - Never show `candidate_cifs`. They are guesses (546 rows, 423 of them unmatched).
4. **Contact data (address, phone, email) is out of scope.** The crosswalk does not carry it.
   The employer assistant snapshot (`static/institutii/`) has it; a later item can join it
   by `org_id`.

## Where the data is

- The crosswalk: `../ciulei/data/posturi-org/crosswalk.csv`, 7,630 rows, built from org
  v0.69.0 (ciulei `e314ded`, 2026-11-23) and the full posturi employer export of 2026-11-23
  (7,630 slugs). 6,417 `linked`, 228 `linked_no_cif`, 985 `unmatched`.
- Employers created on the VPS after 2026-11-23 have no row. Their page shows nothing new.
  This is correct behaviour, not an error.
- The live counts come from the VPS export. Ask the VPS session for them after the deploy.

## Files you may change

- new `ops/build-employer-org.py` (copies the crosswalk into the snapshot)
- new `data/org/employer-org.csv` (the snapshot, committed)
- `export-to-sqlite.py`: a new table, see step 2. Do not change the `employers` table.
- `ops/check-export.py`: one soft rule (a warning, not a failure) when the table is empty
- `webapp-php/pages/employer.php` (the header block only) and a helper, if you need one, in
  a new file under `webapp-php/inc/`. Do not edit `helpers.php`.
- PHP tests and fixtures next to the existing ones (`webapp-php/tests/`)
- `README.md` (a short section), `docs/activity-log.md`, `docs/backlog.md`

Another session may work in this checkout. Read a shared file again just before you edit it.
Stage by path; never `git add -A`, stash or reset.

## Work

1. **Snapshot.** `ops/build-employer-org.py --from ../ciulei/data/posturi-org/crosswalk.csv`
   writes `data/org/employer-org.csv` with the columns `slug, status, org_id, cif, org_url`.
   It keeps only the rows with `status` `linked` or `linked_no_cif`. It checks that each
   `cif` is digits only, and that `org_url` starts with `https://org.gov2.ro/`. It prints the
   counts per status and the number of rows it rejected. The output is sorted by slug.
   Standard library only.
2. **Export.** `export-to-sqlite.py` reads the snapshot and writes the table
   `employer_org(employer_id INTEGER PRIMARY KEY, org_id TEXT, cif TEXT, org_url TEXT)`.
   It joins on `employers.slug`. It prints how many snapshot rows match an employer and how
   many do not. A missing snapshot gives an empty table and a warning, not a failure.
3. **Page.** In the header of `employer.php`, under the name, one line:
   „CUI 12345678 · Fișa instituției pe org.gov2.ro →” (the link opens in the same tab).
   With no CUI, show only the link. With no row, show nothing. Use the role tokens only
   (see CLAUDE.md, the palette rule). Check COMPONENTS.md in the DS for a fitting pattern.
4. **Tests.** A PHP test for the three cases (CUI and link, link only, nothing). A test or
   an assertion for the script: a `P` record never reaches the snapshot.

## Acceptance

- The snapshot has 6,645 rows (6,417 `linked` + 228 `linked_no_cif`) from the crosswalk of
  org v0.69.0 (ciulei `e314ded`), or the script says why not.
- Deploy only after org v0.69.0 is live on org.gov2.ro. Before that, some `org_url` links do not
  resolve on the live site. Ask ciulei or org-dev before the deploy.
- No page shows a link to a `P` record, and no page shows a candidate CUI.
- `php webapp-php/tests/*_test.php` and the browser spec for the employer page pass.
- Report the match count from step 2 on `data/_dev/full-2026-11-23.sqlite` (the full VPS export of 2026-11-23, not in git). Expect 6,645 matches.

## Open points for the owner

- Ciulei has a remote since 2026-11-23 (`gov2-ro/ciulei`). Later, the VPS can clone it and
  the pipeline can rebuild the snapshot itself. Until then, rebuild the snapshot on the Mac
  after each ciulei rebuild.
- The crosswalk contract is `status`: a consumer never links a row with status `unmatched`.
  Ciulei keeps `org_url` for those rows, so the snapshot script must filter on `status`.

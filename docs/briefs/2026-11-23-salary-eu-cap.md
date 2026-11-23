# Brief — the guide cap as context on project posts (SALARY-EU-CAP)

Date: 2026-11-23. Author: Opus. Builder: Sonnet. Owner decision: SALARY-EU-CAP, accepted as proposed (2026-11-23).

## Goal

A project hire that names the programme PoIDS or PEO and states no pay shows one more line in the project box of the detail page:

> Ghidul programului plafonează plata la 35–90 lei net/oră, după rol și experiență.

The line is context, not a figure (ADR 0008 rule 5). It changes no figure, no filter, no sort, no chart and no list row.

## Read first

- `docs/audits/2026-11-23-eu-guide-rates.md` (the table, the scope sentence, the sources).
- `docs/audits/2026-11-23-eu-rules-pcidif.md` § 2 (SMIS counts).
- `classify.py` and `export-to-sqlite.py`: where `project_hire` is set and exported.
- `webapp-php/pages/detail.php` lines 518–545 (the project box) and `webapp-php/tests/project_pay_test.php`.

## Steps

0. **Guide version.** Look for a version of the PoIDS or PEO general guide (*Ghidul solicitantului – condiții generale*) later than March 2023, on mfe.gov.ro or a beneficiary site. If a later version changes the table on the pages of the cap, stop and report. Record what you searched and found in the activity log.
1. **Programme.** Add `proj_programme` (`pids` | `peo` | NULL) next to `project_hire`, set only when `project_hire = 1`. Source: the posting body and bibliography. PoIDS: „Programul Incluziune și Demnitate Socială”, „PoIDS”, „PIDS”, or SMIS 339395. PEO: „Programul Educație și Ocupare”, a whole-word „PEO”. When both match, NULL. Export the column. Unit tests in `webapp/tests/test_classify.py`.
2. **Detail page.** In the project box, when there is no stated pay (`$proj_pay` is null) and `proj_programme` is set, show the line above under the existing note, with `data-pay-project-cap`. Add a short `title` or a note: „Plafonul este suma maximă decontată de program, nu salariul postului. Sursa: Ghidul solicitantului – condiții generale, martie 2023.” Use the existing role tokens; no new class.
3. **Measure.** On `data/_dev/full-2026-11-23.sqlite` after a local re-export (or a direct count with the same rule): postings with `proj_programme` = pids, = peo, and how many of them get the line (no stated pay). Read 10 matches of each by hand and report false positives.

## Out of scope; stop and ask

- No change to `SALARY_EXPR`, `salary_bucket`, the sort, the chart or the list.
- No change to the PCIDIF or PNRR posts.
- No role-to-tier mapping: the line shows the whole range.
- Stop and ask if a step needs a change to ADR 0008, a spec, or a file that this brief does not name.

## Done when

- `project_pay_test.php` and the Python tests pass, with a test for: line shown, line hidden when the pay is stated, line hidden for a post with no programme.
- A screenshot of one PIDS post at 375 px and 1280 px.
- Counts and false positives in `docs/activity-log.md`; SALARY-EU-CAP updated in `docs/backlog.md`. Not deployed.

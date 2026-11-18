# Brief — Pay of project-hired posts (SALARY-EU-DETAIL + PIPELINE-PROJECT-PAY)

Date: 2026-11-19. Author: Opus. Builder: Sonnet. Backlog: SALARY-EU-DETAIL (D1), PIPELINE-PROJECT-PAY (D3).
Evidence: `docs/audits/2026-11-18-salary-eu-projects.md` and `docs/audits/2026-11-18-salary-eu-sample.csv` (spike SALARY-EU-SPIKE).
Owner decisions, 2026-11-19: D1 yes, D2 yes (separate item, SALARY-EU-ESTIMATOR, not this brief), D3 yes.

**Start only after UX-SALARY-WIZARD is committed and reviewed:** both briefs change
`export-to-sqlite.py` and `webapp-php/helpers.php`.

## Problem

A post hired for an EU or PNRR project is paid at the hourly rate of the project (Art. 16
alin. (10^2) Legea 153/2017; HG 234/2023). The salary grid does not apply. Today the site shows a
grid figure for most of these posts: 355 of 472 flagged postings (export of 2026-11-18, data to
2026-09-09). The figure is wrong two ways: more than half are part-time (39 of 60 sampled), and
the stated hourly rate is 1.9–3.9× the grid's hourly pay (median 3.0, 8 postings). 34 of 472 state
a pay in the body or an attachment; the export holds 2.

## Decisions (Opus)

1. **Deterministic, not an LLM.** Add two functions to `classify.py`, called in `export-to-sqlite.py`
   where `funding_kind` is computed (body + attachment text). Do not change an extraction prompt
   or schema: that re-extracts every active posting.
   - `project_hire(text, funding_kind) -> bool` — true when `funding_kind` is set **and** the text
     says the post is hired for the project: „în afara organigramei”, a fixed-term contract for
     the project („pe perioada implementării proiectului”, „pe durata proiectului”), „echipa de
     implementare”, „expert … în cadrul proiectului”, and similar. Build the patterns from the
     spike sample and the 472 flagged postings. The aim is to drop the 1–2 % false flags, not to
     find new projects.
   - `project_pay(text) -> dict | None` — `{amount, unit: "ora"|"luna", basis: "brut"|"net"|"",
     hours_per_month, quote}`. Patterns from the spike: „N lei/oră”, „tarif orar”, „salariu
     brut/net N”, „N ore/lună”. When the text gives several roles with several rates, take none
     and leave a note in the report (no mixing of roles). A rate under 15 lei/oră or over 500
     lei/oră, or a month amount under 1.000 lei, is not pay: return None.
2. **New export columns** on `job_postings` (add, never change an existing column):
   `project_hire INTEGER NOT NULL DEFAULT 0`, `proj_pay_amount REAL`, `proj_pay_unit TEXT NOT NULL
   DEFAULT ''`, `proj_pay_basis TEXT NOT NULL DEFAULT ''`, `proj_hours_month REAL`,
   `proj_pay_quote TEXT NOT NULL DEFAULT ''`. An older export has none of them: the site works as
   today (`tests/compat_test.php`).
3. **The posting page and the list (D1).** For `project_hire = 1`:
   - Do not show the grid figure, the pay meter or „Calculează cu altă gradație”.
   - Show the box „Post finanțat din fonduri europene: salariul depinde de bugetul proiectului.”
   - With a stated pay, show it with its unit, its basis and the hours: „50 lei/oră net · 84 ore/lună
     ≈ 4.200 lei net/lună”. Unknown basis: „brut sau net nespecificat”, and treat it as gross only
     where a number must be computed (as the site does for every stated pay today:
     DATA-SALARY-GROSS-NET). A month figure exists only when the unit is a month, or the unit is
     an hour and the hours are known. Show `proj_pay_quote` under the figure, as the source.
   - List row: the stated figure if there is one, else „salariu din bugetul proiectului”.
   - RSS, Atom and iCal follow the same rule (`UX-SALARY-STATED-FEEDS` used the same order).
4. **Filter, sort and chart.** A project-hired post counts by its stated month pay. With no month
   pay, it is in „fără salariu estimat”. `SALARY_EXPR` becomes a `CASE` on `project_hire`.
   - Net to gross: there is one formula, `net_from_gross()` / `gross_for_net()` in PHP. Do not
     write it again in Python. If SQL needs `gross_for_net()`, register it as an SQLite function
     on the PDO connection. First check that the host's PHP version supports this (see
     `deploy-php.sh` and the README host notes); if it does not, stop and ask.
   - ADR 0008 rule 7: record the count per `salary_bucket` value before and after, on the same
     export, in the activity log.
5. **Copy.** Romanian, short, active. One term: „post finanțat din fonduri europene”, „tarif orar”,
   „bugetul proiectului”. `/despre/` section `salarii`: add two sentences on project posts. Keep the
   owner's text. The diagram gets no change in this brief; list the change it needs in the report.

## Files

You can change: `classify.py`, `export-to-sqlite.py` (the new columns and their fill only),
`webapp-php/helpers.php` (`list_salary()`, `SALARY_EXPR`, new functions; the DB connection file if
the UDF needs it), `webapp-php/pages/detail.php`, `webapp-php/partials/result_list.php`, the feed
pages, `webapp-php/pages/about.php` (decision 5), CSS (`assets/app.css` → `npm run css`), the
fixture builder, tests, `README.md` (one paragraph), `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change: an extraction prompt or `schema_models.py`, ADR 0008, an existing
export column, `salary_grid.py`, `estimate-salaries.py`, the `salary_bucket` keys, a DS file, a
`.g2-` class or token name, or any other file.

## Tests

- Python (`webapp/tests/` or the classify tests that exist): `project_hire()` on the 60 sample
  postings — report precision and recall against the `case` column; at least 10 `project_pay()`
  cases from the sample quotes (hour + hours, hour only, month gross, month net, several roles →
  None, out-of-range → None).
- PHP: the detail page of a project post with an hourly rate, with a month amount and with no pay;
  no grid figure, no meter, no estimator link; the list row; the filter bucket of each; the
  chart counts; a feed item; `compat_test.php` with an older export.
- Playwright: the detail page of a project post at 375 and 1280 px, no overflow.
- Full PHP suite and Playwright (per spec file if the full run stalls). Known failure:
  `alerts.spec.js:54` (TEST-ALERTS-PRIVACY-LINK) — report, do not fix.

## Report

On the export `tmp/261118/grid153/posturi-after2.sqlite` (copy it; data to 2026-09-09): how many
postings get `project_hire = 1`, how many get a pay (by unit and basis), precision and recall on
the sample, the bucket counts before and after, what you assumed, and screenshots.

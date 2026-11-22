# Brief: anomaly signals, phase 1 — table and two pages (ANOMALY-PUBLIC)

Date: 2026-11-23. Author: Opus. Builder: Sonnet.
Spec: `docs/specs/2026-11-23-anomaly-public.md` (read it first; § 3 and § 4 are the design).

## Goal

The export holds every anomaly signal in one table. An admin page shows every check. A public
page shows only the checks that the owner published. Today no check is published.

## Files you may change

- `ops/anomaly-survey.py` and `webapp/tests/test_anomaly_survey.py`
- new `ops/anomaly-checks.json` (the register), new `data/anomaly-reviews.csv` (header only)
- the pipeline: `pipeline.py` (the step after `export-sqlite`) or `ops/run-pipeline.sh`,
  whichever holds the export step today. Read both, choose one, say why.
- `ops/check-export.py`: one hard rule only — the table `anomaly_signals` and the table
  `anomaly_checks` exist. No count thresholds.
- `webapp-php/index.php` (2 routes), new `webapp-php/pages/anomalies_admin.php`, new
  `webapp-php/pages/anomalies_public.php`, `webapp-php/pages/stats.php` (one link),
  `webapp-php/pages/robots.php` (`Disallow: /admin/`), `webapp-php/.htaccess` (basic auth)
- PHP tests and fixtures next to the existing ones (`webapp-php/tests/`), and the export
  fixture if the PHP tests need the new tables
- `README.md` (one short section), `docs/activity-log.md`, `docs/backlog.md`

Another session works in this checkout. It owns `helpers.php` filters (`build_filters()`),
`list.php`, `partials/facets.php` and the inference pass. **Do not change them.** Read a
shared file again just before you edit it. Stage by path; never `git add -A`, stash or reset.

## Work

1. **Register.** `ops/anomaly-checks.json`: one entry per survey check id, with `kind`
   (`error_ours`, `error_posting`, `unusual`, `unusual_employer`, or `context`), `label_ro`,
   `explain_ro` (one or two short sentences, neutral, names the comparison group), and
   `state` from spec § 4. **No entry is `published`.** Follow spec § 3.4 for the wording.
2. **Table mode.** `ops/anomaly-survey.py EXPORT.sqlite --write-table [--checks ops/anomaly-checks.json]`
   writes into the export:
   - `anomaly_signals(posting_id INTEGER, check_id TEXT, value TEXT, peer_value TEXT, detail_json TEXT)`
     with an index on `check_id` and on `posting_id`;
   - `anomaly_checks(check_id, kind, label_ro, explain_ro, state, count, denominator, reviewed, real, false_positive)`,
     from the register, the run counts and `data/anomaly-reviews.csv`.
   It replaces both tables on every run. The JSON and CSV outputs stay as they are.
   A check with no posting (employer-level, distribution) writes what it can; say how you
   handled each such check.
3. **Pipeline.** Run the table mode after the export and before `check-export`. If the
   survey fails, the pipeline logs a warning and continues; the site then has no new signals.
   Do not block a deploy on the survey.
4. **Admin page** `/admin/anomalii/`: every check, grouped by kind; state, count, share,
   review numbers, „gata de publicare” when reviewed ≥ min(10, count) and false positives
   ≤ 20%; then the list of signals (title, employer, published date, value, peer value, link).
   `<meta name="robots" content="noindex">`. No link from any public page.
5. **Basic auth.** Protect `/admin/` in `.htaccess` (the routes go through `index.php`, so match
   the request URI, for example with an Apache 2.4 `<If>` block). The `AuthUserFile` path
   must be outside the web root and outside git; `deploy-php.sh` must not upload or delete it.
   Look at how `deploy-php.sh` treats `posturi-private/` and use the same place if it fits.
   Add a PHP guard too: `anomalies_admin.php` returns 403 when the server did not authenticate
   the request (`REMOTE_USER` / `PHP_AUTH_USER` empty), so a host without the auth module
   does not expose the page. Write in the README how the owner creates the password file
   (`htpasswd -c …`).
6. **Public page** `/statistici/anomalii/`: only `published` checks, same layout. When there
   is none, one short paragraph says that the signals are in review. A link from
   `/statistici/`. Use the gov2 DS components and role tokens (CLAUDE.md § Design system);
   no raw palette classes.
7. **Tests.** Python: the table mode on a fixture (rows written, tables replaced, a missing
   reviews file). PHP: the public page shows no `listed` check; the admin page returns 403
   without auth; robots disallows `/admin/`. Run the full Python suite and the PHP tests.
8. Run the table mode on `tmp/261118/grid153/posturi-after2.sqlite` (copy it first; do not
   change the original) and report the row count per check.

## Stop and ask

- Before you change a file that is not in the list above.
- When the host type (Apache version, auth module) is unclear from the repo. Report what you
  found; do not guess a config that could lock the owner out or expose the page.
- Before a change to a `.g2-` class or a DS token.

## Report

Files changed, commit hashes, test output, the pipeline choice and why, the auth setup and
the steps the owner must do on the host, the row counts, what you measured and what you
assumed. Add an activity-log entry; set phase 1 in the ANOMALY-PUBLIC backlog item. Do not
push or deploy.

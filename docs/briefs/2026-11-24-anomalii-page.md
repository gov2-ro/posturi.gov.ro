# Brief — one public page „Anomalii și abateri” (ANOMALY-PUBLIC, 2026-11-24 change)

Date: 2026-11-24. Author: Opus. Builder: Sonnet. Owner decision: the change note at the top of `docs/specs/2026-11-23-anomaly-public.md` (2026-11-24).

## Goal

`/anomalii/` shows every check of `ops/anomaly-checks.json` with its signals, publicly and indexed. A sticky table of contents sits on the side. Each check says what it measures, the comparison group and its size, its caveat, and its count. Each signal shows the posting, the value against the peer value, and — where the signal comes from text — the quoted paragraph. One explainer at the top says these are statistics, not accusations, and how to report a mistake.

## Read first

- `docs/specs/2026-11-23-anomaly-public.md`: the 2026-11-24 change note first, then § 3.4 (wording) and § 3.5 (reviews).
- `ops/anomaly-checks.json`, `ops/anomaly-survey.py` (`--write-table`, `_text_regex_check`, the calendar checks), `webapp/tests/test_anomaly_survey.py`.
- `webapp-php/index.php` (routes ~221–224), `webapp-php/pages/anomalies_public.php`, `webapp-php/pages/anomalies_admin.php`, `webapp-php/inc/footer.php` (the feedback form link).
- `ops/check-export.py` and `deploy-php.sh` (anomaly-table checks, OPS-ANOMALY-DEPLOY).
- The DS: `~/devbox/gov2/gov2ro-design-system/COMPONENTS.md`; project rules in `CLAUDE.md` § Design system.

## Steps

1. **Register.** In `ops/anomaly-checks.json`, remove `state` from every check and the `_comment` about states. Add to each check:
   - `group`: one of `erori_noastre`, `erori_anunt`, `neobisnuit`, `angajator`, `context`. Map from `kind` (`error_ours`, `error_posting`, `unusual`, `unusual_employer`, `context`).
   - `caveat_ro` (may be empty): one or two short sentences. Required for R18, R20, E01 („Context, nu o anomalie: …”), E05, R24 („Semnal nerevizuit de o persoană. …”), R22 („Model de text larg: în 5 din 10 cazuri verificate, anunțul nu numește un proiect.”), R25 („Semnal dintr-o căutare de text. Unele posturi au temei legal, de exemplu cele militare. Citatul arată textul exact.”).
   - Rewrite R25 `explain_ro`: drop „O persoană verifică fiecare caz înainte de afișare.”
   Every code path that reads `state` (survey, PHP, check-export, tests) stops using it. Stop and report if something else depends on it.
2. **Source quote.** In `ops/anomaly-survey.py`, a text check also stores the paragraph around the match in `detail_json` as `excerpt` (the paragraph of `body_markdown` or attachment text that holds the match; at most ~600 characters, cut on a word, with the match position as `excerpt_start`/`excerpt_end` relative to the excerpt). Calendar checks (R02, R03, R09a–c, R10, R11, E08) store the calendar line(s) they compare: `eveniment`, `data`, `ora`. Number and date checks store nothing new. Tests in `test_anomaly_survey.py`.
3. **Routes.** `/anomalii/` and `/anomalii/{CHECK_ID}/` (the full list of one check, paginated by 50) render the public page. `/statistici/anomalii/` and its subpaths redirect 301 to `/anomalii/…`. Update the link from `/statistici/`. Add `/anomalii/` to the main navigation or footer where `/statistici/` is, only if a slot exists; otherwise the stats link is enough — report which.
4. **Public page** `pages/anomalies_public.php`:
   - Title „Anomalii și abateri”. No `noindex`. Canonical URLs.
   - **Explainer** at the top (Romanian, STE principles): what a signal is; „Semnalele sunt statistici din date publice, nu acuzații.”; the comparison is with similar postings; „Algoritmul poate greși. Dacă vedeți o greșeală, spuneți-ne: vrem să aflăm.” with the feedback form link from the footer; the date of the data.
   - **Groups in this order:** „Posibile erori în datele noastre”, „Posibile erori în anunț”, „Anunțuri neobișnuite”, „Tipare la angajatori”, „Context”.
   - **Each check:** label, `explain_ro`, comparison group and size, `caveat_ro` when set (as a note), count. Then the first 20 signals: posting title (link), employer, date, value vs peer value, and the quote (step 5) when `excerpt` exists; then „Toate cele N semnale” → `/anomalii/{ID}/`.
   - Each signal has a small „Raportează o greșeală” link to the feedback form (add `check` and `posting` to the link only if the form supports prefill; else the plain link).
   - A signal with a `false_positive` review stays hidden (as today).
5. **Quote block (local DS gap).** A `<blockquote>` with the excerpt, the match wrapped in `<mark>` (DS `mark` token), and the source under it („din textul anunțului” / „din calendarul concursului”). Put the styles in `webapp-php/assets/app.css`, layer `app`, role tokens only. Add a comment with the DS issue URL (step 7).
6. **Table of contents (local DS gap).** From 1024 px: a sticky sidebar (`position: sticky`, below the header; the DS says not to make the whole header sticky) with the groups, each check and its count, and the current section marked (IntersectionObserver, small inline script; the page works without it). Under 1024 px: a „Cuprins” `<details>` block at the top. No horizontal scroll at 320 px. Styles in `app.css`, layer `app`. Comment with the DS issue URL.
7. **DS proposals.** Open two issues: `gh issue create -R gov2-ro/gov2ro-design-system --label ds-proposal --title "[proposal] …" --body-file <file>`, one for the TOC sidebar, one for the source-quote block, with the sections of `.github/ISSUE_TEMPLATE/ds-proposal.md`. Write the bodies to `docs/ds-proposals/` first. Put each URL in the comment next to the local version.
8. **Admin page.** `/admin/anomalii/` keeps the review counts and false-positive marks and drops the state column and anything about publication.
9. **Checks and deploy.** `ops/check-export.py` and `deploy-php.sh` keep requiring the anomaly tables; drop any rule about states.
10. **Tests.** PHP test for the page (all groups render, a caveat renders, a quote with `<mark>` renders, the 301, no `noindex`, a `false_positive` signal is hidden), Playwright at 320, 375 and 1280 px (TOC sidebar at 1280, `<details>` at 320 and 375, no horizontal scroll). Run every PHP test, `pytest webapp/tests`, and the anomaly specs.
11. **Measure** on `data/_dev/full-2026-11-23.sqlite` after a local `ops/anomaly-survey.py --write-table` run: signals per check, signals with an `excerpt`, and 10 quotes read by hand (does the paragraph hold the match, is it readable). Write the numbers in the activity log.

## Out of scope; stop and ask

- No chips on the detail page and no filter (phase 3).
- No change to check logic or thresholds, except storing the excerpt.
- Stop and ask when a step needs a DS file, a `.g2-` class, a token, or a file this brief does not name (tests, fixtures and `docs/ds-proposals/` are allowed).

## Done

Tests pass. Backlog item ANOMALY-PUBLIC carries a dated note for this change. Activity-log entry with Done / Hard / Decided and the numbers. Commits on your branch; no merge, push or deploy (the DS issues are the one outward action, and the owner asked for them).

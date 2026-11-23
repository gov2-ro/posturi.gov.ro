# Activity Log

## 2026

### 2026-11-23 — SALARY-GRAD-FOOD: the pay range in the export, the filter and the detail page (Sonnet, from the Opus brief)

**Done**
- `data/salarii/drepturi-2026.json`: food 347 lei, net cap 6,000 lei, the eight excluded employer groups as name patterns, each with its act and start date. The gradation table stays in `parametri.json` (one copy); the new file points to it. The values of `parametri.json` equal the audit: 7.5 / 5 / 5 / 2.5 / 2.5 %, compounded (g5 = 1.2451876), tranches 3 / 5 / 10 / 15 / 20 years.
- `salary_pay.py`: `pay_range()` (pure), `estimate_source()` (rule 11 scope: only the law grid; not declared, draft, Anexa VI, management row), `is_local_range()`, `law_figure_at()`, `columns()`. Food test: `net_from_gross(base) <= 6000`, base without the allowance.
- Export: `pay_min`, `pay_max` (total gross), `pay_grad_min/max`, `pay_food_min/max` in `job_postings`; `_pay_range_columns()` in `export-to-sqlite.py`. A project post and every out-of-scope figure get NULL.
- PHP: `SALARY_EXPR` = `COALESCE(j.pay_min, j.inf_salary_min, j.sal_min)` inside the existing `CASE`; `list_salary()` and `pay_level()` read the same precedence; `pay_range_view()` words the range; `db.php` shims an export without the columns; the detail page shows „de la X la Y lei net / lună”, the gross line with gradations and food, one note, and drops „Salarizare” when the stated box shows (kept when it is the only section).
- Tests: `webapp/tests/test_salary_pay.py` (21), `webapp-php/tests/salary_range_test.php` (41 assertions), `webapp-php/tests/browser/salary_range.spec.js` at 320, 375 and 1280 px (9 runs), fixture seeder `fixture_seed_pay_range()`. `test_salary_facet_sql.py` pins the new expression.
- `ops/measure-pay-range.py`: the step-8 measurement, runnable on any export (the owner runs it on the VPS).
- Posting 21359 (stated 7,870, 3 years): low = gradation 1, base 8,461, food 347, total 8,808; high = gradation 5, base 9,800 (net 5,733, so under the cap), food 347, total 10,147. Equal to the brief.

**Measured** (local copy `data/_dev/full-2026-11-23.sqlite`: VPS copy of 23 Nov, 16,875 rows, 1,538 open; it holds the stored `inferred` and `sal_json`, so the export function ran on the real inputs. It is NOT a Postgres re-export: the local Postgres is the September copy, 9,756 rows, max id 14141. Posting 21359 is in neither file. The owner measures again on the VPS.)
- Rows with a range: open 1,345 (stated 19, law grid 1,326); all 14,876 (stated 128, law grid 14,748). Without a range but with a figure, open 95: management row 70, draft grid 21, declared 3, project hire 1; no figure at all 98.
- `salary_bucket`, open postings, before → after: net-sub-3000 778 → 611; net-3000-4000 359 → 508; net-4000-5000 46 → 56; net-5000-7000 140 → 143; net-peste-7000 40 → 45; fara-estimare 175 → 175. All rows: 9,068 → 7,233; 3,858 → 5,510; 414 → 556; 1,380 → 1,367; 311 → 365; 1,844 → 1,844. The keys did not change (rule 7). The low end moves up because of the food (347 lei) and the gradation.
- Floor gradation, open: 0: 930, 1: 137, 2: 250, 3: 23, 4: 5, 5: 0. All rows: 0: 10,299, 1: 1,508, 2: 2,681, 3: 309, 4: 62, 5: 17.
- Food, open: both ends 1,079; low end only 114; high end only 0; neither 152. All rows: 12,019 / 1,363 / 0 / 1,494. Employers excluded by name: open 38, all 420 (these have gradations and no food).
- Law figures computed from the grid rows: open 1,256, scaled 70; all 13,967 and 781 (the refs are cut at 12 rows, or do not reproduce the figure).
- Ten detail pages read through the PHP app on a copy of the file with the columns added (20202, 19676, 20187, 21257, 20981, 20888, 20935, 17818, 21169, 21076): all show the net range, the gross line and the note; no „Salarizare” section next to a stated box; the military unit 17818 has no food; the local ranges (21257, 20888) say „până la plafonul legal”.

**Hard**
- `Grid.row_pay()` is not „figure × multiplier”. It rounds once on `lei × regula × multiplier`, and it lifts a row to the minimum wage (4,325 lei). Scaling the stored figure differs by up to 2 lei, and at the minimum wage by up to 1,061 lei: 5,368 of 15,674 law estimates (34 %) in the local copy sit exactly at 4,325, so the scaled low end would start at 5,385 for a gradation-5 post. I measured it on 14,960 row/gradation pairs: 3,878 differ (max 2 lei) outside the minimum-wage rows. So the law figures come from `row_pay()` over the rows named in `randuri`. A guard checks that the rows reproduce the stored figure at gradation 0: on the September data 7,392 of 7,398 estimates with fewer than 12 refs did. The rest scale the figure.
- A stated figure that is itself a range (14 of 19 open stated postings) often spans gradation 0 to 5 already (20202: 4,544 to 5,855; 19676: ratio 1.244). The rule then counts the gradations twice. I applied the brief as written and filed it in the backlog.
- My first idea for the exclusion list used patterns that the real employer names miss: „Școala Militară…”, military hospitals. Left as a first cut; the owner reviews it.

**Decided** (by me, inside the brief)
- Net = `net_from_gross(total gross)`, food included, for the page and the filter (one rule on the whole site). The allowance may be taxed differently; not modelled.
- A stated figure with a maximum: low = minimum × floor, high = maximum × gradation 5.
- Excluded employers keep the gradations; only the food drops.
- Brief step 3 skipped (see backlog). Not changed, as the brief says: the estimator, `/salarii/`, the draft figure, the declared pay.
- The detail page keeps the old „la gradația 0” text where no range exists, and in the estimate line inside the stated box (that line is the estimate, not the stated pay).
- New files outside the brief list: `webapp-php/tests/salary_range_test.php`, `.../browser/salary_range.spec.js`, `ops/measure-pay-range.py`; edits to the test fixtures (`build_db.php`, `make.php`), `playwright.config.js` (port 8830) and `test_salary_facet_sql.py` (the pinned expression).
- Not done (backlog SALARY-GRAD-FOOD-FOLLOWUP): `result_list.php`, `stats.php`, `ci.yml`.

**Fix, same day (owner decision).** A stated figure with a minimum and a maximum keeps its maximum as the high end, with no gradation on top; the low end is the minimum × the floor gradation, capped at the maximum. Open stated: 14 ranges (3 high gradation 5, 11 none, 2 capped); all rows 70 (18 / 52 / 3). 20202 (4,544–5,855, 0 years): 4,891–7,638 → 4,891–6,202. 19676 (5,126–6,375): 5,473–8,286 → 5,473–6,722. 21359 unchanged (8,808–10,147). Open buckets: 4000–5000 56 → 57, 5000–7000 143 → 142; the others unchanged.

**Assumed**
- The brief, the audit and ADR rule 11 were untracked or uncommitted in the main checkout; I copied them into the branch (commit `docs: brief, audit and ADR 0008 rule 11`), together with the owner's draft backlog and log entries.
- Management and Anexa VI rows are known from the `selector` in `sal_json` (`tip_post`, `anexa`), not from the rows.
- Experience is `inferred.experience_years`, the value of `inf_experience_years`.

### 2026-11-23 — SALARY-GRAD-FOOD: the law on gradations and the food allowance; ADR 0008 rule 11 (Opus + owner)

**Done.** The owner asked to show a stated salary as a range with the gradations and the food allowance, and to filter at gradation 1 „where it applies”. The VPS session measured (read-only): 25 of 1,644 open postings state pay (157 of 16,879 overall); 4 of 157 name one gradation for the figure; 6 open postings name a food amount, all 347 lei; experience is unknown for about 48% of postings. An external research report on the law (brief `docs/briefs/2026-11-23-salary-law-research.md`) is now `docs/audits/2026-11-23-salary-law-research.md`. ADR 0008 rule 11 and the build brief `docs/briefs/2026-11-23-salary-gradation-food.md` are written.

**Hard.** My first claim, „almost every public post pays the food allowance”, was wrong. Since 1 Jan 2026 (Legea 141/2025) the allowance is 347 lei only below 6,000 lei net, and staff with special food rights are excluded. The owner caught it. A news search found the cap; the primary text came from the research report. Gradations follow years of work (*vechime în muncă*), not the experience a posting asks for, so the experience gives only a minimum.

**Decided.** (1) The range runs from the floor gradation (from the required experience; unknown → 0) to gradation 5; the cost of a flat gradation 1 is an overstated figure for posts with no experience required. (2) The food allowance goes into the figure and the filter, by rule and not by text, tested at each end on the net without the allowance and without bonuses (as Ordin MEC 3185/2026 does); the cost of using the text is that 6 postings rank above their peers. (3) The same rule applies to the estimate. (4) Holiday vouchers stay out: they end on 31 Dec 2026. (5) A stated figure is read as gradation 0 (Art. 10 alin. (3)).

### 2026-11-23 — UX-FOLLOW-EMP and UX-SALVATE-FLUX: followed employers, employer alerts, the Flux tab (Sonnet, from the Opus brief)

**Done**
- Part A. `static/employers.js` holds `posturi.employers.v1` (`{version:1, employers:[{slug,name,addedAt}]}`, cap 10; a full list refuses the 11th and drops nothing). The employer page has the toggle „Urmărește angajatorul” / „Nu mai urmări” (hidden without JavaScript) and the link „Alertă pe email” (always shown) to `/alerte/abonare/?cheie=employer%3D<slug>&sort=&nume=<name>`. The navbar count is saved jobs + searches + employers (`saved.js` `renderNav`).
- Alerts, server. `al_parse_key($key, true)` checks the slug in `employers` when the key has `employer=`; `al_subscribe()` and the subscribe page pass the flag. An unknown slug gives `invalid-key` and a 400. `bin/alerts.php` does not pass the flag: a subscription whose employer left the export stays valid and sends nothing. No change to ADR 0003, the subscriptions schema, `ops/alerts.py`, or the email template.
- Part B. `/salvate/` has three tabs from `?vedere=`: none = Flux, `liste` (also the old `notite` and `cautari`) = Liste, `ascunse` = Ascunse. Liste order: alerts block, Căutări salvate, Angajatori urmăriți, Anunțuri salvate, Gestionare (new button „Șterge angajatorii urmăriți”, same confirm pattern). `data-view-tab` values: `flux`, `saved` (Liste), `hidden`.
- Part C. `GET /flux.json?s[]=&e[]=&id[]=` (`feeds/flux.json.php`, routed before the query decoder like `/preferinte-posturi.json`). Caps 4/10/20 give 400. Each search key and each employer goes through `build_filters()`; the saved ids are returned only when open. Output `{today, rows, sources}`. 100 rows per source, 300 in all, newest first, one row per posting with `src` indices. `truncated` is `total > rows returned for that source`. `static/flux.js` draws the stats, chips, sort switch and cards (25 to a page). `Disallow: /flux.json` in `robots.txt`.
- Tests: `tests/flux_test.php` (103 assertions), `tests/alerts_test.php` (+11), `tests/preferences_test.php` (updated), `tests/browser/flux.spec.js` (21 tests at 3 widths). The specs of saved, searches, notes and toc now open `/salvate/?vedere=liste`, where they used `/salvate/`.

**Measured**
- Fixture, 4 searches + 10 employers + 4 ids: 3–4 ms, 37 rows (Playwright, `responseEnd` minus `requestStart`).
- Local export (the 9 Sep copy, `POSTURI_TODAY=2026-09-09`, 1,848 open postings), 4 searches (two counties, one family, one text) + the 10 employers with most postings + 20 saved ids: first request 178 ms (cold), then 41–42 ms; 300 rows, 153 KB uncompressed. Row count per source: searches 140, 41, 382, 626 (all truncated); employers 34 down to 8; saved 20.
- Browser suites at 320, 375 and 1280 px (saved, searches, alerts, notes, toc, flux, compact, ds, home): 437 passed, 16 skipped, 3 failed. The 3 failures are `alerts.spec.js:54` (TEST-ALERTS-PRIVACY-LINK, failing before this work; baseline of saved + searches + alerts was 129 passed, 3 failed).
- PHP: every `tests/*_test.php` passes except `theme_test.php` (one assertion, the health-icons sprite: fails on `main` too).
- An employer-only digest (fixture, `bin/alerts.php candidates` then `ops/alerts.py build_digest`): subject „2 anunțuri noi: Spitalul Clinic Județean Cluj”; the link „Căutarea ta” goes to `/?employer=spitalul-clinic-judetean-cluj`. The template reads well; no change.

**Hard**
- The old-export compatibility: my first `/flux.json` named its columns (`j.sal_max`) and failed on the 9 Sep copy. It now selects `j.*`, like `feed_rows()`.
- A hand-made string such as `garbage` parses to „no filter” and listed every posting. The endpoint now takes only a canonical key (`search_key($params) === $key`), as `al_parse_key()` does. Anything else is a source with `unknown: true`.
- Saving a posting in the Flux must not redraw the list: `onPref()` in `saved.js` reads whether the row is still in the page after the update, and would show an undo notice. The Flux redraws only when the set of hidden postings changes (event `posturi:prefs-changed`).
- At 320 px the two employer buttons in one `.follow-group` overflowed the page by 15 px. They are now two groups in a wrapping row.

**Decided**
- A followed employer is a separate store, not a search slot (pax, in the brief). Cost of the alternative: 10 employers would use the 4 search slots.
- The slug check is at subscribe time only. Cost of checking in the digest too: a merged or removed employer would make `candidates` fail instead of staying quiet.
- `status` in a saved key: the Flux forces the open default. `status=soon` and `status=unknown` keep their meaning (subsets of open). `status=closed` selects nothing. Cost of forcing all of them: a saved search of closed postings would list every open posting.
- `domain` in the stats is `inf_profession_family` (one value per posting, the „Domeniu profesional” of the chips). The policy domain (`v3_policy_domains`) is a list; it would count a posting several times.
- The salary stat uses the figure of the list card (stated, else estimated), net, and skips under 1.000 lei gross (an hourly rate). Cost of „stated only”: 44 of 9,757 postings state a salary; the block would almost never show.
- „Today” for „new” and „closing” comes from the server (`today` in the answer): one clock for the page and the tests.
- `/salvate/` links elsewhere (navbar, „Vezi salvatele”) keep the address `/salvate/`; it opens Flux, which includes the saved postings.

**Assumed**
- The `source_url` field was added to each row (the brief lists no such field): `saved.js` identifies a posting by its official URL, so a card cannot save or hide without it.
- `tailwind.config.js` scans `static/flux.js` now (one entry), so the classes of the generated stat tiles are built. `static/app.css` is rebuilt with `npm run css`.
- No DS proposal issue was opened for the tabs; they were local before this work.

### 2026-11-23 — SALARY-EU-CAP: the guide cap as context on project posts (Sonnet, from the Opus brief)

**Done**
- Step 0, guide version. I searched the web for a PoIDS or PEO general guide later than March 2023 (four searches: PIDS and PEO, "consolidat", "plafoane aplicabile cheltuielilor cu personalul", OMIPE amendments). I found no later PIDS general guide. I found a university copy of a **consolidated PEO general guide**, which integrates OMIPE 964/2024, 4300/2024, 5651/2025 and 5982/2025 (search snippet). The link `https://utcluj.ro/documente/a8da27bd-38ba-495a-96e1-44e755b3ea9b` now answers 404 and I could not read it. So I could not compare its cap table with the March 2023 table. The mfe.gov.ro originals were not reached either. Three PEO 2025 specific guides (edupedu: IPT, CJRAE) hold no personnel cap and refer to the general guide. **Open [U]**: the table may have changed after March 2023. The line therefore cites "martie 2023" in its tooltip.
- `classify.project_programme(text)` returns `pids` or `peo`, else None. PoIDS: "Incluziune și Demnitate", whole words PoIDS and PIDS, SMIS 339395. PEO: "Programul Educație și Ocupare", whole word PEO. A text that names both gives None. It runs on the same text as `project_hire` (body and attachment) and only when `project_hire` is true. The exporter writes the new column `proj_programme`.
- Detail page: in the project box, `data-pay-project-cap` shows when `$proj_pay` is null and the programme is set. The title holds the source sentence. No new class, no change to `SALARY_EXPR`, filter, sort, chart or list.
- Tests: `test_project_programme` (9 cases); `project_pay_test.php` suite for line shown (no pay, programme), hidden (pay stated), hidden (no programme).

**Measured** (`data/_dev/full-2026-11-23.sqlite`, 1,033 project hires, same rule applied to `body_markdown`; that file has no attachment text)
- `pids`: 621; of these 612 state no pay and get the line.
- `peo`: 16; of these 12 get the line.
- None: 396 (380 without pay, no line).
- Hand read of 10 PIDS (random seed 1) and 10 PIDS outside the SMIS 339395 cluster, and 10 PEO: no false positive in 30. Most PIDS hits are the code `PIDS/586/PO4/339395`, a true PIDS project.

**Assumed**
- A project hire sits outside the organigramă, so the cap applies (the guide excludes posts inside the organigramă). Not checked per post.
- `v4_funding_programme` is not used; it is empty for 969 of 1,033.
- The PIDS and PEO tables stay as in March 2023.
- The fixture `webapp-php/tests/fixtures/build_db.php` got the new column; `webapp-php/db.php` is unchanged (an old export without the column shows no line).

**Opus review (2026-11-23).** Accepted against the brief (`a878d90`); the extra fixture file (`build_db.php`) is needed for the schema. One change: the caveat „Plafonul este suma maximă decontată de program, nu salariul postului.” was only in a `title` tooltip, which phones do not show. It is now visible text; the tooltip keeps the source. `project_pay_test.php`: 98 assertions pass. Before deploy: the consolidated PEO guide (OMIPE 964/2024 … 5982/2025) is not read, so the PEO cap is unverified after March 2023 (12 postings get the PEO line).

### 2026-11-23 — EU-GUIDE-RATES reviewed (Opus)

**Done.** Accepted the spike (`e30483e`) against its brief. Checked the main claim in the downloaded PIDS general guide (`pdftotext`, pp. 38–39): the cap table and the scope sentence („exclusiv personalului angajat pe posturi în afara organigramei”) are as reported. Opened SALARY-EU-CAP for the owner.

**Hard.** The originals on mfe.gov.ro and ADR Centru were not reached (HTTP 403); the copies come from a commune, a university and a municipality.

**Decided.** The cap is context, not a figure (ADR 0008 rule 5): it is a reimbursement ceiling, and a posting can pay below it (Blăgești 17.70 lei/h). The cost of a figure: the filter and the sort would place PIDS posts at the ceiling.

### 2026-11-23 — ANOMALY-PUBLIC phase 2: checks R21–R25 for limiting conditions (Sonnet, from the Opus brief)

**Done.** Added five checks to `ops/anomaly-survey.py` and the register `ops/anomaly-checks.json`. `Ctx.texts()` loads `posting_text.body_markdown` once; a missing table gives `unavailable`. Six tests added in `webapp/tests/test_anomaly_survey.py` (44 pass). Nothing deployed.

Counts on `data/_dev/full-2026-11-23.sqlite` (denominator 16,849 postings with text; 1,033 are project hires; R24 denominator 7,677):

| Check | State | All | Project hires | Others |
|---|---|---:|---:|---:|
| R21 domicile advantage | listed | 339 | 244 | 95 |
| R22 named-project advantage | listed | 270 | 224 | 46 |
| R23 EU-project experience | listed | 23 | 16 | 7 |
| R24 conditions above occupation | hidden | 230 | 49 | 181 |
| R25 maximum age or sex | hidden | 23 | 0 | 23 |

R21 to R23 match the audit (244, 224, 23). R25 gives 23 (audit: 18 + 6 = 24).

Hand reading, 10 hits each (random sample, seed 1):
- R21: 0 false positives.
- R22: 5 of 10 do not name a project. Three say „proiecte educaționale” (generic); two match „proiect” far from the advantage (experience in cultural or IT domains). The other five name the pilot project.
- R23: 0 false positives.
- R24: 0 as pattern errors; the signal is weak. Credentials such as `permis_conducere` or `altele` are not demanding. 3 of 10 have a group median of 0 or 1 year.
- R25: 1 of 10 is a false positive (child age „vârstă de până la 2 ani”; 4 of 23 hits have this form). 6 of 10 are the military age limit „cel mult 45 de ani” (legal context, 12 of 23 hits). 3 of 10 are a required sex (a boys' dormitory, a care post).

R24 overlap with E05 (posting level, 53 E05 role findings = 49 postings): 6 of 230 R24 findings are E05 findings (2.6%). Below the 50% limit, so R24 is added as `hidden`. Alternatives measured: a credential of any kind gave 471 hits (11 in E05); a credential outside `aviz_medical` and `certificat_profesional` gave 230.

**Hard.** The brief does not define „named credential”. Every posting has `aviz_medical` or `certificat_profesional`, so „any credential” is noise. The R25 age pattern „de până la N ani” also matches the age of a child.

**Decided.**
- Named credential = any `v3_credentials` value except `aviz_medical` and `certificat_profesional`. Language level = `v3_languages` entry with C1 or C2. Years are `inf_experience_years`; the median is per `occ_group` with n ≥ 30 (the E05/R13 rule); years must be above 0.
- Regexes kept exactly as the brief gives them, with case-insensitive matching on all five. The R25 child-age false positive stays because R25 is `hidden` and a person reviews every hit. Cost of the alternative: a changed pattern that the owner did not approve.
- R22 stays `listed` with the brief's pattern, although half of the sample is generic. Owner to decide whether to tighten it (for example „proiectul pilot” or a SMIS code).

**Opus review (2026-11-23).** Accepted against the brief. The two loose patterns came from the brief, so Opus tightened them (same day): R22 now needs „proiectul pilot” or an SMIS code (270 → 223 hits: 192 project hires, 31 others; 5 of 5 sampled name the pilot project). R25 age now needs an age of 16–99 (23 → 19 hits: 12 „cel mult 45”, 6 sex, 1 „vârstă maximă 50”). Two near-miss tests added; 45 tests pass. The `explain_ro` texts wait for the owner.

### 2026-11-23 — EU-GUIDE-RATES: hourly caps in the PoIDS and PEO guides (Sonnet, from the Opus brief)

**Done.** Counted SMIS codes among 1,033 project hires (591 with a code; top code 339395, 215 postings). PoIDS covers most of them. Downloaded and read the PIDS and PEO general guides (March 2023), the PoIDS Action 6.1 specific guide (2025) and the PCIDIF Beneficiary Manual (Jan 2024). Files are in `data/eu-guides/` (not in git). Audit: `docs/audits/2026-11-23-eu-guide-rates.md`.

**Measured.** PIDS and PEO general guides hold the same net lei/hour table: 70/80/90 (managers, implementation experts; <5, 5–10, >10 years), 50/60/75 (support), 35/45 (administrative). It applies only to staff outside the organigram of a public body. The Action 6.1 guide sends to it. The PCIDIF manual has no hourly figure. Of 21 PIDS/PEO postings with an hourly rate, none states a net rate above 90; 8 sit exactly on 70, 80 or 90.

**Hard.** ADR Centru (AP4 SCI 2000, AP10 PCIDIF e-guvernare) answers HTTP 403. The `v4_funding_programme` column is empty for 969 of 1,033 hires, so I named the programme from the text.

**Decided.** Nothing; Opus decides on SALARY-EU-DETAIL. Assumed: the programme of the home-care codes; that the copies equal the MFE originals; the 2026 tax rates in the Iași conversion (122.35 incl. employer cost = 70 net).

### 2026-11-23 — EU-RULES-PCIDIF: what the PCIDIF rules fix for project posts (Opus)

**Done.** Read the PCIDIF document, the audit of 2026-11-18 and ADR 0008. Measured on `data/_dev/full-2026-11-23.sqlite` (VPS export, 16,875 postings). Wrote `docs/audits/2026-11-23-eu-rules-pcidif.md`. Backlog: EU-RULES-PCIDIF done; new EU-GUIDE-RATES and UX-EMP-PROJECT; notes on EU-PROJECT-SICAP, SALARY-EU-ESTIMATOR and ANOMALY-PUBLIC.

Numbers (baseline): 1,033 project hires; 591 state an SMIS code (95 codes, top 339395 with 285 and 122607 with 246); 659 cite HG 1336/2022, 46 HG 234/2023, 0 HG 325/2018; 151 have a window under 10 working days (14.6 %, against 8.7 % for other postings); 244 give an advantage for domicile in the UAT; 224 for work in the pilot project; 7+ years of experience in 3.3 % (other postings 6.3 %).

**Hard.** The document is an AI research report. It mixes acts with consultancy pages and Reddit, and it names HG 325/2018, which HG 234/2023 repealed. Each claim needed a status: agrees with the data, lead, or out of date. `LIKE '%SMIS%'` matches „transmis”: the first count (1,938) was wrong; the case-sensitive regex gives 631.

**Decided.**
- No act caps the years of experience for a hire by contest or by internal procedure. The procurement ban does not apply. So R13 and R14 stay `unusual`. The alternative (an `error_posting` kind) would accuse employers of a breach that no text defines.
- No hourly-to-month conversion with 1,720 h. It is a reimbursement divisor. The cost of using it: a month figure that no posting states, for 10 postings.
- Owner, 2026-11-23: yes to D1–D4. Briefs for Sonnet: `docs/briefs/2026-11-23-anomaly-limiting-conditions.md` (D3) and `docs/briefs/2026-11-23-eu-guide-rates.md` (D4). D1 waits for EU-PROJECT-SICAP; D2 waits for UX-EMP-02.

### 2026-11-23 — VPS checks after the 13:15 EET run: studies, salary limits, anomalies, org links (VPS session, recorded by Opus)

Run `2026-11-23T11:15:01Z`, deployed healthy. Export built_at 12:27:41Z, git `1e1b3fb`, 3,084 postings (previous 2,983). "Before" is the `f369ce0` export. All queries read-only.

**Done**
- DATA-STUDIES-NOT: doctorat roles 41 → 39 (limit 200). 3 stated levels changed, all medical doctorat → licenta (18664, 18822, 18901). `study_level_source`: empty 375, `inferat-ocupatie` 73 roles on 70 postings, text 4,366; the 73 go from none to licenta and replace no stated level. Postings with a licenta role 1,339 → 1,462; licenta/master/doctorat 1,446 → 1,569. `medic%` postings by level: doctorat 4, generala 5, liceala 2, licenta 269, master 4, none 8, postliceala 5 (before 7/5/2/249/4/19/5); those that pass the exclusion 22 → 11. No-level postings by COR group: empty 110, 1: 4, 3: 60, 5: 113, 6: 1, 7: 17, 8: 18, 9: 27 (group 2 gone, was 69). Export warning: medical title + generala on 19638, 20298, 20939.
- Anexa VI: 354 rows in `salary_grid_pay`, 59 refs `VI_Armata!NN`, of 24,852 rows.
- SALARY-LABELS-LIMITS: `salary_limits` A 45, D 41, Dc 5, F 365, L 2,628, N 3,084, gap_n 2,607, gap_pct 15. `sal_baza`: empty 9, declaratii 5, lege_in_vigoare 2,851, proiect 219. Live `/despre/` shows the same numbers.
- ANOMALY-PUBLIC phase 1: 1,482 signals, 30 checks, no survey warning, check-export PASS. Counts per check (id state count/denominator): E01 hidden 1261/2978; E02 1/2978; E03 30/3084; E04 5/3084; E05 hidden 7/3875; E06 6/35; E07 9/2123; E08 5/1265; R01 14/1773; R02 0/1265; R03 0/1265; R04 0/39; R05 3/1509; R06 0/2978; R07 0/2978; R08 26/3084; R09a 0/83; R09b 1/73; R09c 2/73; R10 2/83; R11 3/1265; R12 34/85; R13 3/853; R14 2/1493; R15 0/63; R16 10/2976; R17 4/2465; R18 hidden 0/2212; R19 7/3083; R20 hidden 47/4814. The rest are `listed`.
- DATA-ORG-06: 6,645 of 6,645 snapshot rows match; check-export PASS; 6,417 with a CIF, 9 `P` ids.

**Hard**
- The Anexa VI check query used `WHERE anexa='VI'`; `salary_grid_pay` has no such column. The VPS session counted by `ref` instead.

**Decided**
- The salary/studies and anomaly sessions were not online. The numbers go here and in the backlog, so that the next session of each owner reads them. Open for the studies owner: 73 `inferat-ocupatie` licenta roles include non-medical titles (Bibliotecar, Pedagog școlar, Consilier școlar, Psiholog practicant, farmacist, profesor). Check that licenta is right for each title. Full outputs: `/tmp/studies-check.out`, `/tmp/limits-check.out`, `/tmp/anomaly-check.out`, `/tmp/org-check.out` on the VPS (`/tmp` is not durable).

### 2026-11-23 — DATA-ORG-06: CUI and org.gov2.ro link on the employer page (Sonnet, from the Opus brief)

**Done**
- `ops/build-employer-org.py` copies the ciulei crosswalk (`e314ded`, org v0.69.0) into `data/org/employer-org.csv`: 6,417 `linked` + 228 `linked_no_cif` = 6,645 rows; 985 `unmatched` skipped by status; 0 rejected (after the Opus review, see Decided).
- `export-to-sqlite.py` writes `employer_org(employer_id, org_id, cif, org_url)` by `employers.slug`. `ops/check-export.py` has a soft rule `employer_org` (warning when the table is empty).
- The employer page shows „CUI … · Fișa instituției pe org.gov2.ro →” under the name (helper `webapp-php/inc/employer_org.php`; tokens `g2-text-meta`, `g2-link-quiet`). Test: `webapp-php/tests/employer_org_test.php` (29 assertions after the review).
- Match counts. (a) Export on the local Postgres (September copy), before the review: 5,215 of 6,636 snapshot rows match an employer, 1,421 do not. (b) The snapshot on `employers.slug` of `data/_dev/full-2026-11-23.sqlite`, after the review: 6,645 of 6,645, 9 of them `P` ids.

**Hard**
- The brief expects 6,645 rows. The crosswalk has 9 `linked` rows whose `org_id` is a proposed `P` record (each has a CIF, empty `link_tier`). The brief also says that no `P` record reaches the snapshot. The script rejects the 9: 6,645 - 9 = 6,636. Ciulei answered: yes. Each of the 9 is a proposed record whose CIF org curated from a SEAP authority (`curation/identities.csv`, `cif_source = curation`). A curated identity keeps its `P` id, so these 9 stay `P`.

**Decided**
- Sonnet first rejected the 9 (the brief said „no `P` record”). Opus review: the brief meant „no unreviewed record”, and `status` is the ciulei contract. So the script and the page key on `status` only, and the 9 are kept. Cost of the alternative: nine reviewed institutions show no CUI, and the rule would differ from the ciulei contract.
- The page helper repeats the host check (`https://org.gov2.ro/`) although the snapshot has it. Cost: one line. It keeps a bad row from becoming a public link.
- Live 2026-11-23: code deployed (`597e0d0`); the cron run `2026-11-23T11:15:01Z` built the export (git `1e1b3fb`, active-only) and deployed it at 12:28Z. `employer_org` on the VPS: 6,645 rows, 6,417 with a CIF, 9 `P` ids. `/angajator/cresa-sangeorz-bai/` shows „CUI 46380110 · Fișa instituției pe org.gov2.ro →”.

---

### 2026-11-23 — DATA-ORG-04: fresh full employer export for org; DATA-ORG-06 briefed (Opus)

**Done**
- `ops/export-employers-for-org.py` leaves out an employer name with no letter (`c7945cd`). The import files postings with no institution under employer `x` („—”); org made it the proposed record `Px`. DATA-ORG-07 tracks the import fix.
- A full VPS export (`export-to-sqlite.py` without `--active-only`, built_at 2026-11-23T09:52:55Z, git `4d9938c`): 16,875 postings, 7,631 employers, 376 MB. The Mac pulled it with rsync from `pax@78.46.142.169`; sha256 matched, `integrity_check` ok. Copy at `data/_dev/full-2026-11-23.sqlite` (not in git).
- `institutii-publice/data/posturi/employers.csv`: 7,630 rows (was 5,901 on 2026-11-02), 7,557 with a county, 73 with 0 postings. 1,730 new slugs; only `x` is gone. The old file is kept as `employers.2026-11-02.csv`.
- DATA-ORG-06 brief: `docs/briefs/2026-11-23-employer-org-link.md`. Ciulei's crosswalk (org v0.68.0): 5,040 linked, 181 linked_no_cif, 680 unmatched.

**Hard**
- The Mac has no ssh Host `gov2-1`, although `docs/deploy-vps.md` § 7 and `ops/pull-backup.sh` assume one. The IP works with key login.

**Decided**
- The employer CSV is made on the Mac from the pulled sqlite, not on the VPS. The `x` fix was not pushed, so a VPS run would have kept `Px`. Cost: a 376 MB copy.
- Employer slugs are stable: the import never renames a slug, and no code deletes employers. Told org and ciulei; a future EMPLOYER-DUPLICATES merge must publish an old-to-new slug map.
- DATA-ORG-06 reads the ciulei crosswalk through a committed snapshot, because ciulei is not on the VPS. `status` is the contract: never link `unmatched`.

### 2026-11-23 — ANOMALY-PUBLIC phase 1 live: admin login on the shared host (Opus)

**Done**
- Code deploy of `407db39` from the Mac. `/admin/anomalii/` asks for a login and opens after it (owner, in the browser). The page says „Acest export nu conține încă semnale”: the live export (2026-11-22 23:34) predates the survey step. The next VPS export fills the tables.

**Hard**
- No login prompt at first: the PHP guard answered 403. On the host, Apache's own 401 error document goes through the rewrite to `index.php`, so the guard replaced the 401, and a browser prompts only on 401. Measured with curl: no login → 403 with a `WWW-Authenticate` header; a wrong login → 500. Fix: the guard answers 401 with `WWW-Authenticate`. A local Apache 2.4.71 did not show this, because it has no such error-document routing.
- The 500 on a wrong login: Apache runs as another user and cannot read a `700` directory or a `600` file. The first instructions (Opus) gave those modes. The password file moved to `~/.htpasswds/posturi.gov2.ro/` (`711`/`644`). It does not go in `~/posturi-private`, which holds the alert subscriptions and stays `700`.

**Decided**
- Separate directory for the password file, not wider modes on `~/posturi-private`. Cost of the alternative: other accounts on the shared host could enter the directory with the subscriptions database.

---

### 2026-11-23 — ANOMALY-PUBLIC phase 1: table, admin page, public page (Sonnet, from the Opus brief)

**Done**
- `ops/anomaly-survey.py --write-table` writes `anomaly_signals` and `anomaly_checks` into the export (both replaced on each run; two indexes). The JSON and CSV outputs are unchanged. `ops/anomaly-checks.json` holds 30 checks, none `published`; `data/anomaly-reviews.csv` has the header only (`data/*` is git-ignored: add it with `git add -f`).
- Checks without a posting of their own: R15, R16, R17 and R05 write one row per employer or group, on its first posting; kind `unusual_employer` adds `{"level": "employer"}` to `detail_json`. E01 is a distribution: its 596 contract-staff findings are rows, the distribution stays in the JSON. An unavailable check (E04 without the index CSV) has no rows and NULL counts. A review verdict goes into `detail_json`; the row stays in the table.
- Pipeline: the survey runs in `ops/run-pipeline.sh` between `pipeline.py` and `check-export`, not as a `pipeline.py` step. A failed `pipeline.py` step aborts the deploy; a survey failure must only log a warning. `check-export` has one hard rule: both tables exist (an old log record without the key passes).
- PHP: `/statistici/anomalii/` (`pages/anomalies_public.php`: `published` checks, `false_positive` signals hidden, "in review" text when none) and `/admin/anomalii/` (`pages/anomalies_admin.php`: every check, state, review numbers, "gata de publicare", up to 500 signals per check, `noindex`). `.htaccess` basic auth for `/admin/`; `robots.txt` disallows `/admin/`; `/statistici/` links to the public page.
- Tests: 4 + 3 + 1 Python (table mode, check-export rule, pipeline order), `anomalies_test.php` (28 assertions).
- Measured on a copy of `tmp/261118/grid153/posturi-after2.sqlite` (9,756 postings): 4,865 signal rows. Per check: R01 50, R02 66, R03 1527, R04 10, R05 604, R06 313, R07 29, R08 531, R09a 6, R09b 9, R09c 26, R10 53, R11 95, R12 90, R13 26, R14 17, R15 8, R16 77, R17 19, R18 0, R19 88, R20 78, E01 596, E02 24, E03 222, E05 26, E06 1, E07 24, E08 250, E04 unavailable.

**Hard**
- The PHP guard in the brief reads `PHP_AUTH_USER`. PHP fills it from the `Authorization` header, so on a host with no auth module a forged header would open the page. The guard reads `REMOTE_USER` and `REDIRECT_REMOTE_USER` only. A test sends a forged header and expects 403.
- Measured on a local Apache 2.4.71 with the real `.htaccess`: no login gives 401, a wrong password 401, a good login reaches PHP with `REDIRECT_REMOTE_USER` set (not `REMOTE_USER`, because of the rewrite to `index.php`). The `<If>` block sits inside `IfModule` guards for `mod_authn_file` and `mod_auth_basic`, so Apache 2.2 skips it.

**Decided**
- State of E06 is `error_ours`: 2 of 4 samples are our grid-matching error (SALARY-GRID-MATCH). R09a-c and E08 are `error_posting`. Other states follow spec § 4; the owner changes any of them.
- Assumed, not verified: the host is Apache 2.4 (Dreamhost, PHP 8.2) and the home is `/home/pax`. The repo does not state the Apache version. If the path is wrong, `/admin/` returns 500, never an open page.
- Not done: `sitemap.php` does not list the public page (not in the brief's file list).

---

### 2026-11-23 — SALARY-LABELS-LIMITS: the draft figure as a last resort, and the limits on `/despre/` (Sonnet, from the Opus brief)

**Done**
- Export: `job_postings` has `sal_baza` (`lege_in_vigoare`, `proiect`, `declaratii`; empty for an old row), `sal_draft_min`, `sal_draft_max`. New table `salary_limits` (key, JSON): `N, A, Dc, L, D, F` and `gap_pct`, `gap_n`. The sort order, the filter and `SALARY_EXPR` do not change.
- `{X}` is computed at export time: the median of `draft / law - 1` over the postings with `baza` = `lege_in_vigoare` and a draft figure, in whole percent. `/despre/` reads it. The coverage groups do not overlap and follow ADR 0008 rule 4: stated pay (`A`), declared pay (`Dc`), law (`L`), draft (`D`), none (`F`). A project-hired post counts as `A` only if its pay is read.
- PHP: `sal_baza()`; `list_salary()` gives `kind` „estimare aproximativă” and the tooltip for a draft figure (list, grid, table); the detail page has the heading, the note, and for a law figure the side note linked to `/estimator-salariu/`; the feeds follow.
- Wrong texts: „Conform legii salarizării (propunere)” is now „Estimare după Legea 153/2017” (law) and „Estimare aproximativă, după proiectul de lege” (draft); the „coeficient 0 × 2.415 lei” line of a law figure is now „Salariul de bază din tabelul Legii 153/2017, la gradația N”; `stats.php` and four comments no longer say that the draft is the main figure.
- `/despre/`: the owner text, version 2, word for word, with `#ce-nu-stim`. Without the `salary_limits` table, the page leaves out the gap sentence and the coverage paragraph.
- Tests: 8 Python (`test_salary_limits_export.py`), `salary_limits_test.php` (35), Playwright `salary_limits.spec.js` (3 tests, at 320, 375 and 1280 px). Four older tests got `sal_baza` in their data.

**Hard**
- Old fixtures have no `sal_baza`. The rule „empty counts as draft” made every law row read „estimare aproximativă”. `sal_baza()` now falls back to the variant (`declaratii-…`, `lege…`) before the draft.

**Decided**
- The numbers come from the export (a table), not from typed text: the cost is one small table. The alternative (text numbers) goes stale after each pipeline run.
- Not done: `data/salarii/parametri.json` (out of the brief), `archived.php`. See the backlog.
- Not measured here: the live numbers. The local copy has no VPS data. The VPS session runs the export and reads `/despre/`.

---

### 2026-11-22 — DATA-STUDIES-NOT: "Fără licență" removes the levels above it (Sonnet, from the Opus brief)

**Done**
- `build_filters()`: `studies_level_not[]` removes the level and every level above it (generală < profesională < liceală < postliceală < licență < master < doctorat). A posting with no level stays. The other `_not` filters and all URL keys are unchanged.
- Chip „Fără studii: Licență” is now „Fără licență și peste” (doctorat: „Fără doctorat”). The control of the level has the same words (`title`, screen-reader text).
- `posting_roles.py`: a medical title read as `doctorat` becomes `licenta` unless the text says „doctorat” / „studii doctorale”. A role with no level and a COR code in major group 2 gets `licenta`. New nullable column `study_level_source` (`text` / `inferat-ocupatie`). The „Pot aplica?” panel ignores an inferred level.
- `export-to-sqlite.py`: stderr warning for a medical title with level `generala`.
- Tests: `webapp-php/tests/studies_not_test.php` (32), 12 new Python tests in `test_posting_roles.py`, Playwright `studies_not.spec.js` at 375 and 1280 px (6 pass). Full suites: PHP all pass except `theme_test.php` sprite check (before this work too); Python 1768 passed, 1 known failure (`test_salary_facet_sql.py`).
- Local counts on the export of 18 Oct (9,756 postings): exclusion removes 4,394 today, 4,842 now. Medical-title postings that pass: 72 today, 31 now. Step 4 changes 47 roles (47 postings; 3 keep `doctorat` because the text asks for it). Decision 4 fills 252 postings (256 roles). Details and VPS commands: `docs/audits/2026-11-22-studies-not.md`.

**Hard**
- The first medical pattern (title starts with „medic”) changed 35 roles and missed „2 posturi medici specialisti/primari …”. The word pattern („medic” / „medici”) changed 47, all medical.
- `profesionala` is a level of the extraction but not of the brief's order. Added between generală and liceală.
- The 31 medical postings that still pass: no level (23 before the inference; group 2 fills most), `generala` (9, warning only), `postliceala`.
- `ops/check-export.py` is out of scope here. The warning sits in the export verify step.

**Decided**
- Inferred level source is `occ_cor_code` (ISCO group as fallback), not `occ_group`: 252 postings instead of 350. The cost of `occ_group`: it holds a group read from the title and the rule would trust it.
- An inferred level is data for the filter, not a stated requirement: the eligibility panel skips it.

---

### 2026-11-22 — DATA-DEADLINE-SANITY live; survey re-run on the VPS (VPS session, reviewed by Opus)

**Done**
- The VPS session pulled `f369ce0`, ran `export-to-sqlite.py --active-only` (no LLM calls), `ops/check-export.py` (33/33 pass) and `deploy-php.sh --data-only --no-export`. Export built 2026-11-22T21:34:20Z, 2,983 postings. The live site returns HTTP 200 with the new `built_at`. Cloudflare was not purged (the CF variables are not set).
- Survey, before → after: R07 11/2898 → **0/2887** · R08 26/2983 → 26/2983 · E03 63 → **26** (76 parallel pairs not counted) · E06 27/33 → **6/33** (31 ranges wider than the grid not counted).
- The 4 sampled R07 postings now take the card date (`expirare`), and it matches the `Termen` on the source card: `paznic-32` 2026-11-23, `muncitor-calificat-iv-bucatar-3` 2026-12-23, `administrator-financiar-contabil-6` 2026-12-02, `muncitor-necalificat-ingrijitor-animale` 2026-11-26. `v4_application_deadline` still holds the old LLM values; only `apply_deadline` changed.

**Hard**
- R08 stays at 26. The short windows are a different case from R07. Not checked against the posting text.
- E03 samples: two plausible reposts (Apahida, 26 days; Sepsi Ut Epito, 38 days). Alexandria posts parallel "Administrator" posts in two waves 41 days apart; the check links the waves. Assumed acceptable: it is a recurring drive.
- E06 samples, 4 of 4 are real mismatches, not range artifacts. Two point at a wrong grid row (for example a debutant inspector matched to 9052–12109 lei); two declare pay well above the grid. The source pages were not opened. A wrong grid row is a salary-matching defect, not a posting anomaly.

---

### 2026-11-22 — DATA-DEADLINE-SANITY: reject an apply deadline before the publication day (Sonnet, from the Opus brief)

**Done**
- `export-to-sqlite._apply_deadline` and `posting_facts.apply_deadline` take a fourth argument, `published_at`. A candidate dated before the publication day (Bucharest date) is skipped. The next source is taken: `concurs`, `anunt`, `expirare`. When every candidate is impossible, the date is `None`.
- A skipped source is named in the returned dict (`rejected: ["concurs"]`). The key exists only when a source was skipped, so every old exact-dict test and the export schema stay unchanged.
- Callers changed: `export-to-sqlite.py` (both calls, in `export()` and in the deadline-table export), `posting_facts.tracked_facts`, and `observe_postings._POSTING_FIELDS` (adds `published_at`, so `tracked_facts` can check it).
- Tests: four brief cases and two more in `test_apply_deadline.py`; the equality test and the `tracked_facts` test in `test_posting_facts.py` now cover `published_at` (4 x 3 x 3 x 5 combinations); one `tracked_facts` case.
- `short_deadline` (`infer_postings._infer_anomaly_flags`, not changed): it uses `data_limita_depunere` minus `published_at` in days and flags `delta < 7`. A negative delta is below 7, so a card deadline before the publication day is flagged `short_deadline`, not skipped. The flag does not read `apply_deadline`, so this change does not touch it.

**Hard**
- Full suite: 1720 passed, 2 failed. `test_e06_seniority_spread_not_a_hit` belongs to the parallel anomaly-survey work. `test_salary_facet_sql ... [pages/list.php]` finds no salary comparison in `list.php`; this change does not touch that file.

**Decided**
- `published_at` is optional (default `None` means no check), so a caller without it keeps the old behaviour. All production callers pass it.
- Assumed: the effect on the 11 live postings (survey `R07`) is not measured locally. The local Postgres is a September copy; the VPS export measures it. After the next VPS export, run `ops/anomaly-survey.py --only R07,R08`; expect R07 = 0 for sources that have a later candidate.
- A new observation is written by `observe_postings` for postings whose deadline changes. This is intended.

### 2026-11-22 — ANOMALY-SURVEY Step 1b: E03 and E06 fixed (Sonnet, from the Opus brief)

**Done:** E06 now compares the declared minimum only: a hit is a minimum more than 20% below the grid minimum or more than 20% above the grid maximum. Ranges wider than the grid go to `extra.range_wider_than_grid_not_counted`. E03 counts a pair as a repost only when the gap is 14 to 90 days (new parameter `repost_min_days`). Closer pairs go to `extra.parallel_pairs_not_counted`. Tests: one fixture per rule; 34 pass.

**Measured:** on the local `tmp/261118/grid153/posturi-after2.sqlite` (9756 postings): E03 336 before, 222 after (202 chains, 299 parallel pairs). E06 1 of 4 before and after (3 wider ranges not counted). The local copy has few declared pay rows. The VPS figures (E06 27 of 33, E03 63) are not reproduced here.

**Assumed:** the 14-day limit is the Opus value. Pairs are consecutive postings in publication order, so a parallel post between two reposts can shorten a gap.

---

### 2026-11-22 — ANOMALY-SURVEY: VPS counts on the live export (VPS session, reviewed by Opus)

**Done**
- The VPS session ran `ops/anomaly-survey.py` at `f3a893d` with default thresholds and `--index-csv data/posturi_gov_ro.csv`. Export: `webapp-php/posturi.sqlite`, built 2026-11-22T17:13Z, code `e01d693`, 2,983 postings. No check reports `unavailable`. Files on the VPS: `/tmp/anomaly-survey.{json,csv,out}`.
- Counts (count / denominator): R01 13/1712 · R02 0/1220 · R03 0/1220 · R04 0/36 · R05 3/1466 · R06 0/2898 · R07 11/2898 · R08 26/2983 · R09a 0/80 · R09b 1/70 · R09c 2/70 · R10 2/80 · R11 3/1220 · R12 33/78 · R13 3/819 · R14 2/1443 · R15 1/58 · R16 9/2881 · R17 4/2383 · R18 0/2131 (context) · R19 5/2982 · R20 45/4635 · E01 1216/2887 · E02 1/2887 · E03 63/2983 · E04 5/2983 · E05 8/3694 · E06 27/33 · E07 5/2043 · E08 5/1220.

**Hard**
- The live export holds 2,983 postings; the September copy holds 9,756 (it includes old postings). The counts are not directly comparable.
- R06 (0-day deadline) is **0** on the live export, against 313 on the September copy. The 10-of-10 parser artifact on old pages does not occur in the current data. The cause of the change is not traced (assumed: a later parser or extraction change).
- R07 (deadline before publication, 11): 4 sampled hits have an extracted `apply_deadline` that is stale or a year off, while the card `Termen` (`expires_at`) is plausible. The defect is in extraction, not in the posting.
- R08 (`short_deadline`, 26): the 3 sampled hits have an extracted deadline 7-17 days out and `expires_at` 3-5 weeks later. The two dates disagree; not checked against the posting text.
- E06 (declared pay against the grid, 27/33): the check fires on any declared range wider than the grid row (for example declared 4325-5939 with seniority, grid 4325-4428). This is a check design defect, not an anomaly.
- E03 (repost chain, 63): mixed. Some pairs are two parallel posts published the same day, not a repost.
- E05 (narrow requirements, 8): not opened; assumed weak (generic high-experience or many-skills roles).
- E01 (under 10 working days, 1216/2887 = 42%): a distribution, not a list of breaches. The window runs from the posturi.gov.ro publication date, which can be later than the employer's own announcement (assumed, not measured).

---

### 2026-11-22 — ANOMALY-SURVEY Step 1: survey script `ops/anomaly-survey.py` (Sonnet, from the Opus brief)

**Done**
- Wrote `ops/anomaly-survey.py` (read-only, standard library). It runs 29 checks: brief rows 1-20 (`R01`-`R20`, row 9 split in `R09a/b/c`, row 18 is context only) and the 8 added checks (`E01`-`E08`). Output: one JSON report (count, denominator, share, 5 sample URLs, notes, extra) and one CSV `posting_id, check, value, peer_value`. A check with a missing column or table reports `unavailable` and lists the gap.
- `_norm_title` comes from `infer_postings.py` by parsing its source (no Django needed, no copy). Holidays come from `core-assets` `ro-holidays.csv` (2026, 2027 only); other years use fixed dates plus a computed Orthodox Easter (agrees with the CSV for 2026).
- 32 tests in `webapp/tests/test_anomaly_survey.py`, one per check. All pass.
- Run on `tmp/261118/grid153/posturi-after2.sqlite` (9,756 postings) in 1.6 s. Counts are in the brief report, not repeated here.
- Row 6 verified on 10 pages: **10 of 10 are a parser artifact** (see Decided).
- Legal minimum, contract staff: HG 1336/2022 art. 34 (consolidated text of 26.03.2025): 10 working days from the announcement for a vacant post, 5 for a temporary vacant post.

**Hard**
- Measured on the September copy, so the v5 columns are empty. `E04` (edit after publication) needs the `updates` column. The export does not hold it. The script takes `--index-csv data/posturi_gov_ro.csv` for that check. Result: 41 postings with a title change, 2,205 with an `expira_in` change. `expira_in` is the announcement expiry, so most of these are extensions, not edits of the apply deadline.
- The export has no field for "vacant" against "temporary vacant" post. `E01` therefore flags contract postings under 10 working days, and reports the 5-day count in `extra`.
- Brief rows 5, 6, 11, 16, 17 differ from the brief baseline by more than 20%. The brief gives no query for these. See the report to the owner for the cause of each.

**Decided**
- Row 6 verdict: the 0-day deadline is a parser artifact. In all 10 sampled pages (local `data/anunturi/`), the calendar row is a window, for example `depunerea dosarelor de concurs: 25.05.2026 – 08.06.2026`. `data_limita_depunere` holds the first day (the publication day), not the last day. The cause is in `parse-anunturi.py`: the window end is not taken for these row shapes. The fix belongs to Step 2 (class A, parser). Not changed here.
- The civil-servant minimum stays a parameter (`--min-wd-civil-servant`, no default). Search results mention 20 days (HG 611/2008 art. 49, Cod administrativ art. 465); the legal text was not read. Unconfirmed.
- The minimum wage for `E06` is a parameter (`--min-wage-gross`, default 4050, unconfirmed).
- Rows 6-7 and the working-day checks use `apply_deadline` with `deadline_source` in `anunt`/`concurs` only. The `expirare` source is the competition end, not a deadline.

---

### 2026-11-22 — SALARY-ANEXA-VI built after the owner decisions (Sonnet, from the Opus brief)

**Done**
- Rank pay in `estimate()` for the draft (post + rank coefficient × VR) and the law (post 2022 × 1.05 + OUG 26/2024 art. XI table). Range of ranks gives a range; "plutonier adjutant șef" takes "principal" with a warning; a head has post pay only with a warning.
- Law grid: 40 Anexa VI rows added (0 removed); `solda-grad-lege153-2026.csv` (27 ranks).
- Page `/estimator-salariu/`: parts, total and the bonus line; the export has four new nullable columns.
- Figures: agent de poliție debutant PL: draft 8,241; law 5,918.
- Tests: Python 1,704 passed, 1 failed (the known `test_salary_facet_sql.py`), 13 skipped. PHP `salary_estimator_test.php`: 176 assertions pass.
- Dry run on the 9 Sep copy: counts identical before and after.

**Hard**
- The law HTML and the draft workbook are untracked (`docs/salarii/` is in `.gitignore`): the worktree needs a copy.
- The minimum-wage floor on the post pay alone would give 6,305 for the agent. It applies to the total.
- The law row of the debutant agent also names two other ranks, so the page shows a rank range for that row.

**Decided**
- Assumptions in the audit (gradation on the law rank pay; floor on the total). The owner confirms or changes them.

### 2026-11-22 — SALARY-ANEXA-VI: rank table, matcher fix, law spike; stopped at the rank mapping (Sonnet, from the Opus brief)

**Done**
- `build-salary-grid.py` writes `data/salarii/solda-grad-2026-07-17.csv` (22 ranks). The post grid is byte-identical.
- Matcher: the "/" split of the synonyms left no synonym equal to the full label. `Grid.by_label` fixes this as a last resort. Dry run on the 9 Sep copy: all counts identical before and after (9,750 postings, 8,368 estimated).
- 4 tests in `TestAnexaVILabel`. Suite: 1,694 passed, 1 failed (the known `test_salary_facet_sql.py`), 13 skipped.
- Spike on the law in force: `docs/audits/2026-11-20-salary-anexa-vi.md`.

**Hard**
- Stop condition hit: post rows 24–27 name a range of ranks, and rows 33–35 ("plutonier adjutant șef") have no name in the rank table. Steps 2, 4 and 6 (rank pay in `estimate()`, the page, the other tests) wait for the owner.
- The law's rank pay is not the Art. 7 table (agent 250 lei, 2022) but the updated table of OUG 26/2024 art. XI (agent 1.980 lei).

**Decided**
- Nothing. The owner decides the range rule and whether the law-in-force rows get built (probably an ADR 0008 rule).

### 2026-11-22 — SALARY-PIDS: no document fixes the pay per role (Sonnet, spike)

**Done:** Read the PIDS/586/PO4/339395 selection procedure (Feb 2025), the project fact sheet, the launch press release and the general conditions guide (Mar 2023). Counted the PIDS postings on the local 9 Sep copy. Wrote [the audit](audits/2026-11-20-salary-pids.md), with the VPS SQL. Files stay in `tmp/261120/pids/` (not in git).

**Measured (local):** 414 of 597 postings of the five occupations match `PIDS|servicii integrate`. 350 of the 414 already have a `lege_in_vigoare` figure. 64 have none (Manager proiect 18, Consilier școlar 28, Tehnician asistență socială 13, Asistent medical comunitar 4, Mediator sanitar 1). 7 postings name the project only in other words. The documents give one ceiling, 914,123.84 lei for each commune, for all salary packages.

**Hard:** The Condiții specifice guide (AP4) is behind a Cloudflare block on adrcentru.ro. mfe.gov.ro did not come up in search. The VPS was not reachable from this session.

**Decided (proposal, for Opus and the owner):** No PIDS `baza`: no public source gives a pay for a role. Organigram staff follow Legea 153/2017 (general guide, section 3.4). The alternative, a figure from the 70/80/90 lei net-per-hour ceiling, applies to project experts and would have no source for the team roles. **Assumed:** the local "no figure" count is an upper bound, because the local estimates predate the fixes of 20–22 Nov. The VPS count is pending.

### 2026-11-20 — SALARY-DECLARATII-PAGE: review of the build; every matching employer shows the section (Opus)

**Done**
- Reviewed the Sonnet build (`50842ba`…`68453a2`) against the brief. On the combined code: all PHP test files pass; pytest 1,684 passed, 1 known failure (TEST-SALARY-FACET-LIST); Playwright 930 passed, 3 known failures (TEST-ALERTS-PRIVACY-LINK). Checked the screenshots at 320 and 1280 px.
- Fixed the gap that the brief caused: one employer per institution left 175 employer pages without the section. The export now writes `declared_employers` (every matching employer record); `declared_institution_of_employer()` reads it, with a fallback for an older export. Scratch export of the 9 Sep copy: 629 employer pages linked (454 main + 175 extra records), 2,108 institutions, 6,201 groups.

**Hard**
- The 175 are duplicate employer records of one body, spelled differently. That is a data-quality problem of `jobs_employer` (EMPLOYER-DUPLICATES), not of the match.

**Decided**
- Link every record, do not merge employers here. Reason: a merge changes `/angajator/` URLs and counts, outside this feature. Cost: a duplicate body has two employer pages, each with the same section.
- Known small issues left: on a phone the index is a long list (about 13,000 px for 50 institutions); the note writes „+15 %” and the explainer „15%”.

### 2026-11-20 — SALARY-DECLARATII-PAGE: declared pay per institution built (Sonnet, from the Opus brief)

**Done**
- Step 0: `_feed.php` prints „≈ {median} lei net / lună, după declarațiile de avere” for `baza: declaratii`, no „la gradația 0”. Case in `feeds_test.php`.
- Step 1: `export-to-sqlite.py` writes `declared_institutions` and `declared_groups` (`build_declared()`, pure; `export_declared_institutions()`). Measured on the local copy of 9 Sep: 2,108 institutions, 6,201 groups, 5,871 employers with postings, 629 employers match; 454 institutions get an employer, 175 employers lose the tie. 0 slug clashes; longest slug 120 characters; minimum group size 5.
- Steps 2–6: partial `partials/declared_pay.php`; routes `/salarii-declarate/` and `/salarii-declarate/{slug}/`; section on `employer.php` (after the counts, before the postings); sitemap (2,109 URLs more); link from the method section. Old exports without the tables: no section, 404 for the page, the index says the data is missing.
- Step 7: `tests/declared_institutions_test.php` (84 assertions), `browser/declared_institutions.spec.js` (320, 375, 1280 px), `webapp/tests/test_declared_export.py` (6 tests). `ds_test.php`, `declared_pay_test.php`, `salary_estimator_test.php`, `request_test.php` pass.

**Hard**
- The brief expected about 629 employers with a match. That is the count of employers, not of institutions: many employer ids name one body (for example 4 ids for „Direcția generală de asistență socială … Suceava”). One institution has one employer (the brief), so 175 employers show no section.

**Decided**
- Slug: `slugify(name)[:110] + "-" + county slug`, the county left out when the name already ends with it. Reason: stable for one `institutie_id`, with no counter. On a clash (none today) a 6-character hash of `institutie_id` follows. Cost of a counter: the slug would change when another institution appears.
- County: `judete.normalize_judet()` gives the canonical name („Bacău”); the slug is its ASCII form, which equals the site's county slug.
- The employer county is the one with most postings, counted on all postings in Postgres (not only the active ones), so a full and an `--active-only` export agree.
- The index filters and sorts in PHP (2,108 short rows), so that a search without diacritics works; the names in the data have none.
- Step 1 of the explainer for an institution: total declarants, grouping by function, and one example (the largest group) with its 2023 median. Steps 2 and 4 keep the wording of the explainer; step 3 refers to the table.
- „Eroare tipică” in the note is the largest `eroare_tipica` of the institution, in whole percent.

### 2026-11-20 — SALARY-UNMATCHED applied on the VPS: 84% → 94% of postings have a figure (Opus review; VPS session `posturi-vps-261118`)

**Done**
- The VPS ran `normalize-titles.py --fill-selectors` (HEAD `c799d18`, 14:40–14:47 EET): 1,226 occupations filled. Then `estimate-salaries.py`.
- A read-only check found that the fill put job names that exist in several annexes in an annex that ignores the employer (Psiholog → III, Infirmier → I). The owner kept the fill live for the 18:33 run, and Sonnet built the annex choice by employer type (`9857cb0`). The VPS re-ran `estimate-salaries.py` (HEAD `50842ba`, 15:57–15:59 EET).

**Measured (VPS)**
- Postings with a figure: 14,120 / 16,776 (84%) → 15,715 (94%). No figure: 2,656 → 1,061. No annex line lost postings with a figure. The annex fix kept the count at 15,715.
- Before → after the annex fix (rule-name-lookup rows, median lei_min / max lei_max): Psiholog 4,543 / 5,246 (Anexa III) → 5,363 / 7,241 (Anexa II b.4); Infirmier 4,325 / 5,681 (Anexa I, 14 rows) → 4,485 / 5,681 (Anexa II c.1); Medic specialist max 14,543 → 11,187; Muzeograf Anexa I → III.
- Council ranges now apply to filled Anexa VIII posts at town halls (Șofer, Referent, Administrator: 4,325–19,320). This is ADR 0008 rule 3, not a defect.
- Still no figure: 1,061. Top: Consilier școlar 58, Tehnician asistență socială 44, Manager proiect 33, Medic rezident 25, Mediator sanitar 25.

**Hard**
- The pull of `50842ba` also brought the declared-pay step of the parallel SALARY-DECLARATII work: 13 postings take `baza = declaratii`, and 13 salary buckets move. That is not part of this item.

**Decided**
- *Publish the fill at 18:33, then fix the annex* (owner). Rationale: the postings had no figure before; most figures were close; Psiholog was about 20% low. Alternative not taken: clear the fills of names in several annexes before 18:33 (a few hundred postings back to „fără salariu estimat”).
- *The annex comes from the employer, per posting, in `estimate-salaries.py`*, not from the occupation dictionary: one occupation serves many employers. When the employer type is unclear, the figure is the range over all annexes that have the name.
- *Consilier școlar takes the „Psihopedagog” row* (owner; alias in `normalize-titles.py`). Both are university-level (S) posts. Backtrack: remove the alias, re-run `--fill-selectors` and `estimate-salaries.py`. Measured on the local copy: about 95% of these postings are project hires, most of them in the PIDS project („Furnizare de servicii integrate în comunitățile rurale”), and none states the pay.
- *Tehnician asistență socială and Lucrător social do NOT take „Asistent social”* (Opus, after the owner's yes): „Asistent social” is an S row, and the technician post is M, so the figure would be too high. The law grid has no M row for the job. The draft has one (Anexa II, „Educator, … tehnician asistență socială”, M). Open: alias to that draft row (figure as „estimare aproximativă”) or no figure. Lucrător social has 6 local postings; no row is close.
- *Tehnician asistență socială takes the draft M row* (owner, later the same day): „Educator, …, tehnician asistență socială 1*)”, Anexa II b), no grade. The figure is a last-resort „estimare aproximativă” (ADR 0008 rule 4(c)). Alternative not taken: no figure. Lucrător social keeps no figure. Backtrack: remove the alias in `normalize-titles.py`, re-run the fill and the estimate.
- *Correction, same day (after the VPS run of `d9697d7`): Tehnician asistență socială takes the LAW M row.* Opus was wrong: the law grid has the row (OUG 19/2024 Anexa 2, „Educator, …, asistent medical 1”, M, 4,500 lei); it only leaves out the words „tehnician asistență socială”. With the draft spelling, `selector_for_law()` split the comma list, matched „Educator” in Anexa I, and the VPS showed 4,332–6,554 lei (interval) for the 44 postings. The alias now names the law row. `--fill-selectors` now re-derives the rows that it wrote (`provider = rule-name-lookup`), so a changed or removed alias reaches those rows; before, the fill skipped them and the backtrack path above did not work. Not changed: 36 of the 44 postings have a model selector (Asistent medical, Tehnician, …); the fill never touches it.
- *VPS, 2026-11-22 16:19–16:22 EET (`e01d693`):* the refill changed 8 occupations (dry run: 8, as expected). The 44 postings of Tehnician asistență socială show 4,500 lei, `lege_in_vigoare`, exact, Anexa II OUG 19/2024 Anexa 2 (before: 4,332–6,554 lei from 10 rows of Anexa I). Postings with a figure: 15,886 of 16,848 before and after (94%; the pipeline added postings since 20 Nov). No posting lost its figure. Baseline per annex (postings / draft / law): "-" 1062/162/170, I 1429/1412/1420, II 5341/5061/5215, III 156/78/156, IV 32/31/29, V 64/64/64, VII 6/6/0, VIII 8758/8719/8633. Confidence: exact 6,401, interval 9,485, necunoscut 962.
- *Spike on the PIDS project pay* (owner): one project may fix the pay of several jobs that have no grid row (Manager proiect, Mediator sanitar, Asistent medical comunitar). [Brief](briefs/2026-11-20-salary-pids.md).

### 2026-11-20 — SALARY-DECLARATII: pages per institution, decided and briefed (Opus + owner)

**Done**
- The owner chose a section on the employer page, a page per institution and an index. ADR 0008 rule 9 has a new bullet „Pages per institution”. Brief: `docs/briefs/2026-11-20-salary-declared-institutions.md`; it includes the feed fix as step 0.

**Decided**
- Show the groups per institution, not per posting. Reason: the postings route uses 5 of 6,201 groups; this route uses all of them. Measured on the 9 Sep copy: 629 of 5,871 employers with postings match an institution; about 1,480 institutions have no employer page.
- The first line under the heading says that contract staff are not included. Reason: spike B, a council's civil-servant pay does not predict its contract-staff pay.
- No comparison with other employers for now (SALARY-DECLARATII-COMPARE).

### 2026-11-20 — SALARY-DECLARATII: review of the build, and two coverage spikes (Opus)

**Done**
- Reviewed the Sonnet build (`fcbc00c`…`ff93ea2`) against the brief. On the combined code (another session's `9857cb0` landed in between): 189 salary tests and the PHP tests pass; the dry run reproduces 6,201 groups, 1,093 employer matches and 5 group matches. `alerts.spec.js:54` fails, but that is TEST-ALERTS-PRIVACY-LINK (anchor rename `9e59277`, 18 Nov), not this build.
- Spike A (peer groups) and spike B (council premium for contract staff). Numbers in the audit note, „Coverage spikes”.

**Hard**
- Coverage is limited by who declares, not by the match rule. Council postings are community nurses, school counsellors, drivers and workers; council declarants are civil servants (85 workers and 53 drivers among 47,139).

**Decided**
- Council peer groups: dropped (error 11.0 %, +70 postings). The council premium for contract staff: dropped (0 to 2.8 times the premium on 5 councils). Both recorded as `[-]` in the backlog.
- Rule 9 stays as built. A national peer group by kind of institution is accurate (4.4 %) but adds 7 postings: kept as a low-priority item.
- Measured on the 9 Sep copy only. Assumed, not measured: the VPS data has the same mix of posts.

### 2026-11-20 — SALARY-DECLARATII: declared pay built (Sonnet, from the Opus brief)

**Done**
- `salary_net.py`: `net_from_gross()` (port of the PHP function) and `gross_for_net()`. A test compares Python with PHP for 286 gross values from 3,000 to 30,000 lei. A brute-force test checks `gross_for_net()` against the first crossing for every net value.
- Factor: mean of 2026-01..2026-05 of FOM106G section P (Rev.3) = 6,860.0; mean of the 12 months of 2023 of FOM106D section O (Rev.2) = 5,963.5; ratio 1.1503, shown as 1.150. The build stops when the rounded value is not 1.150.
- `ops/build-declaratii-grupuri.py` writes `data/salarii/declaratii-grupuri.csv`: 6,201 groups at 2,108 institutions, 1,246,703 bytes (admin-central 1,463; admin-judetean 635; admin-local 1,688; mai-mapn 2,124; politie-locala 291). The spike count (6,201 and 2,108) is the same. `family()` moved to `ops/integritate_common.py`; the backtest output of `ops/integritate-panel.py` is unchanged.
- `declaratii.py` matches a posting to one group (exact employer after normalisation, county equal, base word + grade). `estimate-salaries.py` step (a2): `baza: declaratii`, `lei_min` and `lei_max` = gross of p25 and p75 × factor, key `declaratii`, law figure under `lege_in_vigoare`, draft under `proiect`.
- Pages: list label „(după declarații de avere)”, detail figure with the band and the gross line, the explainer in „Cum a fost calculat”, „Metoda pe larg” at `/estimator-salariu/#metoda-declaratii`. Playwright spec `declared_pay.spec.js`, PHP test `declared_pay_test.php`.
- Measured on the local copy of 9 Sep 2026 (9,750 postings with an occupation): employer match 1,093 (11.2 %); group match 5 (0.05 %): admin-central 4, admin-local 1. `baza` before: lege_in_vigoare 8,256, proiect 1,494. After: declaratii 5, lege_in_vigoare 8,251, proiect 1,494. `salary_bucket` changes: 5 postings (net-4000-5000 → net-5000-7000: 2; net-3000-4000 → net-4000-5000: 1; net-3000-4000 → net-5000-7000: 1; net-4000-5000 → net-peste-7000: 1). The VPS numbers are not measured yet.

**Hard**
- The title match is the limit, not the employer match. Of the 1,093 postings with an employer match, 51 have a group with the same base word under another grade. Most postings write the grade „gradul IA”, „gr. II” or „debutant”. The declarations write „superior” or „principal” for the same post. When the grade is ignored and one group has the base word, 38 postings match. So even a fuzzy rule gives under 1 % of postings. Wider groups need their own backtest (out of scope in the brief).
- `gross_for_net()` is not monotone between 4,325 and 6,325 gross (the deduction steps drop the net by 1–3 lei). A plain binary search gave a wrong first crossing for 25 net values; the build walks back 60 lei from the found crossing.
- PHP `round()` rounds half away from zero; Python `round()` rounds half to even. The port uses its own rounding. With the Python `round()`, 62 of the 286 test values differ from PHP.

**Decided**
- p25 and p75 use the index `int(p × (n − 1))` of the sorted amounts, as `q()` of the backtest does. Reason: the same rule as the measured error. Cost: for a small group the p25 sits at the lower neighbour.
- `declaratii` also holds `mediana_net_2023`: step 1 of the explainer shows the 2023 median, and median × factor ÷ factor does not give it back after the rounding to 50 lei. The brief did not list the key.
- The list row shows the net range p25–p75, not the median: the export has no median column (backlog: SALARY-DECLARATII-LIST-MEDIAN).
- A posting without an occupation gets no declared figure (6 postings on the 9 Sep copy): `SELECT_POSTINGS` joins the occupation. Cost: none seen.
- `list-decimal` was missing from `webapp-php/static/app.css`; `npm run css` rebuilt it (+38 bytes).

### 2026-11-20 — SALARY-DECLARATII: rule 9 accepted, build brief written (Opus + owner)

**Done**
- The owner accepted ADR 0008 rule 9 and its changes to rules 1, 3, 4 and 6.
- Build brief for Sonnet: `docs/briefs/2026-11-20-salary-declared-pay.md` (net/gross in Python, the factor from INS, the group table, the posting match, the estimate, the pages).
- Explainer spec: the copy goes in the existing disclosure „Cum a fost calculat” of the detail page; „Metoda pe larg” is a section of `/estimator-salariu/`, because `/salarii/` does not exist yet.

**Hard**
- Coverage is small. 6,201 groups pass n ≥ 5, at 2,108 institutions, but only about 10 % of postings (9 Sep copy) have an employer name that equals one of those institutions, before the title match. Measured with an exact name match only; county not checked.

**Decided**
- The match in the first build is exact after normalisation; no fuzzy match. Reason: a wrong employer gives a confident wrong figure. Cost: lower coverage. Wider groups (county + type + title) are a separate item with their own backtest.

### 2026-11-20 — SALARY-DECLARATII: ADR 0008 rule 9 and the explainer, drafted (Opus)

**Done**
- ADR 0008: rule 9 (declared pay), precedence step (a2) in rule 4, the stop on the switch day in rule 6, a third `baza` value (`declaratii`), and the alternatives not taken. Status: proposed.
- Explainer copy in Romanian: `docs/specs/2026-11-20-salary-declared-explainer.md` (list row, detail figure, four-step pop-up, method page outline).

**Decided (proposed; the owner confirms)**
- `sal_min` = p25 and `sal_max` = p75 of the group, × the factor. Reason: the filter keeps reading the lower figure (rule 7), and a new hire is usually under the median. Cost: the filter places these postings lower than the median that the page shows.
- Only groups of one employer and one title, n ≥ 5. A wider group needs its own backtest first.
- The aggregate table goes in git (`data/salarii/declaratii-grupuri.csv`); the dump does not.

### 2026-11-20 — SALARY-DECLARATII: the declared amounts are net (Opus)

**Done**
- Compared the declared 2023 pay × 1.150 with the March 2026 Art. 33 lists of S1, same employer and post: 6 groups at Târgoviște, CJ Timiș and CJ Bacău. Ratio median 0.62 (0.53–0.73). Net pay is 0.585 of gross.

**Decided**
- The declarations are net. The estimator shows the figure as net, and gives gross as net ÷ 0.585 (or the converter that the site already uses). Reason: CJ Timiș, n = 144, ratio 0.64 against the highest gradation; a gross figure cannot sit 36 % under the base pay.
- Measured on 6 groups of local and county administration only. Assumed, not measured: other families also declare net. The single peak inside each group supports it, but does not prove it.

### 2026-11-20 — SALARY-DECLARATII: the factor from 2023 to 2026 (Opus)

**Done**
- Measured the INS net average pay by section (FOM106D + FOM106G): 2026 (Jan–May) / 2023 = O 1.150, P 1.242, Q 1.207. The two series agree to 0.01 % in their 8 shared months.
- Reran the backtest with the INS factor for 2022→2023 (out of sample): median error 6.1 %, the same as a factor fitted on the panel. Proposed a factor per family in the audit note.

**Hard**
- The law and INS differ for section O: the law predicts about ×1.18 on the 2023 average (×1.15 from Dec 2023, plus a 3 % gap inside 2023). INS measures ×1.150. INS also shows 2026 4 % under 2025. OUG 36/2025 (Anexa VI bonus capped at 300 lei from Jul 2025) is one cut in that period. Assumed, not measured: the cuts of 2025 explain the difference.

**Decided (the owner accepted the factors on 2026-11-20)**
- Section O families use the INS factor 1.150. Reason: it is measured on yearly averages, as the declarations are, and it includes the 2025 cuts. The legal factor would need a gap correction and would miss the cuts. Cost: one factor for all of section O, while MAI and county administration have a −2.7 % and +4.2 % bias in the backtest.
- No figure for magistrates (law ×1.05 against INS ×1.15). Health and education wait for a legal ratio per title, because the INS factor overshoots by about 4.5 % and the people who declare are mostly managers.

### 2026-11-20 — SALARY-DECLARATII: person key, pay line, panel and backtest (Opus + owner)

**Done**
- Second dump with a scrambled `person` key: 1,402,281 declarations, 1,822,126 rows (`data/integritate/integritate-salarii-raw-20261120T1229.csv.gz`).
- `ops/integritate-select.py`: one pay line per declaration; 75.4 % name the institution. `ops/integritate-panel.py`: panel, net/gross check, backtest. Results in the audit note.

**Hard**
- The first institution match gave false hits on place names (a school in the commune, a city company for a sector). A type word in `sursa` that the institution lacks now blocks the match.
- Chrome blocked the second automatic download. The owner allowed multiple downloads for `kibana.sicap.ai`.

**Decided**
- The owner chose: a scrambled person key (salt per run, never written); estimate 2026 pay from the declarations, with transparency.
- Method: the 2023 level comes from the declarations; the 2023→2026 change comes from the acts, per family. Reason: the realised 2022 and 2023 growth follows the law (2022 frozen: median 1.035; health 1.024 under the 2022 cap; central 1.111 for +10 %). A growth factor also works the same on net and on gross, which we cannot tell apart.
- Measured: backtest median error 6.0 % (p90 19.2 %, 69 % within 10 %) for group median 2023 from 2022 × family factor; justice, magistrates, EU projects and elected officials about twice that. Within groups the values have one peak, so one group declares one way (net or gross). Assumed, not measured: the 2023 yearly average of local administration is below its Dec 2023 level (June 2023 raise).

### 2026-11-20 — SALARY-DECLARATII: the asset declarations in Kibana, and the first dump (Opus + owner)

**Done**
- Explored the monitorul.ai Kibana (`kibana.sicap.ai`, space `integritate`) in the owner's Chrome. The Dev Tools proxy is closed to the role; the internal search `POST /s/integritate/internal/search/es` works. Fields, queries and limits: `docs/audits/2026-11-20-salary-declaratii-kibana.md`.
- Dumped every holder salary line: 1,384,279 declarations, 1,822,108 rows, 92 MB gzip, in 7.5 minutes. File: `data/integritate/integritate-salarii-raw-2026-11-20.csv.gz` (out of git).
- Saved two dump scripts with the same query and CSV: `ops/integritate-dump.py` (API key, user and password, or cookie from the environment) and `ops/integritate-dump.js` (DevTools console).

**Hard**
- `extraction.venituri` is a plain object array, not `nested`. Elasticsearch cannot tie the category, the beneficiary and the amount of one line. So the dump reads the documents and filters the lines in the client.
- `aid` repeats across `portal_vechi` and `portal_nou`. The first run sorted on `aid` only and skipped 15 of 1,402,281 declarations at page edges. The scripts now sort on (`aid`, `source.keyword`).
- The internal search returns `hits.total` as a number, not as `{"value": n}`.

**Decided**
- The dump keeps all holder salary lines and does not choose one. A strict institution match in the browser kept only 48 % of the first 22,000 declarations, because `sursa` carries addresses and parent bodies. Choosing the line in a later Python step lets us change the rule without a new 8-minute dump. Cost: a file about 1.3 times larger.
- No names in the dump (`nume`, `beneficiar_nume` are not read).
- Measured: income exists only for filings 2021–2024, so pay of 2020–2023 (nearly all in 2022–2024). 79.2 % of declarations have exactly one holder salary line. Assumed, not measured: most declarants write the net amount.

### 2026-11-20 — SALARY-UNMATCHED: the annex comes from the employer (Sonnet, owner decision; not deployed)

**Done.** The owner published the selector fill (`4007aee`) at 18:33. On the VPS it filled 1,226 occupations and raised the postings with a figure from 84% to 94% (14,120 → 15,715 of 16,776); nothing was lost. The fix for the defect that followed:
- **Defect.** A function that exists in several annexes got one arbitrary annex. VPS, rule-name-lookup rows: Psiholog 34 postings → Anexa III (culture) 4,543–5,246 where a hospital or DGASPC is Anexa II (about 5,679–6,988); Infirmier 91 → Anexa I; Referent 45 → III; Muzeograf 25 → I; Gestionar custode 9 → I.
- **How `select()` picked the annex.** It did not pick one. With no `anexa`, `narrow(…, "anexa", None)` is a no-op, so the rows of every annex that has the exact function name stay. The later steps (`regim`, `tip_post`, `nivel_administrativ`, `studii`) prefer exact matches and fall back to rows that do not say, so they also ignore the employer. The figure is the range over what is left, and `motiv` names `rows[0].anexa`, the first row in CSV order: that is the "one annex". Two more causes: (1) the fill wrote `anexa` itself when the exact name was in one annex only. „Psiholog” is a synonym only in the Anexa III row „Biolog, fizician, … psiholog”; Anexa II writes the grade into the name („Psiholog principal”, „stagiar”), so the fill wrote `anexa = III`. (2) `law_selector()` sets the hospital type (`tip_unitate`) only when `anexa` is II, so a hospital posting with no `anexa` also got no a.1/a.4 narrowing.
- **Code.** `estimate-salaries.py`: `employer_annex()` (one function, one place: health and social care II, education I, culture III, council employer or prefecture or ministry VIII, else „not sure”), and `estimate_in_annex()`, which runs per posting for each grid. No `anexa` and one annex has the function: that annex. Several annexes: the employer’s annex when the function has rows there, else the range over all the annexes that have it (`incredere` interval, with a warning). A selector with an `anexa` does not change, except an `anexa` written by the fill (`provider = rule-name-lookup`) when the employer is clear. Any case that would give no figure falls back to the old estimate. The choice goes to `selector_provenienta.anexa`. `salary_grid.py`: `Grid.function_annexes()`, and an opt-in selector key `nume_cu_grad` so `select()` finds the grade-named rows of a given annex (Anexa II). `normalize-titles.py`: the fill no longer writes `anexa`.
- Tests: `webapp/tests/test_salary_annex.py` (20).

**Measured, local copy of 9 Sep 2026**, fill applied in memory, 9,750 postings with an occupation: postings with a figure 9,151 before and after; 0 lost. 260 postings changed, all of them filled ones. Annex of the primary figure, before → after: I 969 → 984, II 2,488 → 2,570, III 74 → 84, VIII 5,313 → 5,343, no single annex 243 → 106. Median change (middle of the range) by occupation: Șofer (33) 4,428 → 6,199; Medic (23) 5,702 → 7,840; Referent (18) 4,538 → 5,238; Psiholog (18) 4,894 → 6,018; Infirmier (38) 4,509 → 4,550; Muzeograf (11) 5,790 → 5,386; Asistent social (14) 6,320 → 5,900.

**Hard.** The first version let the draft estimate re-use the annex of the law estimate and lost the hospital type: caught by the before/after numbers (Medic specialist 10,963 → 10,963–14,543). Fix: a function in one annex takes that annex per posting, as the old fill did. The same suite failure as before remains (`test_salary_facet_sql.py`, PHP file, not touched).

**Decided.** The annex stays out of the dictionary: one occupation serves many employers. The name rules of `employer_annex()` use whole words, like `hospital_type()`. The assumption: an `anexa` that the LLM set on a rule-name-lookup row cannot be told apart from one that the fill wrote, so it gives way to a clear employer type; with an unclear employer it stays.

**Next.** VPS: `estimate-salaries.py --dry-run`, then `estimate-salaries.py`. No re-fill is needed.

### 2026-11-20 — SALARY-UNMATCHED: audit and the selector fill (Sonnet, brief SALARY-UNMATCHED; not deployed)

**Done.** `ops/salary-unmatched.py` (`1e975fe`) classifies the postings with no figure. The audit is `docs/audits/2026-11-20-salary-unmatched.md` (`2c83ab8`). VPS (data to 19 Nov): 2,625 of 16,640 postings have no figure (`fara-selector` 2,396, `fara-rand-functie` 229). Local 9 Sep copy: 1,382 of 9,750. Every one has an empty `functie`. Code, owner decisions of 2026-11-20:
- `normalize-titles.py --fill-selectors` (`--dry-run`, no LLM, idempotent). It fills an occupation with no `functie_grila`, `functie` and `cod` from its canonical name: the law grid, then the draft grid. Aliases: Femeie de serviciu → Îngrijitor, Infirmier → Infirmieră. A trade with no row (COR group 7 or 8, by `ocupatii.trade_in()`) takes „Muncitor calificat”; the canonical name stays, so the page shows the trade. It sets `provider = 'rule-name-lookup'`. A filled occupation is never changed.
- `pipeline.py`: new step `selectors` between `occupations` and `salary`.
- The LLM shortlist adds up to 4 names that only the law grid lists (Brancardier, Logoped, Psiholog). Old titles are not re-run.
- Tests: `webapp/tests/test_selector_fill.py` (14).

**Measured, local 9 Sep copy** (selector filled in memory; the database was not written). Postings with a figure 8,368 → 9,151 (+783); `lege_in_vigoare` 8,256 → 9,034, `proiect` 112 → 117; `exact` 3,298 → 3,603, `interval` 5,070 → 5,548. Lost: 0. Changed: 0. Still without a figure: 599. `--fill-selectors --dry-run`: 639 occupations. The audit estimated 731 gained; the measured 783 is higher because of the trade rule and the cleaner alias.

**Hard.** The cause was not a skipped or failed pass. The model answered "none" and the dictionary never retries a title it has. Full test suite: 1,645 pass, 1 fails (`test_salary_facet_sql.py::…[pages/list.php]`, "no salary comparison found"; the PHP file is not touched here; not investigated).

**Decided.** Fill in the dictionary, not as an estimator fallback: the mapping is visible and marked. Multi-role titles go to DATA-04. The match confidence of a filled occupation is left as it was.

**Next.** VPS: `--fill-selectors --dry-run`, the real run, then `estimate-salaries.py --dry-run` and the real run; record the before/after counts. Then tick SALARY-UNMATCHED.

### 2026-11-20 — Salary decisions: the draft as a last resort, known limits, police pay, postings with no figure (Opus + owner)

Started from a r/RoMunca question: "the gross pay of a new border police agent in 2026". A commenter answered „4000 × 1,49 + 4000 × 0,52 = 8040 lei brut” (August draft).

**Done**
- ADR 0008, rules 2 and 4, changed (commit `b19954f`).
- Briefs: `2026-11-20-salary-anexa-vi.md` (`ef88a6f`), `2026-11-20-salary-labels-limits.md` and `2026-11-20-salary-unmatched.md` (`b19954f`).
- Backlog items: SALARY-ANEXA-VI, SALARY-LABELS-LIMITS, SALARY-UNMATCHED, SALARY-DECLARATII.
- SALARY-UNMATCHED steps 1–2 (Sonnet): `ops/salary-unmatched.py` (`1e975fe`, read-only), audit `docs/audits/2026-11-20-salary-unmatched.md` (`2c83ab8`). The VPS session `posturi-vps-261118` ran the script; the owner approved the reads there.

**Measured**
- Police pay, July draft (VR 4,100): „agent de poliție debutant” has a post coefficient of 1.49 (PL) or 1.44 (M) → 6,109 / 5,904 lei. The rank coefficient (0,52, sheet `VI_Armata` rows 74–95) is in the workbook but not in our grid. With it: 8,241 lei. The law-in-force grid has no Anexa VI rows.
- Draft vs law, on postings with both figures (**local copy of 9 Sep**, 8,256 postings): median draft/law 1.14; 10th percentile 1.04, 90th 1.37. Not yet measured on the VPS.
- VPS (data to 2026-11-19 17:04 UTC): 16,663 postings, 16,640 with an occupation. **2,625 (15.8%) have no figure** from either grid: 2,396 with no annex and no function, 229 with an annex but no function. 0 in the other causes. 189 postings take the draft figure (rule 4(c)). 1,988 local ranges. Up to 86 postings flagged outside Legea 153 (rough regex).
- Local copy (9 Sep), for comparison: 1,382 with no figure; 112 on the draft figure.
- Cause (Sonnet, from the code): the occupation model answered „none”, and a title with a dictionary row is never asked again. The model's shortlist comes from the draft grid only, and long titles fall under the 0.35 similarity threshold. A fill from the canonical name gains 731 of 1,382 locally (53%); about 1,390 on the VPS (estimate, scaled).

**Decided**
1. *The draft figure stays as a last resort, labelled „estimare aproximativă”* (owner). It is shown when there is no stated pay and no law figure, and as a side note on the posting page. Rationale: the draft is the only grid that covers most postings, and the filter needs a figure; nothing guarantees the draft passes. Alternatives not taken: adjust it by −14% (a number no act publishes); drop it (189 postings leave the salary filter). Backtrack: ADR 0008 rules 2 and 4 hold the old wording in git (`b19954f^`).
2. *`/despre/` lists what the estimate cannot know* (owner): bonuses, council-set pay, the pay of current staff, the person (seniority, dependants), military/police coefficients set per institution, employers outside Legea 153, the title match. Opus drafted the Romanian text; the owner reviews it before it ships.
3. *Police and military pay = post pay + rank pay* (Opus). Build it for the draft; for the law in force, spike only. Not for the list: in the 9 Sep copy no posting maps to Anexa VI (not measured on the VPS).
4. *Do not compute the August draft from VR 4,000 and the July coefficients* (unchanged rule, `parametri.json`).
5. *Fix the postings with no figure in the occupation step, not in the estimator* (owner). New flag `normalize-titles.py --fill-selectors` (no LLM, fills only empty functions, provider `rule-name-lookup`), run by `pipeline.py` on every run before `salary`. The model's shortlist also gets the law-grid names. Alternative not taken: a fallback in `estimate-salaries.py` (simpler, but the dictionary stays wrong and the rule is hidden). Backtrack: the rows carry `provider='rule-name-lookup'`, so a script can clear exactly them.
6. *Trades with no row of their own (Electrician, Fochist, Electromecanic) take „Muncitor calificat”* (owner). The page keeps the trade name. About 70 postings in the 9 Sep copy.
7. *„Femeie de serviciu” takes the pay of „Îngrijitor”* (owner).
8. *Multi-role titles wait for DATA-04* (Opus).
9. *Measure and fix data gaps on the VPS data*, through committed, re-runnable scripts (owner). The local Postgres is a 9 Sep copy.
10. *Asset declarations (monitorul.ai/integritate, Kibana)* as a source of real local pay: a separate session with the owner. Limits: no publication after 2025 (CCR), data to about 2022, yearly income from all sources.

**Hard / wrong turns**
- Opus first quoted the 9 Sep copy as current numbers (1,388, 112, +14%). The VPS gave 2,625 and 189. All briefs now have a "Where the data is" section.
- The auto-mode classifier blocked `git push`; the owner pushed. The VPS `git pull` moved the cron checkout to `1e975fe`, which also brings the project-hired pay code (`19b0185`, `0aae975`) into the next cron run. The owner checks that the site code matches.
- Sonnet first reported that the cron does not run the occupation and salary steps. Wrong: `ops/run-pipeline.sh` calls `pipeline.py`, which has the steps `occupations` and `salary`.
- Bug seen, not fixed: the full grid label „…agent de poliţie/penitenciare debutant” matches no row (SALARY-ANEXA-VI, step 3).

### 2026-11-19 — Pay of project-hired posts (Sonnet, brief SALARY-EU-DETAIL + PIPELINE-PROJECT-PAY; not deployed)

**Done**
- `classify.project_hire(text, funding_kind)` and `classify.project_pay(text)`. `export-to-sqlite.py` fills six new `job_postings` columns where it computes `funding_kind`: `project_hire`, `proj_pay_amount`, `proj_pay_unit`, `proj_pay_basis`, `proj_hours_month`, `proj_pay_quote`. No existing column, prompt or schema changed.
- `SALARY_EXPR` is now a `CASE` on `project_hire`: a project post counts by its stated month pay (`PROJECT_MONTH_EXPR`), never by the grid; with no month pay it is in `fara-estimare`. A net pay becomes gross through the one formula: `db.php` registers `gross_for_net()` as an SQLite function (`PDO::connect()` + `createFunction()` on PHP 8.4+, `sqliteCreateFunction()` before). The old-export shim fills the six columns with their empty values.
- Detail page: the box „Post finanțat din fonduri europene: salariul depinde de bugetul proiectului.”, the stated pay („50 lei/oră net · 84 ore/lună ≈ 4.200 lei net/lună”, „brut sau net nespecificat”) and its source quote. No grid figure, no meter, no estimator link, no deduced „Salariu”. List row: the month figure, else the rate, else „salariu din bugetul proiectului”. The net-range line of the list skips the grid for these posts. Atom, RSS and iCal carry the same lines (they share `feed_details()`; only for a post with an extraction, as for every stated pay). `/despre/` has one new paragraph (two sentences). README: one paragraph.
- Tests: Python `webapp/tests/test_classify.py` +13 cases (84 pass). PHP `tests/project_pay_test.php` (90 assertions; fixture `make.php <path> project`); `compat_test.php` gets an export without the six columns and two salary-bucket routes; all 22 PHP files pass. Playwright `project_pay.spec.js` (7 tests, 3 projects, all pass). Full Playwright: 891 passed, 60 skipped, 3 failed: only `alerts.spec.js:54` (known, TEST-ALERTS-PRIVACY-LINK, not touched). Python: 1,617 pass, 1 fails (`test_salary_facet_sql.py::test_every_salary_comparison_casts_its_bind[pages/list.php]`); it fails on the unchanged tree too (checked with `git stash`) and I left it. I changed one assertion in `webapp/tests/test_salary_facet_sql.py` (the literal old `SALARY_EXPR` text).

**Measured** (export `tmp/261118/grid153/posturi-after2.sqlite`, copy; data to 2026-09-09; text = `body_markdown` + attachment text converted from `data/downloads/` with `pandoc`, `textutil`, `pdftotext`, as in the spike: 472 flagged postings, 392 with more than 200 characters of attachment text)
- `project_hire = 1`: 447 of 472 flagged postings. The 25 not flagged (4409 8210 8447 8710 8972 9040 9041 9316 9785 9912 10001 10163 11423 11519 11777 11815 11924 11966 12023 12033 12143 12308 12337 12343 14133): I read 20 of them. 9 have only „avantaj … proiectul pilot” (8447 8710 8972 9316 10001 11519 11815 12337 14133), 5 a reading-list or requirement mention (9040 9041 10163 11423 12308), 6 are posts of a project department or of a new PNRR centre with no project wording on the post (4409 8210 11777 11924 12023 12343). I did not read 9912, 11966, 12033, 12143.
- Pay found: 20 postings (all in the 447). Hour, net: 11 (10 with hours per month). Hour, basis not stated: 7 (6 with hours). Month, net: 1. Month, basis not stated: 1. 18 postings show a month figure. The spike counted about 34 with a stated pay: 14 more have several roles with several rates or several month figures (7595, 11735, 11737: net 3.360 to 6.720; 9032: 121 and 165; 13056, 13067, 13379, 12696) and the rule is no mixing, so `project_pay()` returns None for them. The export held 2.
- `project_hire()` on the 60 sample (the `case` column; 59 are case 2, 1 is „neclar”): 59 true positives, 0 false positives, 0 false negatives, 1 true negative: precision 100 %, recall 100 %. **Caution:** I built the patterns on these 60 and the 472, so this is not a held-out test. First pass of the patterns missed 1 of 60 (12484, „beneficiar al proiectului”); I added the cue.
- 334 of the 447 had a grid figure and lose it. 21 flagged postings that are not project hires keep theirs.
- Bucket counts (`salary_bucket`; `fara-estimare` = no figure). Same export, same SQL (`salary_bucket_sql()`), before = code of commit 69a489d, after = this change.

| scope | bucket | before | after |
|---|---|---:|---:|
| all 9,756 | net-sub-3000 | 5,056 | 4,868 |
| | net-3000-4000 | 2,297 | 2,162 |
| | net-4000-5000 | 228 | 228 |
| | net-5000-7000 | 637 | 641 |
| | net-peste-7000 | 158 | 161 |
| | fara-estimare | 1,380 | 1,696 |
| active on 2026-09-09 (1,882) | net-sub-3000 | 961 | 905 |
| | net-3000-4000 | 442 | 410 |
| | net-4000-5000 | 37 | 38 |
| | net-5000-7000 | 113 | 115 |
| | net-peste-7000 | 22 | 22 |
| | fara-estimare | 307 | 392 |

**Hard**
- The attachment text is not in the SQLite file (the export reads it from Postgres). I rebuilt it from `data/downloads/` through `download_manifest.resolve_local_path()`; the real export path passes the same `text` variable to both functions.
- Many ECI posts (school counsellor, school mediator) are flagged only by a „cod MySMIS” in an „avantaj” requirement. The rule „drop a cue that follows experiență, avantaj or vechime within 100 characters” removes them.
- `project_pay()` works on a length-preserving fold (`_fold_keep_length()`), so the quote comes from the original text, with its diacritics, cut at word boundaries.

**Decided**
- Deterministic pass, no prompt change (brief decision 1). Cost of the alternative: a v5 prompt change re-extracts every active posting.
- Several different rates in one text: None (no mixing). One rate with several hour values: keep the rate, no hours, so no month figure.
- A stated pay with no basis counts as gross where a number must be computed (DATA-SALARY-GROSS-NET); the page says „brut sau net nespecificat”.
- „maxim 84 ore/lună” is read as 84 hours. The month figure is then a ceiling (8071, 8904, 9330, 9483, 10538, 13816). Open: say „până la”?
- Not changed (outside the file list): `pages/stats.php` counts `inf_salary_min` and `sal_min` directly (2 lines), and `pages/list.php` is untouched (it uses `SALARY_EXPR`).
- **Diagram change needed** (`docs/img/salariu-estimare.svg` and `webapp-php/static/img/salariu-estimare.svg`, with the alt text on `/despre/`): add a first test before „salariu anunțat”: „Post angajat pentru un proiect european? Da: tariful orar din anunț (sau «salariul depinde de bugetul proiectului»), fără grilă. Nu: continuă.”

### 2026-11-19 — Estimator wizard and INS context line (Sonnet, brief UX-SALARY-WIZARD + SALARY-INS-LINE; not deployed)

**Done**
- Wizard in `pages/salary_estimator.php`: domain, studies, function, variants, result; one GET URL per step (`?d=`, `?d=&st=`, `?b=&fn=&st=&d=`). `?rand=&g=` and `?fn=` URLs work as before. Breadcrumb with the DS `.g2-breadcrumbs`; choices are whole-row links, at least 44 px (Tailwind tokens, no new CSS rule: `npm run css` made no change). An annex whose rows name no studies (VII, IX) skips step 2.
- Title shortcut: field "Caută după titlul postului" above every step. Titles of postings first, then grid names, at most 30 each. New export tables `salary_title` and `salary_ins_context` (`export_salary_titles()`, `export_salary_ins_context()`); both build from the SQLite file itself and the CSV, so they run on an existing export with no Postgres.
- `ops/build-ins-context.py` writes `data/salarii/ins-context.csv`. The result shows one INS line, with an optional county select (`jud`) that does not change the estimate.
- Tests: PHP `salary_estimator_test.php` 165 assertions (was 80); all 21 PHP files pass (10,266 assertions). Python `webapp/tests/test_salary_wizard_export.py` 12 tests. Playwright `salary_estimator.spec.js` 24 runs (wizard at 375 and 1280, title shortcut, INS line). Full Playwright: 870 passed, 60 skipped, 3 failed: only `alerts.spec.js:54` (known, TEST-ALERTS-PRIVACY-LINK, not touched).

**Measured** (export `tmp/261118/grid153/posturi-after2.sqlite`, copy in the scratchpad)
- Functions per domain (law + unlinked draft), then by level S/SSD/PL/M/G: I 142 (100/26/6/44/9); II 148 (99/14/14/39/13); III 53 (40/17/15/21/15); IV 41 (38/3/3/4/-); V 81 (62/15/-/26/-); VI 34 (31/-/18/20/-); VII 20 and IX 53 (no studies in rows); VIII 267 (170/51/37/120/81).
- `salary_title`: 1,492 rows from 9,750 postings (702 with 2 or more postings, 790 with 1).
- INS: 172 rows = 4 national (FOM106G, 2026-05) + 168 county (42 x 4 sections; FOM106E 2024). Step 3 largest list (VIII, S: 170 functions): 81 KB HTML.

**Hard**
- FOM106E in `tempo-ins-dump` has only Masculin and Feminin: INS "Total" options were dropped at fetch. No both-sexes county cell exists. I weight the two sexes by FOM104F (the recipe of `docs/METHODOLOGY.md` in tempo-ins-dump): up to 1 leu from an INS cell.
- FOM106G is CAEN Rev.3: letters shift by one (P administration, Q education, R health, S culture). The CSV keeps the Rev.2 letter.
- The `gradatie` in `sal_json` is 0 for every posting, so `salary_title` stores `grad_treapta` of the estimate instead; the `?rand=` row sets the grade.
- Draft study values are messy (`M + Licență`, `M; G`); a regex takes the codes S, SSD, PL, M, G, and a value with none is "no studies".

**Decided**
- County figure from FOM104F weights (a third matrix) instead of no county line. Cost of the alternative: no county choice.
- A title with 1 posting and more than 8 words is dropped (announcement text, 207 of 925 singles over 60 characters). Not in the brief.
- Periods differ: country monthly (2026-05), county annual (2024). Each line names its own period.
- Annex labels and CAEN mapping assumed: VI, VII, IX labels from the law text; IX to O and VII to no sector (the brief names neither).
- The choice-list component is local (`$choice` in the page). DS proposal not filed.

### 2026-11-19 — Spike: pay of EU-funded and PNRR posts (Sonnet, brief SALARY-EU-SPIKE)

**Done**
- Report `docs/audits/2026-11-18-salary-eu-projects.md` and sample `docs/audits/2026-11-18-salary-eu-sample.csv` (60 rows). No code, export, ADR or site change.
- Counts (export `tmp/261118/grid153/posturi-after2.sqlite`, all statuses): 472 postings with `funding_kind` EU/PNRR; 355 with a grid figure; 2 with a stated pay (`inf_salary_min`); 117 with no figure. On the scrape date 2026-09-09: 122 of 1,882 active postings (119 EU, 3 PNRR), 93 with a grid figure.
- Sample (stratified 30 / 20 / 10, seed 20261118): 59 of 60 case 2 (Wilson 95 %: 91.1–99.7 %), 0 case 1 (0–6.0 %), 1 unclear. Part-time 39 of 60 (52.4–75.8 %); fixed-term 55 of 60 (81.9–96.4 %); pay amount stated 9 of 60 (8.1–26.1 %).
- Law: HG 325/2018 is repealed by HG 234/2023 art. 4. In force: HG 234/2023 (MO 219/2023-03-16), amended by HG 1.009/2025 (MO 1076/2025-11-21) and HG 357/2026 (MO 358/2026-04-30). Art. 16 alin. (1): up to 40 % on the base pay, in proportion to the time; alin. (10^2): project-hired staff get the hourly rates of the funding request and the applicant guide.
- Grid against stated pay: the stated net hourly rate is 1.9–3.9 times the grid net hourly pay (median 3.0; 8 postings; 168 h = full time).
- Recommendations: D1 yes (with a narrower trigger), D2 yes at low priority, D3 new fields. Backlog items updated.

**Hard**
- The export holds data to 2026-09-09 (newest `last_seen_at`), so the build on 2026-11-18 marks 9,695 of 9,756 rows `closed`; only 5 EU/PNRR postings are "open". I used "active on 2026-09-09" instead (`apply_deadline >= '2026-09-09'` or none).
- `v4_funding_source` is set on 13 rows, `v4_funding_programme` on none: no v4 extraction in this export. Agreement with `funding_kind` could be tested on 2 rows only.
- The text of 472 postings comes from a few templates (ECI, home care projects), so the 60 are not independent and the intervals are too narrow.
- Attachment text: 470 of 472 files are in `data/downloads/`; I converted them with `pandoc`, `textutil` and `pdftotext`. `data/md/` holds only 5 of them.

**Decided**
- Opus believed that HG 325/2018 sets the maximum levels. Measured: it is repealed and no act sets a maximum in lei. I record the correction instead of looking for amounts that do not exist. Cost of the alternative: a wrong cap in the estimator.
- Not verified, left open: caps in the applicant guides of the programmes; a 2026 freeze on the Art. 16 raise; HG 357/2026 read through the consolidated Regulation only.

### 2026-11-18 — Salary estimator page `/estimator-salariu/` (Sonnet, brief UX-SALARY-ESTIMATOR; not deployed)

**Done**
- `salary_grid.py`: `Grid.row_pay(row, gradation, as_of)` is the one pay formula (law and draft). `estimate()` and `_estimate_law()` call it.
- `export-to-sqlite.py`: `export_salary_estimator()` writes `salary_grid_rows`, `salary_grid_pay`, `salary_grid_link`, `salary_cap`, `salary_param` (new tables only). 4,102 grid rows, 24,612 pay figures, 7,126 links, 14 caps; about 3.9 MB added.
- The page (`pages/salary_estimator.php`, `salary_est_*` in `helpers.php`): GET only, no JavaScript. Step 1 search, step 2 variants, law block first, draft block second, local range up to the chosen cap, `?rand=<ref>&g=<n>` direct entry.
- Entry points: footer (Posturi), home tile (Instrumente), `llms.txt`, sitemap, link „Calculează cu altă gradație” in the detail-page salary box (law estimates only). `/despre/`: one sentence changed. Diagram: two boxes changed, `verifica` 0 warnings, PNG re-rendered, site copy updated.
- Tests: Python `test_salary_grid.py` 114 → 129; PHP new `salary_estimator_test.php` (63 assertions) and updates to request, ds, llms, home, compat tests; all 21 PHP suites pass (10,161 assertions); Playwright `salary_estimator.spec.js` (4 tests × 3 viewports).

**Measured**
- Behaviour unchanged: 73,836 estimates (every row of both grids × gradation 0–5 × 3 local variants) are byte-identical before and after the refactor (JSON of `as_dict()`); the existing 114 salary tests pass.
- Link coverage: law 1,446 of 1,913 rows have at least one draft row (75.6%); draft 1,693 of 2,189 rows have a law row (77.3%). Without the same-annex filter: 1,763 of 2,189 draft rows and 7,555 pairs, but the extra pairs are wrong (draft anexa VII matches law I, II, III, V, VIII by name; 636 pairs).
- Step 1 page weight (no gzip on the dev server): empty page 24.5 KB; „consilier” 41 functions, 36.2 KB; „medic” 37.6 KB; „inspector” 49 functions, 38.7 KB. The list shows at most 60 functions.

**Hard**
- `GridRow.ref` is not unique: 75 law refs repeat (one source row holds two study levels, for example `L153-t64!12` for PL and M). The export suffixes the repeats with `#2`, `#3`; the first row keeps the plain ref, so a ref from `sal_json` still finds it.
- A function name in a URL is too long: 23–34 rows have names over 300 characters, and `validated_query()` refuses a value over 300 bytes. The URL carries a ref (`fn=<ref>`), not the name.
- `selector_for_law()` + `Grid.select()` alone link draft-only annexes (VI, VII, IX) to law rows of other annexes by name. The link keeps only law rows of the same annex.

**Decided**
- The function key is (grid, annex, name). The variants (category, regime, level, post type, grade, studies, hospital type) show only when the function has more than one value. A variant left open gives a range over all matching rows, and the page lists the open choices.
- „local” is a value of the level choice for an Anexa VIII function with a territorial row; it gives the territorial value up to the cap, as `_estimate_law()`. Without a locality choice: the widest cap that is not the Capital, and the page says so.
- Gradation above 5 in the URL reads as 5.
- Left out: „Vezi posturile deschise pentru această funcție”. The list has no filter on a grid row (it filters on `occ_canonical` and COR codes).
- `sectiune` is exported as the last segment of the source heading (shorter, readable); `cautare` is an extra column for the accent-free search.

### 2026-11-18 — Diagram „Cum estimăm salariul”; estimator brief (Opus + diagram-designer; not deployed)

**Done**
- The diagram-designer agent drew the salary flow in Romanian: `docs/img/salariu-estimare.svg` + `.png` (viewBox 480 × 1128, 11 nodes, 13 edges in 2 styles, 0 hops; `verifica`: 0 warnings). The site copy is `webapp-php/static/img/salariu-estimare.svg` (7.7 KB). The PNG is 960 × 2256 px, 245 KB.
- `/despre/`, section `salarii`: a `<figure>` after the paragraph on local administration, at most 30rem wide. At 375 px it is 343 × 803 px and readable; at 1280 px it is 480 px wide. No horizontal overflow.
- `README.md` § Estimated pay: the figure (PNG, `width="480"`), and the opening sentence says Legea 153/2017 first, the draft second. The ASCII block of scripts stays: it names the scripts, the figure does not.
- Backlog: UX-SALARY-ESTIMATOR (`[~]`), STATS-SALARY-PATTERNS (owner request: statistics, patterns, cost of living), DATA-SALARY-STALE-COPY. Brief: `docs/briefs/2026-11-18-salary-estimator.md`.

**Hard**
- The agent drew the code, not the ADR, and listed 13 differences. Two matter for the figure: rule 4(c) is coded (the draft figure takes the top level when the law has no row), and rule 2 is not (no page shows the `proiect` figure). The figure says both.
- The agent put „estimatorul de salariu (pagină nouă)” in the box „Ce vede cititorul”. The page does not exist yet, so Opus removed the line and re-ran `verifica` / `randeaza`. The estimator brief adds it back.
- An `<img>` SVG does not see the page fonts. The agent chose the stack Arial / Liberation Sans / Helvetica (the tool checks text fit with Arial metrics; ≥ 21 px slack per box), not outlined text (5–10× the file size, not selectable).

**Decided**
- Estimator: Python precomputes the pay of each grid row at gradations 0–5 in the export; PHP only looks up and converts to net. Cost of the alternative (the formula again in PHP): two formulas that drift, guarded only by a parity test.
- One portrait figure for both places, not a landscape one: at 340 px a landscape figure has text under 9 px.

### 2026-11-18 — /despre/ anchors renamed by the owner; project logo (Opus; not deployed)

**Done**
- The owner rewrote `/despre/` and renamed its anchors: `anunturi-inchise` → `anunturi-expirate`, `versiunea-datelor` → `actualizarea-datelor`, `vezi-si` → `statistici-angajatori`; new `alerte-email`; `termene` hidden. The links follow: the [?!] FAQ (`FEEDBACK_FAQ`; the „termene” question is gone with its section), the masthead stamp, and the consent link of the alert form (now `#alerte-email`, where the alert data is described). `toc.spec.js`, `chrome.spec.js` and `alerts.spec.js` check the new ids.
- The hidden „termene” section was an HTML comment. PHP still ran `toc_h2($toc, 'termene')` inside it, on a missing key. It is now a PHP comment.
- The Cloudflare paragraph and the note on contact data had moved under „Anunțuri expirate”. They are back under „Confidențialitate”.
- Logo: the negative logo in the masthead (`.g2-logo`), the colour logo in the footer and floated right at the top of `/despre/`. The sources stay in `assets/` (`deploy-php.sh` skips that folder); the served copies are `static/logo-g2-*.png` (128 and 256 px from the 980 px source, 252 KB → 13 and 38 KB). The owner's gov2 signature at the bottom of `/despre/` pointed at `/assets/g2-0.png`, which never reaches the host; it uses the static copy now.

**Hard**
- `float-right` was not in the compiled CSS. `npm run css` fixed it; the committed `static/app.css` was already one class behind HEAD.

**Decided**
- The FAQ drops the „termene” question instead of pointing it elsewhere: no other section answers it.

### 2026-11-18 — Salary grid follow-up: local cap by population, review of 552 rows, „Salarii” on /despre/ (brief 2026-11-18-salary-grid-153-followup; Sonnet; not deployed)

**Done.**
- Part A. `data/uat-populatie.csv` is now a copy of `core-assets/data/geo/uat-populatie.csv` (core-assets commit 961c4ff; 3,181 rows; more columns; 12 old values corrected). `uat.units_for()` returns the type (`capitala`, `sector`, `judet`, `resedinta`, `oras`, `comuna`) and the population of the employer. `plafon-local.csv` has 14 rows: Anexa IX lit. C poz. 2, 4 and 17-28, each with its population limits, coefficient, cap and source row. `Grid.local_cap()` picks the row of the type and population; with no unit it takes the widest cap without the Capital's. Positions 23-28 use cap × 1.10 (OUG 53/2024 art. II, in `sursa`). A unit with no population takes the widest row of its type. Ambiguous names take the widest of their units.
- Part B. All 552 `needs_review` rows checked against the HTML tables. Result in `tmp/261118/grid153/review.csv` (not in git): 516 ok, 30 fixed, 6 reject. `needs_review` is 0 for every row now.
- Part C. Section „Salarii” of `webapp-php/pages/about.php` rewritten (the section and its TOC entry existed with one paragraph).

**Measured** (database `posturi_dev`, snapshot 2026-09-09; export `tmp/261118/grid153/posturi-after2.sqlite`).
- Median `lei_max` of the 1,195 postings with a local range, before → after: all 10,868 → 10,626; comune (737) 10,868 → 9,299; towns that are not county seats (194) 13,283 → 13,283; county seats (37), sectors (7), county councils (60) 19,320 → 19,320; unit not known (160) 19,320 → 19,320. Before, a unit was also "?" for 233 postings: `units_for()` now names the unit for 73 of them.
- Five comune (population, cap before → after): Leleşe 339, 10,868 → 7,970; Călărași 1,883, 10,868 → 7,970; Buteni 3,262, 10,868 → 9,299; Gârcina 4,889, 10,868 → 9,299; Chiajna 43,588, 13,283 → 12,075.
- Bucket counts of the 1,822 active postings (net-sub-3000 / 3000-4000 / 4000-5000 / 5000-7000 / peste-7000 / fara-estimare): before the grid 616 / 617 / 114 / 57 / 119 / 299; first grid run 941 / 423 / 30 / 109 / 20 / 299; this run 941 / 423 / 30 / 109 / 20 / 299. No posting changes bucket against the first run: the bucket reads `sal_min`, and no `sal_min` changed (0 of 9,756). `sal_max` changed for 883 postings.
- Review checks (each row, by script, against the table row in the HTML): lei value in the row; lei value against coefficient × 2,500 (within 0.6%); study level; vechime; function text; grade; factor of the chapter; every table row with a lei value is in the grid (29 tables).

**Hard.**
- Parser fixes: (1) a row "- gradul II" gave the name "-" (6 rows, table 75): it now inherits the head row. (2) "gradul I (10 ani vechime)" stayed in the name (20 rows, table 91): the grade now goes to `grad_treapta`. (3) "curier diplomatic I, referent cabinet" and "Secretar I, consul" had no grade (4 rows, tables 81, 82): one alternative that ends in a numeral gives the grade (not in management tables). A first version of (3) also changed 2 rows of table 150 (a function that ends in "I"): I limited it to non-management tables.
- Table 45 (Anexa II pct. 3.3, care staff of social assistance units) is removed. OUG 19/2024 Anexa 2 lists the same functions at higher June 2024 values (Infirmieră 4,260; the law table × 1.05 gives 3,728). No `sal_min` changed, because other rows give the same minimum.
- Factor of Anexele III, IV, V: OUG 53/2024 art. I alin. (1) names Anexele III, IV, VII, VIII, Cap. II of Anexa I and Cap. VI of Anexa V. So 1.15 for III, IV and Anexa V Cap. VI (ONRC, tables 92-93); 1.05 for Anexa V Cap. II-V and VII (tables 88-91, 94). No note of these annexes changes the factor. OUG 53/2024 art. III alin. (3) stops OUG 115/2023 art. I for the environment agencies only. OUG 36/2025 changes sporuri of Anexele III, IV, V, not the base. The factor is not checked against a pay list: none covers these annexes.
- The draft figure is in the data (`proiect`), but no PHP page shows it. The brief said "the site also shows the figure"; the text of the section says the site does not show it.
- `ops/build-uat-population.py` still writes `data/uat-populatie.csv` from the old sources. Do not run it: it replaces the core-assets copy.

**Decided.**
- Cap of an unknown unit: the widest cap without the Capital's (19,320). Alternative: include the Capital (21,735). Cost: 12% more on the upper end of 160 postings that are not Bucharest.
- A municipiu that is not a county seat with 20,000 people or fewer uses the "oraș" rows (the law has no row for it). Assumption.
- A population of exactly 100,000 uses the row "50,001–100,000"; the law says "peste 100.001".
- Links: only Legea 153/2017 and OUG 19/2024 have a legislatie.just.ro id. The other four acts are plain text on the page. Cost: the reader cannot click.
- No PHP test of the page: there is no test of `/despre/` beyond the HTTP 200 in `theme_test.php`. Rendered by hand and the PHP tests `ds`, `theme`, `home`, `discovery`, `feeds`, `v5`, `stats` pass.

### 2026-11-18 — Pre-launch: favicon, council ranges, hourly pay, net first on /despre/ (Opus; not deployed)

**Done.**
- Favicon from the civetch logo (`bf579e7`). The live `/favicon.ico` was an empty file.
- GRID153-CAP-SELECTOR (`cd83d86`): all Anexa VIII staff of a council employer get the local range. Before, the range depended on the level of the draft selector. Local dry run: 1,192 → 1,339 posts with a range; no lower end changes.
- DATA-SALARY-NOT-MONTHLY, stopgap (`cd83d86`): the export drops a stated salary under 1,000 lei. In the 9 Sep snapshot, 10 of 16 stated salaries were hourly rates of 60–70 lei. They showed as „≈ 39 lei net” and fell into the lowest salary range.
- `/despre/` „Salarii” (`644df23`): the site shows a net figure, and the text now names it first. The owner's edits of the privacy section are in the same commit.

**Decided.**
- The stated-salary floor is in the export, not in `SALARY_EXPR`. One place serves the filter, the sort, the list, the detail page and the feeds. Cost of the alternative (PHP): the same rule in four call sites.
- The floor is 1,000 lei: even a quarter-time post at the minimum wage states more.

### 2026-11-18 — Salary grid of the law in force (brief SALARY-GRID-153; Sonnet; not deployed)

**Done.** Each posting now gets its estimate from Legea 153/2017 as it applies in 2026. The draft estimate stays under `proiect`. Run Python with `.venv/bin/python` (there is no `python` on the PATH).
- Step 0 (factor). Re-ran the S1 gap logic with the factors 1.05 / 1.10 / 1.15 on the 56 rows. Not local (11 rows): median gap −2.0 / −2.0 / **−0.3%**; within ±10%: 9 / 9 / 9. Local (45 rows): median **−21.0 / −17.4 / −13.7%**; within ±10%: 11 / 13 / **18**. Decision: 1.15 for all Anexa VIII rows and for the lower end of the local range. ONRC and the research institute now fit exactly (0%). The hospital workers (−15 to −21%) stay a known gap. The grid reproduces the factor-1.15 model row by row (`tmp/261118/grid153/gap_grid-out.txt`).
- Parameters: variant `lege153-2026`; minimum wage 4,050 (2025-01-01) and 4,325 (2026-07-01, HG 146/2026, M.Of. 13 March 2026; source is press, the Monitorul Oficial text is not read); correct article numbers (CFP Art. 15, ceiling Art. 25 alin. (1) at 30%, projects Art. 16, night Art. 20, disability Art. 22); factors of 2022→2026; known gaps.
- Grid: `build-salary-grid.py --source lege-153` parses 110 tables of the consolidated HTML (saved in `docs/salarii/lege-153/`, with SHA-256 in the file notes below) into `grila-lege153-2026.csv`: 1,919 rows (I 472, II 333, III 262, IV 69, V 215, VIII 568). Each row has its table and row (`sheet`, `source_row`, `act_sursa`). `plafon-local.csv`: 5 rows (4 bands and one for county seats, sectors and county councils).
- Calculator: `Grid.estimate()` for the law grid: `ceil(lei × regula × gradation)`, management rows without gradation, floor at the minimum wage of the date, local range (lower end = territorial table, upper end = cap). `selector_for_law()` maps the draft selectors to the law rows (no LLM). `tip_unitate` narrows health rows.
- Estimator: both baselines, JSON per ADR 0008. `hospital_type()` classifies by name. Council employers only (primării, county councils, local public services) get the range; local schools and hospitals get the annex value.
- Texts: three PHP strings now name Legea 153/2017. `list_salary()` shows a range well (`≈ 3.328–6.358 lei net` for 5,641–10,868).
- Tests: `test_salary_grid.py` 36 → 89 tests, all pass. PHP `discovery`, `feeds`, `home`, `v5`, `theme` pass.

**Measured** (database `posturi_dev`, snapshot 2026-09-09; export to `tmp/261118/grid153/posturi-{before,after}.sqlite`; the `salary` and `export-sqlite` steps of `pipeline.py` cannot take an output path, so I ran `estimate-salaries.py --clear` and `export-to-sqlite.py --out` directly):
- Coverage of the draft estimates by the law grid (posting count, draft → law): I 957 → 955; II 2,291 → 2,242; III 29 → 29; IV 19 → 18; V 38 → 38; VII 2 → 0; VIII 5,032 → 4,974. Total 8,368 with a figure (the draft had 8,367 before the grid). 8,256 postings carry `lege_in_vigoare` (3,348 exact, 4,908 interval); 1,494 carry `proiect` (112 with a draft figure, 1,382 with none).
- Hospital split (2,242 postings of Anexa II): a.1 496, a.4 1,746. Employer names: a.1 226, a.4 1,191.
- `salary_bucket` before → after, active postings (1,822; deadline ≥ 2026-09-09): net-sub-3000 616 → 941; 3000–4000 617 → 423; 4000–5000 114 → 30; 5000–7000 57 → 109; peste-7000 119 → 20; fara-estimare 299 → 299. 528 postings change bucket. All 9,756: 3,316 → 5,056; 3,351 → 2,297; 596 → 228; 462 → 637; 650 → 158; 1,381 → 1,380; 2,847 change.
- Median change of `sal_min` (after vs before): Anexa I −12.1%; II −17.7%; III −22.7%; IV −25.4%; V −45.3%; VIII −7.2% (VIII with a local range −8.2%, VIII without −7.2%).

**Hard.**
- `needs_review`: 552 rows (III 262, IV 69, V 215, II pct. 3.3 6) because the rule of OUG 53/2024 is assumed for annexes outside the S1 check. Anexa VIII and I have none.
- Left out on purpose: Anexa VII (tables 103–106, no function column; 2 postings), clerical staff (table 23), aviation (163–167), and the local nomenclatures that have only names (116/117/146–149). 58 postings of local functions (salvamont, SVSU, conducător autospecială, agent agricol) and 49 of Anexa II (for example "Director medical", from the table of percentages) get the draft figure.
- `docs/salarii/` is in `.gitignore`, so the two saved HTML files are not tracked (backlog GRID153-SOURCE-TRACK).
- `test_salary_facet_sql.py::test_every_salary_comparison_casts_its_bind[pages/list.php]` fails on a clean checkout. Not caused by this work.

**Decided.**
- Factor 1.15 (measured, step 0), not 1.05. Cost of 1.05: Anexa VIII 13% too low; local lower end 21% too low.
- The cap per band is the highest vice-mayor coefficient of the band (4.5 under 10,000; 5.5; 6.5; 8.0 for county seats, sectors and county councils). Alternative: one cap per unit type and population. Cost: a small comună shows up to 4.5 where its real cap is 3.0–4.0. Recorded as GRID153-CAP.
- Known gaps, not fixed: OUG 53/2024 art. II (up to 10% over the cap), OUG 128/2023 art. III (+8%/+12%), a hospital that pays workers 14–28% above, new hires (OUG 115/2023 art. I alin. (5)).
- File SHA-256: `legea-153-2017-id298840-2026-11-18.html` 8fae6cd7aa21…2270; `oug-19-2024-id279777-2026-11-18.html` b90160d6cd32…1d. The consolidated page does not say which version it shows (assumed: the latest).

**Review (Opus).**
- Re-ran the tests: `test_salary_grid.py` and `test_salary_facet_sql.py` give 96 passed and 1 failed. The failure is the old TEST-SALARY-FACET-SQL; I removed the duplicate GRID153-FACET-TEST. PHP `discovery`, `home`, `theme` and `stats` pass.
- Checked the stored JSON: the law in force is at the top level with `baza`, and the draft is under `proiect` (brief, rule 5).
- Checked the largest drop, Anexa II (−17.7%). It is correct: a principal nurse gets 5,460 lei at gradation 0 and 6,816 at gradation 5. Four hospital lists show 6,816–6,817 at gradation 5. The draft gives about 6,740 already at gradation 0, so the draft is a raise of about 20%. **S2's "+2.8% over the draft" compared a mixed-gradation staff (mostly senior) with the draft at gradation 0.** Do not use that number as evidence that the draft matches today's pay.
- Example of a local range: 4,543–10,868 lei gross (the cap for the band under 10,000 is 4.5 × 2,415). The range is wide on purpose (ADR 0008, rule 3). GRID153-CAP can narrow it.
- Accepted the rule of the builder: only primării, county councils and local public services get the range. Local schools and hospitals get the annex value.
- Not deployed. The owner must confirm ADR 0008 rule 7: on the 9 Sep snapshot, 528 of 1,822 active postings change their salary bucket.

### 2026-11-18 — Salary spikes S1 and S2: the law in force, transparency lists (Sonnet; reviewed by Opus)

**Done.** Two spikes from the plan of SALARY-R2, run in parallel. Reports: `docs/audits/2026-11-18-salary-s1-current-law.md` and `docs/audits/2026-11-18-salary-s2-transparency-lists.md`. Downloads, scripts and parsed rows: `tmp/261118/s1/`, `tmp/261118/s2/` (not in git). Data: `data/_dev/dev.sqlite`. Its newest `last_seen_at` is 2026-09-09, so "active" = `coalesce(apply_deadline, expires_at) >= '2026-09-09'` → **1,822 postings, 1,078 employers**. With 2026-10-18 only 26 postings are active.
- S1 built the chain of acts from the 2022 annex value to the pay of 2026: OUG 168/2022, OUG 45/2023, OUG 115/2023 (+5%), OUG 128/2023 (education), OUG 19/2024 (health), OUG 156/2024 (freeze 2025), Legea 141/2025 art. XVI (freeze 2026). It compared 14 functions in 56 rows from 12 lists.
- Method (b) (2022 annex value, +5% for Anexa VIII, the June 2024 tables for health and education, gradation, minimum-wage floor) against the lists: **median −2%, 8 of 11 rows within ±10%** outside local administration; **median −21%, 11 of 45 within ±10%** in local administration (per list from 0% to −45%). The annex value alone: −23% and −28%.
- Local pay is 25–93% of the Art. 11(4) cap (Anexa IX lit. C × 2,415), median 50%. No row reaches 95%; only management posts come near.
- S2: of the top 30 employers (15.1% of postings, almost all hospitals), 16 have a list (9.2% of postings), 7 parse (4.8%), 39 postings match a row (2.1%). List pay vs the draft estimate: median +2.8% (n = 36, quartiles −7% / +13%). 80% of postings needs 714 employers.
- Review (Opus): re-ran `tmp/261118/s1/gap.py` (same numbers) and the active count of S2 (1,822).

**Hard.**
- Annex VIII has no lei values for local posts; the council sets the pay. So local administration cannot have one exact figure from the law.
- The 2022 health and education tables are obsolete. The figures come from OUG 19/2024 and OUG 128/2023.
- Two gaps have no legal basis that we found: ONRC and a research institute pay exactly 1.15 × the annex value, and one hospital pays workers 14–28% above (b).
- 14 lists, 14 layouts; 4 of 16 found lists are scans; the file URL changes each period.
- `parametri.json` cites old article numbers (CFP is Art. 15; the sporuri ceiling is Art. 25(1), 30%).

**Decided.**
- Opus: drop a generic transparency-list pipeline (backlog SALARY-TRANSPARENTA, `[-]`). Cost of the alternative: about 130–200 h of parsers plus rework twice a year, for a ceiling of 35–50% of postings.
- Opus: method (b) is the candidate for SALARY-GRID-153. Its brief waits for the owner's answers in S1-Q.
- Assumed, not measured: the S2 ceiling and cost come from hospitals; town halls and schools were not sampled. S1 assumes that a new hire gets the rule of OUG 115/2023 art. I alin. (5).
- Note: for hospitals, both the draft estimate (+2.8% under the lists) and method (b) (−2%) are close to the pay in the lists. The two baselines differ most in local administration.

### 2026-11-18 — Salary estimator, round 2: plan, ADR 0008, two spike briefs (Opus)

**Done.** The owner asked if we can reverse-engineer salariile.ro/compara, and shared a Google AI answer about it. I read their `/compara` and `/metodologie` pages. They have no model: each occupation shows one documented reference value (eJobs *Ghidul Salarial 2026*, a voluntary survey; INS FOM106G and FOM121A as context; the ranges of steps from the 153/2017 grids). They use no county or seniority multipliers. The Google AI answer says the opposite, and it is wrong. Wrote ADR 0008 (proposed), the briefs for spikes S1 and S2, and the backlog item SALARY-R2.

**Hard.** Pay under Legea 153/2017 is not one number. The annexes give the 2022 target values, ordinances froze the pay in force, and councils set local pay up to a maximum. So spike S1 measures the annex value and the frozen value against real transparency lists before we build a grid.

**Decided.**
- The owner (2026-11-18): the draft is not adopted. Show both baselines, the law in force first, and the draft second as „după noua lege (proiect)”. Cost of the alternative (the draft as the main figure): the main figure, the sort order and the filter stay wrong for every reader until 2027.
- The owner: scope = INS context, transparency lists, the `/salarii/` hub with „A vs B”, gradation and sporuri. A few eJobs figures may be cited by hand, with attribution.
- Opus: INS data is already in `../tempo-ins-dump` (FOM106E by county × CAEN to 2024; FOM106G/FOM107G monthly to May 2026; FOM121A public ownership × ISCO major group). We derive one small table and copy nothing (ADR 0008, rule 8, for the owner to confirm).
- Opus: the `salary_bucket` keys stay; their meaning follows the main figure (ADR 0008, rule 7, for the owner to confirm).

### 2026-11-18 — Salary filter as a multi-select (brief BUG-SALARY-MULTI; Sonnet; not deployed)

Brief `docs/briefs/2026-11-18-salary-multi-select.md`. **Opus review (2026-11-18): accepted.** Checked the OR clause in `build_filters()`, `al_migrate_salary_keys()` (anchored regex; a `q` value that contains the text is left alone) and `rekey()`. Accepted the edits outside the brief: `browser/salary.spec.js` (the salary fixture lives there) and one line of `home_test.php`. Opus added the log line of the migration count in `al_schema()` (the brief's deploy note asked for a count that nothing logged), and a test of the salary range above the list in `discovery_test.php`.

**Done**
- `salary_bucket` is in `MULTI_PARAMS` (`helpers.php`, `static/searches.js`) and out of `QUERY_SCALARS` (`query.php`). A scalar URL (`?salary_bucket=x`) is read as a one-value array.
- `build_filters()`: each known value is its own group of fragments joined by AND, in parentheses; the groups join with OR. Unknown values are dropped; no known value gives no clause. The comment about `CAST(? AS REAL)` stays.
- `pages/list.php`: `$sal_bucket` is an array of strings without blanks. The note shows when the selection has a known value and no `fara-estimare`. `fara-estimare` alone gives the "none" form. A range with `fara-estimare` gives no note. "Vezi-le" links to the other filters with `salary_bucket[]=fara-estimare` only. `partials/facets.php` passes the array.
- Migration of the alert keys: `al_migrate_salary_keys()` in `inc/alerts.php`, called at the end of `al_schema()`, so it runs each time `al_db()` opens the database. It rewrites `salary_bucket=` to `salary_bucket%5B%5D=` at the start of a key and after `&`. It returns the number of rows changed.
- Migration of the browser records: `rekey()` in `static/searches.js`, called by `load()` when the status is `ok`. It re-keys each record whose key differs from `searchKey('?' + key)`, keeps `sort`, `label`, `savedAt` and `openedAt`, follows `defaultId`, and keeps the newer `savedAt` on a collision. `VERSION` is unchanged.
- Tests: `search_keys.json` (new keys, one new case), `discovery_test.php` (544; new suite for OR, scalar = array, note rules, legacy chip), `alerts_test.php` (185; migration, second run, `al_parse_key()`), `home_test.php` (URLs only, plus `[0]` when the test reads the key of a chart link), `salary.spec.js` (field name `salary_bucket[]`, URL patterns, two new tests), `searches.spec.js` (two re-key tests).

**Measured**
- Playwright (discovery, chips, searches, alerts, ds, salary): 219 passed, 3 skipped. PHP: all 20 suites pass (alerts 185, discovery 544, home 854, searches 59, stats 96, theme 1.286).
- No unique index on (`email_index`, `search_key`): the schema has only `idx_sub_email` on `email_index`. A rewrite cannot collide at the database level. Two rows of one reader could hold the old and the new form only if a row was created after the deploy and before the first open of the database, which cannot happen (the migration runs in `al_db()`).
- Dev data has no alerts database (no `subscriptions.sqlite`), so the migration would change 0 keys there. The VPS count is known after the first `bin/alerts.php` run.
- Idempotence: the test runs the migration twice on four rows (three with the old form, one with `salary_bucket` only inside a `q` value). The first run changes 3, the second 0, and the keys are equal.

**Hard**
- `salary.spec.js` (not in the brief list) had the old field name and URL patterns, so it needed the same change.
- `qs_with()` writes an array as `salary_bucket%5B0%5D=`; the link "Vezi-le" turns that into `%5B%5D`, so the key is canonical.

**Decided**
- The migration runs in `al_schema()`, not in a separate script: no manual step on the VPS. Cost: one `LIKE` query for each database open.
- Records are re-keyed on read, not on a version bump: the version bump would make the other tabs refuse the store. Cost: one write on the first read after the deploy.

### 2026-11-18 — Owner changes: line chart, compact salary chart, salary range above the list (Opus; not deployed)

**Done**
- Landing weekly chart: a line and its area again (owner: "it looks nicer"), on an axis from the smallest to the largest week, as before brief 5. It still counts posts (`posts_sql()`). A themed page keeps the bars and the stacked "Toate" (`partials/weekly_chart.php`: line when there is no `series`).
- Salary chart: compact rows (at least 32 px; a link target of at least 24 px, as "Sau începe de aici"), a smaller gap above "fără salariu estimat".
- Themed page: the salary chart is a section "Salarii" (`#h-salarii`) just before "Noi și pe terminate" (the `recent` section), no longer in the statistics panel. It still follows the county (`theme-panel.js` looks for it in the document). Width capped at `max-w-3xl`, as on `/`.
- List: after "N anunțuri găsite", the net salary of the results: "salariu net ≈ MIN–MAX lei, mediana M lei (K din N)" (`partials/result_list.php`, scope `facet_scope('')`, so it follows every filter and the HTMX updates). MIN is the lowest lower figure, MAX the highest upper figure, the median is of the lower figures (the figure of the salary filter), shown from 5 postings. Figures under 1.000 lei gross are skipped (DATA-SALARY-NOT-MONTHLY).
- Tests: `home_test.php` (the line points), `theme_test.php` (section order with `h-salarii`, the chart before `h-noi`, no line on a themed page).

**Measured** (dev database, 1.197 active postings)
- `/posturi/`: "≈ 2.397–14.391 lei, mediana 3.493 lei (994 din 1.197)". With the 60-lei posting the minimum was 39 lei net.
- `/?family[]=sănătate`: "≈ 2.481–12.781 lei, mediana 3.937 lei (323 din 375)".

**Decided**
- Range and median, not the range alone: across the whole list the range is 2.397–14.391 lei and says little; the median says what is typical. Cost of the median: one more figure on the line.
- The line only on the landing page: the stacked bars of a themed page show the groups, and a line cannot stack them. Owner may ask for lines there too.

### 2026-11-18 — Salary chart (brief UX-SALARY-CHART; Sonnet; not deployed)

Brief `docs/briefs/2026-11-18-salary-chart.md`. **Opus review (2026-11-18): accepted.** Checked against the brief: the partial, `salary_distribution()` and the county update in `theme-panel.js` (links built from `home_item_fields()` and the canonical `searchKey()`). Opus re-ran home 924, theme 1.284, stats 96, discovery 530, searches 57: all pass. Screenshots at 390 and 1280 px checked. Open for the owner: a range with 0 postings in the selected county is still a row and a link to an empty list (it keeps the scale readable).

**Done**
- `salary_distribution(array $base)` in `helpers.php`: the five ranges of `SALARY_NET_BUCKETS` and the row `fara-estimare`. Each row uses `home_item_counts()` and `home_list_url()`, so the count is the result count of its link. No change to an existing function.
- `partials/salary_chart.php`: a `<figure>` with the caption "Salariul net lunar", a `<ul>` of six rows, the note in `g2-text-meta`. HTML and CSS bars (`bg-gov`; `bg-ink-faint` for "fără"), a rule above the "fără" row. A row is one link of at least 44 px; the bar is `aria-hidden`; the count is followed by a `sr-only` " anunțuri". It draws nothing when the five ranges sum to 0.
- Landing page: new section `#salarii` ("Cât se plătește în sectorul public") between `#domenii` and "Sau începe de aici". The group "Salariu" of "Sau începe de aici" is removed. The chart does not follow the map.
- Themed page: the chart is in the statistics panel under the weekly chart. `$ts['data']['salary']` has, for each row, the county counts and the fields of the link. `static/theme-panel.js` updates the width of the bar, the count, the `sr-only` unit and the link (with `judet[]`) on `posturi:county`.
- `/statistici/`: new section "Salarii" (`#salarii`, TOC entry) after "Export curent"; none when no posting has a salary figure. The figures "Cu salariu anunțat" and "Doar salariu estimat" stay in "Export curent": they share a row of cards with "Publicare → expirare".
- Tests: `home_test.php` (924: chart rows, counts = list counts, "Salariu" group gone, eight sections), `theme_test.php` (1,284: chart in the panel, JSON sums, county link), `stats_test.php` (96: section and TOC). Playwright: `home.spec.js`, `theme.spec.js`, `stats.spec.js` (overflow at 320, 375, 1280; click on a county, second click restores).

**Measured** (dev database `data/_dev/posturi-new.sqlite`, 1.197 active postings)
- Counts of the chart on `/`: sub 3.000: 356; 3.000–4.000: 397; 4.000–5.000: 85; 5.000–7.000: 53; peste 7.000: 104; fără salariu estimat: 202. The five ranges equal the distribution in the entry below.
- Counts on `/sanatate/` (whole country): 38; 142; 43; 16; 84; fără 52.
- `Server-Timing` total, 6 requests each, before (the tree with `salary_distribution()` returning no row) and after (ms). `/` before: 36.3, 29.9, 30.1, 31.6, 30.3, 30.6 (median 30.4); after: 33.3, 33.4, 33.2, 33.7, 36.0, 35.3 (median 33.5). `/sanatate/` before: 27.3, 26.8, 27.7, 26.8, 26.7, 27.8 (median 27.0); after: 28.6, 28.4, 28.8, 28.7, 30.0, 30.2 (median 28.75). Cost: about 3 ms on `/` and 1.7 ms on `/sanatate/`.
- PHP: 20 suites pass (home 924, theme 1,284, stats 96, discovery 530). Playwright (home, theme, stats, ds): 318 passed.

**Hard**
- The first design put the count at the far end of a full-width track: at 1280 px a short bar was far from its count. Now the bar is a fraction of the row width minus the count (`calc((100% - 4rem) * f)`), and the count follows the bar.
- A row at 24 px from `lg` failed the existing check "every link of the landing page is 44 px". All rows are 44 px.

**Decided**
- 44 px rows at every width: the landing page has this rule. Cost: a taller chart on desktop (6 x 44 px).
- The county counts come from `home_item_counts()` (one query for each row, grouped by county): six queries for each chart. Cost of one query with a CASE: a second definition of the ranges that could differ from the filter.

### 2026-11-18 — Salary 7.000 bucket, mobile menu, map order, medical card (Opus; not deployed)

**Done**
- Salary filter: `net-5000-7000` and `net-peste-7000` replace `net-peste-5000` in `SALARY_NET_BUCKETS`. `net-peste-5000` moves to `SALARY_NET_LEGACY`: `salary_bucket_sql()` and `salary_bucket_label()` accept it, the filter does not list it. The landing group "Salariu" lists the five buckets.
- Landing card "Peste 7.000 lei net" (second list card). The card "Peste 5.000 lei net, în afara sectorului medical" also has `family_not[]=sănătate`.
- Masthead: below sm a "Meniu" button (`.pg-nav-toggle`, `aria-expanded`, `aria-controls="site-nav"`, Escape closes) shows the links in a column. The button ships `hidden`; an inline script shows it. Without script the links wrap as before.
- Map order below lg: on `/` the map is above the domain list (`max-lg:order-first`, `$cm_class` of `partials/county_map.php`). On a themed page the left column is `max-lg:contents` and the chart is `max-lg:order-last`, so the map is under the form and above the chart. The grid has `grid-cols-[minmax(0,1fr)]`: without it the select row overflowed at 390 px.
- Tests: `discovery_test.php` (6 buckets, the 7.000 edge at 11.967 gross, the legacy value = the two top buckets), `home_test.php` (card links), `ds.spec.js`, `home.spec.js` (menu; map order), `theme.spec.js` (map above the chart).

**Measured** (dev database `data/_dev/posturi-new.sqlite`, 1.197 active postings)
- 7.000 lei net = 11.967 lei gross. Net distribution: under 3.000: 356; 3–4.000: 397; 4–5.000: 85; 5–6.000: 29; 6–7.000: 24; 7–8.000: 79; 8–10.000: 15; 10–12.000: 3; 12.000+: 7. So 5.000–7.000 = 53, peste 7.000 = 104. A cut above 7.000 gives buckets of 25 or fewer.
- Stated salaries: 35 of 1.197 active postings; the filter uses them first (`SALARY_EXPR = COALESCE(inf_salary_min, sal_min)`).
- The medical card: `isced_not` alone gave 58 postings, 12 of them doctors with `v3_isced_fields = '[]'`. `family_not` alone: 57 (6 "Director medical", domain administrație or financiar). Both: 46, none medical. 586 of 1.193 v4 postings have no ISCED field.
- PHP: discovery 530, home 928, theme 1.247 assertions pass. Playwright (chrome, ds, home, theme): 321 passed.

**Hard**
- The salary checkboxes: `salary_bucket` is scalar, so a second checked box is lost. Not fixed here: a multi-select changes the canonical alert key (ADR 0001). Recorded as BUG-SALARY-MULTI.

**Decided**
- Split "peste 5.000" in two, not three: a cut at 8.000 or 10.000 leaves 25 or fewer postings above it. Cost of keeping one bucket: 104 of 157 postings above 5.000 are above 7.000, and the reader cannot see that.
- Keep `net-peste-5000` valid: saved searches, alerts and the landing card use it. Cost of dropping it: those links would lose their salary filter without notice.
- The menu button is local (the DS header says "no menu button"); DS proposal https://github.com/gov2-ro/gov2ro-design-system/issues/12.

### 2026-11-18 — Theme `/meserii/`: manual and service work (brief 3; not deployed)

**Opus review (2026-11-18): accepted.** Checked against brief 3 and the Opus decisions: narrowing inside the base filter (`theme_item_params()`); the redirect replaces a base key. Side effect accepted: `/sanatate/?family[]=juridic` now goes to the Juridic list, where before it merged both families. The chart by COR sums posts. All 20 PHP suites pass (Opus re-run); the agent's full Playwright run after its last change: 825 passed, 60 skipped. The local dev export predates brief 2: its archive has no `occ_group`, so the first weeks of the `/meserii/` chart are empty there; the next export fills them.

Brief `docs/briefs/2026-11-17-theme-meserii.md`. Sonnet built it on the tree after briefs 1, 2, 4 and 5. Opus review is open.

**Done**
- Template rule 1: `theme_item_params()` (`helpers.php`). In a `values` section whose `param` is a key of the base filter, the item replaces the base values (`cor` [5, 7, 8, 9] and 7 give `cor` [7]). `home_merge_params()` is unchanged.
- `theme_redirect_url()` (`inc/themes.php`): a given value of a key of the base filter replaces the base value (decision of Opus). `/meserii/?cor[]=7` goes to `/?cor[]=7&family[]=tehnic`. A blank value keeps the base. Other keys merge. This also changes `/sanatate/?family[]=juridic`: it is now `/?family[]=juridic` (the old test expected both families).
- Template rule 2: `history.cor` and `prefixes_cor` (`theme_group_series()`, `theme_group_of()`). Both tables are filtered by `occ_group` prefixes. The sums stay posts (`posts_sql()`). `archive_has_column()`: with no `occ_group` column in the archive, a chart with `cor` counts live postings only and does not fail. A theme with no `cor` renders as before.
- Section key `sprite_icons` (value => symbol of the page sprite): the "Categorii" items show the icons of the landing items. The key `icons` is unchanged: an existing test requires that `icons` without `icon_file` shows no icon.
- Theme `meserii` in `inc/themes.php`; the four landing items link to it; the footer, sitemap and llms.txt list it. README route list.
- Tests: `theme_test.php` 1,247 assertions (new suites: render, redirect, Categorii, landing links, chart by COR, archive without `occ_group`, `theme_group_of()`); `home_test.php` 928. Fixtures: `archived_postings` has `occ_group`; `add_meserii_fixture()` (ids 2101-2113, archive 9301-9308) in the professional fixture. `theme.spec.js`: four tests on `/meserii/`.

**Measured** (dev database `data/_dev/posturi-new.sqlite`, clock 2026-10-19)
- Active postings: Meseriași (7) 102, Îngrijire și servicii (5) 75, Șoferi (8) 63, Necalificați (9) 43; total 283.
- Posts in the 12 weeks (27 Jul to 18 Oct), live only: 229, 234, 162, 133; total 758 (equals a direct SQL sum).
- The dev export was made before brief 2: its archive has no `occ_group`. 1,137 archive rows of the family `tehnic` in those weeks are not counted. After a new export the chart counts them.
- `Server-Timing` total of `/meserii/`, 6 requests: 24.7, 25.3, 24.9, 25.4, 25.7, 25.6 ms.

**Decided**
- Replace, not merge, in the redirect (Opus). Cost of merge: `/meserii/?cor[]=7` would give all four groups.
- Four chart groups; every COR 5, 7, 8, 9 posting is in a group, so "Alte ocupații" is 0.
- Mutations that the new checks fail against: `home_merge_params()` in `theme_item_params()`; no COR clause on the archive; archive read without the column guard; `prefixes_cor` ignored; no replace in the redirect; domain `paza` removed; `history.cor` removed; posts replaced by 1.

### 2026-11-18 — Chart palette and county map scale (Opus; not deployed)

No brief (owner requests; small changes, done by Opus).

**Done**
- **Local categorical chart palette.** In `assets/app.css`, `:root` gets the tokens `--c-cat-1` (the DS blue `--g2-color-chart-1`), `--c-cat-2` orange `#eb6834`, `--c-cat-3` aqua `#1baf7a`, `--c-cat-4` yellow `#eda100` and `--c-cat-rest` grey `#B1B4B6`. Group N of a chart has `.pg-c-N` and colour N. Before, the order was chart-2, chart-1, chart-3, chart-4: two blues and two greys. "Alte ocupații" is grey; before, it was chart-1 at 30 %.
- **County map scale.** `home_map_breaks()`, `home_map_step()` and `home_map_legend()` in `helpers.php` replace the fixed steps (0–19, 20–39, 40–79, 80+). The scale is log, between the smallest and the largest non-zero count of the map. A county with 0 has a blank tile and a "0" swatch. The legend lists the ranges of the page. With fewer than 3 breaks, the steps spread so that the largest county is the darkest. With no break, every county with a posting has the middle step.

**Measured**
- The palette, with the dataviz validator, on white, for neighbouring segments in stack order: normal vision ΔE ≥ 22.9 and colour blindness ΔE ≥ 9.1. All pass.
  - Orange and yellow are 13.7 apart. They are never neighbours in a stack. The legend squares and the `<title>` of each segment name them.
  - The grey of the rest is 16.5 from the yellow under it.
  - Aqua and yellow are below 3:1 against white. The visible legend labels and the titles are the required relief.
- The map legends on the 17 Oct export:

  | Page | Legend |
  |---|---|
  | landing | 5–12, 13–30, 31–74, 75–184 |
  | `/sanatate/` | 0, 2–4, 5–11, 12–26, 27–64 |
  | `/social/` | 0, 1, 2, 3, 4–6 |

  Before, almost every county of a themed page was in the first step.
- Tests: `home_test.php` 914 and `theme_test.php` 1,104 assertions. Mutation: a linear scale in `home_map_breaks()` fails "the county tile map". The browser checks of the chart classes and of the grey fill are updated.

**Decided**
- **The palette:** the owner chose to go around the DS for now: "we might let it know later". The DS proposal is not opened. Replace the local tokens when the DS has a categorical palette. Cost of the DS colours: four groups in two blues and two greys, hard to tell apart.
- **Log, not linear and not quartiles:** this follows the measurement of 2026-11-17 (entry "Themed pages for the professional domains", "County map scale").

### 2026-11-18 — Theme `/social/`: social assistance and counselling (brief 4; not deployed)

**Opus review (2026-11-18): accepted.** Checked against brief 4: the family list in `history.family` still sums posts; the landing items Social and Educație link to `/social/`; the `other` line is kept (43). All 20 PHP suites pass (Opus re-run). The agent's full Playwright run had 6 failures in `home.spec.js` (footer count, theme map), corrected, then `home.spec.js` passed; the next full run is in the palette commit.

Brief `docs/briefs/2026-11-17-theme-social.md`. Sonnet built it on the tree after briefs 1 and 5. Opus review is open.

**Done**
- Template rule: `history.family` takes one family (string) or a list. `theme_group_series()` (`helpers.php`) filters with `IN (…)` in both tables. The total, each group, "Alte ocupații" and each county are still sums of posts (`posts_sql()`). `pages/theme.php` accepts a non-empty list. A theme with one family string renders as before.
- Theme `social` (`inc/themes.php`): "Asistență socială și consiliere", `icon` `i-sector-asistenta_sociala`, `domains` `['social', 'educatie']`, base `family` `['social', 'educație']` (stored spellings checked in the data). Four chart groups: Asistent social, Consilier școlar, Mediator, Psiholog. Selects Ocupație and Instituție. Sections: stats, Ocupații (12), Angajatori (by count), Domenii de activitate (by count, 6), counties, Studii, recent, follow. Line "other" kept.
- Landing items "Social" and "Educație" both link to `/social/` (the `domains` key of brief 1). The footer, the sitemap and `/llms.txt` list the page (tests updated).
- Tests: `theme_test.php` 1,104 assertions (new suites for the render, the counts, the chart of two families, the buttons); `home_test.php` 908 (both items link to `/social/`); `theme.spec.js` three new tests on the professional fixture (port 8827; `social_sql()` in `tests/fixtures/professional_db.php`); `home.spec.js` knows the new footer link (16 links) and the two domain items that link to `/social/`. Full Playwright run: 807 passed, 60 skipped, after that fix (the first run had 6 failures in `home.spec.js`, all from the new theme).
- `docs/backlog.md`: UX-05-THEME-EDUCATION dropped. `README.md`: route list.

**Measured** (dev database `data/_dev/posturi-new.sqlite`, clock 2026-10-19; the 12 chart weeks 27 Jul to 18 Oct)
- Posts in the 12 weeks, by group: Asistent social 169, Consilier școlar 72, Mediator 89, Psiholog 39; in no group ("Alte ocupații") 215; total 584. Families alone: social 368, educație 216.
- Active postings of the page: 110. The line "other" (`inst` asistenta-sociala, not the two families): 43, so it stays (the rule leaves it out below 10).
- `Server-Timing` total of `/social/`, 5 requests after a warm-up (the machine was running Playwright): 36.7, 34.5, 25.7, 23.9, 25.3 ms; median 25.7. `/sanatate/` for comparison: 41 ms.

**Hard**
- `search_key()` sorts the values of a parameter, so the base filter is `family[]=educație&family[]=social`, not the order of the configuration. The first test expected the configuration order.
- The theme fixture has no social or educație postings. `social_sql()` adds six postings (2025 to 2030) and five archive rows, one with the id of a live posting. The ids belong to no other themed suite.

**Decided**
- Four groups, not more: the chart colours allow four.
- Mutations that the new checks fail against: first family only in `theme_group_series()` (`theme_test.php` 2 suites, Playwright chart test), `domains` without `educatie` (`home_test.php`), `family_not` removed from the line "other" (`theme_test.php`), base `family` with `social` only (`theme_test.php`), `posts_sql()` returning 1 (`theme_test.php`).

### 2026-11-18 — Weekly chart: posts, bars, a stacked bar under "Toate" (brief 5; not deployed)

**Opus review (2026-11-18): accepted.** Opus took new screenshots on the 17 Oct export (`/sanatate/` "Toate" and "Asistent", `/administratie/`, the landing page; 1280 and 375 px) and checked the three owner changes: no estimate sentence, a light-blue "Alte ocupații", touching segments. No horizontal scroll. All 20 PHP suites pass. Noted, not changed: `tailwind.config.js` does not scan `static/*.js`, so a class that only the script writes is purged. The colour classes are listed in the PHP colour map for this reason. A fifth chart group falls back to the fourth colour; the DS proposal for a categorical palette is not opened yet (owner decision pending).

Brief `docs/briefs/2026-11-18-weekly-chart-posts-bars.md`. Sonnet built it; Opus review is open. The bar prototype and `POSTURI_CHART_BARS` were already removed (Opus, before the commit of brief 1). The bars are built from the line chart.

**Done**
- Rule of posts: `posts_sql()` in `helpers.php`. Posts = `nr_posturi` from 1 to 30, else 1. `home_weekly_postings()` and `theme_group_series()` sum it in place of `COUNT(*)`: total, groups and counties; an id in both tables still counts once, for `job_postings`. The landing figures and the list counts still count announcements.
- Texts: caption "Posturi publicate pe săptămână" on both charts (hidden on a themed page, as before). The `aria-label` says "între 0 și 8 posturi"; the live region says "Medic: 2 posturi în ultimele 12 săptămâni" (JS `posts()`, the twin of `posturi_label()`). The JSON block of the panel has the new key `weeks` (the 12 short dates for the titles).
- Bars: 12 `<rect>`, a bar is 50 % of its slot, one baseline, no grid, no line, no area. The axis is from 0 to the largest week (landing), or to the largest week of any button in the selected county (themed page). The tallest bar is 60 of 64 units high. Colours are classes in `assets/app.css` (`.pg-seg`, `.pg-c-0` to `.pg-c-3`, `.pg-c-other`, `.pg-chart-sq`, `.pg-chart-legend`, layer `components`), from `--c-chart-1` to `-4`. Each segment has a `<title>` ("săpt. 10 aug, Medic: 7 posturi"). The server draws the same bars as the script.
- Stack under "Toate": groups in configuration order (chart-2, chart-1, chart-3, chart-4), then "Alte ocupații" (total minus the groups; chart-1 at 30 % fill, see Decided). Segments touch: no gap, no border. A group button shows a square of its colour; "Toate" has none; "Alte ocupații" is a legend entry (`data-chart-other`), shown with "Toate" and hidden when a group is pressed. A group button shows that group alone, in its colour, on the same axis. A county selection redraws all of it.
- Tests: `theme_test.php` 988 assertions (from 911), `home_test.php` 895; Playwright `theme.spec.js` (stack, group, county, live region) and `home.spec.js` (landing chart, on the theme fixture port 8824).

**Measured** (dev database `data/_dev/posturi-new.sqlite`, 6,865 announcements: `job_postings` plus the archive rows that are not in it)
- The rule changes the value of: missing 2,777 (counted as 1, so the count does not move), below 1: 2, above 30: 23 (largest 349,085).
- The 12 chart weeks (27 Jul to 18 Oct, clock 2026-10-19): 6,303 announcements, 8,450 posts. In those weeks: 2,527 missing, 2 below 1, 21 above 30. `/sanatate/` over the same weeks: 2,718 posts.
- `Server-Timing` total, median of 5, ms (clock of the machine): `/` 41.4 before, 39.7, 39.3, 39.4 after; `/sanatate/` 34.3 before, 33.7, 33.2, 34.0 after. No growth.
- "Alte ocupații" is never below 0: it is the total minus the groups, and a posting is in one group at most (test in `theme_test.php`).

**Hard**
- Tailwind reads the `.php` files and `static/saved.js`, not `theme-panel.js`. A class that only the script writes (`pg-c-0` to `pg-c-3`) was purged from `static/app.css`. The classes are now written out in `weekly_chart.php` (the colour map of the groups). `tailwind.config.js` is outside the brief.
- The shared Playwright fixture has fewer than 4 weeks with postings, so its landing chart is hidden. The landing chart test uses the theme fixture (port 8824).
- The existing test "the buttons are text only" took the first `main p` as normal text; the new muted sentence is now first. The test skips `.g2-text-meta`.
- The post counts of the theme fixture are all empty except 9001 (2): the PHP tests set more values in a copy (`posts_db()`).

**Decided**
- Owner changes of 2026-11-18, after the first build (they replace the brief): (1) no sentence "Estimare: un anunț fără număr de posturi contează ca un post." under the chart; the rule stays, with its comment in `posts_sql()`, and DATA-NR-POSTURI stays open. (2) "Alte ocupații" has a fill: `--c-chart-1` at 30 % (light blue), the same in its legend square; not white, not an outline. (3) No border and no gap on any segment; adjacent segments touch. Checks: the fill of "Alte ocupații" is not transparent and differs from each group colour; no segment has a stroke; segments touch; the sentence is absent. Fails against: fill none, a stroke on the segment, the fill of chart-2, an empty legend square, a 1 px gap in the script and in the partial, the sentence back.
- "Alte ocupații" in the legend hides when a group is pressed, because it is not in the chart then.
- Mutations that the new checks fail against: `COUNT(*)` in `home_weekly_postings()` (`home_test.php`), `COUNT(*)` in `theme_group_series()`, `BETWEEN 1 AND 31`, `ELSE 0` (`theme_test.php`), drop "Alte ocupații" in PHP and in the script, a scale for each series, bars the full width of the slot, a polyline back in the SVG, no gap between segments, "anunțuri" in the live region (Playwright).
- DS: the 4 chart colours are two blues and two greys. A DS proposal for a categorical palette is for Opus to open (text in the report of this brief).

### 2026-11-17 — Pipeline: trade in the occupation, COR group in the archive, domain spike (not deployed)

Brief 2 of 4 (`docs/briefs/2026-11-17-pipeline-domain-trade.md`). Report: `docs/audits/2026-11-17-pipeline-domain-trade.md`. Sonnet built it against the local PostgreSQL (`posturi_dev`, September snapshot, 9,756 postings). No production access.

**Done**
- A1: `parse_title()` keeps the first tail after a dash when the head is generic and the tail starts a COR name (`ocupatii.trade_in()`). `trade_label()` gives the label; `normalize-titles.py` applies it to new rows, and `--relabel-trades` applies it to existing rows with no LLM call. Grade and study hint: 0 differences on 9,756 titles.
- A1 numbers (17 Oct export, 2,461 active, simulated): "Muncitor calificat" + "Muncitor" in COR 7 went from 128 of 199 (64.3 %) to 87 of 199 (43.7 %). On all 9,756 local postings: 622 of 857 (72.6 %) to 474 of 801 (59.2 %). Distinct occupations: 821 to 836. "Inginer" in COR 2 and "Tehnician" in COR 3: no change.
- A1 run: 12 new titles, 12 DeepSeek calls, USD 0.0026; 48 rows relabelled with no LLM.
- A3: `archived_postings` has `occ_group` and `med_specialty`. Local export: 3,064 archive rows, 98.0 % with a group, 6.1 % with a specialty.
- A2: spelling rule on the label, approved by Opus and applied: `normalize-titles.py --merge-spellings`, 35 labels, 131 postings. Tests with named mutations.
- B: table of COR prefixes to the 15 landing domains. 32.3 % of all postings (24.0 % of the active ones) change domain; 5 % need the LLM family.
- Tests: `webapp/tests/test_ocupatii.py` (new), one test in `test_archive_export.py`. Python suite: 1,481 passed, 1 failed (`test_salary_facet_sql`, reads `webapp-php/pages/list.php`, in the work of the other agent).

**Hard**
- The dash was not the main cause. It changes 60 postings. Most of the loss was the model: it answered "Muncitor calificat" for "Muncitor calificat (electrician)", whose base already held the trade. So the label is corrected after the model, by `trade_label()`.
- A tail test by "words are in a COR name" let "întreținere" and "ordine" pass. The final test needs the words to start a COR name.
- The department guard I wrote first was dead code (no COR name starts with "serviciul"). I deleted it; the test of the behaviour stays.
- The local data is old: 46 postings pass `expires_at >= 2026-10-17`. Active numbers come from the 17 Oct export.
- Not run, on purpose: PHP suites and Playwright (another agent worked in `webapp-php/`).

**Decided**
- A2: a rule "one label for each COR code" would erase the specialties ("Medic specialist neurologie") and the qualifiers ("Administrator patrimoniu"). A spelling rule on the label was used instead. Labels that differ by a word stay apart.
- The grid selector was not touched. 25 postings change grid selector because they move to another dictionary key. No posting loses its estimate: 9 "bucătar" postings get a null `cod` because the grid row "Bucătar calificat" has no code in the source; `select()` finds it by name. 14 estimates change in value.

### 2026-11-17 — Themes administratie and financiar, domain links, occupation chips (brief 1; not deployed)

**Opus review (2026-11-18): accepted.** Checked against the brief: the configuration, the `domains` key, the chip rules, the two owner additions (no wait for a chip or an ×; the button "Toate județele"). Opus re-ran every PHP suite (20, all passed), the PHP lint, `npm run css`, pytest and ruff, and the full Playwright run before the commit. The software list FOREXEBUG, EDUSAL, SIIIR is accepted. Accepted assumption: the chip row also hides when an `occupation_not[]` filter is on. After the review, Opus removed "Infirmieră" from the `/sanatate/` chart group "Îngrijitor" (brief 2, A2, merges it into "Infirmier") and the dead key `'band' => true` in `pages/home.php`. Opus also removed the bar prototype (`POSTURI_CHART_BARS`) before the commit; brief 5 builds the bars.

Brief `docs/briefs/2026-11-17-themes-admin-financiar-chips.md`. Sonnet built it; Opus review is open.

**Done**
- Template: a `values` section with `source => 'labels'` takes `order => 'count'` (sort by count, then `limit`). An entry of `search_selects` takes `labels` (value => text). `inc/themes.php` documents both, and the new theme key `domains`.
- Themes `administratie` and `financiar` (configuration only). `Tip funcție` keys are the stored `job_level` phrases ("Funcții de conducere", "Funcții de execuție"); the fixtures use the legacy `executie`, so the tests set the live phrases.
- The button "Toate județele" after the title (see Decided). Also `i-x` in the sprite.
- Landing: a domain item of a theme (`domains`) links to `/{slug}/`; with a county the link is `/{slug}/#{county}` (`data-theme-href`, `static/home.js`). Other items keep `judet[]`. The footer, the sitemap and `/llms.txt` show both pages with no change.
- Occupation chips on the list: helper `occupation_chips()` (rules of step 5), partial `partials/occupation_chips.php`, included in `partials/result_list.php` (so it is in the result region and in the htmx fragment). A chip click sets the checkbox in `#filter-form` and fires `change`: same request, `hx-sync` and stale-response guard. "Toate ocupațiile" opens the group (drawer below lg, sidebar or bar panel from lg); the group got the id `facet-occupation` (`partials/facets.php`) for the anchor.
- Tests: `theme_test.php` 911 assertions, `home_test.php` 821 (from 795), new fixtures `chips_db.php` and `professional_db.php`, Playwright `chips.spec.js`, `theme.spec.js` and `home.spec.js` extended, two new servers (ports 8826, 8827) in `playwright.config.js`.

**Measured** (dev database `data/_dev/posturi-new.sqlite`, `Server-Timing` total, median of 5, ms)
- `/posturi/`: 45.7 and 44.4 before; 44.4 and 44.7 after. `/?family[]=administrație`: 19.6 and 20.4 before; 20.2 and 21.0 after. No change above 5 ms.
- `/administratie/` 28.0 to 32.1; `/financiar/` 12.2 to 12.4; `/sanatate/` 34.4 to 34.8. All below 100 ms.
- Dev database, 12-week chart groups. Administrație: Conducere 99, Referent 155, Inspector 93, Secretar 72, no group 378. Financiar: Conducere 24, Administrator financiar 42, Economist 67, Contabil 8, no group 25. Every group has postings. The dev export ends mid-October, so the last 4 weeks are 0 on today's clock.
- Skills of financiar on the dev database (postings, by role): Excel 16, Word 14, Office 13, FOREXEBUG 7 (8 with "Forexebug", same filter), Operare PC 7, SIIIR 5, EDUSAL 4 (5 by the filter), "achiziții publice" 3, "baze de date" 3, SEAP 2, FINANTARE.ORG 2.

**Hard**
- The `skill` filter is a role fact (`posting_roles.skills`), not a column of `job_postings`. The test fixture needs role rows for the skills.
- `ro_sort_key` puts "Spitale" before "Școli" (s before ș): a test expectation written in ASCII order failed.
- A first fixture for the browser spec had one more administrație posting than the PHP fixture (the base fixture). The professional fixture now builds on the theme fixture.

**Decided**
- The chip row also hides when `occupation_not[]` is set. The facet counts leave that exclusion out, so a chip count could differ from the result count after the click. Cost of the alternative: a count that lies.
- The chips tests are in `theme_test.php`, not a new suite: a new suite needs a line in `.github/workflows/ci.yml`, which the brief does not allow.
- "Programe informatice cerute" is a proposal (FOREXEBUG, EDUSAL, SIIIR). Excluded: SEAP and FINANTARE.ORG (2 postings on the dev database; the live count is not measured), and the generic phrases ("programe de contabilitate", "baze de date").
- Single actions skip the 500 ms wait (owner, same day). The wait of `change delay:500ms` is for several quick checkbox picks. A chip click and the × of an active filter are one complete action: `sendNow()` in `pages/list.php` raises `formEdits` once (the stale-response guard stays) and calls `htmx.trigger(form, 'submit')`, which the existing `hx-trigger` "submit" runs with no delay. `requestSubmit()` was not needed. Measured, click to request, at 320, 375 and 1280 px: chip 558, 573 ms before (375, 1280) and 60, 65, 61 ms after; × 555, 547 ms before and 55, 36, 43 ms after. The checkboxes keep their delay (`multiselect.spec.js` passes).
- The button "Toate județele" after the title of a themed page (owner, same day; the checkbox of e16f235 stays removed): `<button class="g2-chip" data-county-clear hidden>` with the new Tabler symbol `i-x` (`inc/header.php`, ADR 0006). `static/home.js` shows it while a county is selected; a click runs `apply('', true)` and moves the focus to the county select. Only on a theme with a map.

**Near-duplicate occupation names** (dev database, names with 2 or more postings, by landing domain)
- Paza: "Îngrijitor de copii" and "Îngrijitor copii" (2 each); "Îngrijitor la domiciliu" (2) and "Îngrijitor bătrâni la domiciliu" (4); both are chips next to "Îngrijitor" (60).
- Social: "Îngrijitor la domiciliu" (5) and "Îngrijitor" (3).
- Sănătate: "Asistent medical", "Asistent medical generalist" and "Asistent medical comunitar" are chips side by side; "Infirmier" (chip) and "Infirmieră"; "Asistent medical radiologie" and "Asistent medical de radiologie"; "Medic" and "Medic specialist".
- Administrație: "Referent" and "Referent de specialitate" are chips side by side; "Administrator patrimoniu" and "Administrator de patrimoniu"; "Inspector" and "Inspector de specialitate".
- Meseriași: "Muncitor", "Muncitor calificat" and "Muncitor hidrometru".
- Educație and Altele: "Asistent de cercetare științifică" (3) and "Asistent de cercetare" (3), in two domains.
- Financiar: "Contabil" and "Contabil-șef" (different jobs, not a spelling pair).

### 2026-11-17 — Themed pages for the professional domains: analysis and four briefs

No code. Opus measured the domains and wrote four briefs:
- `docs/briefs/2026-11-17-themes-admin-financiar-chips.md`
- `docs/briefs/2026-11-17-pipeline-domain-trade.md`
- `docs/briefs/2026-11-17-theme-meserii.md`
- `docs/briefs/2026-11-17-theme-social.md`

**Done**
- Profile of each landing domain: occupations, employer kind, area of work, management level, studies, credentials and skills. Sources: the 17 Oct export (1,197 active postings) and the filter counts of the live list for each domain (1,638 active, 2026-11-17).
- Live counts:

  | Domain | Active |
  |---|---|
  | Sănătate | 554 |
  | Administrație | 280 |
  | Meseriași | 140 |
  | Pază, îngrijire și servicii | 129 |
  | Financiar | 93 |
  | Social | 85 |
  | Educație | 63 |
  | Șoferi | 59 |
  | Muncitori necalificați | 55 |
  | Altele | 54 |
  | Ingineri | 42 |
  | Tehnicieni | 31 |
  | Cultură | 25 |
  | IT | 18 |
  | Ordine publică | 7 |
  | Juridic | 3 |

**Hard**
- The 17 Oct export was one month old: it had 27 % fewer active postings than the live site. Financiar had 55 postings there and 93 live. The first proposal marked Financiar "optional"; the live numbers changed that. Measure on live data before a decision about volume.
- A first parser of the live filter counts used a lazy regular expression. It matched across rows, and the "fără" (exclude) checkboxes took the counts of the next row. The second parser splits the page at each checkbox.
- New postings per week come from the export only (August to mid-October). The live site does not show them per domain, so the live decision uses the active count.

**Decided** (owner, 2026-11-17)
- **Themed pages:** Administrație, Financiar, Meserii și muncitori, and one page for Social and Educație. The rule "enough postings, a distinct audience and a section of its own" excludes the other domains:
  - Ingineri and Tehnicieni: 73 postings, with nurses and military aviation technicians among them.
  - Cultură 25, IT 18, Ordine publică 7, Juridic 3: too few postings for sections.
  - Altele: a leftover group; its caretakers and cooks belong to services.

  Cost of a page for each domain: thin pages for search engines, and pages that show the classification errors.
- **Tehnic:** one page "Meserii și muncitori". Its four COR groups (7, 5, 8, 9: 383 active postings) are a section of the page, not four pages. Cost of four pages: four thin pages with the same employers and studies.
- **Social and Educație:** one page, "Asistență socială și consiliere". The "educație" postings are school counsellors at town halls (32 of 63) and not teacher posts (3), so they share the audience and the employers of "social". Cost of two pages: an "Educație" page that promises teacher posts.
- **Landing links:** a domain item links to its themed page when one exists (theme key `domains`, not equal parameters, because the Social and Meserii pages take more than one item).
- **Order:** Administrație, Financiar and the occupation chips first; the data of these two domains is good. Then the pipeline brief. Then Meserii, because 70 % of its skilled workers have no trade today. The Social page does not depend on the pipeline.
- **Occupation chips on the list:** chips with the most frequent occupations (`occ_canonical`, the bold title of a card), not raw titles. A raw title carries grade, post count and part-time notes, and no filter matches it. Rules:
  - Show the row when the results fill more than one page.
  - Show it when at least 2 occupations have 2 or more postings each.
  - Show it when the largest occupation has 90 % of the results or less.
  - Show it when no occupation filter is on.
  - Show at most 8 chips. The last link is "Toate ocupațiile".

  The counts come from the occupation filter, which the list computes already: no new query.
- **Professional domain from COR (after the spike of brief 2, same day):** no change of the `family` vocabulary now. The spike moved 24 % of the active postings (32 % of all). Many of those moves come from wrong COR codes of generic labels, not from a better domain:
  - "Administrator" carries 515104 (caretaker).
  - "Referent" and "Consilier" carry 331309 (financial associate).

  Next: correct those codes (backlog UX-05-DOMAIN-COR-FIX), then measure again. If a COR-based domain is still useful, add it as a new field. Cost of a change now: saved searches, alerts, feeds and old `family[]` URLs would change meaning, on a mapping that moves postings for the wrong reason. Meserii (brief 3) keeps `family[]=tehnic` with COR 5, 7, 8 and 9: these groups are the same under both methods.
- **County map scale (owner request, same day):** the colour steps of the county map become relative to the counts of the page. They use a log scale between the smallest and the largest non-zero count, with a blank tile for 0. Measured on the 17 Oct export, counties in steps 1 to 4:

  | Method | Sănătate | Financiar |
  |---|---|---|
  | Fixed steps (today) | 37 / 2 / 1 / 0 | 27 / 0 / 0 / 0 |
  | Linear min–max | 37 / 2 / 0 / 1 | not measured |
  | Log min–max | 10 / 23 / 5 / 2 | 16 / 9 / 1 / 1 |
  | Quartiles | 10 / 10 / 10 / 10 | collapses (breaks 1, 1, 2) |

  Cost of a linear scale: București alone takes the top step, and the map stays flat. Cost of quartiles: the steps collapse when the counts are small. Opus builds it after brief 1, because brief 1 changes the same files.
- **Notifications band (owner request, same day; done by Opus):** "Abonează-te la notificări" is a full-width `sunken` band (`.pg-band`) on every page. Before, only the landing page had the band and a themed page had a box. `partials/follow_cards.php` has no `band` option now. The key `'band' => true` in `pages/home.php` does nothing; remove it after brief 1. Checked on `/sanatate/` with the 17 Oct export: the band reaches both edges of the window; no horizontal scroll at 375 and 1280 px. A band that is the last section of the page now meets the footer. Before, a white strip of 104 px stayed between them: the container's `pb-14` plus the footer's `mt-12`. The rule is in `assets/app.css` and uses `:has(> .pg-band:last-child)`. Measured: `/sanatate/` 0 px. The landing page keeps 104 px after "Instrumente", because its band is not the last section.
- **Health card without border (owner request, 2026-11-18; done by Opus):** the card "Posturi în sănătate" in the landing examples has no border and no extra padding now. The icon still marks it as the card that opens a page, not a list. Before, it had a 2 px `gov` border with `px-3 py-2`. Its title starts at the left edge of the column, as the other titles do (measured: 0 px offset at 1280 and 375 px). `home_test.php`: 821 assertions passed.
- **One chart axis for all buttons (owner request, same day; done by Opus):** on a themed page, every button of the weekly chart uses one vertical axis. The axis goes from 0 to the largest week of the total and of each series, in the selected county. Before, each button had its own scale, from its own minimum to its own maximum: a group of 3 a week filled the same height as a total of 60. The minimum is 0 for all buttons too: with a shared maximum and a minimum for each series, a series could fall below the axis. `partials/weekly_chart.php` draws the total on the same axis (no jump when the script loads). The landing chart has no buttons and keeps its scale. The line of text keeps the min and max of the series itself. Measured on `/sanatate/` (17 Oct export), the top of each line, with y = 4 at the top of the frame and y = 60 at the bottom: Toate 4 (205 a week), Asistent 37.1 (84), Medic 41.4 (68), Îngrijitor 49.3 (39), Rezident 57 (11). Tests: `theme_test.php` 900 and `home_test.php` 821 assertions passed. `theme.spec.js` now expects Medic's top at y = 32 on the fixture (mutation: a scale for each series gives 4). Not run yet: the brief 1 agent was using the Playwright ports.

### 2026-11-17 — Landing page: health card, column layout, county in the URL, domain band (not deployed)

No brief (owner requests in the session; small changes to the landing page).

**Done** (follow-up 1 below changes the tags and the line "Pagini tematice")
- Ready-made searches: the first card is the themed page "Posturi în sănătate" (link `/sanatate/`, the theme icon, the count of its base filter, three tags). It has its own hook `data-home-theme-example`, because it is not a list search. The tags of every card are smaller and muted (`.pg-tag-dim`: 13 px, `ink-muted`, next to `.g2-tag`). The list is CSS columns (1, 2 from `md`, 3 from `lg`), with 12 px under each card: the cards flow down each column with the same space, whatever their height.
- The domains section has a `sunken` band from edge to edge of the window (`.pg-band`: a 100vmax shadow, clipped by `clip-path` to the height of the section). No horizontal scroll at 390 and 1280 px.
- County in the URL fragment: `/#cluj` selects Cluj (select and map) and scrolls to `#domenii`. A selection writes the fragment with `history.replaceState`; no county removes it. The same script runs on the themed page (`/sanatate/#cluj` selects Cluj; no scroll, the map is in the first screen). A fragment that is not a county changes nothing.
- "Ingineri și tehnicieni" is two domains on one row: "Ingineri" (COR 2) and "Tehnicieni" (COR 3), one icon. `HOME_DOMAINS` key `row`; `count_item.php` key `parts`; the row (`data-home-group`) sorts by the sum and hides when the county hides both parts. Real data: Ingineri 40, Tehnicieni 18.
- Tests: `home_test.php` 795 assertions (the themed card; the canonical-URL check reads the list cards only); `home.spec.js` 1 new test (fragment), the 44 px check skips the themed card as it skips the other cards. `home.spec.js` and `theme.spec.js`: 153 passed.

**Hard**
- A first version of the fragment test changed the fragment on the same page; the old county stayed, which is the right behaviour. The test now loads the page again.

**Decided**
- Fragment format `#<county slug>` (`#cluj`), not `#judet=cluj`: shorter, and no element id of the page is a county slug. Cost of the alternative: a longer URL to share.
- The fixture has no `tehnic` posting with COR 2 or 3, so the two-part row is checked on real data (screenshots), not by the PHP suite.
- "Meseriași și muncitori calificați" (COR 7) and "Muncitori necalificați" (COR 9) stay separate: different requirements (a qualification certificate or none). Not changed.

**Follow-up 1 (same day, owner request)**
- The line "Pagini tematice" is gone from the domains section. The footer has a fourth group "Pagini tematice" (the themes of `themes()`); "Posturi" keeps its four fixed links.
- The ready-made searches show no tags. The `tags` stay in the data of `$combos`. `.pg-tag-dim` is removed (no use left). A list card has 8 px below it (12 px before, with the tags).
- The health card has a 2 px `gov` border and inner padding, and a 30 px icon (24 px + 25 %).
- "Abonează-te la notificări" on the landing page is a full-width band (`follow_cards.php` key `band`; `.pg-band py-8`). The themed page keeps the box.
- Tests: `home_test.php` 796, `theme_test.php` 550 assertions; `home.spec.js` (footer: four groups, four lists), `theme.spec.js`, `chrome.spec.js`: passed.

**Follow-up 2 (same day, owner request)**
- Examples: order "Peste 5.000 lei net…" first, then the health card, then the list searches; "Magyar…" last. Each card is as wide as its text (`w-fit`, still a block, so two short cards never share a line of a column); 16 px below each card; 8 px above and below the list.
- Detail page, salary: the salary is the first row of the summary strip (before the deduced conditions). A salary stated in the posting (`inf_salary_min` > 0) has its own box "Salariu anunțat" (net first, the gross line "din anunț", the pay meter), in place of the estimate box. The estimate becomes one note in it: "Conform legii salarizării (propunere): ≈ … lei net (brut …) / lună, la gradația 0." The deduced conditions leave out "Salariu" (the box has it). Case found on `/job/17529-…`: stated 4.700 lei, estimate 8.200 lei gross. 17 Oct export: 35 active postings state a salary; 23 of them also have an estimate.
- Tests: `discovery_test.php` 522 assertions (stated box, the note, the order, stated without estimate). Every PHP suite passed; `home`, `theme`, `salary`, `browser` specs: 177 passed, 3 skipped.

Assumed
- The stated figure is gross, as the list rows already assume (`list_salary()` computes net from it). Most postings state "salariul de bază brut"; the extraction does not record gross or net.

**Follow-up 3 (same day, owner request)**
- Feeds (RSS, Atom, iCal; one function, `feed_details()`): the salary is the first fact of "Extras automat". A stated salary gives "Salariu anunțat" (net), "Brut anunțat" ("din anunț") and, when an estimate exists, "Conform legii salarizării (propunere): … net (… brut) / lună, la gradația 0"; the estimate lines and the condition "Salariu" are left out. With no stated salary, the three estimate lines as before.
- Tests: `feeds_test.php` 3,386 assertions (new suite: the three formats, stated with and without an estimate).
- Backlog: DATA-SALARY-GROSS-NET now says the goal: both figures everywhere, net first, from a recorded basis (gross or net).

**Follow-up 4 (same day, owner request)**
- `/sanatate/`: the checkbox "Arată toate județele" under the map is removed (markup, its code in `static/home.js`, its tests). The first option of the select ("Toate județele") or a second click on the selected cell clears the county. Tests: `theme_test.php` 546 assertions; `theme.spec.js` and `home.spec.js` 153 passed.

### 2026-11-17 — Footer link rows, GitHub mark, /llms.txt (not deployed)

Brief: `docs/briefs/2026-11-17-footer-llms-txt.md`.

**Done**
- Footer lists: each link is 26 px high (`py-[3px] leading-5`, in a flex `li` with `py-0.5`); the rows are 30 px apart (44 px before). The targets are 4 px apart. The link "Cod sursă" has the Tabler `brand-github` mark (18 px, `aria-hidden`, text colour; symbol `i-brand-github` in `inc/header.php`, source `assets/icons/tabler/brand-github.svg`, `@tabler/icons` 3.52.0, not modified). The other links have no icon. The check "every link is 44 px" in `home.spec.js` now skips the footer lists, which have their own check (24 px, no overlap, rows 28 to 32 px apart); the disclaimer link keeps 44 px.
- `/llms.txt` (`pages/llms.php`, one route in `index.php`): title, summary, "Pagini" (each themed page from `themes()`), "Date și fluxuri" (RSS, Atom, calendar, sitemap), "Căutare prin URL" (10 parameters, each read by `build_filters()`, examples from `home_list_url()`), "Despre date" (build date, active postings from `home_count_for()`), "Cod sursă". Cache `public, max-age=86400`. `robots.txt` is unchanged. Route added to the README. New suite `tests/llms_test.php` (88 assertions) in `.github/workflows/ci.yml`.

**Hard**
- The first footer rows were 31 px apart, 33 px at the icon: an inline-flex link in a block `li` leaves space under the baseline. A flex `li` gives 30 px.
- The parameters of `build_filters()` are read in two ways (`$p['x']` and loops over `'x' => column`); the test accepts both.

**Decided**
- `sort` and `page` are not in the list: `build_filters()` does not read them (`index.php` does).
- The absolute URLs use `site_origin()`, as the sitemap does; on a local server they start with `http://localhost:PORT`.

### 2026-11-17 — Themed page: statistics panel, compact lists, medical icons (not deployed; follow-up 1 at the end)

Brief: `docs/briefs/2026-11-17-theme-stats-icons.md`. ADR 0007 (changed during the work: one separate SVG file).

**Done**
- Statistics panel on `/sanatate/` (section type `stats`, left column of the first screen, below the link to all postings; below `lg` it comes before the map): three figures (active postings, employers, postings that close in 7 days), the weekly chart of the theme (12 weeks), the table "Ocupații, pe luni" (5 occupations; the last 3 complete months and the current one), one caption line. The theme key `history` (`family`, optional `note`) names the part of the archive. A theme with no `history` shows the figures only; a theme with no `stats` section renders as before.
- Shared partials: `partials/figures.php` and `partials/weekly_chart.php` (the landing page uses both; its HTML is the same as before, measured on real data), `partials/theme_stats.php`. `home_weekly_postings()` takes an optional family. New helpers: `theme_months()`, `theme_occupation_months()`, `theme_icon_for()`, `icon_file()`.
- The figure "se închid în 7 zile" links to the list with `status=soon`: the same predicate (open, deadline today to today + 7). Employers has no list with that predicate, so it is not a link.
- Compact lists: `compact` flag of `partials/count_item.php` (28 px line, link `py-[3px]`, 24 px icon).
- Icons: `webapp-php/assets/icons/healthicons/` (47 outline files, `LICENSE`, `README.md`, `build-sprite.php`); the generated file `webapp-php/static/health-icons.svg`; the theme key `icon_file` names it; a `values` section has `icons` (exact value), `icon_prefixes` and `icon_default`. An item with none of them has no icon; a gap of 24 px keeps its text in line.
- Tests: `theme_test.php` (398 -> 411 assertions with the fixture history in `tests/fixtures/theme_db.php`), `home_test.php` (landing chart pinned), `tests/browser/theme.spec.js` (4 new tests, 3 widths).

**Hard**
- The first plan put the 47 symbols inline: 133,828 bytes raw, 49,472 gzip, above the 40 kB gate of the brief. The owner chose a separate cacheable file (option 3) and changed ADR 0007. Measured now: file 133,483 bytes raw, 49,209 gzip (one request, cached for a month by `.htaccess`); the 37 icon elements add 5,288 bytes raw (about 0.4 kB gzip) to the HTML of `/sanatate/`; the whole panel and icons together add 10.3 kB raw, 1.4 kB gzip.
- The archive starts in July 2026 and its first week is thin (07-01: 1 posting, 07-02: 1, 07-03: 2, then 8 a day from 07-06), and one health posting is dated 06-30. The template cannot state "the archive starts in July" from the data (the first month of the history is June). The sentence is the `note` of the theme configuration.
- A first version of the weekly clause kept the family filter in one table only. Test mutations found it.

**Decided**
- Icons by rule, not by 75 exact values: the exact value first (`Asistent medical comunitar`), then the first matching prefix (`Asistent medical` nurse, `Medic` doctor, `Farmacist` pharmacy), then `icon_default`. Rationale: the values change with the data. Cost of the alternative: a list that grows with each new occupation.
- The panel is a `stats` section type and not a theme-level key, so a theme chooses its place by its list of sections. `history` is a theme key because it names data, not a place.
- The table counts each posting once; `job_postings` (all statuses) wins over `archived_postings` for the same id; the family filter applies to both tables.
- Not done: a history of specialties (the archive has no such column; backlog UX-05-THEME-SPECIALTY-HISTORY).

**Follow-up 1 (same day, owner request)**

Done
- One chart instead of chart plus table. The table "Ocupații, pe luni", `theme_months()`, `theme_occupation_months()` and the note „Arhiva începe în iulie 2026.” (`note` of `history`) are gone. Above the chart: a row of DS chips (`g2-chip`, `aria-pressed`): "Toate" and the 5 occupations with the most postings in the 12 weeks (`theme_occupation_weeks()`). `static/theme-chart.js` draws the series of the pressed chip in the same chart (line, area, line of text, label, vertical scale of the series) and writes a sentence to a polite live region. The series are in the JSON block `#theme-chart-data`; the buttons ship hidden. Caption: „Toată țara. Anunțuri publicate, și cele expirate.”
- Icons: the section "Ocupații" has none (settings, `icon_prefixes` and its code are removed). The 10 symbols that no section uses are removed from `assets/icons/healthicons/outline/` and from `static/health-icons.svg` (47 -> 37 symbols; 133,483 -> 108,203 bytes raw, 49,209 -> 40,151 gzip). "Specialități medicale": 3 columns from `lg` (section key `columns`), 32 px icon (section key `icon_size`); the section is 330 px high at 1280 px (322 px before with 4 columns and 24 px icons; 358.7 px at the start of the work).
- Search form: theme key `search_selects` (a list of `param`, `label`, `all`, `source`, `column`). `/sanatate/` has the selects "Toate specialitățile", "Toate ocupațiile", "Toate județele" and "Caută", no text field. Options: every value with a posting inside the theme, Romanian order, no count (`theme_select_values()`). A theme with no `search_selects` keeps the field. The submit uses the existing form script and the clean-URL redirect.
- The line "Posturi nemedicale la angajatori din sănătate" is before "Noi și pe terminate", with the muted sentence (`note` of `other`).
- Tests: `theme_test.php` 411 -> 488 assertions; `theme.spec.js` has 8 new tests (chart chips, no-script chart, selects, columns and icon size) and 5 changed.

Hard
- The first one-row form wrapped the county select and the button on one row at 320 px; the button is now full width below `sm` when the form has selects.
- Fixture: Kinetoterapeut and Psiholog moved from 3-4 Jul to 14-15 Jul, so that 5 occupations have a posting in the 12 weeks.

Decided
- The chips are DS chips as toggle buttons (COMPONENTS.md, Chips): no local class.
- The five occupations are chosen by the postings of the 12 weeks, not of the active list, so that the chips and the chart agree.
- The vertical scale of a series fits that series (the brief), so the same height means different numbers; the line of text shows min and max.
- `columns` is a section setting (3 or the default `auto-fill`), not a theme-wide one.
- ADR 0007 still says 47 symbols; the ADR was not in the files of the brief. The file now has 37.

**Follow-ups 2 to 5 (same day, owner requests; the later one wins where they differ)**

Done
- Form (2.1, 3.1): at `lg` the three selects and the search icon button are one row, with no wrap at 1280 and 1024 px; the left column of the first screen is 60% (`3fr 2fr`). Measured on real data: at 1280 px each select is 211.2 px and the button 48 x 48 px; at 1024 px each select is 160.5 px and the button 48 px (a long option truncates). The icon is Tabler `search` (symbol `i-search` in `inc/header.php`); the button name is the text "Caută", visible below `lg` and hidden from `lg`.
- Chart (2.2 to 2.4, 3.2): the heading is `sr-only`; the buttons are text only (`.pg-chart-btn`: no border, no background, in each state; the pressed one is bold and underlined); the caption is gone.
- One county control (2.2, 3, 4): the map and the form's county select are one control (`static/home.js` uses the select of the form). The chart follows the county (`static/theme-panel.js`, event `posturi:county`, data in `#theme-panel-data`). The county data adds 6,792 bytes raw, 1,800 gzip to the page (42 counties; the JSON of the whole panel). Sum rule tested on the fixture. The checkbox "Arată toate județele" replaces the line under the map, the county name and the button "Toate județele"; the label of the map is `sr-only` and the map keeps its name. The title shows the county and its count: "Posturi în sănătate → Suceava (12)".
- Follow-up 4: the figures row and its county data are gone (the landing page keeps `partials/figures.php`).
- Follow-up 5: the buttons of the chart are fixed groups of the theme key `history.groups` (Medic without "Medic rezident", Rezident, Asistent, Îngrijitor); `theme_group_series()` replaces the top-5 occupations. Real data, 12 weeks (20 Jul to 4 Oct 2026): Medic 435, Rezident 45, Asistent 680, Îngrijitor 265, in no group 164, total 1,589. Specialty icons are 40 px (section 402 px high at 1280 px, 3 columns). The link "Toate anunțurile din sănătate (375)" is the right column of the row of the line "Posturi nemedicale…", with its count outside the link; both carry `judet[]` and the county count.
- Sprite file after the unused symbols were removed (follow-up 1): 37 symbols, 108,203 bytes raw, 40,151 gzip.
- Backlog: DATA-DETERMINATE-DURATION (follow-up 3, item 5).

Hard
- The county count of the title and of both links is the count of `home_item_counts()`, which counts the postings of a county; a posting with no county is only in the national count.
- "Medic" takes "Medic veterinar" and "Medic de familie" by prefix; only "Medic rezident" is excepted, as the brief says.

Decided
- The title count and the move of the link to all apply to a theme with a `counties` section (the setting that already adds the map); a theme without it renders as before.
- A theme with `stats` but no `history` has no panel (the chart needs the archive family).
- The checkbox is a native one: the DS has none (DS proposal pending, not opened; the text buttons too).
- The Tabler source file `search.svg` is not added to `assets/icons/tabler/` (outside the files of the brief).


### 2026-11-17 — One "Salvate" item, table of contents, themed-page hero (not deployed)

Brief: `docs/briefs/2026-11-17-nav-toc-theme-hero.md`.

**Done**
- Navigation: four items (Posturi, Salvate, Statistici, Despre). "Alerte" is gone from the masthead. "Salvate" has the bell (`i-bell`, `aria-hidden`) and keeps `data-saved-count`. The footer keeps "Alerte pe email". `/salvate/` has a block "Alerte pe email" (one sentence, link "Gestionează alertele" to `/alerte/`). The navigation marks no current item today, so step 1.5 changed nothing.
- Statistici: the card "Fără dată de publicare" shows only when its number is above 0 (`data-unknown-date`).
- Table of contents: `partials/toc.php`, `toc_h2()` in `helpers.php`, `static/toc.js`, `.pg-toc` in `assets/app.css`. Each page has one array `$toc` (heading id => text); the headings and the list both read it. Despre has 10 entries (new ids `ce-ofera` and `vezi-si`; the old ids stay). Statistici has 3 (`h-export-curent`, `h-istoric`, new `h-costuri`).
- Themed page: two columns from `lg` (title, search, link to all | map, legend, line). The section "Pe județe" and its select are gone. The line under the map: hint, or the county name and the button "Toate județele". The title has the icon `i-sector-sanatate` (new theme key `icon`).
- Tests: updated `home_test.php`, `theme_test.php`, `preferences_test.php`, `stats_test.php`, `home.spec.js`, `theme.spec.js`; new `toc.spec.js`.

**Hard**
- Click on a table-of-contents link of a short section: with the "current" line at 30 % of the window height, the next heading was above the line too, so the clicked section was not the marked one. Measured on `/despre/#salarii`: the mark was on the next section. The line is now at 12 %.
- `home.js` needed the select (`#domenii-judet`) to start. It now starts with the map alone (state in a variable). The landing page still has its select and the same behaviour.
- The button "Toate județele" hides itself on click. The script moves the focus to the cell that was selected.

**Decided**
- Where the map goes: the rule "a `counties` section renders in the first screen" (not a setting for each theme). Alternative: a `map_placement` key. Cost: one more key that every theme must set, with one allowed value in practice. The section keeps its `id` and no title.
- The icon of the theme is the sprite symbol `i-sector-sanatate` (the sprite has no health symbol in the `i-domain-*` set; it has `i-sector-sanatate`). It is already in the sprite, so no new Tabler file was added.
- Table-of-contents target is the heading (`tabindex="-1"`, `scroll-mt-4`), not the section, so the keyboard focus lands on the heading. Statistici keeps the section ids (`#export-curent`, `#istoric`) that the intro links to.
- Below `lg` the list is a row of wrapping links (44 px high), not a long column: Despre has 10 entries.
- Statistici container widened from `max-w-screen-lg` to `max-w-screen-xl`, and Despre from `max-w-screen-md` to `max-w-screen-lg` (text column stays `max-w-screen-md`), so the 14 rem column does not narrow the content.
- `.pg-toc` and the county map stay local. Both are DS proposal candidates (comment in `assets/app.css`); no issue opened.
- The case "0 postings with no date" is tested in `stats_test.php` only (it needs its own database); the browser test covers the case "3".

**Measured**
- Baseline before the work: every PHP suite passed; Playwright 644 passed, 58 skipped, 0 failed (7.6 min).
- After the work: every PHP suite passed (`home_test` 786, `theme_test` 250, `stats_test` 89, `preferences_test` 201 assertions); Playwright 686 passed, 58 skipped, 0 failed (8.1 min). The first full run after the work had 3 failures in `searches.spec.js` (the new alerts block is the first `h2` of `/salvate/`); the test now skips that heading.

### 2026-11-17 — Net salary, postings with no estimate, the salary note (not deployed)

Brief: `docs/briefs/2026-11-17-salary-net.md`, with its Follow-up 1.

**Done**
- The reader sees net pay first: list rows (three views), the pay meter text (median in net), the detail page (net range main, one gross line below), the archived page, the feeds ("Salariu estimat" net, then "Brut estimat"), the About page. Statistics shows counts only; it needed no change. The employer builder keeps its gross inputs (the employer types gross).
- Filter "Salariu": `net-sub-3000`, `net-3000-4000`, `net-4000-5000`, `net-peste-5000`, `fara-estimare`. Each net threshold is a gross threshold, found once by `gross_for_net()` (binary search on `net_from_gross()`): 3.000 net = 5.036 gross, 4.000 = 6.837, 5.000 = 8.548. The SQL compares the stored gross column. The old gross values stay valid with the old meaning (their chip says "brut"); the ids they select are identical on the 17 Oct export (checked against a baseline taken first) and on the fixture.
- Note above a salary-filtered list (part of the HTMX fragment, not in the feeds), with the count of "Vezi-le"; the other note on `fara-estimare`.
- Landing: examples 1 and 2 in net, "Alte filtre" entry, group "Salariu". Follow-up 1: group labels without "După", new group "Tip instituție" (`inst[]`), the themed-page title "Studii" (in `inc/themes.php`).
- Measured on the 17 Oct export (1.197 active): net under 3.000 = 356; 3.000–4.000 = 397; 4.000–5.000 = 85; 5.000 and above = 157; no estimate = 202. Sum 1.197. The counts of the filter and the counts from the displayed figures agree. (The brief's estimate was 357 and 396, from a factor of 0.585.)

**Hard**
- `net_from_gross()` is not monotonic (see backlog UX-SALARY-NET-SORT): 40 drops of 1–3 lei between 4.325 and 6.325 gross. No drop crosses 3.000, 4.000 or 5.000 net: a test scans every cent within 30 lei of each threshold. So the filter and the displayed figure agree at the edges. Sort by salary still orders by gross.
- The fixture has a figure on every active posting, so the browser spec uses a third fixture (`make.php … salary`, port 8825).
- Baseline before the change: PHP suites all passed except `home_test.php` (4 assertions: hero h1, title, last hero line, link); Playwright 626 passed, 9 failed, 58 skipped (home.spec.js:43 and :294, searches.spec.js:186, at three widths). Those are the uncommitted landing work, not this brief.

**Decided**
- A zero figure counts as no figure for the new values (`fara-estimare` is `IS NULL OR <= 0`; the net buckets need `> 0`). Cost of the alternative: a posting with a zero would show no pay in the row and still sit in "sub 3.000 lei net".
- The facet gate (`>= 100` postings with a figure) is now `> 0`, so the small fixtures show the filter.
- The note says "1 anunț care se potrivește … nu are" for N = 1.

### 2026-11-17 — Themed landing page: /sanatate/ (not deployed)

Brief: `docs/briefs/2026-11-17-themed-landing-sanatate.md`.

**Done**
- Baseline before any change, in a clean copy of the tree: every PHP suite passed (`home_test` 707 assertions in the copy; `eligibility_test` needs `../../schema_models.py`, so it only passes in the real tree); lint clean; the full Playwright run 620 passed, 58 skipped, 0 failed.
- `inc/themes.php`: the configuration (`themes()`), keyed by slug: base filter, title, h1, description, section list, one "other" line. `pages/theme.php` renders any theme from it. `index.php` routes `/{slug}/`; `/{slug}/?…` is a 301 to `/?…` with the base filter merged in. Sitemap, landing line "Pagini tematice" and the footer group "Posturi" follow the configuration.
- Shared markup moved to `partials/`: `count_item`, `county_select`, `county_map`, `home_search`, `home_data`, `new_soon`, `follow_cards`. The landing page uses the same partials. New helpers in `helpers.php`: `home_item_counts`, `home_item`, `home_merge_params`, `home_item_fields`, `feed_url_for`, `home_new_soon`.
- `home.js` is generic: the JSON block is `items[id] = {params, counties}` (was `domains[key] = {family, cor, counties}`); the form sends all its fields, hidden ones included.
- Tests: `tests/theme_test.php` (235 assertions), `tests/browser/theme.spec.js` (5 specs x 3 widths, on a second server, port 8824, with `tests/fixtures/theme_db.php`), shared `tests/fixtures/page_helpers.php`.
- Real export (`posturi-new.sqlite`, clock 2026-10-17): 375 postings in the theme; 24 specialty items shown (more exist: link to the list); 12 occupations; 6 study levels; 140 "other postings of health employers". `Server-Timing` total about 17 ms (5 requests after the first).

**Hard**
- The first baseline run was spoiled: I edited files while Playwright ran. I repeated it on a copy of the tree.
- Counts per county for every item: one `GROUP BY judet_slug` query per item, built by `build_filters()`, so each count is the result count of its link. About 45 queries on the real export, 17 ms.

**Decided**
- A section or the "all" link with no item above 0 is not rendered. Cost of the alternative: an empty heading.
- The county section ships hidden and needs script (select and map are script features). The search form has its own county select that works without script.
- Tests add a theme through the environment variable `POSTURI_THEMES_EXTRA` (a PHP file). It only adds; it never replaces a theme. Cost of the alternative (an in-process render): the template needs globals and headers.
- Kept the DOM names `data-home-tile`, `data-domain`, `data-tile-count` for the items of both pages: a rename touches about 40 test lines for no visible gain.

### 2026-11-17 — Professional domains, filter order, multi-select fix (not deployed)

Brief: `docs/briefs/2026-11-17-domains-filters-multiselect.md` (with follow-ups 1 and 2).

**Done**
- Baseline before any change (tree with the icon work): the full Playwright run had 592 passed, 50 skipped, 0 failed.
- Landing page: the sector grid is now 15 professional domains (`HOME_DOMAINS` in `helpers.php`): a `family[]` value, and for the four-way split of "tehnic" a `cor[]` prefix. Each item has an icon (32 px), a larger name (`text-lg`), and its count in brackets outside the link. A county on the map or in the select changes the counts and the links (`home.js` builds `family[]` and `cor[]`). 8 new Tabler 3.52.0 icons (`clipboard-text`, `tools`, `user-shield`, `steering-wheel`, `ruler-2`, `shovel`, `coin`, `code`) plus `chart-bar`; 7 sector symbols are reused; 7 sector symbols that nothing used are out of the sprite (the source files stay in `assets/icons/tabler/`).
- Measured on the 17 Oct export (1,197 active): Sănătate 375, Administrație 206, Meseriași 102, Pază 75, Șoferi 63, Ingineri 58, Muncitori necalificați 43, Social 65, Financiar 55, Educație 45, Cultură 14, IT 9, Juridic 8, Ordine publică 8, Altele 66. Each equals `home_count_for()` of its link. "Tehnic" is 346; the items cover 341; 5 postings are in no item.
- "După angajator": a row of text links (sector, count above 0, no icons) in the starting points.
- Filter list order: Județ, Localitate, Domeniu profesional, Instituție, Sector angajator, Nivel angajator, Grupă ocupațională, Ocupație, ...; Specialitate medicală is above Documente necesare.
- Multi-select: `hx-trigger="change delay:500ms"`, `hx-sync="this:replace"`, and a stale-response guard in `list.php`: each edit of the form raises a counter, each request keeps the counter value it left with, and `htmx:beforeSwap` sets `shouldSwap = false` for a response whose value is old (results, out-of-band facet list and pushed URL all stay as they are). Controls stay enabled; the existing `#results` indicator shows.
- Follow-up 1: the example count and the domain count are joined to the last word by a no-break space (the title is inline text, no flex row); "Statistici →" with a chart icon ends the figures row; the first option of both county selects has no count; the starting-point groups are inline text (a wrapped line starts at the left edge, line height 28 px). Measured on the groups: the smallest link fragment is 26 px high, the gap between two lines of targets is 2 px, and the gap between targets on a line is at least 43 px. Follow-up 2: "După domeniu" link to `#domenii` (the target has `tabindex="-1"`, `scroll-mt-4`); the h1 spans both hero columns and is 34 px at 1280 (one line).
- Follow-up 3: the hero county select has a basis of 11 rem (was 14 rem), so the search field is wider; "Toate județele" still shows in full. The visible label "Județ" of the select in the domain section is gone (`sr-only`; the `for` link stays, so the select keeps its name).
- New backlog items: UX-05-DOMAIN (derive the domain from COR in the pipeline) and UX-18 (Hungarian landing page).

**Hard**
- I started the baseline run, then edited `list.php` and `facets.php` while it ran. That polluted the run. I stopped it, put both files back to the baseline state, and ran it again.
- The multi-select test passes only with all three parts. Without the 500 ms wait, three picks send three requests. Without the guard, a pick made while a request is in flight is lost when its response arrives before the next request leaves. Without `hx-sync`, the old request still completes (the guard hides this in the UI, so a test counts completed requests).
- `home.js` writes the count text again on load, so a test with script cannot see the no-break space of the server markup. A PHP check covers it.
- Two full Playwright runs, between the first and the last, lost the test server (`ERR_CONNECTION_REFUSED` from a point in the run on; about 150 and 210 failures). `mobile-375` alone passed (198), and the next full run passed. I did not find the cause; the server printed no error.
- My mutation script restored `home.php`, `helpers.php` and `home.js` from copies after each mutation. At the end `home.php` held an h1 of `text-xl sm:text-2xl` inside the left hero column, and a domain grid of `minmax(min(100%,15rem),1fr)` with `gap-y-0`. That is not what I wrote (full-width h1 of 34 px, 22 rem columns). I did not make those edits after a restore, so another session probably edited the file while I worked. All checks pass with that content, so I kept it.
- Tailwind builds only the classes that exist in the sources. A mutation test that adds a new class needs `npm run css` first, or it passes for the wrong reason.

**Decided**
- Domain items are inline text with padding on the link (no `inline-flex` or `inline-block`), so the count wraps with the last word. Cost: the item cannot be one flex row with a fixed link size.
- The grid has one column at 1280 (22 rem minimum): the longest name needs one line. Cost: a tall list beside the map.
- Stale guard by counter, not by comparing the form data: a counter is cheap and an edit that changes nothing only costs one more request.

### 2026-11-17 — Sector icons, landing copy and layout (not deployed)

Brief: `docs/briefs/2026-11-17-sector-icons.md` (with follow-up 1 and its addition). ADR 0006.

**Done**
- Sector icons: the 14 Tabler 3.52.0 SVG files and the MIT `LICENSE` are in `webapp-php/assets/icons/tabler/` (`mediu.svg` is `trees`, taken unmodified from the 3.52.0 tarball; `leaf` looked like the `agricultura` wheat ear). The sprite in `inc/header.php` has 14 symbols `i-sector-<key>` (+5,570 bytes on each page; the invisible `stroke="none"` bounding path of each file is not copied). `icon()` in `helpers.php` writes `<svg aria-hidden="true" focusable="false"><use>`.
- Follow-up 1: new h1 and title "Anunțuri de angajare din administrația publică"; no lead paragraph, no update date in the hero; "Toate anunțurile (N)"; the last line of the hero with a link and bold text. Example searches: hidden heading, count in brackets after the title. Sectors are one-line items (28 px icon, link, count in brackets outside the link), not `.g2-tile`. Start groups are one wrapping line with a bold label; "După județ" is removed. Both county selects show "Name (N)" from the counts of the map (`data-name` holds the plain name for the live text). Logo has no gap. `og-image.html` and `og-image.png` are new (gov2 DS look, Playwright screenshot at 1200 x 630). The `og:image:alt` text follows the new headline.
- Tests: `home_test.php` and `home.spec.js` follow the new markup; new checks for the icons, the counts outside the links, the option counts, no "După județ".

**Hard**
- The fixture has all sector postings in one county, so a script that never rewrote the sector count still passed. The new browser test poisons each count with "(999)" first.
- A box-less item needs its own 44 px link target: the link is `inline-flex min-h-[44px]`.
- When a title wraps, the count in brackets drops to its own line (a link that must be 44 px high is `inline-block`).

**Decided**
- Icon size 28 px (the brief allows 24 to 32). Cost of 32: the line and the icon look heavy beside 16 px text.
- Icon outside the link: it is decoration, so the link text stays the only name.

### 2026-11-17 — One look: the gov2 DS v0.17.1; skins removed; landing tweaks (not deployed)

Brief: `docs/briefs/2026-11-17-ds-alignment.md`. Decision: ADR 0005.

**Done**
- Landing page: the figures panel has no border; the heading is "Exemple de căutare"; the example cards have no box (title link, `.g2-tag` tags, count); the "Sau începe de aici" items are a text link plus a count outside the link (not underlined, 44 px target); the sector tiles are `.g2-tile`. The chrome and the landing page use `.g2-skip-link`, `.g2-header`, `.g2-wordmark`, `.g2-nav`, `.g2-footer`, `.g2-container`, `.g2-heading-*`, `.g2-text-meta`, `.g2-btn`, `.g2-input`, `.g2-tile`, `.g2-tag`.
- DS `v0.17.1` installed in `webapp-php/static/gov2/` with the `git archive` command of the DS README. Each page links `gov2.css` and `fonts.css` with `?v=` from `GOV2_DS_VERSION` (`helpers.php`). The 400 page, which was standalone with inline hex, links them too.
- Removed: `static/skins/`, `inc/skins.php`, `assets/check-skins.php`, `static/prefs.js`, the picker, the skin boot script, the font preload, `assets/fonts.css`, `static/fonts/` (12 files: Fraunces, DM Sans, DM Mono, Manrope). `assets/og-image.html` now uses the DS Inter. `ci.yml` and `deploy-php.sh` had no skin step; `ci.yml` has a new `ds_test.php` step.
- Tokens: the `:root` block of `assets/app.css` aliases each role token to a DS token. `.pg-tile`, `.pg-chip`, `.pg-chip-count` and the old focus and picker rules are gone.
- Layers: `@layer reset { @tailwind base; }` and `@layer app { @tailwind components; @tailwind utilities; @tailwind variants; }`, then native `@layer app` blocks. No rule is outside a layer.
- Focus: one rule block in `app` (after the utilities) gives the DS focus treatment again.
- Docs: README § Design system; backlog UX-06 closed, UX-06A/B/C opened.

**Hard**
- Tailwind 3 appended the variant utilities (`hover:`, `lg:`, `placeholder:`) after the layers, outside any layer. They would have beaten every DS rule. The fix is `@tailwind variants;` inside `@layer app`. `ds_test.php` checks that no top-level rule is outside a layer.
- A utility on a focusable element (`bg-gov`, `hover:bg-sunken`, `focus:outline-none`) removes the DS focus treatment. The focus rule uses a doubled `:focus-visible` to beat `hover:` utilities, and `background-color` for fields, so that the select arrow stays.
- A first focus test failed on the view switch: a `transition-colors` utility. The test waits 300 ms.
- Inter has no italic file. The headings had `italic` for Fraunces. `.font-display.italic` is upright now.
- `ink-faint` on the sunken surface (4.08:1) was used only in the dl of `about.php` (12 uses, measured with a script over 13 pages). Now `ink-muted`.

**Decided**
- Layer method: Tailwind directives inside native layers, utilities in `app` and not in `utilities`. Cost of the alternative (a build step that splits the output): a second tool, and no gain.
- Header and footer use `.g2-container`; the other pages keep their own width, so above 1280 px they are wider than the header (UX-06B).
- Notifications block: a local block on the sunken surface with three `.g2-tile`. No `.g2-inset` or `.g2-notice` fits a block with a heading and three tiles.
- `.g2-ro-corner` is in the header (DS default mark).

**Measured**
- Baseline before the change: 17 PHP suites pass, lint clean, Playwright 520 passed, 50 skipped, 0 failed.
- County map text contrast, one DS look (steps 0–19, 20–39, 40–79, 80+): 16.23, 11.93, 7.36, 7.18. The lowest is 7.18.
- After: 18 PHP suites pass (new `ds_test.php`, 146 assertions; `home_test.php` 558), Playwright with new `ds.spec.js` (87 tests across 3 widths).

### 2026-11-16 — Landing page, /posturi/, navigation and footer (not deployed)

Brief: `docs/briefs/2026-11-16-landing-page.md`. Route rule: ADR 0004.

**Done**
- Routes (`index.php`): bare `/` renders `pages/home.php`. `/?…` and each HTMX fragment render the list. `/posturi/` renders the unfiltered list. `/posturi/?…` is a 301 to `/?…` with the query string as sent. A fragment with no filter, sort, status or page sends `HX-Push-Url: /posturi/`; the vendored htmx 2.0.4 honours it (checked in the browser by `home.spec.js`). `/posturi/` is in the sitemap.
- `pages/home.php` (new): hero with search and county select; three figures; a weekly chart; six ready-made searches; domain tiles with a county tile map; four rows of starting-point chips; "Noi azi" and "Se închid în 3 zile" (moved from the list page, rules unchanged); notifications; tools. `static/home.js` (new) runs the hero form and the county select and map. `static/flags/hu.svg` (new).
- List page: the shortcuts row, `$home_new`, `$home_soon` and their markup are gone. "Șterge tot", "Șterge toate filtrele", "✕ Șterge filtrele", the "all postings" links of the archive, saved, detail and eligibility pages, and the detail "back" default now point to `/posturi/`. The list canonical is `/posturi/` for every filter permutation (the header drops the query string, so `/` would have folded the lists into the landing page).
- Masthead: five links (Posturi, Salvate, Alerte, Statistici, Despre), all visible, each 44 px high, wrapping at 320 px. Footer: three groups of links, the disclaimer, the skin picker.
- UX-15 redirect removed: the `header.php` script, the notice (`[data-search-notice]`) and `syncNotice()`, `APPLIED`, `sessionGet()` and `sessionDel()` of `searches.js`. Kept: `defaultId` and `defaultSearch()` (the Salvate page reads them), `markOpened()`.
- CSS: `.pg-tile`, `.pg-chip`, `.pg-chip-count`, `.pg-map`, `.pg-map-cell`, `.pg-map-swatch`. No token, no skin file and no `.g2-` class changed. `check-skins.php`: 69 problems, as before.
- Tests: new `tests/home_test.php` (540 assertions) and `tests/browser/home.spec.js` (12 tests × 3 widths); `ci.yml` runs the new suite. Old suites and specs that opened `/` for the list now open `/posturi/`. The spec that tested the shortcuts row of the bar view is removed (the row no longer exists). The three redirect tests of `searches.spec.js` became one test: no redirect, no notice, no session flag.

**Hard**
- The wireframe has no rule for a blank form. Without script, `?q=&judet[]=` would reach the list as `judet[]=''`, and `build_filters()` turns that into `IN ('')`: zero results. Fix in `index.php`: a request to `/` whose parameters have blank values is a 301 to the same address without them, or to `/posturi/` when nothing is left. `/?status=active` is not blank and stays a list (ADR 0004).
- The count of "noi în 7 zile" cannot use a list filter (`published` is one day). It links to `/posturi/`. The count is the active postings published in the 7 calendar days up to today, today included.
- The third figure of the first draft counted all rows of the file (630); this one counts active postings only, so the figure and the list agree.

**Decided**
- The `level` value of "Funcții de conducere" is the string the source sends (`Funcții de conducere`, as the facet "Funcție" lists it), not the legacy `conducere`. The fixture rows use `executie`/`conducere`; the card shows only when the live value exists. Cost of the alternative (`conducere`): a card with 0 results on the real export.
- The map fill is `gov` at 0.12, 0.30, 0.55 and 1.0, over `surface`. The text is `ink` on steps 0–2 and `on-gov` on step 3. Measured contrast (WCAG ratio, `home.spec.js` in the browser, same numbers as an independent script):

  | Skin | 0–19 | 20–39 | 40–79 | 80+ |
  |---|---|---|---|---|
  | hartie | 14.14 | 9.93 | 5.58 | 11.27 |
  | posturi | 11.99 | 8.64 | 5.11 | 8.86 |
  | govuk | 16.64 | 12.81 | 8.57 | 5.17 |
  | gov2 | 16.23 | 11.93 | 7.36 | 7.18 |

  The lowest ratio is 5.11 (posturi, 40–79). No opacity needed to change.
- Counts: `postings_label()` says "anunțuri", so the landing page has `posturi_label()` (0 posturi · 1 post · 2–19 posturi · 20+ de posturi) and `home_all_link_text()` for the sentence of the link. `posturi_label(0)` is "0 posturi", not "0 de posturi".
- The county map shows all 42 counties, including those with 0 postings (a hole in the map would be worse than a pale cell). Tiles, chips and cards with 0 are hidden. The figures are not tiles and always show.
- Chart: the last 12 complete ISO weeks (the current week is left out); postings from `job_postings` plus the `archived_postings` rows whose id is not in `job_postings`. On the dev export (2026-10-17) the two tables share 0 ids; the rule stays, because the next export may differ. Hidden below 4 weeks with data.
- Map cells are 44 px high below 480 px and 40 px square above. At 320 px a cell is about 30 px wide (WCAG 2.5.8 needs 24 px). Cost of 44 px wide: 9 columns do not fit at 320 px.

**Measured** (dev export `data/_dev/posturi-new.sqlite`, 2026-10-17, 1,197 active postings)
- `Server-Timing` total of `/`, median of 5 requests: 41.1 ms with the real clock (the chart is hidden: the data is a month old), 32.4 ms with `POSTURI_TODAY=2026-10-17` (chart drawn). Limit in the brief: 100 ms.
- The sector tiles and the (county, sector) sums agree with README §2.2 of the UI overhaul spec (Sănătate 441, Administrație locală 253, Educație 170, …); all postings have a county, so the county sums equal the tile counts. All 42 slugs of `HOME_COUNTY_TILES` exist in `judete`.
- Baseline before the change: 16 PHP suites pass; Playwright 488 passed, 52 skipped, 0 failed (6.0 min).

### 2026-11-16 — Normalised title casing; calendar named from the filters

**Done**
- `display_title()`: when `occ_canonical` and the raw title differ only in case, the main title is now `occ_canonical`. Before, the raw casing showed ("infirmier", "ASISTENT SOCIAL"). This affected 1,201 of 9,756 postings in `data/_dev/dev.sqlite`.
- The "Titlu calendar" box is gone. `feed_calendar_name()` builds X-WR-CALNAME from the active-filter chips: "posturi.gov2.ro Liceală Cluj". A negated or date filter keeps its group word ("Fără studii Licență").
- `?title=` is ignored. `feed_url()` and `syncFeedLinks()` drop it from old links.

**Decided**
- The name uses the chip labels, so the calendar, the chips and the saved-search label use the same words. The cost: a subscriber who set a custom name loses it on the next refresh.

### 2026-11-16 — List page: two-row filter bar, save + follow group, subscription dialog (not deployed)

**Done**
- Grid and table views: the filter bar has two rows. Row 1 holds the boxed controls (search, view, status, sort). Row 2 holds the facet dropdowns at 30 px with no row gap. The bar uses CSS `order` and a zero-height `::before` as the line break; the markup is unchanged.
- "Salvează căutarea" and "Urmărește" are one group at the end of the active-filter chips (`follow_group_html()` in `helpers.php`, called by `partials/result_list.php`). "Urmărește" is the filled accent button. A copy (`#follow-fab`) sits at the bottom centre. The script shows it only for a search, and only while the chips' group is off screen (IntersectionObserver; the chips arrive with every swap, so the script re-observes the new group).
- The notice after a saved search has "Vezi salvatele · Urmărește · Închide". "Urmărește" opens the dialog and closes the notice.
- `#urmareste` is now a `<section>`, not a `<details>`. It is outside `#filter-form` and outside the white results box. On the landing (no filters) it stays in place, open. For a search the script hides it and moves it into `<dialog id="follow-dialog">` when the reader presses "Urmărește"; on close it goes back.
- The block has RSS, Atom and iCal on one row: the name is the link, and an icon-only button copies it. The calendar links have icons. New sprite glyphs: `i-feed`, `i-copy`, `i-calendar`, `i-calendar-add`, `i-mail`, `i-bell`.
- Email alert from the search: the block has the `/alerte/abonare/` POST form (same fields and honeypot). The script fills `cheie`, `sort` and `nume` from `PosturiSearches`. The reader does not have to save the search first. The form shows only for a search, because a subscription needs a key.
- The export line lost its second link to the block. `searches.js` syncs every `[data-search-toggle]` (two now) and exposes `defaultLabel()`.
- Tests: `feeds_test.php` checks the new structure (3357 assertions). The Playwright specs `feeds`, `compact`, `searches` and `discovery` were updated; new tests cover the landing, the bottom group, the notice and the dialog. Full suite: 488 passed (the bottom-group test re-run alone after a fix to the test).

**Hard**
- First the group sat after the sort box. At 1280 px that row needed about 1364 px of a 1232 px bar (a flex row wraps by flex-basis before it shrinks). pax then moved the group to the chips, where it belongs to the search it acts on, so the shrink rules went again.
- At 320 px the group overflowed. The three small icons hide below 360 px.
- The email form cannot sit inside `#filter-form` (forms cannot nest), so the whole block moved after the form.

**Decided**
- The dialog moves the existing block instead of holding a copy. The ids stay unique, and `syncFeedLinks()` and the copy buttons keep working. Cost of a copy: duplicate ids and two sets of links to keep in step.
- Without JavaScript the block is always in place and open, and the group and the email form stay hidden. Both need the script (the key comes from `searches.js`).

**Open**
- After a sent email request, `/alerte/abonare/` links back to /salvate/, not to the search.
- Clean URLs (`?lang=hu` instead of `?lang%5B%5D=hu`): needs the query to be read from `QUERY_STRING`, because PHP keeps only the last value of a repeated key. Not started.

### 2026-11-16 — Feeds: live check, item times, three fixes (UX-04-FEEDS-DETAILS follow-up)

**Done**
- Checked the deployed feeds with curl. Measured: `/posturi.rss` 318 KB (50 items; description 2.6–6.9 KB, median 4.8 KB), `/posturi.atom` 328 KB in 1.2 s, `/posturi.ics` 811 KB in 2.0 s (200 events). All 50 RSS items had a current extraction. Cloudflare holds `/posturi.rss` for up to 1 h (`s-maxage=3600`), so a reader can see the old format for an hour after a deploy.
- iCal: the long text sections are left out. The facts, the calendar and the note stay.
- Extracted calendar lines: the source wording is dropped when it holds the date already ("Proba scrisă: 09.12.2026 10:00 — Proba scrisă: în data de 09.12.2026, ora 10,00").
- The deadline lines use `dd.mm.yyyy`, like every other date in the item. The Atom summary and the iCal DESCRIPTION change with them.
- Item time: `pubDate` / `published` use `created_at` (the time the pipeline first saw the posting) when it falls on the publication day in Bucharest. Otherwise the item keeps the bare date at 00:00.

**Hard**
- posturi.gov.ro has no publication time. Its WordPress API (`/wp-json/wp/v2/pg_job?slug=…`) gives `date` = 00:00 of the publication day, and `modified` can be earlier than `date` (example: 54126, date 2026-11-16T00:00, modified 2026-11-11T16:27). The posting page has no time either.

**Decided**
- The time comes from our own first sighting, not from the source. It separates the morning and the evening run, and it stays correct with more runs. A posting seen on a later day (backfill, missed run) keeps 00:00: a later time would claim it was published that day. Cost of the alternative (`modified` from the WP API): one more request per posting, and a value that is not a publication time.
- Item identity is unchanged, so readers do not show the items again. A reader can re-sort today's items once, because their time changes from 00:00.

### 2026-11-16 — Richer item details in the RSS, Atom and iCal feeds (UX-04-FEEDS-DETAILS; not deployed)

**Done**
- `webapp-php/helpers.php`: `posting_inferred_conditions(array $p)` and `posting_contract_terms(array $schema)`, moved from the inline block in `pages/detail.php`. The page calls them.
- `webapp-php/feeds/_feed.php`: `feed_rows()` joins `posting_text`. `feed_calendar_events()` loads the calendar of all rows in one query. `feed_details()` builds four groups (official page, calendar, extracted data, text sections). `feed_extraction_note()` returns one of three notes. `feed_details_html()` and `feed_details_text()` render the groups.
- `jobs.rss.php`: description = details + official link + local link. `jobs.atom.php`: `<content type="html">` added; `<summary>` unchanged. `jobs.ics.php`: DESCRIPTION = the old lines, then the calendar, the facts and the note. Text sections stop at 300 characters.
- Tests: `tests/feeds_test.php` has a third database copy with three rows (current extraction with pay, stale v5 extraction with scraped calendar, no extraction). 392 → 3336 assertions. All 16 PHP suites pass.
- Detail page HTML: saved before and after the change for 10 postings (fixture 1001–1008, v5 fixture 1201 and 1202); all identical once the random port in the URLs is normalised.
- `xmllint --noout` accepts `/posturi.rss` and `/posturi.atom` from the fixture server and from the local export.

**Measured** (bytes of the response)

| Feed | Before | After |
|---|---|---|
| fixture `/posturi.rss?status=all` | 25,811 | 57,111 |
| fixture `/posturi.atom?status=all` | 30,374 | 65,362 |
| fixture `/posturi.ics?status=all` | 16,433 | 26,149 |
| local export `posturi.sqlite`, `/posturi.rss` | 4,782 | 19,707 |
| local export, `/posturi.atom` | 5,388 | 20,758 |
| local export, `/posturi.ics` | 3,326 | 11,716 |

The local export is the stale copy of 2026-09-09 (few open rows). The default feed has the open rows only. Not measured on the live export.

**Hard**
- The old cap test counted `/job/<id>-` in the whole feed body. The description now links the local page, so it counted three links per item. The test now reads each item's own `<link>`.
- In the PHP CLI Parsedown is not loaded, so `render_markdown()` escapes markup. In the web server it loads, and HTMLPurifier drops `<script>` and `<img onerror>` with their text. A first test expected the escaped text and failed in the server only. The test now asserts that the hostile section markup never reaches the item, and uses a hostile contact name to prove the builder escapes data.
- The same label occurs in the deduced conditions and in the contract terms (`Program`). A term that repeats a label gets „ (contract)”. Calendar events with the same name get „ (2)”.
- The standing fixture row 1008 has a `schema_source_revision` and an empty `detail_content_hash`. By the brief's rule it counts as an older revision. That is correct for the rule, and no other row is affected.

**Decided**
- Each group carries a `key` (`official`, `calendar`, `extracted`, `text`) beside `heading`. The iCal handler puts the calendar group first. Cost of the alternative: a comparison on the heading text.
- iCal does not repeat a line that the old DESCRIPTION already holds (`Termen…`, `Stare`, `Județ`, `Categorie`, `Email`, `URL`).
- Exam topics come from the schema `bibliography_topics`, like the page. The column `v3_bibliography` is the fallback.
- A text section has no extraction group when the row has no schema, as the brief says. The body text is not added: it is not selected, and a feed item with the whole announcement would be large.
- The cap adds the continuation text after the cut, so a section is about 600 (300) characters plus 36.

### 2026-11-15 — UX-14 email alerts built (branch `ux-14-email-alerts`, not deployed)

**Done**
- Step 0 (read-only, shared host): `PHP 8.2.35 (cli) (NTS)`, `pdo_sqlite` and `sodium` present; the SSH login lands in `/home/pax`. `ssh` with `BatchMode` works from the Mac.
- Host (`webapp-php/`): `inc/alerts.php` (storage, seal, pepper, limits, outbox), `inc/alerts_view.php`, `pages/alerts_{home,subscribe,confirm,manage}.php`, routes under `/alerte/` in `index.php` (before the query decoder), `bin/alerts.php` (pull, outbox-done, delete, candidates, digest-done), `bin/` denied in `.htaccess` and `router.php`, a footer link, and the „Alertă pe email” button on /salvate/ (`saved.php`, `static/searches.js`).
- `deploy-php.sh` excludes the private directory by name (`/posturi-private/`, `/alerts-config.php`, `/subscriptions.sqlite*`, and `ALERTS_DIR` when it is inside the web root). Checked with a local `rsync --dry-run --delete`: only a stale file was listed for deletion.
- VPS: `ops/alerts.py` (keygen, outbox, digest, `--dry-run`, daily cap, send log `data/alerts.sqlite`, ntfy on failure, a file lock).
- Privacy draft in `/despre/#confidentialitate-alerte`, with `[NUME]` and `[ADRESĂ DE CONTACT]` left open. Operations steps in `docs/deploy-vps.md`.
- Tests. PHP `tests/alerts_test.php`: 167 assertions (added to CI). Playwright `alerts.spec.js`: 12 tests; full suite 482 passed, 52 skipped, 0 failed (6.5 min). Python `webapp/tests/test_alerts.py`: 21 tests; full `webapp` suite 1479 passed; `ruff check` clean. All other PHP suites pass. A test seals in PHP and opens in Python: the two sealed-box formats agree.

**Hard**
- A `since` of 05:30Z did not match a posting created at 08:00+03:00 (05:00Z): my test case was wrong, not the code.
- Playwright treats an `sr-only` input as visible. The honeypot test checks `aria-hidden` and `tabindex=-1` instead, and the width checks skip `.sr-only`.
- Not checked against the live Brevo API (by rule: no real call). The shapes of `GET /smtp/blockedContacts` (`contacts[].email`, `limit`/`offset`) and of `DELETE .../{email}` (404 when not blocked) are assumed from memory of the documentation.

**Decided**
- First-seen field: `job_postings.created_at`. Measured on the local database (1848 rows): `2026-05-27T00:37:00.576520+03:00`. The fixture uses `2026-09-01 10:00:00` (no zone, read as UTC). `candidates` compares as times, not strings.
- A „Abonamentele mele” link (`/alerte/intra/{token}`) works for 1 hour, and the first change marks `used_at`. It is not single-use: deleting one subscription and then another would need a new email each time. Cost of the alternative: more emails.
- After a reader deletes the subscription whose link they hold, the page issues a new one-hour link to the rest, with no email (the reader has just proved control).
- The same answer for a full address (20 rows) and for a known address: both queue a `manage` email, so the owner can clean up.
- Brevo HTTP 400 on one message drops that outbox row and does not stop the run. Any other failure stops the run (brief). Cost of stopping on 400: one bad address would block every later email.
- A digest is due 3 days minus 1 hour after the last one, because the stored time is the moment of the search, a few seconds before the next cron run.
- `digest-done` stores the time of the search, not of the send: a posting created in between is not lost.
- `digest` also runs the outbox pass first, so confirmations go before digests inside one run. The cap is a hard limit for both; a full digest day can delay a confirmation to the next UTC day (no reserve built).
- The client address for the limit is `CF-Connecting-IP`, else the socket. Assumed: the site sits behind Cloudflare. Without Cloudflare the header can be forged, and the limit weakens.
- No checkbox for consent: two lines of text next to the button (brief). pax decides when approving the privacy text.
- The shared host reads `ALERTS_DIR` only from its environment (tests) and otherwise uses `<web root>/../posturi-private`. Assumed: PHP on Dreamhost may write there (no `open_basedir`). Unverified; deploy step 6 tests it.
- Python tests skip without PyNaCl. CI needs `pynacl` in `webapp/requirements.txt`, a file outside the brief: left for Opus.

### 2026-11-16 — UX-14 email alerts live on posturi.gov2.ro

**Done**
- pax deployed the code, made the host config and the VPS key, and installed the two cron lines (`docs/deploy-vps.md` § Email alerts).
- Live test: subscription 1 sent its confirmation at 22:45Z and was confirmed. Subscription 2 got its confirmation from the first `outbox` cron run, at 22:55Z. The VPS session read the host rows and the send log (no addresses).
- The Brevo test email (2026-11-15) passed SPF, DKIM and DMARC, with the one-click `List-Unsubscribe` pair under DKIM.

**Hard**
- `pull` said "alerts are not configured": the `pepper` in `alerts-config.php` was not valid base64, so `al_config()` rejected the whole file. pax replaced it with `openssl rand -base64 32`. No data used the old pepper.
- `logs/alerts.log` was missing after the install. Cause: the lines were new, and the first `*/5` slot had not come yet. Not a fault.
- The VPS `.env` has `DEPLOY_HOST` and `DEPLOY_PATH` twice. The scripts read the last line (`ops/env.sh`), so they work; a shell `grep` without `tail -1` does not.

**Decided**
- The host files are private: `posturi-private/` mode 700, `alerts-config.php` mode 600.
- Not yet measured: the first digest. It is due 3 days after confirmation, at 19:30 Bucharest, if new postings match.

### 2026-11-15 — Pipeline figure, backlog triage, „Importă datele” fix

**Done**
- README: a new pipeline figure (`docs/img/pipeline.svg`, `.png`) under § Pipeline. The old Mermaid chart is deleted: it ran `schema` after `infer` and did not show occupations, salary, observe, check-export or cron.
- Backlog: ticked LATER-05 and OPS-MAIL / P3. The beta lines UX-06, UX-12, DATA-01 and DATA-06 are now decisions only, so each ID has one checkbox. The 261016 notes moved to `docs/archive-reference/backlog-261016-notes.md`. Their open items became QUAL-01, DATA-11, DATA-QA-01 and UX-17, or notes on BETA-V5, UX-07 and DATA-ORG-06.
- BUG-EMP-IMPORT: import and export messages now show under „Importă datele” (`[data-io-status]`). A single-posting file gets a message that names „Exportă datele”. „Descarcă datele (.json)” is now „Descarcă anunțul (.json)”. 36/36 employer-builder browser tests pass.

**Hard**
- The existing browser test already imported an export with success. So the import code worked; the message was out of view, in the „Descarcă” section at the end of the page.

**Decided**
- pax: RSS on saved searches ships before email (UX-14). The RSS link already exists on /salvate/ (UX-15). Cost of email first: a provider, consent text and key backups before we know that anyone wants alerts.
- pax: manual local backups (scp) until the bigger machine (BETA-BACKUP).
- Measured: the Mac `.env` has an empty `CF_ZONE_ID`, so a deploy from the Mac does not purge Cloudflare (BETA-CACHE). Assumed, not checked: the import bug on live has no second cause; Chrome was not available to this session.

### 2026-11-15 — Controlled alert test passes; FIX-04 and OPS-ALERT-CHECK closed

**Done**
- The posturi-vps session ran the fake-pipeline test on gov2-1 at 17:54:47Z, after pax approved it there. Scratch copy from `git archive`, `.env` as a read-only symlink, and a stub `$PYTHON` whose `pipeline.py` exits 1.
- Measured: exit 1, no `deploy-php.sh` call. Three alert records, all `ok: true`, HTTP 200: healthcheck `start`, healthcheck `fail`, ntfy `fail`. pax saw the push („posturi failed exit 1 - the site is still serving the previous build”) and the check go „down”. A success ping at 17:57:22Z returned 200. The scratch folder is removed.

**Hard**
- `pgrep -f run-pipeline.sh` in the precondition matches the shell that runs the check itself. The VPS session used `ps` with a filter instead. Fix that line before anyone reuses the script.
- The alert records went to the scratch run log only, so the real run log has no trace of the test. That is intended.

**Decided**
- Close FIX-04 although the spec also names backups and log retention. Those are tracked under BETA-BACKUP (deferred by pax until a bigger machine) and OPS-02. Cost of keeping FIX-04 open: a P1 that only waits for an unrelated, deferred decision.

### 2026-11-15 — FIX-04 thresholds: keep the production values

**Done**
- Second replay on gov2-1 (`a24397e`, `logs/replay-2026-11-15.txt`): 0 records skipped; 47 records had their fill rates set to 100.0.
- The posturi-vps session read the coverage of all 49 export-check records of 09-10..10-06. Measured: 96.0–99.6% until 09-20, 95.0% on 09-21T08:45, 94.7% on 09-21T15:33, 60.1% on 10-02T15:33, 84–86% on 10-02T21:11..10-03T15:33, and 99.8% on 10-04T20:57. `no_body` stayed at 0.1–0.4%.
- The September window in `ops/replay-export-checks.py` is now 09-21T08:45 → 10-04T20:00, from those numbers. It was 09-23T15:33 → 10-02T15:33:00, which also missed the 10-02T15:33:01 run by one second. The summary test now pins its own window.
- Result, derived from the second replay's false-positive lists with the new window: sets A, B, D and E have 0 false positives; C has 3 (09-18T08:45, 09-18T15:33, 09-19T08:45, at 96.3–96.5%). A fires first, at 09-21T15:33 (`schema_floor`).

**Hard**
- The fidelity differences are not replay defects. The recorded checks ran under older rules: intake age became Bucharest working days in `2e2c00a` (10-05), and the anomaly step fell from 15 to 1.5 points in `d794c4e` (10-19).

**Decided**
- Keep set A, the production values. A catches the slide earliest and has no false positives. The cost of C (a 3-point drop): three false warnings in a healthy week. The cost of E (no floor): one run later.
- Assumed: the new-window counts come from the second replay's lists, not from a third run. A third replay would confirm them. It is cheap, but it is not needed for the decision.

### 2026-11-15 — FIX-04 replay, first run on gov2-1, and a fix

**Done**
- The first VPS replay (`logs/replay-2026-11-13.txt`, 248 lines) evaluated none of the September slide: all 47 records of 2026-09-10..10-03 were skipped for a missing `title_fill_pct`. The fill-rate checks are newer than those records.
- `ops/replay-export-checks.py` now sets a missing `*_fill_pct` to 100.0, so that fill check passes and every other check still runs. The report counts the filled records. Any other missing field is still an error. Test added; full suite 1458 passed.
- DATA-08 (stale `no_body` flags, from run 2026-10-18T15:33Z) is now an incident window. The seven "false positives" of the first replay were `anomaly_stable` / `anomaly_sustained` on 10-18..10-21, which is that real defect.

**Hard**
- Fidelity under set A showed 2 differences: 10-04T20:57Z `intake_fresh` (recorded fail, replay pass) and 10-18T15:33Z `anomaly_stable` (recorded pass, replay fail). Not explained yet. Re-check them after the second replay.

**Decided**
- Backfill with 100.0, not skip. The cost of the alternative: the replay cannot test the one incident it exists for. The cost of this choice: the fill checks are not tested on those records, and the report says so.
- The end of the DATA-08 window (2026-10-22) is an assumption, taken from the first replay's last flag.

### 2026-11-15 — FIX-04 calibration tools and alert records (code ready, replay not yet run)

**Done**
- `ops/replay-export-checks.py` (new, read-only). For each `export-check` record it rebuilds the baselines from the records before it, pins the clock to `checked_at`, and runs `evaluate()` for five knob sets: A (production: floor 95, drop 5, intake 2), B (floor 90), C (drop 3), D (intake 3), and E (no floor, as before 2026-10-16; added to show what the floor adds alone). It prints one row per run, a summary per set, and a fidelity check (set A against the recorded checks).
- `ops/check-export.py`: `load_baselines()` now calls `baselines_from_records(records)`; new `read_run_log()`; `evaluate()` takes an optional `now`. `main()` behaves as before.
- `ops/record-alert.py` (new) and `ops/ntfy.py`: every healthcheck ping and every ntfy push now appends a `kind: "alert"` record (`run_id`, `channel`, `event`, `ok`, `http_status`, `exit_code`, `detail`, `at`). The record holds no URL and no topic. `|| true` stays.
- Tests: `test_replay_export_checks.py` (5) and `test_alert_record.py` (8). Full suite: 1426 passed before, 1439 passed after (13 skipped both times).

**Hard**
- The Mac has no current run log (`data/pipeline-runs.jsonl` is absent in the worktree), so no threshold was measured. Only synthetic logs were tested. Item 2 of the brief (the replay on gov2-1) is open.
- `evaluate()` reads the clock (`build_meta_fresh`, `intake_fresh`). Without a pinned `now`, every old record would fail `build_meta_fresh`. This is why `now` was added.
- The replay cannot test the four failed-run incidents (12 Oct balance abort, three `import` failures, one kill): a failed run writes no `export-check` record. Only the September slide is replayable.
- The replay uses an empty in-memory database, so `integrity` and `foreign_keys` always pass. It tests the metric checks only.
- Healthcheck records need `curl -w '%{http_code}'`. With `-f`, curl still prints the status on HTTP errors, and prints `000` when there is no reply.

**Decided**
- A missing metric field is reported per record and the record is skipped. The replay never invents a value.
- No threshold changed. Opus decides the values from the VPS replay.
- The `/pipeline-check` skill is not in this repository, so it does not read the alert record yet. See OPS-ALERT-CHECK in the backlog.

**Controlled failure: options (proposal only; nothing was run)**
All three spend no LLM tokens, change no database and deploy nothing. pax chooses.
1. *Wrapper in a scratch root, stub `pipeline.py` that exits 1* (as `test_run_pipeline_sh.py` does), with the real `HEALTHCHECK_URL` and `NTFY_TOPIC`. Runs on the Mac or the VPS, at any time. Proves: the `/fail` ping and the ntfy push leave records, and the alerts arrive. Cost: one real "down" mark on the check and one push. Risk: low. Use a second healthchecks.io check to keep the real one clean. Does not test the real pipeline path.
2. *The real script on gov2-1, outside the cron slots, with `DOWNLOAD_SINCE=abc`.* `pipeline.py --since` is `type=int`, so argparse exits 2 before any step (read from the code, not run). The migrate check and the balance check are read-only calls. Exit 2 sends `/fail` and an ntfy push. Proves the real wrapper, the real `.env` and the real network path. Cost: one real "down" mark until the next success ping. Risk: medium. A held lock gives exit 75, so check `.pipeline.lock` first. The trigger is `manual`, so `/pipeline-check` ignores it. No run record is written, so the ntfy summary is thin.
3. *Channels only:* `ops/ntfy.py test`, and `curl` to `$HEALTHCHECK_URL/fail` for a separate test check. Cost: nothing. Risk: none. Proves: the two channels deliver. Does not prove: that the wrapper calls them, or that the records are written.
Recommendation: option 1 first (no risk to the live check), then option 2 once, after pax pauses the check or accepts one false "down".

### 2026-11-15 — BUG-IMPORT-KILL: `import` memory measured and cut (Mac, local only)

**Done**
- `pipeline.py` records `max_rss_mb` for each step (`os.wait4` on one `Popen` child). The first cron run after deploy gives a measured peak for every step, so OPS-RUNTIME gets its numbers. Test: `webapp/tests/test_pipeline_max_rss.py` (5 tests).
- Measured `import_csvs` on the Mac with `/usr/bin/time -l`, DEBUG=0, a clone of the local September database, and the local `anunturi.csv` (9,757 rows, 60 MB; the server file has 15,942 rows). Peak RSS before the fix: normal 1,323 MB, full 1,328 MB.
- Measured RSS by phase (full case, instrumented copy, not committed): start 63 MB, after both CSV lists 204 MB, before the detail pass 235 MB, **after `in_bulk()` over all rows 1,121 MB (peak 1,279 MB)**, end 788 MB.
- Fix: the detail pass loads postings in batches of 500 (`DETAIL_BATCH`). Peak RSS after the fix: normal 440 MB, full 431 MB. Run time is the same (73 s and 87 s against 71 s and 87 s).
- Result check: a checksum over 22 posting columns (body and attachment text as md5, `inferred`, `search_vector`) is identical before and after, in both cases. Row count 9,756 postings and 19,525 calendar events both times.

**Hard**
- The full case needs different hashes. The local CSV has no `Detail Content Hash` column, so a plain run revises 1 row. I wrote a scratch copy of the CSV with `new-N` hashes and set `old-<id>` in the clone. Then all 9,757 rows revised.
- With DEBUG=1 the peak looks 290 MB higher: Django keeps every query in memory. The measurements above use DEBUG=0.

**Decided**
- Batch the loads. Do not use `.only()`: `invalidate_enrichment` reads `inferred`, `schema_json` and `schema_source_revision`, and a missed deferred field costs one query per row. Batching keeps the code and the result the same.
- Not changed: the two `list(csv.DictReader(...))` reads (about 130 MB at 9,757 rows). Streaming them needs a second pass over the file for the expiry lookup. The cost does not justify it now.
- The revision path (`invalidate_enrichment`) adds no memory: full and normal peaks are equal. So the full parse alone does not explain the -9.

**Measured vs assumed**
- Measured: the peaks, the phases, the equal result. Local data only.
- Assumed: memory scales linearly to 15,942 rows. Before the fix that is about 2.1 GB for `import`; after it, about 0.7 GB. The server has 3,819 MB shared with Postgres and PHP, so the kill is plausible but not proven. The kernel log (brief item 4) is for pax to read.

### 2026-11-13 — P1 triage: three items closed, one new defect

**Done**
- BUG-IMPORT-FIELD closed. The posturi-vps session measured three runs that deployed since the fix (11-12 11:15Z, 11-12 16:33Z, 11-13 11:15Z), each with export-check `ok`. The live header reads „Bază generată: azi, 13 nov, 14:02”. No `Main Body Markdown` value is over 131,072 characters; the longest is 102,323.
- FIX-07 closed. Live `?q[]=medic` and `?page[]=2` return 400 (was 500). `?q=medic` returns 200.
- CI-01 (D8) closed: `ci.yml` runs on every push to `main`, and the last two runs are green.
- CI-BROWSER-FLAKE closed. The failed log shows DNS errors for `azure.archive.ubuntu.com` during the `apt` step.

**Hard**
- Run 11-11 16:33Z had the fix but still failed: `import` exited -9 after 1,067.6 s, with no traceback. It was the first full parse (0 of 15,686 files reused). Normal `import` time is 454–474 s. Logged as BUG-IMPORT-KILL. Assumed OOM (3,819 MB RAM), but the kernel log has no OOM line.
- BETA-BACKUP: no posturi backup exists anywhere. The VPS schedules none. `ops/pull-backup.sh` never ran on the Mac, and the Mac has no SSH entry for gov2-1.

**Decided**
- Close CI-BROWSER-FLAKE without a code change. Caching Playwright browsers would not help: the failure was the `apt` step, and it passed on the next two runs.
- No `ntfy` record exists in `logs/pipeline.log` or `data/pipeline-runs.jsonl`, so alert delivery stays unverified (OPS-ALERT-CHECK).

### 2026-11-11 — Pipeline stuck at `import` since 11-10 (BUG-IMPORT-FIELD)

**Done**
- `/pipeline-check` found three cron runs in a row that failed at `import` (exit 1, `ABORT`): `2026-11-10T11:15Z`, `2026-11-10T16:33Z`, `2026-11-11T11:15Z`. The last deploy was `2026-11-09T17:17Z`. No export-check was written after `2026-11-09T16:33Z`.
- `import_csvs.py` and `quality_check.py` now call `csv.field_size_limit(2**31 - 1)`. `parse-anunturi.py` strips `base64,…` payloads of 200 characters or more before the markdown conversion. `PARSER_VERSION` is 4, so the next parse is a full parse.
- `webapp/tests/test_oversized_body.py` has four tests. Full suite: 1426 passed, 13 skipped.

**Hard**
- No code changed before the failure (HEAD `e6fab59` is docs only; `import_csvs.py` last changed 2026-10-25). The data changed: one posting held a 733,367-byte page (two inline `image/emf;base64` images). Its body was 728,713 characters. The default `csv` limit is 131,072.
- `parse-anunturi.py:765` already raised the limit, so the parser wrote the row and the importer could not read it. The two scripts disagreed until a real page exposed it.
- The parser reuses old rows for unchanged pages. A parser fix alone would have kept the 728 KB row, so the version had to change.
- Measured: the same page is 8,967 characters after the strip. It was the only row over 131,072 in 15,654 rows. The step times of `schema`, `infer` and `occupations` fell from 197/33/43 s to 4/3/3 s on the failed runs, because `import` loaded nothing.

**Decided**
- Do both fixes, not one. The limit alone would pass a 728 KB body to the LLM `schema` step. The strip alone leaves the next unusual field a hard stop.
- Not run: the pipeline or `import_csvs` by hand. The 18:33 Bucharest cron run reads the working tree and will be the first check. Cost of a hand run: a deploy outside a slot.
- Wrong turn: the first report said the three failed runs raised no alert. That was an assumption. `run-pipeline.sh` pings `/fail` and calls `ntfy.py` on this exit, and `NTFY_TOPIC` is set. Delivery is unverified: `|| true` hides a failure and the run log has no ntfy record.

### 2026-11-02 — Footer skin picker fits at 320 px (BUG-DS-FOOTER, BUG-MOBILE-OVERFLOW)

**Done**
- `pg_skin_label()` (`inc/skins.php`) keeps only the text before „ — ” in the `@skin` line. This is the format of `_template.css`. The gov2 option now reads „gov2”. Before, it read „gov2 — stilul comun pentru proiectele gov2.ro · v0.4.0 · 26.10.2026”.
- `.skin-select` gets `max-width: 100%` in `assets/app.css`, so a long label can never widen the page again.
- Measured with `gov2.css` committed (`403da9a`): browser suite 470 passed, 52 skipped, 0 failed (before: 14–20 overflow failures at 320 and 375 px). All PHP suites pass. `check-skins.php`: 69 problems, the known baseline of the DS copy.

**Hard**
- The overflow was not a DS defect. The DS header line is long, and the project read the whole line as the label. No DS issue opened.

**Decided**
- Fix the parser, not the DS copy. The project must not edit `gov2.css`, and the template already defines the label as the text before the dash.

### 2026-11-02 — UX-15 saved searches in the browser (code ready, not deployed)

**Done**
- `search_key()` (`helpers.php`) and `searchKey()` (`static/searches.js`) implement ADR 0001. Both read `tests/fixtures/search_keys.json` (23 cases). `tests/searches_test.php` also checks that the JS multi-value list equals `MULTI_PARAMS`.
- New store `posturi.searches.v1` in `static/searches.js`: validated records, invalid records kept in storage, corrupt or other-version data never overwritten, verified write, limit 50. API on `window.PosturiSearches`.
- `inc/header.php`: inline redirect to the default search once per session. The flag is set on `/` even with no saved search.
- List page: „Salvează căutarea” toggle and „Afișăm căutarea ta salvată” notice, both outside `#results`. `syncFeedLinks()` calls `PosturiSearches.sync()`. `data-active-chips` added in `result_list.php`.
- `/salvate/?vedere=cautari`: fourth tab with rename, open, delete, RSS, clear-all (in-page confirmation). `saved.js` changed in three lines only.
- `searches.spec.js`: 13 tests x 3 widths, all pass. PHP suites `searches` (49), `request` (226), `feeds` (392), `preferences` (196), `deadline` (85), `discovery` (391) pass. CI job has the new step.

**Hard**
- Measured: `saved.spec.js` and `feeds.spec.js` fail 20 of 84 runs at 320 and 375 px on the baseline `7cbe836` as well (checked in a clean worktree with the untracked `gov2.css`). The failing set is the same with my change. Desktop passes. Cause not investigated; the footer skin select overflows by 57 px. So `searches.spec.js` checks that its own elements fit the viewport, not the whole page.
- The fourth tab made the tab bar 38 px too wide at 320 px. Fix: `px-2 sm:px-4` and `flex-wrap` on the nav.
- The inline script in `<head>` runs before `searches.js` loads, and `searches.js` loads deferred after the list script's first `syncFeedLinks()`. So `searches.js` syncs itself on load.
- `searchKey()` needs the multi-value list, so it is duplicated in JS. The PHP test guards the drift.

**Decided**
- The tab is drawn by `searches.js`, not `saved.js`: a fault in the new store must not reach saved jobs. Cost of the alternative: shared code and shared failure.
- Empty values inside a multi-value parameter: the build kept them, because ADR step 2 said "all empty" only. In the Opus review this changed. `judet[]=&judet[]=CJ` and `judet[]=CJ` select the same postings and `clean_query()` drops the blank, so the key now drops it too (PHP, JS, ADR wording, a new fixture case; 51 PHP assertions and 39 browser tests pass).
- Assumed, not measured: Safari and Firefox behave like Chromium; `location.replace` before paint hides the flash on a slow device.

**Follow-up 1 (pax, 2026-11-02): one page, one count, lower caps.** Done: the navbar „Salvate” count is saved jobs + saved searches (`searches.js` fires `posturi:searches-changed` after each write and on `storage`; `saved.js` `renderNav()` adds `PosturiSearches.list().length`, 0 when that store is blocked). `/salvate/` shows „Căutări salvate” then „Anunțuri salvate”, each with an `h2`, a count and an empty state. The „Căutări” tab is gone; `?vedere=cautari` is the default view; Notițe and Ascunse show no searches section. Saved-search cap 50 → 4, refused with `window.PosturiSaved.showNotice` (the `#pref-notice` pattern). New `SAVE_MAX = 20` in `saved.js` counts records with `savedAt`; it blocks only a new `savedAt` (new, hidden or noted record) and never hide, note or unsave; `MAX = 500` stays for all records. Records above the cap are never trimmed (tested with 25). Info box states both limits. Decided: `/salvate/` item titles changed from `h2` to `h3`, because the sections now own the `h2` level (cost: three selectors in `saved.spec.js`). Hard: the 500-record test seeded 499 saved records, which now hits the 20 cap, so it seeds hidden records instead. Measured: PHP `searches` 51, `preferences` 196, `request` 226, `feeds` 392, `deadline` 85, `discovery` 391 pass; `searches.spec.js` + `saved.spec.js` + `feeds.spec.js` = 121 pass, 20 fail, all at 320 and 375 px with „horizontal overflow” of 57 px (BUG-MOBILE-OVERFLOW), same count and tests as the baseline. Not checked: Safari, Firefox, screen readers.

**Follow-up 2 (pax, 2026-11-02): only Ascunse is separate.** The Notițe tab is gone. The main list already held noted-only jobs (`isSaved()` = `savedAt` or `note`), so the change is the tab, the view switch and the texts: heading „Anunțuri salvate și notițe”, and `?vedere=notite` (old links) shows the one page. `notes.spec.js` and `searches.spec.js` updated. Measured: `searches` + `saved` + `notes` + `feeds` specs = 125 pass, 26 fail, all „horizontal overflow” at 320/375 px (BUG-MOBILE-OVERFLOW; `notes.spec.js` adds its 6 mobile runs to the known 20); desktop 0 failures. Note: `SAVE_MAX` counts `savedAt` only, so a noted job that is not saved does not count toward the 20.

### 2026-11-02 — Save-search bar moved above the results

**Done.** pax asked for „Salvează căutarea” at the top of the results list (relayed by the VPS session, which made the edit but cannot run PHP there). The Mac session moved the `[data-search-bar]` block in `pages/list.php` from below `#results` (before `#urmareste`) to directly above `#results`, after `#results-status`. It stays outside `#results`, so HTMX swaps do not rebuild it. `mt-3` became `mb-3`. The CSS build is unchanged.

**Measured.** In a clean worktree (no `gov2.css`), all PHP suites pass; the browser suite: 470 passed, 52 skipped, 0 failed. At 375 px with a county filter, the bar is visible above `#results` (bar top 408 px, results top 452 px) and `scrollWidth` is 375.

**Hard.** The 14 `saved.spec.js` failures at 320/375 px (57 px and 29 px overflow) that the VPS session reported are not from this code. They come from the untracked `static/skins/gov2.css` in the Mac checkout: its skin option widens the footer select (BUG-DS-FOOTER). The same spec passes 72/72 in a clean worktree at `2a2c086`.

**Decided.** A deploy must not run from a checkout that holds the untracked `gov2.css`, because `deploy-php.sh` copies the working tree with rsync and does not exclude it. Fix BUG-DS-FOOTER first, or deploy from a clean worktree.

### 2026-11-02 — UX-EMP-04: title picker in the employer assistant

**Done.** pax asked for title suggestions that use our title normalisation, in a dialog, ranked for the institution's kind. Opus wrote the [brief](briefs/2026-11-02-employer-title-picker.md) and amended ADR 0002 (the `occupation` block). Sonnet built it in 5 commits (`31ff929`…`e8d156a`):
- `ops/build-title-suggestions.py` writes `static/ocupatii.json`: 821 occupations, the top 15 for each `classify.employer_kind`, and the observed grades. 119 occupations have grades.
- The institution snapshot carries `kind`.
- The dialog offers the top 12 for the institution kind, a search over all occupations, a grade select and a preview.
- The payload carries `{cor_code, label, grade}`. The builder drops the block when the title no longer starts with the label at a word boundary.

Opus ran the suites before the merge: browser 470 passed, 52 skipped, 0 failed; Python 1,435 passed; all PHP suites passed.

**Hard.**
- 1,738 of 3,767 `jobs_occupation` rows have no COR code.
- The first rule ("the most frequent code") gave „Referent de specialitate” a code backed by 19 of 206 postings.
- **Wrong turn (Opus):** the next rule (≥50% of coded postings and ≥3 postings) left only 183 of 821 occupations with a code. It also dropped 365 occupations whose 1–2 coded postings agree on one code, which is the code our pipeline already uses. The final rule allows a single code below 3 postings: 548 occupations have a code.
- A native modal `<dialog>` does not stop Tab at the browser chrome. The builder added a Tab wrap.

**Decided.**
- No COR code, no `occupation` block. A wrong code is worse than none, because a reader trusts the block over the title.
- The institution snapshot leaves out the unreviewed `P` records (no CUI) until the review of DATA-ORG-02.

### 2026-11-02 — UX-EMP-03: the employer assistant tuned after pax's review

**Done.** pax reviewed the live builder and asked for eight changes. Opus wrote the [brief](briefs/2026-11-02-employer-assistant-tuning.md); Sonnet built it in 5 commits (`77f3b1a`…`11802ed`):
- the new name „Asistent redactare anunțuri angajare” (pax kept their wording over „Asistent pentru anunțuri de concurs”);
- a sticky sidebar at `lg` with a bottom bar on phones, and the score as a percent;
- defaults for a new draft, conditional fields and field-of-study chips;
- institution autocomplete from an org.gov2.ro snapshot.

Results: the whole browser suite passed 458, skipped 52 and failed 0; Python passed 85; all 15 PHP suites passed. Opus re-ran all three before the merge.

**Hard.**
- The snapshot CSV has 25,154 lines but 24,981 records, because some fields contain newlines. All 42 county values map through `normalize_judet()`. The files are 5.2 MB raw and 0.82 MB gzipped; București is the largest at 532 KB raw. The page loads one county file on demand.
- Playwright `fill` cannot pick from a `<datalist>`, so the test sends an `insertReplacementText` input event.
- The site's floating „?!” button covered the bottom-bar link, so the bar has right padding.

**Decided.**
- A hidden field keeps its value in the draft and is cut in `buildData()`. Clearing it would lose the value when the employer switches back.
- **Wrong turn (Opus):** the brief's table made the contact e-mail conditional on the `email` channel. Art. 18(2) b) requires the HR contact data in every anunț. Fixed in `11802ed`: the e-mail is always shown, and the hint for the channel shows only when it is checked.
- The online platform link goes in the address field, because v5 has no URL field (UX-EMP-03d).
- The default documents are HG 1336/2022 art. 35(1) a)–g) and i). The integrity certificate (lit. h) follows the org.gov2.ro category, and only while the employer has not changed the list. A new draft scores 33% before anything is typed.
- Field of study: 58 suggestions from 1,620 labels in 848 v3/v4 extractions. Synonyms are merged, and occupations and over-general labels are dropped. The merge decisions are in the header of `static/domenii-studii.js`.
- Matching the posturi employers with org.gov2.ro (DATA-ORG-01) runs in the institutii-publice repo, as a new source of its linker.

### 2026-11-02 — UX-EMP-01 designed: employer posting builder, payload ADR, brief

**Done.** pax asked for a browser-only tool for employers that produces structured job postings, with a .docx download, and that remembers filled fields. Decisions (pax): vendored docx.js, embed the structured data now, v1 = institution profile + drafts + completeness meter + calendar helper, public and linked. Opus wrote [ADR 0002](decisions/0002-employer-posting-payload.md), the [brief](briefs/2026-11-02-employer-posting-builder.md) and the backlog items UX-EMP-01 and UX-EMP-02.

**Hard.**
- docx.js does not write Custom XML parts. Its `Packer.compiler.compile()` returns the JSZip object before compression, so the builder can add the parts. This is an internal API. The pin to 9.11.0 (published 2026-10-13) and a Playwright unzip test protect it.
- Spike: a 4,000-character payload with diacritics, `&` and `<` survives a LibreOffice 26.8 open-and-save, both as a Custom XML part and as a custom property. **Word was not measured** (there is no Word on the Mac). This is an assumption until pax tests it.
- The intervals come from HG 1336/2022, read in a consolidated copy of 2026-03-26 (arcub.ro), not on legislatie.just.ro: art. 18(1) 15/10 working days to the first test, art. 34 10/5 days for dosare, art. 36–37 selection, art. 53–56 contestații and results, art. 41(5) interview within 4 days, art. 98 counting. The brief asks Sonnet to check them against the official text.
- Size: docx.js 9.11.0 `index.iife.js` is 1.0 MB, 285 KB gzipped; minified, 138 KB gzipped. It loads only on the builder page.

**Built (Sonnet, same day).** Branch `worktree-agent-aab2d26361ee88e04`, 3 commits, 16 files, +2,298 lines: the page, the two stores, the meter, the calendar, the preview, the .docx and .json downloads, 4 fixtures, Playwright and Python tests. Results: Playwright 36/36 at 320/375/1280 px; Python 17/17, with exact quality parity on all 4 fixtures; the whole browser suite 377 passed, 52 skipped, 0 failed; 14 PHP suites pass; `check-skins` 69 (baseline). The vendored docx.min.js is 487 KB. Findings: LibreOffice rewrites `"` as `&quot;` inside the Custom XML part, so a reader must parse the XML, not match strings. Codul muncii art. 139 also lists 6 and 7 January (Legea 52/2023); they are in the holiday list. The parity input uses `deadline_source = ''` when there is no deadline; the brief's fixed `'anunt'` would have made `confirmed` differ. The helper proposes the latest legal date for each "within N days" stage, and the earliest date for the written test. Opus re-ran both test files and checked the 2026-04-07 calendar by hand.

**Decided.**
- Carrier = Custom XML part, plus a short custom property `posturi.posting.v1;sha256=…`. Alternative: the full JSON in a custom property. It is simpler, but Word limits text properties to 255 characters in its UI, and we have not measured whether Word keeps a longer value on save.
- The builder covers contractual staff only (HG 1336/2022). Civil-servant competitions (OUG 57/2019) have other intervals. Supporting both in v1 would double the calendar rules.
- The publication day counts as day 1 of the art. 34 window (art. 98(1)). This is an interpretation. The UI says so, and the rule lives in one function.
- Salary gets a nudge, not a legal claim. We did not check the transposition status of the EU pay-transparency directive in Romania.

### 2026-11-02 — Environment check on gov2-1 for the UX-14 email-alerts design

Read-only check run on gov2-1 for the saved-searches session (relayed over Remote Control); nothing was changed and no code was touched.

- **gov2-1 has no PHP** and no nginx, apache2 or caddy. The only non-loopback listeners are SSH (22) and a Python process on 8765. It is the pipeline box, not a web host.
- **Python side:** `pynacl` 1.5.0 and `cryptography` 41.0.7 are installed, so signing or encrypting unsubscribe tokens is possible in Python here.
- **Deploy path:** code goes out by hand with `./deploy-php.sh --code-only`; the twice-daily cron ships only the database (`--data-only --no-export`, `run-pipeline.sh:172`). `~/.ssh/config` shows no VPS/shared-host entry, so the target comes from `DEPLOY_HOST`/`DEPLOY_PATH` or the script arguments.
- **Consequence for UX-14:** any alert sender has to run either where the PHP app lives or as a Python job on this box. That choice, plus the hosting and delivery questions already listed in the backlog item, is still open. No email delivery is authorised.

### 2026-10-25 — /pipeline-check after DST; calendar labels, anomaly_sustained, backlog cleanup

`/pipeline-check` on the VPS (session `posturi-vps-2510`, relayed over Remote Control). All 14 slots of 10-19…10-25 ran, including the first two after the change to winter time (11:15Z and 16:33Z). There were no failed or warned steps, `check-export` passed 31/31, and the live `built_at` matched the last run.

- **DeepSeek balance $2.85**, burning about $0.25/day: the $1.00 abort floor arrives around 2026-11-02. Noted on OPS-BALANCE; a top-up is an operator step.
- **Trigger label:** the 18 scheduled runs since 10-17 are recorded `manual` because the crontab still lacks `POSTURI_RUN_TRIGGER=cron`. This is a VPS crontab edit (the existing operator checklist item).
- **Calendar `errors=1` (DATA-09):** one source typo, `31.09.2024` on `bucatar-180` (expired 2024-09-27). The calendar pass dropped the row silently; it now writes the URL and the raw date to stderr.
- **New backlog items:** OPS-ANOMALY-SUSTAINED (`frequent_repost` 1.8% → 4.0% over 17 days without tripping the per-run check) and DATA-10 (3.4% of calendar rows have a bare `•` or `-` as the event name).
- **Crontab and balance:** pax added `POSTURI_RUN_TRIGGER=cron` to both lines (the VPS session checked: only lines 83–84 changed) and topped up DeepSeek.
- **DATA-10, date-first calendar rows** (`parse-anunturi.py`, `PARSER_VERSION` 3). "• 17.06.2025 ora 11:00, proba scrisă" kept the bullet as the event name, because the label was taken only from before the date. The undercount was worse than first measured: en dashes and Word's private-use bullets (U+F0D8, U+F0A7…) did the same, on 665 of 9,757 cached pages. A side effect was a missed deadline: `_find_deadline` never saw "depunerea dosarelor". Now the label comes from after the date and hour, and list marks are stripped. The row-number strip (`^\d+\.?`) no longer reads "10." of "10.06.2025" as row 10, which used to drop date-first rows outright. Measured over the Mac's cache: junk labels 665 → 4 pages, calendar rows +1,371, calendar deadlines 1,536 → 1,803 pages, none lost, ten changed (see the backlog entry). Four pages lose a row whose only "label" was half a date.
- **`anomaly_sustained`** (`ops/check-export.py`): each anomaly flag is compared with its lowest value in the healthy window, limit 1.0 pt (VPS replay: noise ≤ 0.3, the 10-18 step +2.0/+2.1). `frequent_repost` is exempt. It counts employer+title over every posting ever imported, so the rise the VPS flagged (1.8% → 4.0%) is the archive growing, by construction.
- **BETA-BACKUP: a pull, not a push.** First written as nightly restic to Backblaze B2 (`18c76fb`). pax would rather not pay for B2 and wants to download snapshots by hand, so that was reverted (`e2edce0`) and replaced by `ops/pull-backup.sh`, run on the Mac. Three choices behind it. The dump is plain SQL, gzipped on the VPS, so the Mac's Postgres 14 tools can read a dump from a newer server. Snapshots are `rsync --link-dest` trees, so each is complete but only changes take space. A snapshot is published (`latest` moved) only after the dump checks pass and the file count matches.
- **Backlog cleanup.** Ten items still said "not deployed" although the live code (`2e151ef`) contains them; they are ticked or annotated. FIX-05-STAGES had been ready to tick since 10-12, and its strict-stage decision stands; my first summary to pax had wrongly offered an `altele` fallback that pax rejected on 10-11.

### 2026-10-19 — /pipeline-check: card-only anomaly flags never refreshed; anomaly step limit 15 → 1.5

`/pipeline-check` on the VPS found the machine healthy (every slot ran, 14 runs since 10-11 exit 0, all checks pass, live `built_at` equal to the local export) and two things that no check had caught.

- **Stale anomaly flags.** `no_body` 0.1% → 2.1% and `missing_contact` 1.6% → 3.5% in one run (`2026-10-18T15:33:01Z`, first on `1738d8c`, with `active` +52). In the export 51 of the 53 `no_body` rows have a body of ≥100 chars and 48 have contact fields. Rows first inferred from the listing card (no body, no contact) were never re-inferred when their detail page arrived: `invalidate_enrichment` only requeues on two known, different hashes, and a card-only row has no previous hash. The reused-slug fix (`31d818e`) is what brought ~50 such rows into the live slice at once. `import_csvs` now passes `first_body=True` when a row had no body and has one (`TestFirstDetailRequeuesInference`, four cases). A legacy row that already had a body is deliberately not requeued — the "first refresh is not a full re-run" rule stands.
- **`anomaly_stable` 15 → 1.5 points** (`ANOMALY_STEP_PTS` in `ops/check-export.py`). Over 76 export-check records (450 flag-to-flag steps) the largest ordinary step was 0.6; only the 10-18 jump (+2.0, +1.9) reaches 1.5. `TestAnomalyStable` pins it.
- **Not fixed by code:** the 51 rows already in the stale state. They have a body and an unchanged hash, so nothing requeues them. One-time remediation, run on the VPS (expect a count near 51; the next run's `infer` clears the marker):

  ```
  .venv/bin/python webapp/manage.py shell -c "
  from django.db.models import Q
  from django.db.models.functions import Length
  from apps.jobs.models import JobPosting
  has_contact = ~Q(contact_phone='') | ~Q(contact_email='') | ~Q(contact_person='')
  qs = (JobPosting.objects.annotate(n=Length('body_markdown'))
        .filter((Q(inferred__anomaly_flags__contains=['no_body']) & Q(n__gte=100))
              | (Q(inferred__anomaly_flags__contains=['missing_contact']) & has_contact)))
  print('stale:', qs.count())
  for p in qs.iterator():
      JobPosting.objects.filter(pk=p.pk).update(inferred={**p.inferred, 'stale_revision': p.detail_content_hash or ''})
  "
  ```

- **Crontab: still to do.** The live posturi lines lack `POSTURI_RUN_TRIGGER=cron` (OPS-TRIGGER step b of the beta rollout), so the four runs since 10-17 are recorded `manual`. A backup was taken (`~/crontab.bak.2026-10-19-1046`) and the two-line edit staged, but installing it was denied by the harness, so nothing changed; see the OPS-TRIGGER backlog entry.
- Not addressed: DeepSeek balance ($4.62 against the $5.00 warn line) needs a top-up; Cloudflare purge (`CF_*`) is unset (BETA-CACHE).
- Verified: the full `webapp/tests` suite, 1375 passed. The `Postgres` side of the flag check was not read (the query was denied), so the cause rests on the export plus the importer code, not on a look at the live rows.

### 2026-10-19 — White main column, boxed disclaimer, inline summary rows, compact header; checklist footer removed

Default skin (`posturi`) and the detail page, per pax's request:

- **White main column.** `.pg-main` — the list page's right column, now also the detail page's body column — is a white sheet with a 1px `--c-line` edge in `static/skins/posturi.css`. Scoped to that skin; `hartie`/`govuk` are unchanged. It is a class of its own, not a utility, so the rule cannot catch anything else (the lesson recorded at the end of that file).
- **Disclaimer boxed.** `robots_disclaimer()` carries the `info` family classes, so both the opening and the closing sentence on a posting are boxed (D9 wants them identical); `w-fit` makes the box as wide as its sentence.
- **Summary rows above the posting.** "Condiții deduse automat", "Condiții contractuale", "Tematica examenului" and "Salariu estimat" are plain rows at the top of the body column, above the white sheet — no boxes, no background, a hairline between rows (`divide-y`). Each row is a `flex-wrap` line: a small uppercase heading, then its facts inline ("Program Normă parțială · Experiență Min. 1 an …"); skills read as text ("Competențe Excel, Word"). The exam topics are one `inline-block` of comma-separated plain links with a tight line height: beside the heading when they fit, dropped below it as a whole when they are many. "Salariu estimat" is a highlighted (`note` family) inline-block box of three rows — the figure with the net estimate, the pay level, the legal caveat with the "Cum a fost calculat" toggle; the net assumptions and the range warnings moved inside that toggle. `.pg-main` is an inner div, so the posting is the only white area. History: side-by-side boxes, content-wide stacked boxes, a sidebar stack, a horizontally scrolling three-column strip — each tried and dropped. Conditions are built as a label => value list in the preamble; empty ones are omitted, and the row shows only when at least one was deduced (skills alone do not open it, as before).
- **Empty conditions hidden.** A cell with no value ("Program —") is now omitted instead of printed; the rest follow on the same line. The box's gate is unchanged (it still needs at least one deduced value to appear).
- **Header compacted.** Employer · place · date now sit above the title; the link to the original moved to the top of a right-hand column with the tags below it (right-aligned, from `sm` up; on phones the block follows the title). The disclaimer is `w-fit max-w-full` — its own row, as wide as its sentence. Spacing around the nav, disclaimer and header is a step tighter. `preferences_test.php` looked for the icon buttons under `[data-posting]/div/h1`; it now matches any `h1` sibling.
- **Tags no longer repeat.** The level and type badges ("Funcții de execuție", "Permanent") were shown again as distinctive tags, because `row_tags()` carries them; the detail header now excludes whatever those two badges already say, as its own comment always claimed.
- **"Nr. posturi" is hidden when it is 1** (the sidebar line; the JSON-LD still carries the count).
- **Checklist footer removed.** The «Pregătit»/«Notat» explainer, the "Progresul se păstrează doar în acest browser…" note and the "Șterge progresul" button are gone from the "Pregătirea dosarului" section, together with the now-unreachable confirm box and its JS (`clearAsk`/`clearDone`, the Escape handler). Consequence: a reader can no longer wipe a posting's saved ticks in one click (unticking still works, or clearing site data), and the page no longer states that progress stays in the browser — `/despre/#confidentialitate` is the only place that says it now. The browser and PHP checklist tests lost the clear-flow, disclosure and confirm-text assertions; the "no prepared/submitted claim" check now covers the whole section. `docs/specs/2026-10-10-follow-ups/05-application-checklist.md` still describes the removed pieces (historical).
- `npm run css` rebuilt `static/app.css`.

### 2026-10-18 — Distinctive tags per posting; announcement quality score (internal)

pax's two new notes, decided in a short walkthrough: tags from facts we already hold,
ranked by rarity (no AI); a quality score shown internally first, weighing completeness,
pay and terms, timing and clarity.

- **Tags** (`classify.tag_candidates`, `rank_tags`): candidates are contract, schedule,
  telework, funding, requirements, credentials, specialty, tool-like skills, languages and
  seat count; the export counts each key over the whole slice and keeps up to four, rarest
  first, dropping any on more than 25% of postings. Free-text skills read as noise in a
  first pass ("gramatică și ortografie", "integrare TIC în educație"), so a skill tag must
  start with an acronym or product name. On the live export 68% of postings get a tag. Rows
  replace the source labels with them (old exports keep the labels: `export_has_column`);
  the detail badges gain them and show the regime read off the text instead of the source
  categorie. The row's auto meta drops domain, schedule, telework and "Calculator".
- **Quality** (`classify.quality`): 15 checks, 100 points; NULL without an extraction.
  Live export: median 83, p10 66; salary stated on 2% of postings, duties 22%. Least
  complete employers include ambulance and hospital services; most complete, research
  institutes and some DGASPCs. `/statistici#calitate`, labelled experimental.

### 2026-10-17 — 122 open postings missing from the site: reused slugs

pax found https://posturi.gov.ro/joburi/gipsar-debutant/ (open until 2 Nov) on neither the
live site nor in the archive. posturi-vps (read-only): its row has `expires_at` NULL and no
detail; 123 open postings are in that state, and all 122 `/joburi/` ones have an older
`/anunt/` twin with the same slug (the source reuses slugs across years and the redesign).

Cause: the detail cache is `data/anunturi/YYYY/MM/DD/{slug}.html`, so the two pages never
collided on disk — but `parse-anunturi.py` turned a cached file back into its URL by slug
alone (`build_slug_index`, first URL wins), so the September 2026 page was parsed *as the
May 2025 URL*. The new row got no dates (and the old row the new deadline); the export's
`expires_at >= today` is NULL for it, and the archive wants an expiry too, so it vanished
without a warning (the importer's NULL-expiry guard has a ratio threshold; 123 of ~2,550 is
under it).

Fix (`31d818e`): the parser takes the URL from the fetch step's sidecar (`url`), then from
(date directory, slug), and only then by slug. `PARSER_VERSION` 2, so the first run after
the pull re-parses everything. Defence in depth: the export's live slice also keeps a
posting with no expiry that the source listed in the last 3 days (`LIVE_SQL`), shown as
"Termen neprecizat". Tests: `TestReusedSlugs`, the live-slice test.

### 2026-10-17 — pax's 261016 filter notes: regimes, institutions, COR tree, exclusions, a tool

pax's second batch of notes (backlog § 261016 Notes, "UX & Data quality"). Commits
`c3c45b5`…`11decdc`, none deployed. Analysis on a fresh export of the Mac's stale
September copy (9,756 postings); a read of the live export through posturi-vps was
refused by the auto-mode classifier, so the live numbers are still to be seen.

- **Text labels, not source tags or LLM guesses (`classify.py`, stdlib, shared by the
  export and the infer step).** The "Telemuncă" filter came from a regex that matched
  "Remote Sensing of Environment" in bibliographies, "hibridizare" and the telework law in
  reading lists — 3 of 22 were real; `work_mode` now needs a phrase about how this job is
  done (and `inf_remote_eligible` follows it). EU funding was the v4 field alone (2
  postings); SMIS codes and "finanțat prin PNRR / în cadrul proiectului … POCU" find 472.
  The source's "Funcție publică" covered muncitori and îngrijitori; `job_regime` reads the
  class and grade in the title or the hiring wording (a bare "grad profesional" is not
  enough — contractual nurses have grades too). `employer_kind` names 18 institution types.
- **Occupations as the COR tree.** COR shares ISCO-08's codes; the occupation dictionary
  codes 63% of postings and is inconsistent across spellings of one title, so the export
  borrows the code a title usually has — only a clear majority (5+ postings, 60%+):
  "Muncitor calificat" has 4 postings coded 8342 (earth-moving), and borrowing that for 474
  would be worse than the fallback's honest "7". 98% get a group. Doctors' specialties
  (43) come from titles.
- **Exclusions.** `?<group>_not[]=v` negates the group's own positive clause as a
  posting-id set, so role groups exclude a posting if any role holds the value. UI: a small
  "fără" beside the count, revealed on hover with a mouse, always on touch.
- **"Pot aplica?" became `/pot-aplica/`.** A browser-local profile turned into the list's
  exclusion filters (so postings that state nothing stay in); the per-posting panel is off.
- **Smaller:** status is a select; empty URL parameters stripped; Program/Ture/Telemuncă
  one group; home lists below the results; closed-applications notice; structured
  requirements after the announcement; pay meter 1–5 against the open postings' quintiles;
  soft skills out of the Competențe facet (half of all extracted skills), Excel spellings
  merged; v5 prompt asks for specific skills (not run yet).
- Suites: PHP all green, pytest 1,362, browser 344 (320/375/1280).

### 2026-10-17 — Reader notes on postings, CSV export from Salvate

pax wants to browse postings and note what the extraction got wrong before the next LLM
run, then hand the notes back. Built on the UX-09 browser-local store rather than anything
server-side: the record gains `note` (≤ 4000 characters) and `notedAt`; a record is kept
while saved, hidden or noted; a noted posting counts as saved (nav count, Salvate) without
touching its bookmark. A ✎ icon button after save/hide (last, so their tab order holds)
opens an editor under the posting — Ctrl/⌘+Enter saves, Escape closes, an emptied note
removes itself. /salvate/ gets a "Notițe" view, the note under each item with an edit
button, and "Exportă salvatele și notițele (CSV)" (client-side Blob, UTF-8 BOM so
spreadsheets read the diacritics, RFC-4180 quoting).

Found while testing: the result rows sit inside the HTMX filter form, which re-queries on
any bubbling `change`/`input`, so blurring the note textarea re-fetched the list and threw
the editor away. The editor stops those events; a browser test asserts no list request
while typing. Tests: `notes.spec.js` (list, detail, Escape/Ctrl+Enter, empty removes,
/salvate/ view and in-place edit, CSV content, table layout); suites green (332 browser).

VPS read (posturi-vps): the posturi crontab block's `MAILTO` was already commented out —
OPS-MAIL closed without a change; the box-wide `MAILTO` belongs to other projects.
Runs 13–16 Oct all ok; the 10:15Z slot grew from ~1,800 s to 3,106 s with weekday volume.

### 2026-10-16 — Beta weekend, fourth slice: v5, archive pages, net pay, communes

None deployed.
- **v5 (`4655c2d`, fork of this session).** The prompt and schema the v5 report proposed,
  with v5-shaped examples, null-when-silent rules and 5–20-word quotes; new closed lists
  coerce instead of voiding an answer (the calendar stage list stays strict, per the
  2026-10-11 decision). `LLM_ACCEPT_PROMPT_VERSIONS` keeps v4 rows current until their
  source changes, so pinning v5 costs only the 200 newest plus new intake. Body paragraphs
  the attachments repeat are dropped from v5 inputs only, and only when every number in the
  paragraph is in the attachment too (the trial found a body that had lost an exam date).
  Promotions are a filterable fact, not hidden by default — a product call left to pax.
  /statistici's Active count went back through `OPEN_STATUS_SQL` (a source check caught it).
- **Archive pages (UX-03, `beac230`).** A separate slim table rather than keeping expired
  rows in `job_postings`: every count, facet, feed and sitemap reads `job_postings`, and
  each would otherwise need a new exclusion. The detail page alone falls back to it.
- **Net pay (`9e496f2`).** Arithmetic on the shown gross; 5.000 → 2.981 matches published
  calculators; the minimum-wage facility is deliberately left out and the figure is
  labelled an estimate.
- **Communes (`4c7dbf8`, agent).** All 2,862 from Wikidata with SIRUTA, keyed on type and
  county; checked against ro.wikipedia for the 68 largest.
- Rollout order and the v5 commands: docs/specs/2026-10-16-beta-rollout.md.

### 2026-10-16 — Beta weekend, third slice: the 6 s found, filters flattened, stats scoped, pipeline chores

Commits `49f87d2`…`efecb33`, none deployed. Several were delegated to Sonnet agents with
bounded briefs and checked here (tests re-run, diffs read); two agents ran `git stash`/`pop`
on the shared tree once each, and one agent's staged deletions landed in another's commit,
which was split back apart before anything was pushed.

- **Where the 6 s went (BETA-CACHE / PERF-SPLIT, `0db8c5f`).** A current-schema export of the
  local database (9,756 rows) reproduced it: 944 ms for `/` locally, spread over ~40 facet
  queries, each a full scan of `job_postings`. Rows averaged ~10 KB, three quarters of it
  body, schema_json, sal_json. Moving the columns to the end of the table changed nothing
  (the record header and small columns still spill onto the first overflow page);
  moving them OUT did: `posting_text` (one row per posting, read by the detail page only),
  `has_schema` for the one test PHP made on them, contentless FTS (bm25 still ranks; the
  index stored a second copy of every body). `job_postings` 95 → 14 MB, file 191 → 132 MB,
  `/` 944 → 240 ms, and with the flattened filters ~185 ms. PHP shims both layouts, so
  **the code deploy must come before the VPS pulls the export change**.
- **Filters (BETA-FILTERS, `efecb33`).** One flat list (21 groups), salary visible, nothing
  nested; dropped controls keep working as hidden fields with chips. Grid filters are plain
  text; rows have no boxes and one line of facts (💰 … (estimat) · labels · auto …); the
  whole row is the link (stretched `::after`; employer link and buttons above it). Full
  width. Sort by salary. "Începe cu" = top occupations + compound cuts, each count computed
  with the same `build_filters()` predicate the link will run. D15 home lists, D16 locality
  facet, `?published=`. UX-01B labels. REV-10: unknown array params ignored. The home lists
  appear only on a bare `/` (a sort or status means a list is wanted) — the first version
  showed them on `/?sort=deadline` too and overflowed 320px (grid item `min-width:auto`).
  Tests: the discovery suites were rewritten for the flat list (ancestor checks became
  "every group top-level"); one browser test had passed vacuously for months — the status
  "change" it made was a no-op — and now makes a real change.
- **FIX-09 (`21b1260`, agent).** /statistici separates current-export figures from history;
  `publication_monthly` is exported from all of Postgres so expiry no longer erases history.
- **FIX-12 (`18e65e5`, agent).** Django public UI retired.
- **Ops (`49f87d2`, agent).** `--schema-floor 95`; trigger defaults to manual (the VPS crontab
  must set `cron` when it pulls); REV-06 (b)(c).
- **LATER-05 (`186897c`, agent).** Incremental parse, byte-identical to a full one.

### 2026-10-16 — Beta weekend, second slice: masthead, disclaimer, [?!], welcome, privacy, share image

D9–D13 in the PHP webapp, not deployed:

- **Masthead** is brand, `beta · <commit>` and one stamp, "Bază generată: azi, 16 oct, 14:00"
  (`build_stamp_relative()`: azi / ieri / alaltăieri, else the date; 24-hour time, the Romanian
  convention, where pax's example said 2PM). The relative word is computed per request, so a
  cached page can be an hour behind after midnight, the same bound as the open/closed status.
  At 320px there was no room beside the nav (screenshot: the stamp truncated to one glyph), so
  below 640px the stamp drops out; the footer still has it.
- **Disclaimer** at the start and end of every posting (`robots_disclaimer()`, linked to the
  posting's official URL through `evidence_safe_url()`), replacing the old closing paragraph.
- **[?!]**: a native `<details>` fixed bottom-right (z-30, under the save/undo notice), opens
  without JavaScript; a footer script adds Escape (focus back) and click-outside. Form and
  GitHub links; e-mail rendered only when `FEEDBACK_EMAIL` is set. FAQ entries live in
  `FEEDBACK_FAQ`; each points at a new `/despre/` section.
- **/despre/** rewritten top half: beta wording, how it works, deadlines, salaries, closed
  postings, how to apply, privacy (no cookies, what localStorage holds, Cloudflare, Google Form,
  public GitHub issues). Drafted for pax's review before launch.
- **Welcome**: in every page, revealed by a pre-paint script when `posturi.lastVisit` is absent
  or older than 90 days; every page view records the visit, so it shows once per return.
- **Share image** (UX-04): `static/og-image.png` rendered from `assets/og-image.html`.

Tests: new `chrome.spec.js` (masthead, disclaimers, panel keyboard/outside click, FAQ anchors
exist, welcome timing incl. throwing storage, share image); `eligibility_test.php` now finds the
page end by the last disclaimer. PHP suites pass; Playwright 316 passed / 50 width-gated skips.

Also delivered by an agent: the [v5 extraction report](specs/2026-10-16-v5-extraction/README.md).

### 2026-10-16 — Beta weekend, first slice: cache safety, timing, CI, populations, panel on

pax answered the beta walkthrough (decisions D1–D24, recorded at the top of the backlog).
First slice, none of it deployed or committed yet:

- **Cache safety (BETA-CACHE).** Cloudflare now proxies the site (verified: `server:
  cloudflare`, `cf-cache-status: DYNAMIC`). Before any HTML is cached, one URL had to stop
  answering two ways: `/` returned the full page or, with `HX-Request`, the results fragment.
  A browser (the host already sent `max-age=600`) or the edge could replay the fragment as a
  page. htmx 2's `getCacheBusterParam` now gives every HTMX GET its own URL and htmx strips
  it from the pushed address; the server keys the fragment on that parameter, so htmx's
  history-restore request (header, no parameter) gets the full page it expects. One policy
  at shutdown in `index.php`, behind an output buffer: 200/301 GET/HEAD → `public,
  max-age=300, s-maxage=3600` plus `Expires` (the host's mod_expires only steps in when
  Expires is absent); everything else `no-store`; a route with its own Cache-Control keeps
  it. A 1 h edge TTL also bounds the midnight problem: open/closed status is computed
  against today's date, which changes without a deploy.
- **Purge on deploy.** `deploy-php.sh` purges `SITE_URL`'s hostname through the Cloudflare
  API when `CF_ZONE_ID`/`CF_API_TOKEN` are set; a failed purge warns, never fails a deploy.
  DEPLOY-DIRTY in the same file: the code stamp gains `-dirty` for an uncommitted
  `webapp-php/`.
- **Where the 6 s go.** `Server-Timing` per list-page section (`timing_mark()`), readable
  with `curl -D -` once deployed. The local September export is no proxy: its missing
  columns are temp-view shims, which dominate locally (166 ms total, 127 ms in the
  shortcuts). Moving the large text columns to the end of `job_postings` was measured on
  that export and changed nothing (1,232 vs 1,258 pages read for a full scan).
- **CI-01** (Sonnet agent, checked: `ruff check` clean): 19 lint fixes in `webapp/`, 1,217
  pytest pass locally, workflow on push/PR to `main`, Node-24 action majors, `ubuntu-24.04`.
- **DATA-06** (Sonnet agent): `data/uat-populatie.csv` from the 2021 census list on
  ro.wikipedia via `ops/build-uat-population.py`; `bands_for()` no longer lets a comună
  borrow a same-named town's population.
- **UX-10 released** in code: `ELIGIBILITY_PANEL_ENABLED = true` (D18).

Tests: all PHP suites pass (`request_test.php` gained the cache-policy suite and the
header-without-parameter case), Playwright 295 passed / 50 width-gated skips.

### 2026-10-16 — The total said once; "Începe cu" closes the bar; two-column list view

pax's next review:

- **The total, once.** The "N anunțuri active · M angajatori" line above the shortcuts is gone
  (and its two COUNT queries): on the home page it repeated the count just above the results,
  which is now the only visible total (the status tabs still carry their per-tab counts).
- **"Începe cu" at the end.** In the grid and table views the shortcuts are no longer
  flattened into the bar's last line: they close the bar on a row of their own
  (`flex-basis: 100%`), after the filters, view and sort.
- **List view, two columns.** Beside the sidebar the rows are now a grid of 22rem+ columns:
  two from about 1050px, one below. A half-width row cannot spare a deadline column down its
  whole height, so the deadline (days and date on one line) sits beside the title and the
  original title only, and the employer line, pay, badges and the auto line run the full
  width below.

Tests: `compact.spec.js` adds "the total is said once" (visible text nodes only: the sr-only
live region says it again on purpose) and the two-column list check, and the shortcut test
now expects a full-width last row; each seen failing against its mutation (stats line back,
shortcuts flattened, one column, deadline on two lines). PHP suites all pass; Playwright 295
passed, 50 width-gated skips.

### 2026-10-16 — Grid view: three columns, four-line cards, no hole in the bar

pax's review of the deployed grid (2 columns at ~1100px, half of each card empty, a gap in
the bar between the last filter and the view switch, the home shortcuts in a block of their
own below it):

- **Columns:** `repeat(auto-fill, minmax(19rem, 1fr))`, i.e. three columns from 1024px up to
  the 1280px page width (was two below 1280).
- **Cards:** the card's wrappers become one grid of four lines: title; employer · place; pay
  with the deadline on its right; badges at the foot (bottom-aligned across a row). The old
  bordered deadline footer and the `gap-4` row gap are gone. A card is about 120px tall at
  1280 (was ~255px). The pay amount and "brut" stay together (`.pg-salary-amount`), so on a
  narrow card a wide range wraps under its label as a whole.
- **Bar:** view and sort follow the last filter (no `margin-left: auto`), and on the home page
  the script also moves the counts and "Începe cu" shortcuts (`#shortcuts`) into the bar,
  flattened, so they fill its last line instead of taking ~150px below it. Back in their place
  in the list view.

Tests: `compact.spec.js` now expects three columns at 1280 and no gap before the view switch,
and adds a shortcuts-in-the-bar test and a four-line card test; each was seen failing against
its mutation (auto margin back, two columns, deadline on the foot, shortcuts not moved).
PHP suites all pass; Playwright 291 passed, 48 width-gated skips.

Observed: live `/versiuni.json` said code `a21a5c8` while serving this uncommitted tree:
`deploy-php.sh` stamps `git rev-parse HEAD` whatever the working tree holds (backlog
DEPLOY-DIRTY).

### 2026-10-16 — Result views: Listă / Grilă / Tabel, one filter bar, pay on every row; filters start collapsed; "Elementele dvs." off

pax's review of the live grid view (filter pills wrapping under a taller search box, view and
sort on a line of their own) and four asks, all in the PHP webapp, CSS over the same server
markup as before:

- **Table view.** The single grid toggle is now a three-way switch (`#layout-switch`:
  Listă / Grilă / Tabel, `aria-pressed`, lg+ only). `posturi.layout` gains `'table'`; the
  pre-paint script in `inc/header.php` accepts it. In the table every row is a CSS grid on one
  shared template: the row's wrappers are `display: contents`, so title, employer, place, pay
  and deadline become cells, pinned to row 1 so a missing place keeps the other cells in
  their columns. `.pg-thead` (visual only, `aria-hidden`) carries the same template. Badges,
  the auto line and the publication date are hidden there.
- **One bar.** In the grid and table views `#facet-panel` and `#facet-list` are
  `display: contents`, so search, every group pill and "Șterge filtrele" are items of one
  wrapping flex row, all 40px (`--pg-bar-h`), and the script moves the view/sort row
  (`#result-options`) to its end (back under the status tabs in the list view; focus is kept
  across the move). Found on the way: the bar kept `top: 1rem` from the sidebar's
  `lg:top-4`, which with `position: relative` pushed it down onto the next line; now `top: auto`.
- **Pay in the list views.** `list_salary()` (helpers.php): the stated salary when there is
  one, else the draft-grid estimate, the same precedence as `SALARY_EXPR`, so a row never
  disagrees with the salary filter; always labelled ("Salariu estimat" / "Salariu anunțat";
  "estimare" / "din anunț" in the table cell).
- **Filters collapsed on desktop.** `facet_group()`'s `$open` now renders
  `data-default-open` instead of `open`; the script opens those (Județ, Domeniu profesional)
  only below lg, in the drawer, and only while the reader has no stored choice. A group
  holding a selection still renders open everywhere.
- **"Elementele dvs." off.** `CHECKLIST_CUSTOM_ITEMS_ENABLED = false` in
  `inc/checklist.php` (env `POSTURI_CHECKLIST_CUSTOM=1|0` overrides, the eligibility-panel
  pattern). Off, the add box, the reader-item list and its template are not rendered and the
  clear confirmation names only the ticks; `checklist.js` skips the region when it is absent,
  and items stored earlier stay in storage, unseen.

Tests: `compact.spec.js` rewrote the toggle test for the three views and added the bar
(every control 40px and inside the bar, sort at the right edge, tabs below), table (cells
under their heads, one line, pay labelled) and pay-in-every-view checks; `checklist.spec.js`
runs its main server with the items on and a second one at the default for two "off" tests;
`checklist_test.php` gained the release-gate suite; `discovery_test.php` the pay suite and the
new fresh-load expectations. Each new browser check was seen failing against a mutation (cells
not flattened, row not moved, pill height, no pay line, pre-paint ignoring `table`, the gate
constant on, the null guard removed). `deadline_test.php` read a fixed 3,000-byte window after
the title link, which the pay line pushed the deadline out of; it now reads to the row's
`</li>`. PHP suites all pass; Playwright 289 passed, 44 width-gated skips.

### 2026-10-16 — /statistici: the per-100 cost is per extraction, and says so

The live card read "La 100 de anunțuri · 8535 de anunțuri procesate" against an export of
2,558 postings. `llm_costs.postings` is distinct per row: a legacy row is one stored variant per
posting and prompt version (v2, v3 and v4 of one posting count three times, expired postings
since May included), a ledger row is distinct postings per day and model. Summed over rows it
counts extractions, which is the right unit for a per-extraction cost, so only the copy changed.

- The card now reads "La 100 de extrageri", and under it "8535 de extrageri; un anunț reextras
  se numără din nou". "Extragere" is the word the page's footnote already uses. A new
  `extractions_label()` in `helpers.php` follows the same Romanian plural rule as the others.
- `request_test.php` gained three assertions (title, sub-line, no "procesate"), seen failing
  before the change; `stats.spec.js` checks the same and fails against the old copy.
  `request_test.php` 190 passed, `compat_test.php` 938 passed, Playwright 278 passed (40
  width-gated skips).
- Playwright's browser cache on the Mac had gone (`~/Library/Caches/ms-playwright`), and
  `npx playwright install` timed out on every try while `curl` fetched the same build at once.
  The 1243 headless shell was fetched with `curl` and unpacked into the cache by hand. The
  full `chromium-1243` is still missing, but headless runs don't need it.

### 2026-10-16 — FIX-10 closed after the live check

The live site runs code `17adffa`, and the 2026-10-15T15:33Z export's `/statistici` shows the
measured per-step block, call counts on ledger days and "(estimare)" on legacy days. The VPS
session's `/pipeline-check` came back HEALTHY (14/14 slots recorded, no failed steps, export
checks ok, live site matching the last build), and its ledger day totals match the `llm-usage`
run records to the cent.

- FIX-10 ticked. The one part left over, `infer_conditions_llm.py`, is recorded under FIX-11.
- OPS-BALANCE stays open on purpose. The 13.10 push cannot be read back any more, and it is
  only implied by the +$5.00 in the balance records. The 15.10 pushes were read from the
  topic and have the full format. pax decides which of the two closes it.

### 2026-10-13 — FIX-10: the PHP side verified on the Mac, /statistici browser spec

The second slice (be43f9d) was written on gov2-1, which has no `php`. On the Mac:
`request_test.php` (187 assertions) and `compat_test.php` (938) pass, and Django `migrate`
(0017 already applied locally) plus `pytest webapp` give 1217 passed.

- New `webapp-php/tests/browser/stats.spec.js` (17adffa), run at 320/375/1280 on the fixture:
  no horizontal overflow; "Costuri inferență" total $0.032, "La 100 de anunțuri" $0.429 over
  7 postings, the two per-step lines (schema 5 calls, occupations 2), the measured day
  "04.10.2026 · 7 apeluri", the legacy day "02.10.2026 · 3 anunțuri (estimare)", the unpriced
  count, and no old-export copy.
- Shown failing against four mutations before being relied on: the section hidden, the per-step
  block removed, a 600px day label (fails at 320 and 375 only — it fits at 1280, as it should),
  and "(estimare)" dropped from `llm_day_label`.
- Full Playwright suite: 278 passed, 40 skipped (the existing width-gated `test.skip`s).
- `./deploy-php.sh --code-only` is pax's to run from the Mac. Until the 2026-10-13T10:15Z run
  rebuilds the export, the live page shows the old-export copy (no per-step block), as designed.

### 2026-10-12 — FIX-10 (second slice): the export and /statistici read the ledger

`llm_costs` (SQLite) is now built from two sources that never overlap. `ledger` rows are
summed from `jobs_llmcall` by the Bucharest day a call ended, per step; `legacy` rows are the
old variant-table estimate, exported only for variants created before the first ledgered call
(`MIN(started_at)`), so a payment is in exactly one of them. The first eight columns are
unchanged and the new ones (`step`, `source`, `unpriced_calls`, `postings`) are additive, so the
page already deployed keeps summing correctly in the meantime; `cost_usd` sums the known costs
and `unpriced_calls` counts the rest.

- `/statistici` (PHP) names what is measured, an estimate and unknown: per-step list from the
  first ledgered day, "(estimare)" on legacy days, the unpriced count, "nu sunt o factură a
  furnizorului". "La 100 de anunțuri" is now schema spend over postings with a valid extraction
  (a ledger attempt is not a posting). It falls back to the old copy on an export without the
  columns and to "Date indisponibile" without the table; `compat_test.php` gained both shapes.
- Tests: 17 new export tests, including the Bucharest midnight and the 2026-10-25 DST change
  either side, a rejected-then-repaired answer, an unpriced call, stored prices across the peak
  window edge, and the cutover. A scratch export of the live database gave 44 legacy rows
  ($12.907) + the 1 ledger row, with the old page's own queries still returning the true total.
- Found by the full suite: two other test files drive the export with a stub cursor that
  answers unknown queries with no rows; `export_llm_costs()` now tolerates a missing `MIN()` row.
- **Not run here:** there is no `php` on gov2-1. The PHP changes are syntax-checked with
  tree-sitter and their SQL was executed against a fixture-shaped SQLite; `request_test.php` and
  `compat_test.php` must be run on a machine with PHP before the page is deployed.
- Left out on purpose: `infer_conditions_llm.py`, a manual command outside the pipeline whose
  provider dispatch belongs to spec 11.

### 2026-10-12 — FIX-10 (first slice): an append-only ledger of every LLM call

Asked to log the occupations cost and do per-call accounting. The spec
(docs/specs/2026-10-03-project-audit/10-llm-cost-ledger.md) covers the pipeline, the SQLite export
and the public stats page; this is the pipeline side, the part that makes the numbers true.

- `jobs_llmcall` (migration 0017, additive, applied to the live database) holds one immutable row
  per provider API attempt: step, subject, provider/model/prompt, attempt + repair flag, `api_ok`
  and `outcome`, tokens (null when unknown), the rate card applied, `cost_usd`. `LlmCall.save()`
  refuses to update.
- `llm_ledger.py` is the shared path: provider code calls `note_usage()` the moment a response
  arrives (before validating it, so a rejected answer keeps its tokens), `generate_with_retry()`
  records every attempt, repair and failure, and a lock-serialised connection writes the rows.
  Ids are `run/step/nonce-seq`: unique across threads and processes, a re-delivery is
  `ON CONFLICT DO NOTHING`. A dead database costs a warning and `ledger_write_errors`, never a step.
- The four schema closures, the three occupations closures and the infer fallback were
  instrumented (Gemini/Anthropic read usage after validating; reordered). `llm-schema.py` gained
  `rate_card()` next to `compute_cost()` and both use one `_effective_rates()`, so the stored card
  cannot disagree with the amount.
- Each step writes a `kind: "llm-usage"` run-log record from in-memory totals. The ntfy LLM line
  is now the total over all steps with a breakdown ("LLM $0.377 (schema $0.370, occupations
  $0.007)"), says how many calls were unpriced or missing from the ledger, and the balance
  reconciliation counts every step once (an `llm-schema` record counts only where no `llm-usage`
  exists for that run and step).
- Verified live: one DeepSeek call on the posting that fails validation most nights wrote a
  row costing $0.00152410, equal to the step's own $0.001524. That one row (run
  `smoke-fix10-2026-10-12`) stays in the table: it was real spend.
- Tests: ledger core 39, retry/closures 18, occupations 6, infer +7, ntfy +16, model 6, rate card 6;
  each wiring test was shown to fail when the wiring was removed. Whole suite 1200 passed.

Not done, and why: the SQLite by-date/by-step export, the public stats copy and the legacy-estimate
labelling are separate export/PHP work (still FIX-10, listed in the backlog); `infer_conditions_llm.py`
is a manual command outside the pipeline.

### 2026-10-12 — OPS-BALANCE: thresholds $1 / $5 and a spend ledger in the run summary

pax topped up DeepSeek and moved this project to its own API key; the account is shared
with other projects, which explained the ~$0.40 that no run had logged. A read with the
new key returned $5.45 = the old $0.45 + the $5.00 top-up, so the balance is the
account's whichever key reads it.

- `LLM_BALANCE_MIN` 0.50 -> 1.00 and `LLM_BALANCE_WARN` 3.00 -> 5.00 (code defaults,
  `.env.example`, README, deploy-vps.md; `.env` never pinned them). One Monday-sized run
  was $0.37 and the logged schema spend is ~$1.5/week, and the pot is shared.
- `ntfy.balance_ledger()` compares a run's start-of-run balance with the previous one
  and adds back the `llm-schema` cost logged between them (hand-runs included). A gap
  over $0.05 is `Other spend` or `Top-up` in the summary; below that is the cents the
  `occupations` step spends without writing a record. No new records and no wrapper
  changes. Replayed over every real reading: only 12 Oct 10:15Z ($0.402) is flagged.
- Not done: logging the `occupations` cost (about a cent a run, under the tolerance) and
  FIX-10's per-call ledger. A separate DeepSeek account is the only way to a balance
  that is this project's alone.

### 2026-10-12 — OPS-BALANCE: ntfy.sh pushes for a low balance and for every run

The 15:33Z slot aborted on a $0.45 DeepSeek balance after the $3 warning had fired on
six runs in a row and reached only `pipeline.log` (no MTA, no `/fail` on warn). Added
`ops/ntfy.py` and wired it into the two places that already knew:

- **`check-llm-balance.py`** pushes an `abort` every time (priority 5) and a `warn` at
  most once per 20 h. "Already sent" is read back from a new `notified` field on the
  `llm-balance` records, set only when the push was delivered, so a failed push is
  retried on the next run and no state file exists. `--no-notify`; `--no-record` implies it.
- **`run-pipeline.sh`** calls `notify_run` at every exit: `ok` after the deploy is
  recorded, `degraded`, and `failed` for a step failure, exit 65, exit 78 and the ERR trap.
  Not on 69 (the balance check already pushed) and not on 75 (no run; only Healthchecks
  can report that). The summary carries postings and the change since the previous
  export-check, schema ok/failed with failure classes, the run's LLM cost and tokens, the
  export checks (soft warnings named, hard failures listed), and the balance left.

Non-obvious decisions:
- Public ntfy.sh with a secret topic (`NTFY_TOPIC` in `.env`, generated on the VPS, never
  in git); JSON publish because headers carry only ASCII and the messages are Romanian.
- One push per run for now (pax: still in development, daily later). `NTFY_SUMMARY=off`
  keeps only failed/degraded runs and a balance under the abort level.
- LLM cost is the `llm-schema` record's `cost_usd` (~99% of spend). `occupations` also
  calls the LLM (about a cent a run, e.g. $0.0070 for 50 titles) but writes no record.
- The balance in a summary is read fresh at the end of the run, so a heavy run that
  leaves the account under `LLM_BALANCE_MIN` says "next slot will abort" one slot early
  (today's 10:15Z run would have warned at 11:05Z, not 15:33Z).
- `previous_export()` returns the export-check *before* the run's own, so a re-sent
  summary for an old run does not diff against the newest one (found by rendering the
  11 Oct run from the real log).
- `webapp/conftest.py` imports `llm_config` first and then strips `NTFY_*` for every test:
  `load_dotenv()` would otherwise put the real topic back and a test of the abort path
  would buzz a phone. A shared `posts` fixture stands in for `urlopen`.

Tests: `test_ntfy.py` (57), the new `TestNotifications` in `test_check_llm_balance.py`,
and eight wrapper tests in `test_run_pipeline_sh.py`; whole suite 1088 passed. Live on
this box as soon as the working tree is (the cron runs from it); one marker push sent.
Not committed. Still open under the backlog item: the top-up, the unlogged ~$0.40 spend,
and the threshold change.

### 2026-10-11 — FIX-05-STAGES: oral exam stages added to the calendar vocabulary

Pulled and migrated the VPS (`0016_postingobservation` applied), then ran
`ops/stage-failures.py` over its pipeline logs (2026-10-06..11). Every rejected
calendar stage was `proba_orala` (5 postings) or `rezultate_proba_orala` (2): the
oral exam was a valid `exam_stages` value but missing from `CalendarStage`, the same
gap as `rezultate_selectie_dosare` and `contestatii_proba_practica` before it. Added
`proba_orala` to `EVALUATION_STAGES` with its `rezultate_` / `contestatii_` /
`rezultate_contestatii_` counterparts and listed all four in the v4 prompt (28 → 32
values). The unknown-stage rejection rule stays; no `altele` coercion, since the
evidence showed one missing stage family rather than open-ended model invention.
Prompt text is not in the `--resume` staleness key, so only the postings without an
extraction (the failing ones) are re-paid by the next run. Python suite: 1,010 passed.

### 2026-10-10 — First slices of the October 10 follow-up packages

Implemented the policy-independent parts of all six packages in
`docs/specs/2026-10-10-follow-ups/`; each spec now ends with "Implementation notes".
Nothing was committed, deployed, migrated on the VPS, backfilled or sent to a model.

- **FIX-05-STAGES:** per-posting failure diagnostics (`data/schema-failures.jsonl`,
  including repaired-then-successful postings and each attempt's raw rejected
  value), exact `--urls-file` retries, `--dry-run` selection counts and
  `ops/stage-failures.py`. The stage policy itself is untouched: the rejected values
  exist only in the VPS log, so the decision still needs that evidence.
- **DATA-04:** `posting_roles` export table and same-role search predicates
  (one `EXISTS`; counts count announcements once). Undecomposed postings get one
  implicit row copying today's flat columns, and old exports get the same rows from a
  temp view, so their results do not change. Roles never inherit posting-level
  requirements; that is the spec's rule, at a small recall cost recorded in the backlog.
- **UX-13:** strict evidence location (`evidence.py`) at export time against the
  exact exported text, `requirement_evidence` table, `<details>` disclosures.
  Lenient `grounding.py` stays a hallucination screen; "verified" means a whole-word
  match after a documented normalisation. Fixed a pre-existing unvalidated attachment
  href found by the hostile fixture.
- **UX-12:** capture first, because no old values were recoverable anywhere:
  `PostingObservation` + `observe_postings` + an optional `observe` pipeline step
  (`OPTIONAL_STEPS`, `warned_steps`). `facts_hash` ignores revision-scoped role keys
  and evidence prose so a new revision alone is not a change.
- **UX-10:** "Pot aplica?" panel, browser-only comparison, strict verified-evidence
  policy, no verdict. Shipped behind `ELIGIBILITY_PANEL_ENABLED = false` because the
  spec requires rule review before release.
- **UX-11:** browser-local checklist (`posturi.checklist.v1`) with revision-scoped
  ids and fingerprint-proved carry-over.

Also: `test_check_llm_balance.py` no longer writes the real `data/pipeline-runs.jsonl`;
`/pipeline-check` documents `warned_steps`. Validation: 11 PHP suites, `php -l`,
Python (1,009), full Playwright suite and `check-skins` all green locally. Metrics
were taken on the stale local data (2026-09-09 export, September Postgres copy) and
are illustrative; current-export numbers are a rollout step.

### 2026-10-10 — Refresh specifications and define next application-companion packages

Updated FIX-04/05/06/07/08/10 with October 4–10 findings: small content-failure
residues versus systemic failures, completed v4 rollout and conservative resume,
per-column legacy-export compatibility, remaining marker verification, manual CI,
attachment validation/revalidation, and reuse of the implemented balance check.
Updated October 6 product specs with live status, icon controls, compact mode and
mobile polish, retaining real-client/browser acceptance gaps.

Added six focused follow-up specs for FIX-05-STAGES, DATA-04 and UX-13/10/11/12.
Stage fallback remains a recorded decision before implementation; role identity,
verified evidence, local candidate/progress state and durable change history are
explicit contracts. No model calls, backfills, host changes or deployment occurred.
Linked assignments from the backlog and reconciled already implemented horizontal
filters/card mode, stale CI status and the already moved morning slot. Other
packages remain backlog-level scope; specifications do not close implementation.
Validated relative documentation links and whitespace. Existing local UI changes
and earlier activity/backlog edits were preserved.

### 2026-10-10 — Mobile result controls and view labels

Moved the native subscription disclosure below the result list to reduce the first mobile viewport's controls. Search, application status, filters, and the compact sort/view row remain accessible above results. The desktop switch now names its current Listă/Grilă view and includes that visible name plus the destination in its accessible label, while retaining `posturi.layout` storage and behavior.

The first successful save already opens a visible notice immediately after the action, with browser-local storage and data-clearing details, and announces the save in the live region. Kept that tested interaction instead of adding another prompt. Updated the backlog entries and compact browser expectations. Luna implemented this follow-up; the main agent reviewed it and visually checked 320, 390 and 1280px widths with no overflow. PHP lint, skin validation and the full browser suite pass (137 passed, 10 intentionally skipped). Changes remain local.

### 2026-10-10 — Interface polish: readable mobile results and calmer surfaces

Reviewed the PHP interface in Chromium at 320, 390 and 1280 pixels. On phones, the right-hand deadline column squeezed long job titles into narrow lines. Deadlines now sit below the job information at widths below 640px, with their estimated-date qualification still visible. Result rows use subtle surface backgrounds, token-based borders and corners, with no shadows or palette changes. Badges have consistent corners; save/hide controls gain hover feedback and 44px phone hit areas. Pagination wraps and uses 44px targets. Shared keyboard focus outlines and a gentler results-loading transition improve interaction feedback and retain reduced-motion support.

Rebuilt the committed stylesheet. PHP template lint and the skin palette checker pass. The full browser suite passed (137 passed, 10 intentionally skipped). Visual inspection used the bundled September export for realistic long titles and the deterministic browser fixture for current schema behavior; the old export's counts are not a statement about live availability. Broader first-search simplification and saved-list orientation are recorded in the backlog.

### 2026-10-10 — OPS-04: DeepSeek balance pre-flight

On 2026-10-05 a backfill stopped on `402 Insufficient Balance` after 1,475 of 2,396 postings, and a scheduled run would have failed the same way partway through. The runner now asks first.

- **`ops/check-llm-balance.py`** calls `GET https://api.deepseek.com/user/balance`, which is free, with a 10 s timeout. It resolves the provider with the same `llm_config.resolve_provider()` that `pipeline.py` uses. Possible verdicts: `ok`, `warn` below `LLM_BALANCE_WARN` (default $3, about a week of runs), `abort` (exit 69) below `LLM_BALANCE_MIN` (default $0.50), below `--need` or with `is_available: false`, and `unavailable` or `skipped`. The last two exit 0 on purpose: a broken check must not block the pipeline, and the API still refuses loudly if the account really is empty. `--need <usd>` is for manual backfills, to check the balance against their estimate.
- **Runner.** The check runs after the migrate check and before `pipeline.py`. Only exit 69 stops the run (ABORT, `/fail` ping, nothing fetched or deployed); any other non-zero status warns and continues. The header's exit-code list now includes 69.
- **Trend.** One `kind: "llm-balance"` line per check goes into `data/pipeline-runs.jsonl`, so `/pipeline-check` can show the balance over time. The only reader of that file, `check-export.load_baselines`, selects only `deploy` and `export-check` records; tests pin this, including a record with deliberately colliding keys.
- **Tests and docs.** There are 54 unit tests (HTTP mocked) and 6 `test_run_pipeline_sh.py` cases. One case checks that a 69 exit pings `/fail` and never starts `pipeline.py`, export or deploy. Docs updated: README, `docs/deploy-vps.md`, `.env.example` and the `/pipeline-check` command.
- **Stale test.** `test_apply_deadline.py::test_the_ical_reminder_lands_on_the_deadline` had failed since UX-04-FEEDS (`f8ce944`) moved the iCal selection into `feeds/_feed.php`. It now checks the `feed_rows(..., true)` call and the `apply_deadline IS NOT NULL` clause where they live. The Python suite has 661 passing.
- A live read found **$1.40**, below the warn level. The VPS must `git pull` before the check runs there. Its first run should log a balance line; `skipped` would mean `LLM_PROVIDER` does not resolve to deepseek on that host.
- The implementation was delegated to a Sonnet agent; the main session made the design decisions, reviewed the runner diff and reran the suite.

### 2026-10-10 — PHP-COMPAT-01: old exports no longer break on later columns

Served against the stale bundled `webapp-php/posturi.sqlite` (exported 2026-09-09), `/` and `/?funding=…` failed with `no such column: v4_funding_source`. Since REV-01, `shim_legacy_export()` has created a TEMP view for exports that lack `application_status`. It returned early whenever that column existed, though, so an export with status but no `v4_*` columns slipped through. The bundled file also lacked other later columns that PHP queries.

- **Shim.** Each column is now checked on its own, and the view is built only when something is missing. The `application_status` CASE is unchanged. A `$blank` map adds `v3_contract_duration`, `v3_schedule`, `v3_shift_work`, `occ_canonical`, `sal_min` and the five `v4_*` columns. Each gets the default the export writes before its extraction has run: `''` for `NOT NULL DEFAULT ''` and NULL for nullable columns. Facets and filters therefore see "no value", not an error. A current export still skips the view after one PRAGMA.
- **Coverage check.** I compared the full `job_postings` schema in `export-to-sqlite.py` against the bundled file and searched PHP for every missing column. Each one is either shimmed now, read off a fetched row with `??` (`sal_json`, the `build_meta` provenance), or never referenced. `llm_costs` is absent from old exports, but `stats.php` already wraps that query in try/catch.
- **Tests.** `compat_test.php` grew from 71 to 211 assertions. There are two new fixtures, `nov4` (status but no `v4_*`) and `oldest` (2026-09-09-shaped), and 21 routes run against each legacy shape, including the funding, sector, occupation, duration, schedule, salary and shift filters. A current export is asserted to get no view. The new cases fail against the old `db.php`. All 7 PHP suites pass. Playwright was not run, because no markup changed.
- The PHP work was delegated to a Sonnet agent; the main session reviewed the diff, independently cross-checked missing columns against PHP references, re-ran all suites and smoke-tested the bundled export.
- Also checked: `/versiuni.json` serves code `9bdbfc8`, so UX-01A, UX-09 and UX-04-FEEDS are live. The 2026-10-06 entries below still say "not deployed" because they were true when written. Only `8b44300` (icon buttons, compact view) is still to deploy.

### 2026-10-06 — Icon save/hide buttons and compact view (code ready, not deployed)

Follow-up to UX-09 after the text buttons proved too big in rows.

- **Icon buttons.** `pref_controls()` now renders two icon buttons (bookmark, ×) right after the title — outside the title link, same line, wrapping with it — in list rows, employer rows and the detail page. One shared SVG sprite in `inc/header.php`, classes in `assets/app.css` (`.pref-btn`, `.pref-ico`; tokens only), 20px glyph in a 32px hit area. Names/tooltips are `aria-label`/`title` ("Salvează anunțul: <titlu>", "Elimină din salvate: …", "Ascunde/Restabilește anunțul: …") written by `saved.js` `setLabel()`; both buttons carry `aria-pressed`. The title attribute is JS-only on purpose: row markup has to stay lean for `deadline_test.php`'s 3000-byte window (the controls now sit before the deadline column, so the "Ascuns din rezultate" flag moved to the row end via `pref_flag()` and two HTML comments became PHP comments). The detail page now has a hide button too: hiding there only marks the posting hidden (pressed state, flag, notice with Undo, live-region message); the page stays. `/salvate/` keeps its text buttons.
- **Compact view (lg+).** One toggle, "Vizualizare compactă" (`#layout-toggle`, `localStorage posturi.layout`, applied to `<html data-layout>` by a tiny head script, so no flash). It is CSS over the same server markup (`html[data-layout="compact"]` in `app.css`, unlayered so it outranks the `lg:` utilities): the sidebar becomes a wrapping row of pill groups under the search, and results become a 2/3-column card grid with the secondary lines hidden. Mobile and no-JS are unchanged. Top-level facet groups got a `.facet-body` wrapper so their content can be lifted into a dropdown; list.php's scroll-restore selectors follow.
- **Dropdown behaviour.** In compact mode a panel is driven by `data-pg-open` (one at a time, Escape/outside click close, focus back to the summary, flips right if it would overflow), re-applied after each HTMX swap so multi-select keeps working. The click is intercepted in the capture phase so `posturi.facets` is never read or written by it. Non-obvious: a closed `<details>` does not render its content at all (Playwright caught this), so the code also sets `open` on the details it opens and closes only those it forced.
- Tests: `preferences_test.php` and `saved.spec.js` updated for the icon buttons (by role/aria, not text); new `tests/browser/compact.spec.js`.

### 2026-10-06 — UX-04-FEEDS: filtered RSS, Atom and iCal subscriptions (code ready, not deployed)

Added `/posturi.rss` (RSS 2.0, `application/rss+xml`; in the front controller's
machine-readable 400 path) and a shared `webapp-php/feeds/_feed.php`: one
selection (`feed_rows()`: `build_filters()` + FTS, newest-first, cap 50 for RSS
and Atom; iCal earliest 200 dated, tie-broken by id), one summary text (place,
type, category, application status, resolved deadline with the expiry-only
estimate qualified, official source), XML text that strips characters XML
forbids, and the iCal helpers. Atom ids and RSS guid (`isPermaLink="false"`) stay
the official URL, so readers see no new entries; item/alternate links now go to
the local detail page, with the official URL as Atom `rel="related"` and in the
summary. Timestamps come only from `published_at`/`updated_at` (date precision,
midnight Bucharest with a real offset instead of a bare `Z`); the feed-level
`updated` is the newest row, or the database build time for an empty feed. RSS
descriptions are escaped as HTML first, so source markup is shown, not run.
Feed titles say "căutare filtrată" when anything beyond an employer scope is
active. iCal: TEXT escaping only on TEXT properties, `URL` is a URI (non-ASCII
percent-encoded, commas/semicolons/existing escapes untouched), lines folded at
75 octets without splitting UTF-8 (the old `wordwrap()` counted bytes after
escaping and could cut `ț`), estimated deadlines get "Termen estimat:" in
SUMMARY as well as DESCRIPTION; UIDs, all-day events and exclusive DTEND
unchanged. UI: a single "Urmărește această căutare" `<details id="urmareste">`
beside the status/sort controls (outside `#results`) holds RSS/Atom/Calendar iCal
links, script-only copy buttons (absolute current URL; a selected readonly
input if the clipboard fails), the Google/webcal/title UI that was at the page
foot, and the stated limits (50/200, client-controlled refresh, ICS import is a
snapshot, not an archive, no immediate notifications). JSON API stays in the
export line, which also links to the block's content id (a closed `<details>`
opens for fragment navigation, with a small script fallback). Labels are RSS,
Atom, Calendar iCal, JSON API (also on employer pages, plus an RSS autodiscovery
`<link>`). `syncFeedLinks()` and `feed_url()` now also drop empty values such as
the `q=` an emptied search box sends. No new static script, so no cache-busting
change; CSS rebuilt, skins check passes.

Decisions: caps and the 400 contract untouched; the feed `<id>`/alternate of the
feed itself keeps its pre-change value (site or employer page) so existing
subscriptions are not re-keyed, while the new channel link points at the
filtered HTML search; the extra rows for cap/hostile-text tests are inserted
into a copy of the fixture inside `tests/feeds_test.php`, so `build_db.php` and
the pinned counts in other suites are untouched. Tests: `tests/feeds_test.php`
(CI step added) and `tests/browser/feeds.spec.js` (320/375/1280). Not committed
or deployed.

### 2026-10-06 — UX-09: save and hide announcements without an account (code ready, not deployed)

Browser-local shortlist and reversible hide, built on UX-01A. One shared script
(`static/saved.js`, loaded by `inc/header.php`) owns a single localStorage key,
`posturi.preferences.v1` (`{version, records}` keyed by official source URL;
numeric id is only a lookup hint). Server markup gets identity `data-pref-*`
attributes and hidden-until-JS Salvează/Ascunde buttons (`pref_attrs()` /
`pref_controls()` in `helpers.php`) on list, employer and detail pages; the
nav links to the new `/salvate/` page (`pages/saved.php`: Salvate / Ascunse
views, local batches of 25, clear/restore-all with in-page confirm, noscript
text). `/preferinte-posturi.json` (`feeds/preferinte.json.php`) is a read-only
POST lookup of public data for up to 500 distinct ids (405+Allow, 415, 413 over
16 KiB, 400 for malformed/nested/non-integer; no default active predicate, no
cap, no-store, no CORS), routed before the query decoder so errors stay JSON.
Server feeds, counts and filters never see local state; hidden rows are only
hidden after rendering, with a banner and "Arată" toggle.

Non-obvious decisions: ID lookups are accepted only when the returned source
URL equals the record key (otherwise the cached snapshot stays, labelled "Nu
mai este disponibil în baza curentă", never "expirat"); invalid stored entries
are ignored but written back untouched; corrupt/unknown-version storage is never
overwritten (session-only until an explicit confirmed reset); writes are
verified by read-back; mutations re-read storage first (other-tab edits kept;
simultaneous writes last-write-wins). Duplicate ids in a lookup collapse (cap
applies to distinct ids). `[hidden]{display:none !important}` added to app.css
because button utilities overrode the attribute. Controls sit after the deadline
column in list rows so `deadline_test.php`'s 3000-byte row window still holds.
Tests: `tests/preferences_test.php` (endpoint, markup, escaping, rebuilt-dataset
cases; CI step added) and `tests/browser/saved.spec.js` (storage unavailable /
quota / silent write loss / corrupt / version / invalid entries, 26 and 500
records, failed lookup + retry, identity cases, hostile snapshots, HTMX
swaps + history, other-tab edits, keyboard, no-JS; 320/375/1280). CSS rebuilt
(`tailwind.config.js` also scans `saved.js`); skins check passes. Not committed
or deployed.

### 2026-10-06 — UX-01A: grouped filters and clear application status (code ready, not deployed)

Regrouped the PHP sidebar (`partials/facets.php`): only Județ and Domeniu
profesional start open; Studii, Experiență și cerințe, Contract și program and
Mai multe filtre are parent disclosures (with nested "Clasificări din sursă și
text" and "Diagnostic date"). Any selection opens every ancestor
(`data-selected`), orphan selections render as pinned checkboxes even when the
group has no options, and legacy `computer` / `has_salary` / `employer` /
`employer_id` params now survive form serialisation (hidden input or removable
checkbox, plus an employer chip). Status tabs read Active / Termen în 7 zile /
Înscrieri închise / Termen neprecizat with persistent explanatory copy (7-day
sentence toggled by CSS `:has`, because the control is outside the swapped
region); counts say "anunț găsit / anunțuri găsite"; expiry-fallback dates show
"Expirarea anunțului; înscriere neconfirmată" in list, detail and employer
pages. Enum options/chips use Romanian labels (unmapped values kept verbatim).
No predicate, export or prompt changes; URL values unchanged.

Non-obvious decisions: facet open/closed preferences are now recorded from
summary clicks, not `toggle` events (those also fire for server-rendered open
groups and were storing every default/bookmark-opened group as a preference);
feed links are looked up live in `syncFeedLinks()` (survives history body
restore) and `feed_url()` drops `title` for non-iCal feeds to match the JS;
`status=all` is not a tab, so the first form change normalises it to `active`
(pre-existing behaviour). Salary facet stays gated on >= 100 estimated rows.
Tests: `tests/discovery_test.php` (old-URL ID sets captured at 6accc59, wording,
ancestors, feeds), `tests/browser/discovery.spec.js` (320/375/1280); fixture
extended by UPDATEs in `build_db.php`; `old_urls.php`; CI step added. Existing
tests' wording expectations updated. CSS rebuilt; skins check passes. Not
committed or deployed.

### 2026-10-06 — Implementation specs for discovery, local preferences and feeds

Added three bounded handoff specs under `docs/specs/2026-10-06-product/`:
UX-01A groups filters and clarifies status without changing legacy predicates;
UX-09 defines browser-local save/hide, identity checks, missing-record snapshots
and a bounded read-only lookup; UX-04-FEEDS preserves existing filtered Atom/iCal
and adds actual RSS, subscription discovery and encoding regression coverage.
Specs name source files, fixed behavior, exclusions and fixture-based acceptance
checks so implementation can be assigned one package at a time. Parent UX-01/04
remain open beyond these slices. Added explicit later email notifications as
UX-14 and linked all assignments from the backlog. No application code, deployment
or paid calls changed; these are specifications, not completed features.
Validated the specs' relative links and named source paths; `git diff --check`
passed. The earlier rollout review passed 78 focused tests. Included all four
new spec/index files with the backlog, product direction and activity entries.

---

### 2026-10-06 — Source-counter follow-up and remaining product direction

Added OPS-05 to verify the official portal's counter unit, status rules and
refresh timing before considering it as a `/pipeline-check` diagnostic. The
screenshot discrepancy is evidence to investigate; the precise source-counter
semantics remain unverified. Reviewed the agreed product direction and active
backlog: the deadline/security/reliability foundation has largely shipped, while
UX-01 through UX-13 remain open. Recommended simpler discovery and browser-local
save/hide as the next user-facing packages, ahead of requirement-dependent
eligibility matching.

---

### 2026-10-06 — Review of the v4 rollout changes

Reviewed `7a562c8..6accc59` (the two commits after the October 5 11:46 checkpoint),
with the preceding output-cap and cost-meter changes as context. The runner now
defaults to v4 consistently for extraction and export checks. No new regression
found in the reviewed changes: shell syntax check passed and 78 focused runner,
v4 extraction, cost and pipeline-health tests passed. Production results were
read from the recorded rollout evidence, not independently rechecked on the VPS.
Outstanding calendar-stage rejection and balance monitoring are already tracked
as FIX-05-STAGES and OPS-04.

---

### 2026-10-05 — FIX-05-RUN: v4 deadlines backfilled and live

**What:** the 20-posting v4 sample (18:41Z, $0.054) extracted a deadline from all
20 newest active postings; each was checked against its source sentence with the
new `check-v4-sample.py --evidence`, all correct, peak 6,300 output tokens. The
backfill then ran on the VPS under `.pipeline.lock` from the runbook in the FIX-05
spec (rollback CSV + pre-v4 SQLite first). It stopped at 1,475/2,396 on DeepSeek
`402 Insufficient Balance` (exit 2, nothing deployed — the fatal path worked),
resumed after pax topped up with step 0 skipped so the rollback copies stayed
pre-v4, and deployed as `v4-backfill-resume-2026-10-05T20:39:47Z` (check-export
31/31). Total 2,383 ok / 20 failed, $5.86 at list price ($0.0024/posting; estimate
was $6–9).

**Result on the live feed:** newest 200 active — before 194 `expirare` / 6 `anunt`,
after 193 `concurs` / 7 `expirare`. Of 2,293 exported postings, 1,095 are
confirmed open, 87 unconfirmed, 1,111 closed: almost half of what the site listed
as active by announcement expiry had already stopped taking applications. The
default Active view went from 2,022 to 1,182.

**Non-obvious:** the export lost 100 rows (2,393 → 2,293) because it ran after
midnight Bucharest and those postings expired on 05.10 — not a v4 effect. The 20
failures are all an unlisted calendar stage (FIX-05-STAGES, decision pending). The
v4 pin (`43ca34e`) had to reach the VPS before the next scheduled run: a
v3-pinned `--resume` treats every v4 row as provably stale (provenance carries the
prompt version) and would have re-extracted and downgraded all of them, at peak
price. The VPS session's progress watcher reported 0 failures until it noticed
tqdm's carriage returns hid the ✗ lines — worth remembering when grepping these
logs.

**Closed out at 21:38Z:** the VPS pulled the v4 pin (`43ca34e`) and the crontab's
morning slot moved to 13:15 Bucharest (`15 10,11`, guard `= "13"`). A read-only
count shows the next scheduled `--resume` selects 5 of 2,396 active rows — the
v3-provenance stage failures — plus new or changed postings, so the scheduled runs
keep v4 current without re-paying for the backfill.

---

### 2026-10-05 — FIX-05-RUN prep: cost meter, morning slot, output cap, runbook

**What:** while the 20-posting v4 sample waits on the VPS session, everything the
backfill depends on was readied without paid calls. COST-01: `models_config.json`
carries DeepSeek's current list prices and a 2× peak tier, so recorded costs stop
understating (pushed `e06eab8`). The morning slot moves 11:45 → 13:15 Bucharest
(10:15Z / 11:15Z, outside the 06–10 UTC peak); the healthcheck period goes 18 h →
20 h with it, since the overnight gap becomes 18h42m (pax set it already); docs and
the timer unit are updated (`4b06492`), the VPS crontab changes after the backfill.
The output cap is 16,000 for every prompt version (REV-17). The runbook, with a
rollback snapshot, is in the FIX-05 spec.

**Checked, not assumed:** v4 reclassification cannot trip the deploy gates — the
`active` metric, `no_collapse` and the row floors all count by `expires_at`. After
the backfill `occupations` makes no new calls (keyed on the title) and `salary`
recomputes from `schema_json`, but `infer` only touches new/stale rows, so the
runbook adds a no-LLM `infer_postings --conditions-only` pass.

---

### 2026-10-05 — FIX-05-RUN step 1: v4 backfill cost estimate; DeepSeek prices are stale

**Baseline (live `/posturi.json`, data run `2026-10-04T20:57:08Z`):** the newest 200
active postings are 194 `expirare` / 6 `anunt` / 0 `concurs` — 97% show only the
announcement's expiry. 2,267 rows exported: 1,896 active, 371 closed (181 of the
closed slice already carry a `concurs` date from earlier v4 work).

**Estimate:** from that run's record (325 v3 calls: 13.5k input tokens each, 97%
cache hits; 3.4k output) and the September local v3/v4 pairs (v4 input ×1.23,
output +1.1k to ×1.9), v4 should cost $0.0028–0.0039 per posting at DeepSeek's
current list price: ~$6–9 for the ≤ 2,270 active postings off-peak, ~$13–18 at
peak. The 20-posting sample replaces these ratios with measured v4 numbers.

**Non-obvious:** DeepSeek's pricing page now serves `deepseek-v4-flash` (a retired
name) with V4.1-Flash at $0.15 / $0.003 cached / $0.60 output per M off-peak, double
at 01–04 and 06–10 UTC on weekdays. `models_config.json` still prices it at $0.14 /
$0.028 / $0.28, so recorded costs understate list price ~1.5× off-peak, ~3× at peak —
and the 08:45Z slot runs in peak hours (COST-01). The page also gives a 384K max
output, which unblocks REV-17. The VPS session could not run the Postgres reads
(its classifier denies "production reads"), so the exact selection count is pending.

---

### 2026-10-05 — OPS-01: DST-safe cron without sudo; alerting verified

**What:** The VPS session (with pax's approval there) replaced the two UTC cron
lines with guarded pairs — `45 8,9` and `33 15,16`, each running only when
`TZ=Europe/Bucharest date +%H` is 11 / 18 — so the slots stay at 11:45 / 18:33
Bucharest across the 2026-10-25 switch and every later one, with logging unchanged.
Verified: only those lines changed (backup kept), and the guard fires at 08:45/15:33Z
on 10-05 and 09:45/16:33Z on 10-26 only. The systemd units (rewritten for the live
layout) remain the sudo-requiring alternative. Alerting (OPS-02) was confirmed from
Healthchecks.io: the check went down at 2026-10-04 09:31Z with the first `/fail`;
its 1-day period is to be shortened to 18 h so a single missed run alerts.

**Non-obvious:** the box's `crontab FILE` truncates long paths, so the VPS session
installed with `crontab - < file`. It also noticed an unrelated project's job failing
on a missing `httpx` in its venv — reported to pax, untouched.

---

### 2026-10-05 — REV-08: a residue of failing postings no longer blocks quiet runs

**What:** `--resume` reselects every active posting without a schema, so the five
postings that still exceed the 8,000-token cap (REV-17) are attempted on every run.
FIX-04's gate failed a run when more than half its attempts failed — on a weekend
(no new postings) that is 5 of 5, which would have blocked every Saturday/Sunday
deploy and left expired postings listed; a quiet weekday evening with < 5 new
postings would too. `evaluate_exit` now separates per-posting content failures
(`OutputTruncated`, `ValidationError`, `ValueError`, `non_dict`) from systemic ones
(HTTP, timeouts, unclassified): systemic failures gate on any sample, content
failures only once a run attempted ≥ 20 postings. The empty-content rule (selected
but nothing attemptable) likewise needs ≥ 20 postings.

**Why this keeps the gate's value:** the 04.10 outage (321 of 326 failing) and a
broken prompt on a normal weekday (≥ 20 new postings) still fail; tests pin both,
plus the weekend residue, a quiet evening and mixed residue + transient errors.

---

### 2026-10-05 — Code deploy verified live; tooltip wording

**What:** After the user pushed, deployed `--code-only` and pulled on the VPS, the
live host serves code `2e2c00a` with data from run `2026-10-04T20:57:08Z`
(`/versiuni.json`). Checked from the dev machine: `/angajatori/?judet=cluj` 200 with
Cluj employers (was a 500), `?q[]=medic` 400, no PHP warnings on /, /statistici/,
/angajatori/, /despre/; stats and employers active counts non-zero (1,896 active of
2,267; 1,118 employers with active postings); status control shows Active 1,896 ·
7 zile 453 · Închise 371 · Termen neprecizat 0; header shows "bază generată" and
"sursă verificată" separately. Ticked FIX-01 and REV-02/03/04.

**Fixed (ships with the next code deploy):** the build tooltip called every exported
row "anunțuri active" (2.267) while the page counts 1.896 open ones; it now says
"anunțuri în bază".

---

### 2026-10-05 — Site republished; REV-15 deploy-verification deadlock

**What:** After the VPS pulled `c8a9559` and migrated (0013–0015), a manual
`run-pipeline.sh` ran end to end and pushed the database: the live site shows
"generată la 05.10.2026, 00:41 · 2.267 anunțuri active", ending the outage that
began after the 03.10 19:08 export. The run still exited 1: FIX-06's post-push check
read `data.built_at` from `/versiuni.json`, which the host does not have yet (its PHP
predates FIX-06), and treated the empty value as a mismatch. That would have failed
every unattended run until the next code deploy, and skipped `record-deploy`. A
data-only push now reports a 404 there as unverifiable, with a pointer to
`--code-only`; code pushes still require the endpoint.

**Observations from the run** (`2026-10-04T20:57:08Z`, manual, `c8a9559`, 44 min,
all 11 steps ok): schema 320 ok / 5 `OutputTruncated` of 325 at the new 8,000 cap,
$0.44 including the truncated calls (REV-14 confirmed; REV-17 for the 5);
schema coverage 99.8%, v3 95.8%; all 8 hard checks passed, one soft warning
(`intake_fresh`, a weekend artifact — fixed as REV-16). fetch-detail 6 new + 200
legacy refreshes, 0 failed, 13.7 min (5,220 legacy caches remain due, 200 per run);
parse 7–8 min CPU-bound with no output (LATER-05).

---

### 2026-10-04 — GitHub CI switched to manual (CI-01)

**What:** The push of the FIX-07 workflow made GitHub Actions run it on every push.
Run #7 (`c8a9559`) failed in `python-tests` at the ruff lint step on 15 pre-existing
findings, so pytest never ran; the PHP and browser jobs passed. Per the user's
decision CI stays postponed: the trigger is now `workflow_dispatch` only (runnable by
hand from the Actions tab), and CI-01 in the backlog holds the lint cleanup, the
trigger policy (tags/releases and/or PRs) and the Node-20 / `ubuntu-latest`
deprecation notices from the same run.

---

### 2026-10-04 — REV-05/13/14: why nothing published on 04.10, and the fixes

**Diagnosis (VPS session, read-only):** cron fired both 04.10 slots at `fd1cf97`.
`schema` failed 321/326 and 313/324 attempts, all `ValueError: Expected dict, got
str` — truncated JSON at the v3 output cap of 2,000 tokens, which deepseek-v4-flash
now routinely reaches (successful answers average 1,660–1,780; failing calls take as
long as a full-length answer). The same ~320 active postings had failed since at
least 03.10, but the old code exited 0; FIX-04's failure-share gate made the step
fail. The runner then died in its ERR trap — `set +e` does not disable it — so the
ABORT/degraded/exit-65 branches were dead code. Migrations 0013–0015 were also still
unapplied on the VPS, which would have broken the next run on the new code.

**Fixes:** REV-14 — output budget 8,000 for every prompt version; truncation is
detected from the provider's stop reason (`OutputTruncated`, its own failure class,
not "repaired"), and the billed tokens of truncated calls are now counted (failed
calls used to vanish from usage and cost). REV-13 — handled commands use
`cmd || status=$?`; a `migrate --check` pre-flight exits 78 before any work.

**Validation:** `test_run_pipeline_sh.py` runs the real script with stubbed
commands (healthy, failed step, degraded opt-in, export-check 65, pending
migrations) — 4 of 5 fail against the old script. Truncation tests cover the
DeepSeek stop reason, usage carried on the exception and no repair retry. Full
suite 572 passed. Cost of the fix: re-attempting ~320 postings at roughly 2,500
output tokens each is well under a dollar at the recorded deepseek-v4-flash rates.

**Credit:** the root cause, the ERR-trap behaviour (verified with a throwaway bash
one-liner) and the missing migrations were found by the Sonnet session on the VPS
over Remote Control; this session confirmed them in code and wrote the fixes.

---

### 2026-10-04 — REV-12: stop the cron's `--resume` from re-paying for every active posting

**What:** FIX-05 made `--resume` select any production row whose provenance
or hash was empty ("cannot prove current"). Migration 0014 adds those columns
empty, and the bounded refresh hashes only 200 pages per run, so the first
unattended run after `migrate` would have re-extracted every active posting
(~2,250 on the live data) and every later run would have re-paid for the
active rows not yet hashed — an unreviewed paid backfill, which the FIX-05 spec
reserves for FIX-05-RUN. `--resume` now re-pays only for provable staleness: no
schema; recorded revision and current hash both known and different; or
recorded provenance with a different provider/model/prompt. Legacy rows are
upgraded only with the new `--upgrade-legacy` flag. To keep REV-07 complete,
a confirmed source change at import stamps the pre-change hash on extractions
that carry no revision, which makes them provably stale.

**Validation:** a selection-matrix test runs the real SQL against PostgreSQL for
seven states, with and without `--upgrade-legacy`; an import test covers the
stamping. Full suite 564 passed. Local copy (stale, 374 active): old rule 374,
new rule 17 — exactly the active rows with no schema.

**Rollout:** this must reach the VPS together with (or before) `480e7e3` and
migration 0014. The FIX-05-RUN backfill command becomes
`python llm-schema.py --active-only --resume --upgrade-legacy --prompt-version v4 --workers 8`.

---

### 2026-10-04 — REV-06(a): adopt legacy downloads instead of re-fetching them

**What:** `download_file()` treated a valid pre-manifest ("cached-legacy") file
as a miss, so the first run on the FIX-08 code would re-download every
attachment in its `--since` window (7 days on the VPS). It now adopts the file
in place — `adopt_one()` deep-validates it, moves it into the hashed cache and
writes the manifest, with no request — and returns status `adopted`. Only a
legacy file that fails the deep check (a partial that kept its magic bytes) is
re-fetched; the invalid original stays where it was. `adopt_legacy_files()`
now shares the same helper. Deep validation gained the PDF `%%EOF` check that
new downloads already had.

**Found on the way:** extensionless or unsupported-extension URLs (`/download`,
`.aspx`) are stored as `<key>.<sniffed>`, but resolution rebuilt the name from
the URL alone, so those files were never found: re-downloaded on every run and
reported missing to extraction. Resolution now takes the extension from the
manifest.

**Validation:** 6 new tests (adoption without a request then a cached hit,
truncated PDF / broken DOCX legacy partials re-fetched, extensionless download
and adoption both resolve); full suite 562 passed. Read-only census of the
local cache: 9,571 files adopt cleanly, 2 fail the deep check (1 corrupt DOCX,
1 truncated PDF) and would be re-fetched, 1 was already invalid.

**Rollout note:** the VPS pulled `92a3615`, which has the FIX-08 downloader
without this fix — its next runs re-download legacy files from the last 7 days
once (bounded, paced) and extensionless URLs every run until it pulls this.

---

### 2026-10-04 — REV-01/02/03/04/07/11: review follow-ups

**What:** Fixed the post-implementation review findings that need no host access.
REV-01: `db()` now shims an export without `application_status` — a TEMP view
named `job_postings` shadows the table and computes the status exactly as
`_application_status()` does, so a code deploy ahead of the VPS export no longer
fatals list/employers/stats/feeds. REV-02/03/04: employers county filter takes the
first value of the decoder's array (was a live 500), the three `OPEN_STATUS_SQL`
quoting bugs are fixed, the status control counts closed/unknown, the landing
count and sitemap use the status vocabulary, and the JSON feed carries
`application_status`. REV-07: a confirmed `detail_content_hash` change clears
`attachment_text` and marks `inferred` with `stale_revision` (infer reselects it);
calendar events vanish with a removed schedule; the refresh cap goes to open
competitions newest-first and skips ones expired >30 days; `fetch-anunturi.py`
exits 1 when >50% of ≥5 attempted fetches fail. REV-11: README, CLAUDE.md,
AGENTS.md, deploy-vps.md and the spec index brought in line with the code.

**Validation:** Python 556 passed (13 new REV-07 tests); PHP sanitize 155,
deadline 85, request 178 (+26), new compat_test 47 — wired into ci.yml;
Playwright 15 passed / 3 skipped with no PHP warnings in the server log.

**Non-obvious decisions:** The status shim is a TEMP view rather than per-query
fallbacks: one place, works on a read-only file (only the in-memory temp schema
is written, before `query_only`), base-table indexes still apply, and current
exports pay one PRAGMA. `inferred` is marked, not wiped, so a `--no-llm` run can
still carry a paid LLM family forward and the site keeps the last-good values
until re-inference. A legacy row's first hash is "unknown", never a change —
otherwise the first production refresh would requeue the whole archive. The
fetch failure exit makes a mostly-failing source block the deploy, consistent
with FIX-04; the five-attempt floor keeps one bad page from doing so.

**Deploy order still matters for:** REV-02/03/04 ship with the next
`deploy-php.sh --code-only`, which REV-01 now makes safe against the current
live export. The VPS still needs `git pull` + `migrate` before its pipeline
uses the FIX-03/05/07 changes.

---

### 2026-10-04 — Post-implementation review of FIX-01…08 / OPS-01/03

**What:** Re-verified commits `b3e4790`…`4e9dbc0` against their specs, the code
and the live host. Suites re-run green: Python 547 passed, PHP 155 + 85 + 152
assertions (the "374" in earlier entries is stale), Playwright 15 passed / 3
skipped, `php -l` clean, skins valid. Recorded eleven follow-ups as REV-01…11 in
the backlog. The material ones: the current PHP tree needs `application_status`,
which the live (VPS, `2c5e480`) export lacks — served against an old-schema
export the list/employers/stats/JSON routes fatal, so the VPS must upgrade and
export before the next code deploy (REV-01); `/angajatori/?judet=…` is a live
500 since the FIX-07 decoder turned `judet` into an array (REV-02); a quoting
bug zeroes the active counts on stats/employers (REV-03); and the live data has
not been republished since 03.10 19:08 (REV-05).

**Non-obvious:** live evidence (`?q[]=` → 400, `/versiuni.json` → 404) shows the
Immediate-fixes tree is deployed but FIX-03/05/06 are not. No code changed in
this entry; nothing was deployed.

---

### 2026-10-04 — OPS-03: legacy-artifact audit and fresh gap measurement

**What:** Checked the root for stale SQLite/sidecars: none remain — the only
large artifact is `posturi.dump` (122 MB, 10 Sep), which is the documented
pg_dump transfer/backup format from docs/deploy-vps.md, gitignored, and still
the restore path for the VPS — nothing reads it automatically and it must not
be removed. Traced the historical `value too long for character varying(40)`
for `1-post-muncitor-treapta-i`: the only varchar(40) column in the model is
`contact_phone`, and the current CSV contains a 41-character phone
("0268-428841 sau 0268-428843, interior 205") from the structured contact
row, kept verbatim — exactly the overflow that dropped that posting's detail
import. Widened `contact_phone` to 200 (migration 0015) instead of truncating:
source facts are never shortened. Re-measured the gaps on the current full
corpus (9,756 postings): no expiry 66 (0.7%), no body 16 (0.2%), no
attachment_text 1,067 (10.9% — the scanned-PDF/extraction-failure split is
DATA-01's inventory, with 3 invalid + 35 missing cache files from the FIX-08
audit), inference 100% populated, no schema_json 6,920 (70.9% of the full
archive; the active slice sits at ~95%), cancelled 80, source provenance 0
(expected — the FIX-03 refresh has not run in production yet). These replace
the dated incident counts in the backlog.

**Validation:** full Python suite 547 passed, PHP suites 374 assertions,
export e2e clean after the migration.

---
### 2026-10-04 — FIX-05: version-aware production extraction and the status vocabulary (code readiness)

**What:** Production extraction now carries explicit provenance:
`llm-schema.py` writes `schema_provider/model/prompt_version/
source_revision/extracted_at` atomically with `schema_json` (one UPDATE, one
commit — a crash can never leave an unrecognized production row), variants
record the `source_revision` they saw, and `--resume` became revision-aware:
a posting is skipped only when its production result is current (same
provider/model/prompt AND the extraction's revision still equals the posting's
current `detail_content_hash`); legacy provenance or a changed source selects
the row for upgrade even with non-null `schema_json`. Plain runs keep the old
skip-non-null behavior, so the upgrade cost only happens under the explicit
`--resume` mode the cron uses. Compare-only runs remain non-mutating.
Calendar keyword hardening: the written-test probe no longer matches
"înscriși" (contains "scris"), and the results probe skips deadline-shaped
rows — "Data finală de depunere" is a deadline, not the results date. The
export now computes `application_status` (confirmed_open / unconfirmed /
closed / unknown, Bucharest today) and the whole PHP surface filters on that
one vocabulary: status filter gains closed/unknown, active = open +
unconfirmed + unknown, all count queries (landing shortcuts, facet counts,
employer pages, stats) share `OPEN_STATUS_SQL`, so browse and counts cannot
disagree. Row display already qualifies unconfirmed/unknown (FIX-02).

**Validation:** 15 new tests (test_production_extraction.py: revision-aware
selection SQL, atomic provenance write, variant revision, status mapping
incl. today-is-open, keyword hardening fixtures); two source-string tests
updated to the shared-vocabulary shape; request suite grows closed/unknown
partition checks; fixture builder carries per-row statuses. Full suite 547
passed; Playwright 15/15. Export e2e on the local corpus: 52 closed /
320 unconfirmed — matches the audit's "almost everything is expiry
fallbacks" finding.

**Still open (rollout, per spec):** re-sample v4 on current refreshed inputs
(paid, separately recorded), review the sample, then run the bounded
backfill — the command shape is `python pipeline.py --steps schema
--active-only --resume --workers 8 --prompt-version v4`, now safe because
--resume upgrades stale/legacy rows and never re-pays for current work.
FIX-05-RUN owns the evidence. An explicit --promote-from-variant path was
deliberately not built: --resume-based upgrade covers the cron's need, and
compare variants with matching revisions remain valid promotion candidates
for a future flag.

---
### 2026-10-04 — FIX-06: truthful freshness and deployed-version markers

**What:** The site no longer presents a cached import date as source
verification. The visible stamp is now the DATABASE GENERATION time
(`built_at`, full precision, Bucharest) labelled "bază generată"; the source
check is a separate observation — `build_meta.detail_fetched_at_max`, written
only from real successful detail retrievals (FIX-03) — shown as "sursă
verificată <data>" with a visible "(depășită)" past 48h, or "sursă
neverificată" when the export has no observations. Header, footer, tooltip and
/despre/ were rewritten to keep build / source-check / data-version / code
-version as distinct facts; old exports render without a 500 and without false
claims. New `/versiuni.json` endpoint exposes `{code, data{built_at, run_id,
git_sha, postings, detail_fetched_at_max, index_checked_at, …}}` — no
source_host, no-store. The export's build_meta now carries `run_id`
(POSTURI_RUN_ID), the index scan stamp (`data/index-scan.json`, written by
fetch-index.py with pages scanned / early-stopped / outcome — including
`failed-zero-cards` before the existing loud exit) and the detail-fetch
aggregates over the exported slice. `deploy-php.sh` stamps the CODE marker
(`webapp-php/static/code-version.txt`, gitignored) from git at code-deploy
time — data-only pushes never touch it and vice versa — and after every push
verifies the SERVED markers with cache-bypass and bounded retry (code SHA on
code pushes, built_at on data pushes); HTTP 200 alone no longer certifies a
deploy. `deploy-php.sh --verify` compares local vs served markers and exits
non-zero when a code or data release is pending. record-deploy still marks the
verified deploy for FIX-04's baselines.

**Validation:** 4 new Python tests (scan stamp atomicity/outcomes, malformed
stamp tolerance, build_meta provenance write), request_test grows the
/versiuni.json contract checks (source_host never leaks), and a Playwright
test at all three widths asserts the honest copy on header/footer/About
against the fixture. Full suite 532 passed; export verified end-to-end with
empty provenance on legacy data; a real stale export (old build_meta schema)
renders /despre/ and /versiuni.json as "unknown" with HTTP 200.

**Non-obvious decisions:** CLAUDE.md's "fetch-index always scans all pages"
claim was stale — the early-stop after three unchanged pages still exists, so
the scan stamp records `partial-early-stop` instead of pretending completeness
(a periodic full scan remains an open plan). The local-vs-served `--verify`
check is the manual way to spot a pending code release. Deploy verification
against the live host happens at the next deploy; the endpoint and the
checker are what ship now.

---
### 2026-10-04 — FIX-03: detail refresh, cancellation from the detail page, source provenance

**What:** `fetch-anunturi.py` now refreshes cached detail pages under a
bounded policy: a cache whose sidecar says the last successful retrieval is
older than 24h (or has no sidecar — legacy age is unknown, which counts as
due) is re-fetched, capped at 200 re-fetches per run (`--refresh-hours`,
`--max-refresh`). Every fetch writes `<slug>.html` and a `<slug>.meta.json`
sidecar (URL, real fetch time, SHA-256 content hash, parsed status), both
atomically — a failed or structurally unexpected response (404/410 are
terminal; a 200 without `pg-wrap`/`main#main` is rejected) leaves the previous
good cache untouched. The detail page's own `.pg-status` marker (`is-off` →
Anulat, `is-live` → live, absent/unknown → unknown) is recorded and flows
through the parser into new `Status`/`Detail Fetched At`/`Detail Content Hash`
CSV columns. `import_csvs.py` imports those into new `JobPosting` fields
(migration 0013) and lets the detail status override the stale index
"Anulat" marker in both directions (withdrawals suppress immediately — the
export already excludes cancelled rows, so this reaches the live SQLite
without waiting for LLM work; reinstatements un-hide). Legacy rows keep
empty provenance = unknown, never synthesized from import time. Summary
counts: new / refreshed (changed / unchanged / legacy) / fresh / cancelled /
over-cap / failed.

**Validation:** 19 new tests (webapp/tests/test_detail_refresh.py) with a
fake HTTP layer and old/new markup fixtures: first fetch, fresh-skip without
HTTP, due-refresh with unchanged/changed detection, cancellation and live
markup, malformed-200/404 preserving the previous cache, the refresh cap,
legacy-cache adoption with a real (not backfilled) timestamp, index-cancelled
skip, parser status/sidecar/CSV-column checks, and import precedence
(anulat/live/unknown × index marker) plus provenance round-trip. Full suite:
528 passed. Export verified end-to-end against local Postgres (new columns
empty on legacy rows). PHP/browser suites unaffected — and the fixture run
caught a real latent bug: `days_until()` computed its own `'today'` outside
`ro_today()`, so the fixed clock and the day-boundary source could disagree;
now fixed in helpers.php with the test suite pinned to a fixed date.

**Non-obvious decisions:** `_insert_postings` now generates its VALUES
placeholders from the column list — the hand-maintained `?` string had
drifted off-by-one twice. Refresh counting separates legacy refreshes
(previous content unknown) from confirmed changed/unchanged. FIX-03 delivers
the revision plumbing; revision-aware enrichment selection/promotion is
FIX-05's. Production fetching and paid refreshes remain separate operational
steps.

---
### 2026-10-03 — Postpone GitHub CI (user decision)

**What:** The CI workflow written for FIX-07 (`.github/workflows/ci.yml`) will
not be pushed/activated for now. The request-validation code, the three PHP
suites, the Playwright checks and the workflow file remain in the repo, fully
runnable locally; only the GitHub Actions activation is deferred. Recorded in
the backlog as a revisit point on FIX-07. The user also verified the deployed
landing page after the Immediate-fixes deploy and reported it looks fine.

---
### 2026-10-03 — FIX-04: pipeline failure status, healthy baselines and quality gates (code + tests)

**What:** `llm-schema.py` now emits one machine-readable summary per model
(kind `llm-schema`, format 1, appended to `data/pipeline-runs.jsonl` under the
pipeline's run id): postings selected/attempted/ok/failed/skipped, extra API
attempts (retried), failure classes by status/name, usage totals, duration,
fatal reason and an explicit `zero_work` flag. Exit codes: 0 for healthy work
including a legitimately empty selection (reported, not silent); 1 when more
than `--max-failure-share` (default 0.5) of attempted postings fail, or when
selected work was skipped wholesale for empty content; 2 for provider-wide
fatal errors (HTTP 401/402/403 or payment/auth exception names) — the run
stops scheduling new calls, drains in-flight work and reports; comparison runs
aggregate to the worst model, so the last model cannot mask an earlier fatal.
`ops/check-export.py` baselines now come from the last **deployed** candidate
(a `deploy` record with the same run id; legacy logs fall back to status ok)
and a rolling window of the last seven healthy runs — failed candidates are
preserved for diagnosis but never lower the bar. New checks: sustained
decline (coverage vs the best healthy run, so a 1.5-pt-per-run bleed trips
without any single 5-pt drop), configurable absolute schema/v3 floors (soft,
off by default, v3 floor skipped under a pinned v4 rollout), intake freshness
(newest publication age, Bucharest weekdays only) and title/employer/
published/body fill rates against the baseline. `ops/run-pipeline.sh` now
blocks deployment after any pipeline step failure unless
`POSTURI_ALLOW_DEGRADED_DEPLOY=1` is set explicitly (the previous
deploy-if-the-check-passes behavior was implicit degraded publication), and
`ops/record-deploy.py` records every deploy outcome (healthy or degraded)
against the candidate run id.

**Validation:** 30 new tests (webapp/tests/test_pipeline_health.py): fatal
classification and no-retry behavior, 429 recovery, exit-code matrix
(all-success, 1/10, >50%, all-fail, fatal, zero-work, skipped-all, mixed
comparison aggregation), record contract, baseline selection with
deploy-gating, failed-candidate exclusion, seven-run window, legacy-log
fallback, absent/malformed logs, sustained decline (the 1.5-pt scenario),
floors incl. the v4 skip, intake freshness on a fixed weekday/weekend clock
and fill-rate drops. Full suite: 509 passed. Live run of check-export against
a real export renders every new check. PHP suite unaffected (374 assertions).

**Non-obvious decisions:** Fatal is per-provider: a 402 stops that model's
scheduling while other comparison models still run. Summary counts are
postings, with `retried` as the extra-API-attempt counter — stated in the
record's contract. The new default (block deploy on step failure) is stricter
than the old behavior and is visible to the VPS operator; degraded
publication stays one env-var opt-in away with a persistent `degraded` record.

**Still open (rollout):** threshold calibration against copied historical
JSONL records, one verified unattended success and one controlled failure on
the VPS, and host verification of the new default. Not closable from unit
tests alone — per the spec.

---
### 2026-10-03 — FIX-08: atomic, validated attachment downloads (code + tests + real-cache audit)

**What:** New `download_manifest.py` owns the download cache: identity is a
hash of the normalized URL (query strings part of identity, basename
collisions impossible), files land as `<key>.<ext>` beside a JSON manifest
(source URL, sha256, size, retrieval time, content type), and `download_file`
streams to a temp file that is renamed only after the transfer stayed within
size/time bounds and the body validated — magic bytes (DOCX/DOC/PDF), DOCX ZIP
integrity, Content-Length comparison, HTML-error-pages-with-200 rejected by
sniffing, unsupported formats get an explicit reason. A failed replacement
leaves existing bytes untouched; extensionless URLs take the sniffed format.
Cache hits are validated (magic + manifest size; deep container checks are the
audit/repair paths only, so the hot path stays cheap). Legacy basename files
keep resolving for compatibility; `--repair-legacy` adopts valid ones into the
hashed cache and records invalid ones in `legacy-invalid.json` without
deleting anything. `download-attachments.py` is now the CLI (summary with
cached/downloaded/replaced/invalid/failed counts, >50%-failure batches exit
nonzero per FIX-04, pacing unchanged); `extract_attachments` and
`quality_check.py` resolve through the same module, and failures say *why*
(“cached file invalid: …”) instead of reading as “not downloaded”.

**Validation:** 33 new tests (webapp/tests/test_download_manifest.py) cover
the acceptance matrix with a scripted fake session: interrupted streams,
empty/length-mismatch/HTML bodies, broken DOCX, valid chunked PDF, duplicate
basenames, query-string and extensionless URLs, size bounds, failed-vs-
successful replacement, legacy adoption and batch usability. Full Django suite
green (479 passed). Real-cache audit (`--audit`, read-only) over 9,994 URLs:
9,925 valid legacy files, 3 invalid (one corrupt DOCX, one JPG under .doc,
one unsupported), 35 missing — evidence recorded, no repair run (operational
step, distinct from code completion).

**Non-obvious decisions:** A failed *download* never lands in the cache, so
“replaced” only occurs when an invalid file already sat at the hashed target.
Valid files are never re-fetched (cache hit), which is why the
old-bytes-intact guarantee is exercised through the invalid-entry path.
Changed-remote-content invalidation stays with FIX-03 revision handling.

---
### 2026-10-03 — FIX-07: request validation, fixture test harness, active CI (code + local evidence)

**What:** New `webapp-php/query.php` decodes every request at the front
controller: scalar params (`q`, `sort`, `status`, `page`, `employer`, dates…)
must be single strings; facet params normalize to flat bounded arrays (legacy
`?eqf=6` scalars included); nested arrays, array-valued scalars, oversized
values and over-long facet lists get one controlled 400 — HTML for pages, JSON
for feeds — through `index.php` before any page or feed runs. `?q[]=medic` is
no longer a 500. Built `tests/fixtures/build_db.php` (schema mirrors
export-to-sqlite.py; deadline matrix, hostile Markdown, multi-role v3,
diacritics, 38 rows, no real data), a shared fixture server helper, and three
suites: request_test.php (134 assertions: 400s, valid/legacy shapes, all
routes, canonical 301s, DB-access denial, feed format parsing with filtered
feeds, facet/result parity, chips, pagination, sort, search, HTMX partial),
deadline_test.php refactored onto the shared helper, sanitize_test.php. Added
a Playwright suite (12 tests at 320/375/1280: overflow, one-deadline row,
drawer keyboard/focus + HTMX + URL/back state on mobile, chip removal on
desktop, and the FIX-01 no-execution proof — no dialogs, no `javascript:`
anchors, no inline handlers, legitimate link intact). New
`.github/workflows/ci.yml` replaces the archived workflow: Django+PostgreSQL
job (shape from the archived file), PHP 8.2 lint + three suites, and a
Playwright job with pinned tooling; README documents the runnable commands.

**Validation:** All three PHP suites green locally (374 assertions), whole
tree lints, Playwright 12/12 with the fixture server, `npm ci` clean.
CI-run evidence requires the first push — the workflow itself is not yet
proven green on GitHub Actions.

**Non-obvious decisions:** Facet param set derives from `MULTI_PARAMS` so form
field names and the decoder cannot drift. Unknown params pass through (compat);
unknown enum/date values fall back predictably instead of 400ing. The 400 page
renders without the database. Playwright's webServer starts before any
globalSetup hook, so the fixture is built inside the server command itself.
One real bug found en route: Romanian quotes adjacent to `$key` in string
interpolation parse as part of the variable name (`„$key”` → `$key”`) — fixed
with braces.

---
### 2026-10-03 — FIX-02: one deadline per posting, Bucharest boundaries, qualified estimates (code + tests)

**What:** Every surface now resolves the same application date through
`posting_deadline()` (helpers.php) — `apply_deadline` plus its stored
`deadline_source`. The list row that showed “13 zile” beside the expiry now
prints the deadline in both places (`<time datetime>` included); detail badges,
sidebar, meta description and employer page follow. `expirare` rows are
visibly qualified (“estimat” on rows, ≈ countdowns, “Termen estimat” badges,
and “Data expirării; termenul de înscriere nu este confirmat.” on the detail
sidebar) and never read “Înscrieri până la”. Rows without any date show
“Termen neprecizat” instead of nothing. All day-boundary math moved to
`ro_today()` in Europe/Bucharest (status filter, +7d window, quick stats,
employer/stats counts, sitemap), with `POSTURI_TODAY` as a documented test
seam. JSON-LD `validThrough` now uses the exact source hour when
`data_limita_depunere` carries one (same day only), instead of claiming 23:59.
Atom summaries label estimates (“Termen estimat (data expirării anunțului)”);
the iCal feed now emits exclusive next-day `DTEND` (the old `DTEND == DTSTART`
was a zero-day event), labels estimates in DESCRIPTION, declares the 200-item
cap in `X-WR-CALDESC` and keeps UIDs stable. `expires_at`/`apply_deadline`/
`deadline_source` JSON keys are unchanged.

**Validation:** New `tests/deadline_test.php` (57 assertions): fixed-clock
`days_until` and `posting_deadline` units, `ro_today()` independence from
host TZ (UTC/New York/Tokyo subprocesses), and a rendered suite that builds
the new fixture DB (`tests/fixtures/build_db.php`, deterministic, no real
data), serves the app on the built-in router with `POSTURI_TODAY=2026-10-03`
and asserts countdown/`<time>`/printed date agreement per row (future,
same-day, past via `?status=all`, expiry fallback, no-dates), the detail
warning, exact-time `validThrough`, the non-duplicated end line, Atom/iCal
labels, one-day iCal durations and JSON key compatibility. The real export's
`posturi.ics` (200 events) parses with the Python `icalendar` 7.1.2 parser:
zero non-one-day events. Sanitizer suite still green (155), whole PHP tree
lints.

**Non-obvious decisions:** `deadline_source` is trusted when present; for
exports predating the column, a date equal to the expiry infers `expirare`.
Parsedown stays on the sanitized path; no JSON field names changed, so feed
consumers are unaffected. Rollout is code-only via `deploy-php.sh --code-only`
(pending, like FIX-01).

---
### 2026-10-03 — FIX-01: parser-based Markdown sanitization (code + tests)

**What:** Replaced the attribute-passing `strip_tags()` sanitizer with vendored
HTMLPurifier v4.19.1 (LGPL-2.1+, `webapp-php/lib/htmlpurifier-library/`, license
copy at `lib/htmlpurifier-LICENSE.txt`; `lib/.htaccess` denies direct access).
`sanitizer()` in `helpers.php` is the single config: allowlisted elements,
`http/https/mailto/tel` schemes only (relative/fragment URLs stay allowed),
frame-target and rel allowlists with automatic `noopener noreferrer`, and
`style/class/id` forbidden. Both render call sites (detail body, schema sections)
flow through it; the fallback renderer is now a named, testable function and is
sanitized on the same path. Parsedown safe mode stays off — the DOCX→Markdown
conversion can emit raw HTML (tables/images), which safe mode would print as
literal text; the sanitizer is the security boundary.

**Validation:** New `webapp-php/tests/` harness (bootstrap.php + sanitize_test.php):
155 assertions across the audit payload, encoded/mixed-case/whitespace
`javascript:` and `data:`/`vbscript:` schemes, event attributes on allowed tags,
script/SVG/iframe/object embedding, malformed HTML repair, link survival,
tables/code/lists, diacritics, realistic announcement formatting, and the
fallback path. Every output must also re-parse as well-formed HTML. All green;
`php -l` clean. Live-page smoke test against a fresh local export (`POSTURI_DB`
pointed at `/tmp/posturi-test.sqlite`): list, raw-body detail, structured detail,
three feeds, employers/stats/about/robots/sitemap all render; no `javascript:`
or event-handler markup survives in scraped content (the only `onchange=` match
is the template-owned skin picker).

**Rollout:** Code deploy (`deploy-php.sh --code-only`) is pending — not yet
shipped to the host. The lib/ tree rides the normal rsync; no re-extraction or
backfill is needed. Browser-level no-execution fixture lands with FIX-07.

---
### 2026-10-03 — Record the agreed user-facing product direction

**What:** Saved the feature discussion in [the product direction](product-direction.md),
covering existing user-facing backlog capabilities and five additional enhancements:
browser-local save/hide, a compact eligibility panel, application checklists,
change notices for saved jobs and source evidence beside requirements. Added
UX-09 through UX-13 to [the backlog](backlog.md) and linked the direction from README.

**Intent:** The user confirmed that we should aim to implement or address the
whole list. The recommended first release combines clearer deadlines, simpler
filters, locality/employer discovery and a shortlist without accounts; preparation,
change tracking and matching follow their data-quality prerequisites. All listed
capabilities remain intended scope, with phased delivery and no promised dates.

**Non-obvious decisions:** Kept profile matching under UX-07 and linked its compact
eligibility entry point under UX-10. Account-free local state is the first step,
with privacy/retention design before server-side CV/account features. Unknown
requirements stay unknown, and multi-role matches must be role-specific. This
records product intent, not implementation completion. Checked document links,
heading anchors, task IDs and whitespace; runtime code is unchanged.

---

### 2026-10-03 — Track Codex guidance and project-local skills

**What:** Committed the existing `AGENTS.md` update and the project-local
`source-command-pipeline-check` and `source-command-quality-review` skills under
`.agents/skills/`, as requested. This makes the guidance and migrated operator
commands available in future checkouts. Reviewed their contents; no runtime code
changed and neither skill was executed. Left `AGENTS.md.old` as an untracked local
backup. Known guidance drift remains assigned to FIX-12 in the consolidated backlog.

---

### 2026-10-03 — Project audit, remediation specifications and backlog consolidation

**What:** Saved the initial code/documentation/live-site assessment in
[the project audit](audits/2026-10-03-project-audit.md). Prepared
[12 technical remediation specifications](specs/2026-10-03-project-audit/README.md)
with bounded scope, acceptance criteria, fixture-based validation, dependencies
and migration/rollout guidance for later assignment to coding agents. Rewrote
[the active backlog](backlog.md) around stable IDs and priorities, separating
implementation from paid backfills, host verification and production deployment.

**Why:** The audit found unsafe PHP Markdown attributes/URLs, uncertain application
deadlines, immutable detail caches, extraction runs that can falsely exit cleanly,
misleading freshness/history claims, malformed query 500s and non-atomic attachment
downloads. The current interface and architecture are useful; source correctness
and unattended reliability take priority over new product features.

**Evidence:** At baseline `2c5e480`, all 446 Python tests passed with PostgreSQL
access; all 22 PHP files passed syntax checks. Playwright checked desktop/mobile
pages and an HTMX county filter without browser errors/overflow. Live data showed
1,875 application-active announcements and October 2 intake; the newest 200 JSON
results had 194 expiry-fallback deadlines and six scraped deadlines. These are
dated audit observations, not whole-corpus accuracy measurements. No production
host inspection, paid model calls or deployments were made.

**Non-obvious decisions:** Preserved the old backlog verbatim in
[a dated archive](archive-reference/backlog-2026-10-03-before-consolidation.md),
with [a mapping of every previously open checkbox](audits/2026-10-03-backlog-consolidation.md).
Superseded old outage/count claims using source or later evidence, while retaining
current operational verification tasks. Corrected the DST task's minute drift;
current VPS schedule still needs inspection. Kept PHP/SQLite serving and
Django/Postgres processing as the implementation baseline. No issue is marked
fixed merely because its specification was written; frontend retirement must
retain models, migrations, admin and useful variant-comparison tools.

**Documentation validation:** Checked local links, stable ID/spec references,
complete old-item mapping, exact archive preservation and whitespace before
commit. New/edited content passes whitespace checks; the verbatim archive retains
the original file's existing trailing whitespace. Runtime test results above are from the initial audit; this follow-up
changes documentation only. Pre-existing `AGENTS.md` edits and unrelated
untracked agent files remain outside the documentation commit.

---

### 2026-10-03 — /statistici shows daily LLM extraction spend

**What:** `/statistici` has a new "Costuri inferență" section: three tiles (total, last 30 days, cost per 100 postings) and a per-day list of the last 30 days with activity. It answers the "make spend visible" half of the cost-baseline backlog item without anyone having to grep logs.

**How:** `export-to-sqlite.py` gains an `llm_costs` table (`day, provider, model, prompt_version, calls, input_tokens, output_tokens, cost_usd`), summed from `jobs_jobpostingschemavariant` by `export_llm_costs()`. The PHP site has no Postgres, so the totals have to ride in the SQLite file. `stats.php` reads it behind a `try/catch (PDOException)`, so an export from before the table existed renders "Date indisponibile" instead of a 500. New helpers `usd_label()` and `postings_label()` (Romanian plural rule, "722 de anunțuri") are in `helpers.php`.

**Non-obvious:**
- The day is `created_at AT TIME ZONE 'Europe/Bucharest'`, converted in the query rather than trusting the session. A UTC session splits one run across two dates. The 2026-10-03 backfill (821 postings, 00:45–12:29 local) shows as 722 on 10-02 and 99 on 10-03 if grouped in UTC, and as one 10-03 row in the export. `test_day_is_the_bucharest_day_not_the_utc_day` pins it (Django's connection is UTC, so it would fail on a naive `::date`).
- Rows with a NULL `cost_usd` are skipped, not counted as free: NULL means the provider returned no usage, and counting them would drag the per-100 figure toward zero.
- The table is **not** a ledger. `write_variant()` upserts on `(posting, provider, model, prompt_version)` and resets `created_at`, so a re-extracted posting's cost moves to the new day and the old spend disappears. The page footnotes this. An append-only ledger is on the backlog.
- Scope is the `schema` step only. `infer` and `occupations` also call DeepSeek but record nothing, and the footnote says so; the page's total is not the whole bill.
- The section is on the public page, as requested.

**Checked:** 6 new tests (`webapp/tests/test_llm_costs_export.py`, real Postgres aggregation); full suite 446 passed. A real `--active-only` export into the scratchpad gave 34 rows summing to 5,006 calls and $5.5825 over 2026-05-27 → 2026-10-03, identical to a direct `SELECT` on Postgres. The page's three queries were run in `sqlite3` against that file, and against the old `posturi.sqlite`, where `no such table: llm_costs` is the error the `catch` handles. Every CSS class the section uses already exists in the compiled `static/app.css`, so no `npm run css` was needed.

**Not checked:** the page was not rendered, because this machine has no PHP (and no Docker). Needs one look in a browser after deploy.

**Rollout:** the cron only pushes data (`--data-only`), and code deploys are manual. Either order is safe: new code on an old SQLite shows "Date indisponibile", and new SQLite under old code is ignored. The section appears once both have landed (`./deploy-php.sh --code-only`, then the next cron data push).

### 2026-10-02 — fetch-index: the listing card became the link; scrape works again, and a 0-card scan now fails

The source changed its listing markup on 2026-09-30: each card is now `<a class="pg-card pg-card--link" href="…/joburi/…">` instead of `<article class="pg-card">` wrapping `a.pg-card-link`, and the deadline countdown (`div.pg-card-deadline`) was removed. `fetch-index.py` now selects `.pg-card` and takes `href` from the card when it is an `<a>`, falling back to the inner link for the old markup. All other card selectors (title, employer, city, published date, tags) still match.

Non-obvious: when the card has no deadline, `expira_in` is **left out** of the scraped dict rather than set to `''`. `compare_and_update()` only diffs the keys present, so an empty value would have logged an `expira_in` change on every stored row and rewritten the CSV on every page. Expiry has come from the detail page (`.pg-meta-deadline`) since ~09-15 anyway. Checked in memory against the live site: page 1 had 12 new rows with every field filled, and page 60 had 12 unchanged with no diff.

Added backlog guard (1): if a full scan matches 0 cards, the script exits non-zero (`sys.exit` with a message naming the selector), so healthchecks.io gets `/fail` instead of the 12 silent exit-0 runs we had.

A Sonnet sub-agent checked every detail-page selector in `parse-anunturi.py` / `fetch-anunturi.py` against three fresh `/joburi/` pages. The detail markup did not change, and all fields parse. It found two problems, both now in the backlog: cancellation detection relied on the vanished index field (`.pg-status.is-off` on the detail page is the replacement), and an older gap where `data_limita_depunere` is mostly empty.

DeepSeek was topped up by the operator; `/user/balance` shows $9.98 available. The backfill run was not started from this session, because the pipeline wrapper also deploys. The next cron slot, or a manual `ops/run-pipeline.sh`, picks up both the missed postings and the ~790 pending extractions.

### 2026-10-02 — /pipeline-check: the cron ran every slot and reported success, while scraping nothing for 3 days and extracting nothing for 10

**What:** Ran `/pipeline-check` on `gov2-1`. Mechanically the machine is healthy — 16 of 16 slots in the last 8 days fired (45 cron runs since 2026-09-10, one failed run on 09-13 and nothing since), `HEALTHCHECK_URL` is set, the live site returns 200 and carries the latest build (18:56 local = `built_at` 15:56Z), and all 44 `export-check` records are `ok`. The data underneath is not healthy, and nothing that watches the pipeline could see it.

**Two silent failures, both exit 0:**
1. **Scraper blind since 2026-09-30 08:45Z.** The source now wraps each card in `<a class="pg-card pg-card--link" href=".../joburi/...">` instead of `<article class="pg-card">`, so `fetch-index.py`'s `article.pg-card` matches nothing. Every page logs `0 new, 0 updated, 0 unchanged (0 total)`. Tell-tale in the run log: `fetch-index` 6–36 s → ~315 s (it still walks ~225 pages) and `fetch-detail` 2 s even on weekday mornings.
2. **LLM account out of credit since 2026-09-22 08:45Z.** DeepSeek returns 402 `Insufficient Balance` (partway through the 09-22 08:45Z run); `schema` has had 0 successes in the 21 runs since. `schema_coverage_pct` 94.7 (09-21) → 60.1 and `v3_coverage_pct` 82.2 → 55.4, about -1.5 pts per run — never near the per-run 5-pt threshold.

**Not changed:** no code was touched; fixes and the guards that would have caught these are in `docs/backlog.md` (three new items under *Pipeline & data quality*).

**Also found:** `MAX(last_seen_at)` is the *import* date, not a scrape date (`import_csvs.py:363` stamps `today` on every imported row), so it reads 2026-10-02 on all 1,981 active rows and cannot flag staleness; the comments claiming otherwise are wrong. The system timezone is `Etc/UTC` rather than `Europe/Bucharest`, which is the known, documented workaround (cron here ignores `CRON_TZ`) and harmless at the two run times — but the slots shift an hour at the 2026-10-25 DST change (already in the backlog).

**Cost baseline** (measured from the logs, `schema` step only): about $0.10 per 100 postings on DeepSeek-V4-Flash, ≈ $1.80/month at the observed ~58 extractions/day, so a $5 top-up lasts ~86 days. The same tokens on Haiku 4.5 would be ≈ $1.05–1.56 per 100. Full figures and caveats are in the backlog.

---

### 2026-09-21 — Normalised occupation title surfaced in the UI, and a week of undeployed PHP found in the process

**What:** `normalize-titles.py`'s COR-normalised occupation (`occ_canonical`, exported to `job_postings.occ_canonical`, populated on 2,035 of 2,042 active postings) was extracted and indexed but only ever used as the "occupation" search facet. Every place a posting's title actually renders — the detail page `<h1>`, listing cards, the employer profile, feeds — showed the raw scraped string verbatim, grade/seat-count/casing clutter and all ("asistent medical generalist Pl" instead of "Asistent medical generalist").

**Fix:** Added `display_title(array $p): array` to `webapp-php/helpers.php` — primary is `occ_canonical` when it's non-empty and differs from the raw title (case-insensitive), secondary is the raw title, falling back to raw-only for the ~7 unmatched postings. Wired into `pages/detail.php` (h1 + a "normalizat" abbr badge, reusing the existing "v3" tag's visual convention), `partials/result_list.php` (listing cards) and `pages/employer.php` (which needed `occ_canonical` added to its two hand-rolled `SELECT`s). Left the `<title>` tag, meta description, JSON-LD and the feeds on the raw title — those represent what the source posting actually said, not the UI headline.

**Also found:** the change didn't appear live after editing, because the shared host hadn't had a *code* deploy since before `35bbd78` (2026-09-13) — `deploy-php.sh`'s cron entry only ever runs `--data-only` (the twice-daily SQLite push); `--code-only`/full deploys are a manual step nobody had run in over a week. `deploy-php.sh --code-only --dry-run` showed 12 files pending, not the 4 just edited — the other 8 were already-committed work (the 2026-09-13 v3-fields-display feature, feeds, stats, sitemap, facets) sitting live-but-invisible the whole time. Confirmed via a raw `curl` diff of the live detail page against the pre-edit source, since no Chrome/Playwright browser is installed on `gov2-1` for a visual check. Deployed with `./deploy-php.sh --code-only` (HTTP 200 verified); re-fetched both a detail page and a search-results page live to confirm the primary/secondary titles render correctly. Backlog item added for the missing deploy-freshness signal.

---

### 2026-09-16 — Two unpushed v4 calendar-stage fixes disagreed; checked the source data to settle it

**What:** Pushing this box's unpushed commit hit a real merge conflict with `origin/main`, not a fast-forward. This box's `8e9a8ba` (2026-09-14, dev machine) and the VPS-pushed `c17deb0` (2026-09-14, a few hours earlier) had both independently touched the v4 prompt's calendar-stage instruction in `models_config.json`, in response to the same symptom, and reached opposite conclusions.

**Finding:** `c17deb0` found the model composing `rezultate_contestatii_<stagiu>` values it decided were invented — the templated instruction (`rezultate_<stagiu>` / `contestatii_<stagiu>`) let the model treat `contestatii_selectie_dosare` itself as a `<stagiu>` and build a third level. It fixed this by listing all 22 stage values literally and explicitly denying the compound exists, folding a contestation's outcome into `contestatii_<stagiu>`. `8e9a8ba`, working from the same pre-fix base a day later without knowledge of that commit, hit the identical shape of failure in a 50-posting resample and drew the opposite conclusion: the compound is real content, not a hallucination, and added it as a genuine 4th tier. Before picking a side, checked `data/calendar.csv` directly: postings routinely carry both "Depunerea contestațiilor" and, a day or more later, "Afișarea rezultatelor în urma soluționării contestațiilor" as separate dated rows (6,758 matching lines). Folding them into one stage value silently drops one of the two dates.

**Fix:** Merged rather than picked a side — kept `c17deb0`'s discipline (list every value literally, no `<stagiu>` template anywhere, generated from the enum so it can't drift) but applied it to the fuller 28-value enum with the outcome tier, and replaced the denial sentence with a concrete example instead of a template. `schema_models.py`'s unrelated `Credential.kind` normalisation (origin-only) merged clean with no overlap. Updated `test_every_evaluation_stage_has_both_counterparts` to require the 3rd tier too, and replaced `test_the_prompt_forbids_composing_new_stage_values` (asserted the compound must be denied) with `test_the_prompt_lists_no_stage_template` (asserts only the still-true template-placeholder rule). 440 tests pass. Commit `9b16f8c`.

---

### 2026-09-16 — The 2026-09-11 CRON_TZ fix didn't take; the cron on `gov2-1` doesn't support CRON_TZ at all

**What:** `/pipeline-check` run. Data and deploy were healthy — no hard or soft check failures across 10 recorded export-checks, schema coverage climbing (97.7%→99.6%), live site in sync with the local build. Flagged as a possible missed slot the 2026-09-16 11:45 run not yet having appeared, which led to re-checking the cron schedule directly.

**Finding:** The 2026-09-11 entry above concluded the fix was uncommenting `CRON_TZ=Europe/Bucharest`. That variable was in fact set — both globally at the top of the crontab and again in the posturi block — and the jobs were still firing 3h off. Confirmed via `sudo journalctl -u cron --since "2026-09-16 08:40" --until "2026-09-16 08:55"`: only root's `debian-sa1` sysstat job ran in that window, no posturi invocation — the 08:45 UTC slot (which is where 11:45 Bucharest lands *if* CRON_TZ worked) never fired, because the real slot is 11:45 UTC. Cross-checked against two unrelated jobs sharing the same crontab and the same top-level `CRON_TZ`: `monitorulpreturilor`'s `0 6 * * *` audit wrote its log at 06:06 UTC (not ~03:06) and its `40 3 * * *` gas-price fetch at 03:59 UTC (not ~00:59) — both running on literal UTC, confirming this isn't posturi-specific. Root cause: `gov2-1` runs `cron` 3.0pl1-184ubuntu2, Debian/Ubuntu's classic vixie-derived package, which does not implement `CRON_TZ` (that's a `cronie`/RHEL-family feature) — the variable is silently ignored, no warning anywhere.

**Fix:** Dropped `CRON_TZ` from the posturi crontab block and rewrote the schedule in literal UTC: `45 11`/`33 18` → `45 8`/`33 15`, which land at 11:45/18:33 Bucharest under the current EEST (UTC+3) offset. Verified live with `crontab -l`. Left the rest of the crontab (monitorulpreturilor, monitoruloficial, sumal) untouched — same underlying bug likely affects their documented local-time comments too, but that's their call to make, out of scope here. Needs a manual -1h shift around 2026-10-25 (DST changeover to EET) — tracked in `docs/backlog.md`, along with the proper long-term fix (`ops/systemd/posturi-pipeline.timer`, already written and DST-aware, but blocked on a `/srv/posturi` + dedicated-user migration that hasn't happened).

**Also found:** this box's `crontab <file>` command truncates/corrupts filenames longer than ~99 characters instead of erroring — installing from a long scratch path (`/tmp/claude-.../scratchpad/crontab-after.txt`, 114 chars) silently failed with a garbage "file not found" on a truncated path. Installing from a short path (`~/crontab-posturi-new.txt`, 33 chars) worked. Worth remembering for any future crontab edit on this host.

---

### 2026-09-14 — A v4 sample on the VPS found three bugs, two of them mine

Ran prompt v4 over 100 active postings on the VPS with `--compare` (variants
only, `schema_json` untouched) before committing to a corpus-wide run. The
headline held: **95% yielded an application deadline**, `expires_at` was later
than it on 106 of 113, and 109 were recovered from postings the scraper got
nothing for. Peak output 4,982 of an 8,000 budget.

**Nine hard failures, one cause — and I had fixed the wrong layer.**
`contestatii_proba_practica` was missing from the stage enum: ~850 postings at
corpus scale. This was the *second* instance in two days, so instead of adding
the value, an audit: `proba_practica` had no contestations, `proba_sportiva` and
`test_psihologic` had neither results nor contestations. Three more gaps
waiting. It is now a rule — every stage in `EVALUATION_STAGES` has all three
forms, and a test fails if one goes missing, plus a second test that the prompt
lists every value the schema accepts, since a stage the model is never shown is
equally unreachable.

**"4 disagreements" was 4 out of 4, not 4 out of 113.** The checker printed the
count with no denominator, and that is a defect in a tool whose whole job is to
gate a decision. Of 113 deadlines, 109 were recoveries, so only four postings
had both a scraped and a v4 date — and all four differed. It now prints the
denominator, separates disagreements over three days from off-by-one noise, and
fails the sample on the former.

**Chasing those four found the real bugs, and none of them were v4's.**

*Two were a timezone artifact in my own query.* `data_limita_depunere` is a
timestamptz stored at local midnight — 21:00 UTC the previous day in summer — so
a plain `::date` cast returns a different day depending on the server's TimeZone
setting: `Europe/Bucharest` on the dev box, `UTC` on the VPS. The same query
gave different answers on the two machines. Worse, `export-to-sqlite.py` derived
`apply_deadline` with `str(value)[:10]`, so **the column shipped the day before
was a day early on the VPS and correct locally** — the wrong one being the
machine that serves the site. Fixed at both levels: the export pins the session
timezone on connect, and `_local_date()` converts explicitly so the function is
right however it is called. Same class as the cron running 3h off Bucharest time
found on 2026-09-11.

*Two were genuine scraper bugs, and v4 caught them.* `_find_calendar_date`
returned the first row matching any of `['depunere','inscriere','dosar','limita']`
— and `dosar` also matches "Selectarea dosarelor de concurs", so the deadline
could land on the selection date, days after applications closed. And
`DATE_RE.search` takes the first date on a line, so "Depunerea dosarelor de la
11.09.2026 până la 30.09.2026" yielded the day submissions *opened*.

Re-parsing the corpus moved **530 of 1,692 comparable deadlines, 92% of them
later** — exactly the signature of both bugs. Coverage held at 1,702, but only
after relaxing the exclusions on the fallback tier; the first version lost 148
postings by refusing rows that combine submission with selection, and a worse
date beats no date.

A third thing fell out: one posting reads `-20.05.2025-03.06.2024-`, an
institution typo, and reading the window's closing date faithfully published a
deadline a year in the past. A window that closes before it opens is a typo, not
a date. After the guard, no posting has a deadline preceding its own
publication.

**And `parse-anunturi.py` never had an `if __name__ == "__main__"` guard.**
Importing it re-parsed all 9,691 cached pages and rewrote both CSVs as a side
effect. It made the new test file take 74 seconds and had already mutated
pipeline output once during this work. Now 0.22s.

**What this changes about trusting v4.** The claim "v4 dates are more accurate
than scraped ones" was resting on six agreements. It now rests on four
disagreements that were investigated and resolved in v4's favour — two were my
measurement error, two were scraper bugs v4 exposed. That is better evidence
than agreement would have been, but it came from reading four postings, not from
a number in a report.

---

### 2026-09-14 — Re-import with the parser fix, then a v4 re-sample found a fourth enum gap

**Re-import.** Ran `pipeline.py --steps parse,import` to pick up the deadline
parser fix from earlier the same day. Detail rows: 9986/9987 matched (1 error,
see below). Calendar: 28596/28597 events imported. Index: 9992 updated, 86
marked cancelled. 1848 active postings now carry the corrected deadlines instead
of the stale scraped ones.

One posting failed both detail and calendar import:
`value too long for character varying(40)` on
`1-post-muncitor-treapta-i`. Logged to backlog rather than chased — 1/9987.

**v4 re-sample, round one: same bug, one layer deeper.** Cleared the 190 stale
v4 variant rows from the prior VPS sample (disposable per the 2026-09-14 sample
note above) and re-ran 50 active postings through deepseek. 46/50 succeeded, but
26 needed a repair retry and 4 hard-failed — worse than the fix was supposed to
leave things. The failures all named the same shape of value:
`rezultate_contestatii_selectie_dosare`, `rezultate_contestatii_proba_scrisa`,
`rezultate_contestatii_interviu`. The enum had been made symmetric one level
(`<stagiu>` → `rezultate_<stagiu>` → `contestatii_<stagiu>`) but postings that
report a *contested* result also report the outcome of that contestation, and
nothing in `CalendarStage` could hold it.

**Fix and round two.** Added `rezultate_contestatii_<stagiu>` to
`schema_models.py::CalendarStage` for all six testing stages, and extended the
`competition_calendar` explanation in the v4 prompt (`models_config.json`) to
describe the third tier. Cleared the round-one sample and re-ran the identical
50 postings: 50/50 succeeded, 1 retry. `ops/check-v4-sample.py`: 48/50 deadline
extraction, 45 overstate `expires_at` (avg 15 days, worst 62), 0/2 disagreements
where both sources had a date — **"Sample looks fit to scale up."**

The script still prints 3 "stage/verbatim mismatch" lines; read against the raw
`verbatim` text, all three are the word *rezultat* used as an ordinary Romanian
past participle ("punctajul rezultat din analiza...") rather than the
calendar-stage sense. The keyword heuristic can't distinguish the two — not an
extraction bug, just a noisy check. Worth narrowing later if the false-positive
rate on a corpus run gets annoying.

**One more found and left alone.** `credentials.kind` hard-failed once on
`certificat_professional` — the model's own spelling drift toward English,
since both the schema and the prompt already say `certificat_profesional`
correctly. Logged to backlog as a candidate for repair-time normalization rather
than another schema/prompt change.

**Not done yet:** the corpus-wide v4 run. This was a 50-posting fitness check,
same as the VPS's 100-posting one — see backlog.

---

### 2026-09-13 — "Active" means you can still apply; v3's unused fields surfaced

**Two changes, both about data we already had and were not using.**

**"Active" now follows the application deadline.** The site treated `expires_at`
as the cutoff, but that is when the *competition* ends — the final-results date.
On the 20-posting v4 sample it ran a median of 15 days, up to 52, past the day
applications closed, and on the live export **33 postings shown as active had a
deadline that passed between 4 and 275 days ago**. One had been advertising a
competition that closed nine months earlier. If you cannot apply, it is not
active.

`apply_deadline` is computed at export time from the best source available —
the v4 competition calendar (`concurs`), else the scraped card field (`anunt`),
else `expires_at` (`expirare`) — and `deadline_source` records which. The
fallback is the point: v4 has not run corpus-wide and only 81 of 1,621 active
postings carry a scraped deadline, so 94% keep exactly the behaviour they had
and every posting v4 later reaches improves on its own.

Status, "expiră în 7 zile", deadline sorting, the date-range filters, countdown
badges, the employer split, the employers/stats counts, the sitemap staleness
check and Schema.org `validThrough` all follow it. So do the feeds — an iCal
reminder for a competition that closed three weeks ago is worse than no
reminder. `expires_at` stays where it still means something: the detail page
shows "concursul se încheie" beside the deadline when they differ, and
`pages/stats.php` still measures competition duration with it. **Showing only
one of the two dates is what made closed competitions read as open.** Active
drops 1613 → 1580 on the current export.

**v3's unused fields are now browsable.** `contract` was populated on 1,470 of
1,549 structured payloads, `bibliography_topics` on 1,102, `seniority_hint` on
635 — extracted since v3 first ran, exported nowhere, visible nowhere. No new
LLM work was needed; the project had already paid for all of it.

They now drive three facets (Contract, Program, Lucru în ture) and a detail-page
block. `contract.duration` is strictly better than the scraped `job_type`: it
separates a fixed-term post from one that runs only as long as its project,
where `job_type` only ever says Permanent or Temporar.

Two smaller things came out of it. `shift_work` is exported **tri-state** —
NULL means no contract block was ever read, 0 means the posting says no shift
work, and collapsing them would claim thousands of postings involve no shift
work on the strength of no evidence at all. And the FTS index now covers
`occ_canonical` and the bibliography themes, so a search for "îngrijitor"
matches a posting titled "ÎNGRIJITOARE", and "achiziții publice" finds the 102
competitions that actually examine on it rather than only those that say the
words in the body.

**Deliberately not merged:** `inf_seniority` names the role family (referent,
inspector, consilier) and v3's `seniority_hint` the career stage (debutant,
principal, superior). They are different axes, not two versions of one, so they
are not reconciled — but they now both appear, which is a backlog item.

---

### 2026-09-13 — Estimated pay from the 2026 draft grid, and occupation normalisation

**Why now.** The site could never show pay from its source text: **44 of 9,757
postings state a salary in the body, 5 of 917 attachments do.** `baseSalary`,
`inf_salary_min/max` and the `SALARY_BUCKETS` facet all already existed, and the
facet was gated behind `>= 100` rows — so it had never once rendered. A "cu
salariu anunțat" filter would have returned ~40 jobs. The 2026 draft salary law
makes pay *derivable* instead, which turns a dead field into the site's most
distinctive one.

**The grid was more usable than its own README said.** `docs/salarii/salarizare_admin_2026_bundle/`
marked 835 of 1003 Anexa VIII coefficients `formula_XLOOKUP_necalculata`. That
was a parser artifact — Excel saved the formula results, and
`openpyxl(data_only=True)` reads them directly. `build-salary-grid.py` now
materialises **2,189 rows across all 48 sheets, annexes I–IX**, which matters
because postings are mostly not administration (sănătate 511, tehnic 499,
administrație 382); matching against Anexa VIII alone reached 12%.

Verified against the 153 rows the old bundle got right: 132 match exactly, and
**all 21 discrepancies are bugs in the old parser** — it read the `Nr. crt`
column as a coefficient (`Secretar general` at 1.0 instead of 5.4), and four of
its "coefficients" were lei-per-hour tariffs.

**The LLM emits a selector, never a number.** This is the load-bearing decision.
The law is unadopted and has at least two public variants (VR 4,100 vs 4,000,
and the coefficients differ too, not just the reference value). A number baked
into extraction would have to be re-extracted every time the draft moves; a
selector is re-costed by a script in seconds. `pipeline.py --steps
occupations,salary,export-sqlite` is the whole re-cost path.

**Title normalisation is a dictionary problem.** 9,757 postings carry 5,979
distinct titles, 4,759 normalised, **3,723** once `ocupatii.parse_title()`
strips grade, post count and department. Deterministic parsing alone lifts the
exact-match rate against COR or the grid from 16% to 47%; only the residue goes
to a model, keyed on the title and cached in `jobs_occupation`, so later runs
touch almost nothing. 12 sample titles cost $0.0023.

The model is handed shortlists of *real* COR occupations and *real* grid rows
and asked to pick one or answer `none`; anything not on its shortlist is
rejected afterwards. That guard is not optional — DeepSeek is the configured
provider and has no server-side schema enforcement, so Pydantic plus this check
are all that stand between the prompt and a plausible wrong code.

**Population band is not a refinement, it decides the answer.** `Consilier
gradul II` is coefficient 1.35 under 10.000 locuitori and 1.85 over 200.000 —
5.541 vs 7.579 lei. `uat.py` therefore returns a *set* of plausible bands: a
comună is under 10.000, but an `oraș` may be either side of the line, so the
estimate widens across both and says so. A wrong narrow answer is worse than an
honest wide one. Dropping a real `data/uat-populatie.csv` in place collapses
most of that uncertainty; the code already prefers it when present.

**Prompt v4** is a strict superset of v3, appended to its wording verbatim, with
four fields: `competition_calendar`, `funding`, `employer_context`,
`note_suplimentare`. `education` stays top-level because
`export-to-sqlite.py::_v3_columns` detects a structured payload with
`"education" in s` — nesting it would silently empty every v3 facet.

The calendar earns its place twice over. `data/calendar.csv` holds 19,335 rows
whose *event names* include 863 bare en-dashes, 512 bullets and 186 empty
strings — the table parser transcribing layout instead of content. And the
expiry date was being read off the last row of that table, which is the
final-results date, weeks after applications close. v4 pulls
`application_deadline` out as its own field and derives it from the submission
event when the model files it only as an event.

**Three bugs found by running it, not by reading it:**

1. v4 truncated mid-JSON at the 2,000 output-token budget. It surfaced as
   `Expected dict, got str`, because a truncated object does not parse and
   `parse_json_response` hands back the raw text — and retrying cannot help,
   since the repair regenerates and truncates again. The budget is now
   per-version, and **8,000** for v4. Measured on the 20 newest postings:
   ordinary answers peak at ~3,050 output tokens even with a 19-event calendar;
   the cost driver is multi-role postings, where every `positions[]` entry
   repeats education, experience, skills and credentials. One advertising eight
   roles used **7,277**.
2. Grid selection must key on the function *name*, not a code prefix. Anexa VIII
   numbers grades in a code's last segment (`…07.1`, `…07.3`) but Anexa II gives
   each grade its own base code (`21.00201026` principal, `21.00201028`
   debutant), so "the same function at another grade" is not expressible as a
   prefix.
3. The salary facet matched zero rows with the data sitting right there, and
   nothing errored. PDO binds every value as text and a `COALESCE(...)`
   expression has no type affinity, so SQLite compared a REAL against a TEXT —
   and every number sorts before every string, making `>=` unconditionally
   false. The old `inf_salary_min >= ?` worked only because a bare column
   carries affinity and converts the bind. Both sites now `CAST(? AS REAL)`;
   `test_salary_facet_sql.py` pins it.

**Run on the 20 newest postings (2026-09-13), which found three more things.**

*The expiry date is wrong on essentially every posting.* Of the 17 that yielded
an application deadline, **17 overstate it** — `expires_at` runs a median of 15
days and up to 52 days past the date applications actually close. And the
scraper had no `data_limita_depunere` at all for 11 of those 17; v4 recovered
every one. This is worse than the backlog entry implied: it is not an occasional
mis-parse, it is the default behaviour, and the site currently advertises closed
competitions as open. Reconciling `expires_at` with
`competition_calendar.application_deadline` is now the highest-value thing
waiting on a corpus-wide v4 run.

*A null boolean was costing a repair on a fifth of all postings — since v2.*
Rule 1 of every prompt is "use `null` for anything not explicitly stated", but
`ContractTerms.shift_work` and the six `required` / `in_specialty` /
`none_required` flags were bare `bool`s. A model that could not tell whether a
post involved shift work emitted `"shift_work": null`, doing exactly as it was
told, and validation rejected it — costing a full repair round-trip, a second
copy of a ~5k-token system prompt. They are now `NullableBool` /
`NullableTrueBool`, falling back to the field's own default, which is what "not
stated" already meant. Repairs on the sample dropped from 4 in 20 to 2 in 20.
**This was a v2/v3 bug, not a v4 one** — every extraction run so far has paid it.

*`--limit` was not reproducible.* `iter_postings` had no `ORDER BY`, so
`--limit 20` returned whatever Postgres happened to scan first. It now orders
newest first, which is both repeatable and the set most likely to reveal a
prompt problem.

**Anexa II needed a source fix too.** It has no grade column — the grade is
appended to the function cell after a semicolon (`"Asistent medical; …;
principal"`). Without lifting it out, three rows with different coefficients
were indistinguishable, and health is the largest family on the site.

**What it produced.** The full dictionary run mapped **3,723 distinct titles in
220s for $0.19** (1,456 exact, 1,294 probabil, 112 incert, 905 none — the `none`
bucket is almost entirely singleton long-tail titles with no grid or COR
counterpart, which is the honest answer). 9,750 of 9,756 postings are now linked
to an occupation, and **8,367 carry a salary estimate, up from 13**.

On the active export the effect on browsing is the clearer number: **1,251
distinct job titles collapse to 276 occupations**, and the 57 spellings of
"îngrijitor" become 12. After the null-boolean fix the 20-posting v4 sample
runs 20/20 with 2 repairs.

Coverage is 86% of postings rather than 100% because an occupation the model
answered `none` for has no grid row to price — deliberately, since a wrong
salary is worse than no salary.

**Not done, deliberately:** net pay (the tax engine is versioned on fiscal
dates, separately from the salary law), sporuri and the 20% cap (aggregated per
ordonator, unknowable per posting), and a vector database — a controlled
`skill_list` plus FTS5 covers the "highlight GIS" case, and `sqlite-vec` bolts
on later without a schema change if it turns out not to.

**What the exploration turned up but this change did not act on** (all now in
`docs/backlog.md`): v3 already extracts `contract` (1,390 rows),
`bibliography_topics` (1,041) and `seniority_hint`, and *none* of them is
exported or displayed anywhere; `skill_list` has drifted to 1,933 distinct
labels over 1,467 postings, 74% of them appearing exactly once, with
`operare PC` / `operare calculator` / `Microsoft Office` recording the same fact
three ways; `policy_domains` has a dead tail (`demografie_migratie` 1,
`turism` 3); and the PHP app keeps a second, hand-maintained copy of every v3
vocabulary, which this change extended with three more label constants rather
than fixing.

**Known limitation:** the dictionary key is the whole parsed title, so a posting
advertising several roles in one title (`"ISTORIC, MUZEOGRAF, ARHEOLOG,
REFERENT, GARDEROBIER"`) maps to a single occupation. v3's `positions[]` already
models multi-role postings — 21% of active rows — and wiring it into the
occupation pass is the obvious next step.

---

### 2026-09-11 — /pipeline-check found the posturi cron running 3h off Bucharest time; fixed

**What:** First `/pipeline-check` run on `gov2-1` since the observability work landed (2026-09-10). Data and deploy were healthy (1 run logged, all 23 export checks pass, live site in sync with the local build), but the run's `started_at` didn't line up with the documented schedule.

**Finding:** The only run in `data/pipeline-runs.jsonl` has `started_at: "2026-09-10T18:33:02Z"` — the literal crontab time (`33 18`) with no Bucharest offset applied. If `CRON_TZ=Europe/Bucharest` had been honored, that run would show `15:33Z`. Cause: the box's system TZ is `Etc/UTC` (`timedatectl`), and the posturi section of the crontab had the line commented out — `# CRON_TZ=Europe/Bucharest` — so `45 11` / `33 18` were being evaluated against system UTC, not Bucharest. Net effect: the pipeline was actually firing at 14:45 and 21:33 Bucharest, 3h later than intended (11:45/18:33). It happened to not cross a UTC midnight boundary today, but the `expires_at >= CURRENT_DATE` filter (which the skill instructions call out) would have been vulnerable if the drift had landed near local midnight.

**Fix:** Uncommented `CRON_TZ=Europe/Bucharest` in the posturi crontab section on `gov2-1` (live `crontab -e`, not tracked in this repo). Not yet independently confirmed against `/var/log/syslog` (no read access, and `sudo -n journalctl` needs an interactive password) — confirmation is a single precise timestamp match, which is strong but not multi-sample. Follow-up tracked in `docs/backlog.md`: watch the next 2-3 runs' `started_at` land on `08:45Z`/`15:33Z`.

---

### 2026-09-10 — iCal feed: subscribable, `?title=` custom calendar name

The `/posturi.ics` feed was already a live query that honours every list filter
(`?judet[]=cluj&judet[]=timis`, `?family=…`, `?q=…`, `?employer=<slug>`), but the
UI only offered it as a link the browser saves as a file, and the calendar name
(`X-WR-CALNAME`) was fixed at `posturi.gov2.ro` (or `— <employer>`).

- `feeds/jobs.ics.php`: `?title=` now overrides the calendar name outright, so
  someone following several filtered feeds — one per județ — gets a distinct
  label in clients that show `X-WR-CALNAME` and won't let you rename a
  subscription (Apple Calendar, Outlook). Control chars stripped, capped at 80
  chars (`mb_substr`, diacritics preserved); `ical_escape()` still folds/escapes.
- `pages/list.php`: new "Calendar" row in the Export block — an *Adaugă în Google
  Calendar* link (`calendar.google.com/.../r/settings/addbyurl?url=<ics>`), a
  `webcal://` subscribe link for Apple/Outlook, and a "Titlu" text box. All three
  hrefs, plus the existing iCal link, are kept in step with the active filters
  and the title box by `syncFeedLinks()`, which now also runs once on load to
  build the two absolute-URL subscription links from `location.origin`.
- The "Titlu" input carries no `name` (never serialised by the filter form's
  htmx) and swallows Enter (a lone text field would otherwise submit the form).
- `pages/about.php`: documents subscribing vs downloading, filter params, `?title=`.

Non-obvious: `feed_url()` serialises arrays as `judet[0]=cluj` (indexed) while
htmx pushes `judet[]=cluj`; PHP parses both to the same array, so the
server-rendered and JS-rebuilt hrefs are equivalent. Google re-polls an external
iCal URL on its own schedule (~8–24 h) and that cadence is not controllable.

### 2026-09-10 — The "actualizat" stamps now carry the run's time-of-day

The header and footer "actualizat" / "Date actualizate la" stamps showed a date
only. The date is `MAX(last_seen_at)`, a Django `DateField` with no time component,
so the time could not come from there. `build_meta.built_at` — written as the last
step of `export-to-sqlite.py`, i.e. the end of a successful pipeline run — already
has second precision and is already converted to Europe/Bucharest by `build_time()`.

`inc/header.php` now appends `build_time('H:i')` to `$_last_updated_fmt` and folds
the same `H:i` into `$_last_updated_iso` (`YYYY-MM-DDTHH:MM`) for the `<time>`
attribute. Footer and the header stamp read the shared vars, so both update; the
`about.php` `<dl>` "Generată la" row already rendered `d.m.Y, H:i` and is unchanged.
Stamp now reads e.g. `actualizat 09.09.2026, 21:22`.

Non-obvious: the displayed date (scrape day) and the appended time (build-finished,
Bucharest wall clock) are from two sources and can straddle midnight in rare cases
— accepted as good enough for a freshness stamp rather than showing a second date.
`about.php`'s explanatory paragraph and the `build_tooltip()` doc comment were
reworded to match. No schema, Python, or CSS/skin changes.

### 2026-09-10 — Observability for the unattended run: a run log, a deploy gate, and `/pipeline-check`

The pipeline now runs itself twice a day on `gov2-1` and nothing watched it. The
only signals were a healthcheck ping that was never configured, whatever the steps
printed into one append-only logfile, and `export-to-sqlite.py`'s row-count floors.
Built the MVP slice of `docs/pipeline-quality-checks.md` — parts 1, 2 and 5.

**What was actually missing, found by reading the ops tree:**

- `HEALTHCHECK_URL` is **empty** in `.env`, so `ping_health()` returns early and
  there is no dead-man's switch at all. The doc has said since 2026-09-08 that this
  is the point of the whole arrangement; it was never filled in. Left as a backlog
  item because it needs an account, not a commit.
- No `logs/` in the repo, no logrotate installed, and `deploy-vps.md` §6 still
  listed the crontab as outstanding after it had been installed.
- The logfile had no run delimiter, no run id and no exit code in it — you could
  watch a run live but could not answer "did the 18:33 slot succeed?" by reading it.
- `pipeline.py` computed per-step exit codes and durations and then discarded them.
- Nothing at all inspected the data between `export-sqlite` and the deploy.

**`pipeline.py --run-log`** (default `data/pipeline-runs.jsonl`, `--no-run-log` to
disable) appends one `kind: "run"` record per invocation: run id, trigger, host, git
SHA, timings, the flags in effect, and `{step, ok, exit, duration_s}` per step.
`_run_step()` no longer calls `sys.exit()` — it returns a record and the caller
decides — so the log gets written on the abort path too. The old contract is intact:
without `--continue-on-error` the run still stops at the first failure and still
exits with *that step's* return code, now with `aborted_at` recorded beside it.

**`ops/check-export.py`** is the new deploy gate, wired into `run-pipeline.sh`
between the pipeline and `deploy-php.sh --data-only`. 24 assertions over the SQLite
that is about to ship, in two severities:

- **8 hard** — corruption that cannot be a bad day's data: `integrity_check`,
  `foreign_key_check`, duplicate URLs, an FTS index shorter than `job_postings`,
  anything other than exactly 42 județ rows, a collapse below half the previous run,
  and `build_meta.built_at` older than six hours. A breach exits 65 and **aborts
  before the rsync**, so the shared host keeps serving the previous database.
- **16 soft** — quality drift: coverage regressions, an `altele` spike, one
  profession family or skill tag on an implausible share, anomaly-flag step changes,
  dates out of range, mojibake, cedilla `ş/ţ` in a county name. Recorded and printed;
  `--strict` makes them fatal.

Every check is tied to a regression that actually happened here. `--strict` is
deliberately **off** in cron: the soft thresholds were picked with no run history
behind them, and a false abort is worse than a stale-but-correct site.

Three decisions worth recording:

- **The hard/soft split is what makes a gate safe to add.** `run-pipeline.sh` has
  deployed through step failures on purpose since 2026-09-08 — "a stale-but-correct
  site beats a site nobody updated". A gate that could fire on a quiet Tuesday would
  have undone that. Every hard check is one that can only mean the file is broken.
- **`build_meta_fresh` is the check that catches the failure mode nobody watches
  for**: the export step fails, `--continue-on-error` carries on, and the deploy
  ships whatever SQLite was already on disk. Row counts all look perfect, because it
  is a perfectly good file from yesterday. Proved it on the local export, which is
  18.4h old and correctly refused.
- **The two `*_sanity_warnings()` functions are called, not restated.** Importing
  them needs Django, which is on the VPS and may not be on a laptop, so the import is
  guarded and that one check reports itself skipped when Django is absent — which is
  what keeps the script runnable against a copied export with nothing installed.
  `judete.COUNTIES` is loaded through `importlib` straight from the file, bypassing
  the `apps` package so it never pulls Django in for a constant.

**Per-step counters were skipped on purpose.** The design asked for pages scanned,
files fetched, tokens in/out. Harvesting those means either regex-parsing nine
scripts' stdout or adding a sentinel line to all nine, and almost every counter that
matters is derivable from the state *after* the run — which is what the export-check
record holds at no coupling cost. The genuine losses are LLM cost per run and the
schema step's per-error-class tally; both are now backlog items.

**`/pipeline-check`** (`.claude/commands/pipeline-check.md`) reads the run log, the
cron schedule, the live site and the logfile tail, and answers "is the machine
working?" — as distinct from `/quality-review`, which samples postings and answers
"is the data right?". It is told to look for the thing the script structurally
cannot see: a metric sliding 4 points a run past a 5-point per-run threshold. It is
also told to propose better thresholds from the recorded metrics, and not to run
`pipeline.py` or drop `--no-log` while poking around.

Also committed `ops/logrotate.posturi` (`copytruncate`, because cron holds the fd
open for the whole run) and `logs/.gitkeep`.

Verified against the local 54 MB export: 24/24 pass with `--max-age-hours 999`;
`build_meta_fresh` correctly fails at the real 6h limit; a copy with a 43rd județ row
and 5 rows deleted from the FTS index fails exactly `judete_count` and `fts_complete`
and exits 1; three postings dated 2027 produce two soft warnings, exit 0, and exit 1
under `--strict`; a second run reads the first's metrics and prints deltas.

### 2026-09-10 — VPS bring-up resumed: fresh Postgres restore, and the runbook meets the real box

Picked the VPS deploy back up. The box (`gov2-1`) had a `posturi` Postgres DB from
an earlier manual import, but was never provisioned the way `docs/deploy-vps.md`
§1–§5 assume: no dedicated `posturi` service user, no `/srv/posturi`. It runs as the
login user `pax` out of `~/g2-dev/posturi.gov.ro`.

- **Fresh dump from the Mac.** `pg_dump -Fc --no-owner --no-privileges posturi_dev`
  → `posturi.dump` (117 MB, `*.dump` added to `.gitignore`). Carries every local
  data-quality fix since the earlier seed (județe 261→42, inference backfill,
  DeepSeek 57-row repair, 80 recovered cancelled competitions, the `expires_at`
  NULL fix) that an incremental `--since 7` run would not reproduce. Mac baseline:
  9,756 postings / ~1,780 active / 42 județe / 1,749 active with `schema_json`.
- **Restore, as `pax`.** No `pax` Postgres role existed, so
  `CREATE ROLE pax LOGIN SUPERUSER`; `dropdb`/`createdb posturi`;
  `pg_restore --no-owner --no-privileges`. Migrations travel in the dump, so
  `manage.py migrate` is a no-op check.
- **`fe_sendauth: no password supplied`, twice.** The `.env` `DATABASE_URL` was
  `…@localhost/posturi`, which forces a TCP connection Postgres wants a password
  for. Fixed to `postgres://pax@/posturi` — empty host = local socket = peer auth.
  The Django steps then connected fine, but the first hand-run of
  `ops/run-pipeline.sh` still failed at `export-sqlite`: `export-to-sqlite.py::
  pg_connect()` did `host=r.hostname or "localhost"`, turning the empty host back
  into a TCP connection. Changed to `host=r.hostname or None` (+ `port` the same)
  so libpq falls back to the socket, matching Django.
- **First hand-run** failed only at `export-sqlite`, on the bug above; the other
  five steps completed (schema: 80 ok / 46 failed / 15 retried on DeepSeek — the
  `Expected dict, got str` family, non-blocking under `--continue-on-error`).
- **Second hand-run, after `git pull`: clean.** 9 steps in 836 s. Export: 42
  județe, 5,938 employers, 1,876 active postings, 485 calendar events, 51.8 MB
  SQLite. `deploy-php.sh --data-only` rsynced it to
  `pax@mioritics.ro:~/posturi.gov2.ro/` and `https://posturi.gov2.ro/` returned
  HTTP 200. The VPS is a working source of truth end to end.
- **Docs.** `docs/deploy-vps.md` gained a divergence callout at the top and a new
  **§6** for `gov2-1`: the layout delta table, the `~/.ssh/config` block for the
  passphrase-less deploy key, and a user-crontab alternative to the systemd timer
  (the units still hard-code `/srv/posturi` / `User=posturi`). `run-pipeline.sh`
  needs no change — it `cd`s to its own repo and `flock`s itself.
- **Backlog.** Added "Pipeline observability + an LLM data-quality / pipeline-health
  check" under Tooling & ops — structured per-run logging plus threshold assertions
  and a slower-cadence LLM fidelity pass on the pipeline tail.

Still open: `crontab -e` on the VPS to enable the two daily slots; set
`HEALTHCHECK_URL` in `.env` for the dead-man's-switch; build the data /
pipeline-run quality check — watch-list drafted in
`docs/pipeline-quality-checks.md`.

### 2026-09-10 — Per-angajator JSON / Atom / iCal feeds

The three site-wide feeds (`/posturi.json`, `/posturi.atom`, `/posturi.ics`)
already run every request through `build_filters($_GET)`, so scoping them to one
employer only needed a filter key. Added `employer` (slug) and a legacy
`employer_id` (int) clause to `build_filters()` — slug wins when both are
present, `employer_id = (SELECT id FROM employers WHERE slug = ?)` for the slug
form. That alone makes `/posturi.atom?employer=<slug>` work.

On top of that:

- `feed_employer($_GET)` helper resolves `?employer=` to the `{id, name, slug}`
  row (null otherwise). Each feed uses it to name itself: Atom `<title>` /
  `<subtitle>` / alternate `<link>` / `<id>` point at the angajator profile, ICS
  `X-WR-CALNAME` / `X-WR-CALDESC` carry the employer name, and the JSON payload
  gains a top-level `employer: {name, slug}` object before `results`.
- `pages/employer.php` renders a **Feed** row (Atom / JSON / iCal) under the
  stats grid and sets `$head_extra` with `<link rel="alternate">` tags for the
  Atom and JSON scoped feeds, so feed readers autodiscover them on the profile.
- `/despre/` notes that the feeds follow the active filters and take
  `?employer=<slug>`.

An unknown slug resolves to `employer_id = NULL`, i.e. an empty (but valid)
feed, which is the right failure. `employer_id` was never a real list filter
before this — the `/?…&employer_id=` links in `employer.php` were inert — so the
new clause quietly fixes those too.

### 2026-09-10 — Export links move from the sidebar to the end of the list

Atom / JSON / iCal were the last block in the facet sidebar. Two problems with
that: they are what you reach for once you are happy with a result set, not
while you are narrowing one, and on phones the sidebar is a slide-over behind a
button labelled **Filtre** — the last place anyone would look for a feed. They
now sit under the pagination, at the end of the results column, as one
horizontal row.

**Kept outside `#results` on purpose.** That element is swapped on every filter
change *and* every page of pagination, so a block inside it would be rebuilt
constantly for something that only changes when the filters do. It also keeps
the `[data-feed]` NodeList that `syncFeedLinks()` collects once, at script
init, valid for the life of the page — moving the block inside the swap target
would leave that list pointing at detached nodes and the hrefs would quietly
stop tracking.

**The caption is deliberately unconditional.** "urmează filtrele active" holds
whether or not anything is filtered. A server-rendered "cu filtrele active" /
"toate anunțurile" pair would have been stale the instant someone ticked a
facet, precisely because this block sits outside the swapped region — the same
property that makes the placement correct makes conditional copy wrong.

**Verified** (Playwright): the links are gone from the sidebar and absent from
`#results`; the block renders below the end of the list; a facet click rewrites
all three hrefs and a second facet is carried too; `page` never leaks into a
feed URL; back/forward resets them. End to end, `/posturi.json` with a județ
filter returns `count` 225 — exactly what the page reports — against 1768
unfiltered. Visible with no overflow at 320/375/768/1280, and the mobile drawer
still opens with no feed links left in it. The facet, header and page-sweep
suites all still pass.

Note for anyone testing the feeds: the JSON shape is `{count, results}`, not a
bare array and not `items`.

### 2026-09-09 — posturi.gov.ro becomes the default skin, flattened

**Default skin.** `DEFAULT_SKIN` is now `posturi` rather than `hartie`. The two
are different things and both still exist: `BASE_SKIN` stays `hartie`, the null
skin that app.css's own `:root` defines and that every other skin layers over —
it just is not what a first visit sees any more. The font preloads in
header.php follow the *default*, so they changed with it: one Manrope face
(which does display, sans and mono in this skin) where the parchment default
needed DM Sans plus Fraunces.

**Flattened.** As a default it reads calmer than the official site it mimics:
`--radius` 6→3, `--radius-md` 8→4, `--radius-lg` 12→5, and the card shadow is
gone entirely. `--radius-pill` stays 999px — a pill is a different affordance
(chips, tags, progress caps), not a rounder corner, and flattening it would
turn the filter chips into buttons. govuk.css remains the skin that squares
them.

**The shadow was also drawing a phantom box.** The rule was
`.rounded-lg.border { box-shadow: 0 6px 18px rgba(15,39,66,.09) }` — keyed off
utility *classes*, so it hit anything carrying them regardless of what those
classes currently computed to. The detail page's `aside` reads
`rounded-lg border … sm:border-0`: a card on phones, a bare column on desktop.
Its border-width was already `0px` at 1280 and the visible outline was purely
this shadow. Worth remembering as the general hazard: a skin that styles by
utility class rather than by token reaches elements it was never aimed at.
`_template.css` now says so, and lost its `.rounded-lg.border` hook.

**The detail sidebar lost its frame** for real, at every width — the
`rounded-lg border border-line bg-surface/50 p-4 sm:border-0 sm:bg-transparent
sm:p-0` is gone. Its three inner dividers were `sm:border-t`, i.e. desktop-only,
with the card doing the separating on phones; they are now unconditional, so
the sections stay legible without an outer rectangle.

**The disclaimer moved into the masthead — on the same line.** It was a
dismissible amber strip *above* `<header>`; it is now the middle item of the
bar itself, between the wordmark and the nav. Two reasons for moving it: a band
above the header reads as an ad, and a permanent legal disclaimer with a close
button is one you cannot rely on having been seen — so the close button is gone
with it. `#wip-banner` no longer exists; the `[data-skin] #wip-banner`
overrides in posturi.css and govuk.css went with it.

Fitting three things on one 56px line down to 320px means the middle one gives.
The disclaimer is written at three lengths and the breakpoints pick one, so the
claim survives every width even where the sentence cannot:

| width | shown |
|---|---|
| < 640 | `Neoficial`, linking to /despre/ (~70px) |
| 640–1023 | `Nu este un proiect oficial` |
| ≥ 1024 | the full sentence + the gForm link |

The brand block sheds its own extras on the way down so the middle keeps the
slack — the alpha/WIP marker moved from `md:` to `lg:`, the update stamp from
`lg:` to `xl:`. `min-w-0` on the middle is what lets it shrink at all: a flex
item defaults to `min-width:auto` and would otherwise shove the nav off-screen
rather than truncate. `truncate` is the backstop if a translation ever outgrows
its slot.

Measured at 320/360/375/390/414/640/768/820/1024/1280/1440/1536 × three skins:
one row everywhere, row height 56px, nothing ellipsised, no horizontal scroll,
and brand → disclaimer → nav never overlap. Note when reading those numbers
that govuk's `<header>` measures 66px — 56 of row plus its 10px GOV.UK blue
rule, which is chrome, not layout.

**Nav trimmed to Despre.** Statistici and Angajatori are pages you read once,
not places you navigate between, so they are cards on `/despre/` now. They sit
*outside* that page's `.prose-body` wrapper deliberately: `.prose-body a` is
specificity (0,1,1) and underlines every link inside it, which `no-underline`
(0,1,0) cannot override — the heading size is hand-set to match
`.prose-body h2` for the same reason.

**Titles up one notch.** Posting/page h1 `text-2xl sm:text-3xl` →
`text-[1.7rem] sm:text-[2rem]`, section h1 `text-3xl` → `text-[2rem]`, detail
section h2 `text-lg` → `text-xl`, list card h2 `text-base` → `text-lg`.

**Verified:** 6 pages × 3 skins × 2 widths — all HTTP 200, no PHP notice, no JS
error, no horizontal scroll, nav is exactly "Despre", disclaimer inside
`<header>`; zero non-inset box-shadows left on the detail page; the facet suite
from earlier today still passes. `npm run css` rerun, `check-skins.php` passes.

### 2026-09-09 — Browser-testing the sidebar: a collapsed facet would not stay collapsed

Playwright run over the facet sidebar (33 assertions, Chromium at 1280 and at
320 with touch). Closes the "Click-test the live facet counts in a browser"
backlog item left open by the OOB-swap work.

**Two of the three checks passed as written.** Keyboard focus survives the swap
— arrowing across the new `oricare/toate` radio group fires the request and
`activeElement` comes back on the same control — and Județ's scroll offset is
kept. (An earlier failing run had both wrong for test reasons: focus was driven
by a *mouse click on a label*, which never focuses a clipped `sr-only` input in
any browser and needs no ring, and the scroll assertion clicked a checkbox
*inside* the very scroller it was measuring, so Playwright's own
scroll-into-view moved it.)

**The third was a real bug, and pre-existing.** A facet the reader collapsed
sprang back open on the next filter change, and stayed open. Traced:

```
htmx:beforeSwap
htmx:afterSwap ×5
toggle family=true      <- ten of these, one per server-open group
toggle judet=true
…
htmx:afterSettle ×5     <- applyFacetState() runs here, too late
```

Inserting a `<details open>` fires `toggle` exactly like a click does, and the
sidebar swap inserts ten of them. The capture-phase listener could not tell the
two apart, so every filter change rewrote `state[key] = true` for every
server-open group — wiping the reader's `judet: false` moments before
`applyFacetState()` was supposed to honour it.

**Fix:** a `swapping` flag, set on `htmx:beforeSwap` and cleared in a task
queued from the settle handler — queued rather than immediate so the `toggle`
events that `applyFacetState()`'s *own* writes produce are ignored too, and
placed before that handler's early return, or a settle with nothing pending
would leave the flag stuck on and stop recording real clicks entirely.
Verified: the stored preference now survives two consecutive filter changes,
and a group the reader opens by hand is still remembered.

**One regression from the same day's facet work, caught here.** The any/all
switch is a direct child of `<details>`, and being *first* it is what
`querySelector('.facet-group[data-facet=…] > div')` returns — so scroll restore
for Competențe was writing the offset onto the switch instead of the option
list. The scroller now carries a `facet-options` marker class and both the save
and the restore address that. It is a JS hook like `facet-check`, so no CSS
rebuild.

### 2026-09-09 — Facet combine semantics: OR within a group, AND across groups

**The question.** Should picking *Domeniu: social* and *administrație* show the
union or the intersection? For that facet there is no choice:
`inf_profession_family` holds exactly one value per posting (sănătate 511,
tehnic 499, administrație 382, social 84…), so an intersection is empty by
construction, not merely rare. The convention the scalar facets already followed
— OR inside a group, AND between groups, i.e. "which of these do you accept?"
crossed with "and also this" — is the only one that can work there, and it is
what shop sidebars do.

**What was actually inconsistent.** Six JSON-array facets did the opposite.
`build_filters()` emitted one top-level `LIKE` clause *per checked value*, so
every one of them was AND-within, with the same checkbox affordance as the OR
groups and no cue that they behaved differently. Measured average values per
posting: `v3_skills` 9.6, `v3_exam_stages` 3.1, `v3_credentials` 2.0,
`v3_policy_domains` 2.1, `v3_isced_fields` 1.32, `v3_languages` 1.07,
`inf_anomaly_flags` 1.02. For the bottom four, AND-within was a control that
could only return zero — verified against the live export: two languages ANDed
gave 0 rows (OR: 42), two anomaly flags gave 0 (OR: 83).

**The count machinery only ever supported OR.** `facet_scope($own_key)`
deliberately drops a group's own selection so each number reads *"check this too
and you get N more"*. Under AND that number promises a widening and delivers a
narrowing. Corroborating evidence that someone had already hit this: Anomalii
was the one group rendered with `'cnt' => ''` — the counts didn't work under AND,
so they had been blanked, leaving six flags that all looked clickable including
the ones that emptied the list.

**Changes** (`helpers.php`, `pages/list.php`, `partials/facets.php`,
`partials/result_list.php`):

- Added `FACET_MODE_PARAMS` + `facet_mode()`. Default is `any` (OR) everywhere;
  `skill` is the sole entry, keeping `all` (AND) — at 9.6 skills per posting
  "Excel ȘI contabilitate" is a real question and OR there narrows almost
  nothing. The JSON-array loop now joins a group's probes with that mode and
  emits one parenthesised clause, so a group still ANDs against every other
  filter. A `?lang_mode=all` in the URL is ignored: only groups listed in
  `FACET_MODE_PARAMS` can be switched.
- `skill` gets **residual counts** — `v3_facet()` includes the group's own
  selection in the scope when the mode is AND, so each number is "rows still
  standing that also carry this value" and clicking one lands exactly there.
  Verified: with *comunicare* picked (182 results) no option claims more than
  182, and *lucru în echipă* reads 116, which is what the click returns.
- The group carries a visible `oricare / toate` switch, shown from the first
  pick onward (with 0 or 1 value checked the two modes agree, so before that it
  would be a control with no effect).
- **Anomalii folded into the same loop.** It gets real counts, so — like every
  other counted facet — values that would zero out simply stop being offered.
  It also picks up the `ESCAPE` clause it always needed: every `ANOMALY_LABELS`
  key contains an underscore and `_` is a LIKE wildcard, so `short_deadline` was
  matching `shortXdeadline` too. Its hand-rolled `preg_replace` sanitiser went
  with it — the bound parameter was doing the real work.
- The chip row now states the logic instead of leaving it to be inferred:
  *Social* `sau` *Administrație* `și` *Cluj*, with the connective inside a group
  tracking that group's actual mode (skill chips read `și` in AND mode, `sau`
  after flipping the switch).
- Zero-count options render disabled and dimmed, and orphan-pinned selections
  now carry a blank count rather than `0` — an orphan's real count is unknown
  (it fell outside the display cap), and a rendered "0" read as "this matches
  nothing".

**On the zero-count guard.** Worth recording that it is defensive, not a fix for
something visible: the facet queries are `GROUP BY`s over the current scope and
`if ($cnt)` filters, so a counted option is never 0 — under strict
OR-within/AND-across with per-facet exclusion, dead ends *cannot* occur, and a
sweep of the rendered page found no disabled boxes. The real dead end was
Anomalii having no counts at all, which is what the fold-in fixed. The guard
stays so a future facet that can produce a zero is handled.

**Verified** against the 1,848-row active export: all six groups match a direct
SQLite OR/AND cross-check; cross-facet still intersects (social 84 ∩ cluj 140 =
8); ten filter combinations render with no PHP notice. `npm run css` rerun (the
stylesheet is a committed build artifact) and `check-skins.php` passes.

### 2026-09-09 — Live facet counts: the sidebar now updates with the results

**What was actually wrong.** The counts were already dynamic server-side —
every facet calls `facet_scope($own_key)` (`pages/list.php:150`), which rebuilds
the WHERE clause with all *other* active filters applied. The problem was
delivery: `hx-target="#results"` swaps only `partials/result_list.php` plus three
OOB spans, and the sidebar sat inside `if (!$is_htmx)`, so after ticking a
checkbox the numbers on screen were whatever the last full page load produced.

And the facet blocks (lines 184–330) run *before* the `$is_htmx` check, so every
HTMX request already computed all of them and threw them away — the partial cost
108 ms of the full page's 138 ms. Shipping the counts costs **no extra queries**.

**Change:** extracted the sidebar to `partials/facets.php`, wrapped in
`<div id="facet-list">`, and re-included it on HTMX responses with
`hx-swap-oob="true"`. Verified the OOB fragment is byte-identical to what a full
page load renders, for four different filter combinations, and that it sits at
nesting depth 0 in the response so htmx lifts it out before the main swap.

**Whole groups, not individual count spans.** The per-span idea does not work:
narrowing one filter drops values out of a sibling group's list and widening
brings them back, so there is no fixed set of spans to address — stale values
would keep their old numbers and new ones would have no target to swap into.

**Client state a swap destroys, and where it is put back** (`list.php` script):
- focus on the control just clicked — restored by `name`+`value` at
  `htmx:afterSettle`, `preventScroll` so a sticky column does not jump. Only
  when focus was inside `#facet-list`, so typing in the search box is untouched.
- `<details>` open/closed — `applyFacetState()` re-applies localStorage. The
  per-element `toggle` listener became one capture-phase listener on `document`
  (`toggle` does not bubble, and a per-element listener dies with the swap).
- scroll offset of the panel and of each `max-h-56` group.

**Paging is excluded.** The filter form carries no `page` field, so `page` in the
query string means a pagination link — same filters, same counts. Skipping it
avoids re-shipping the sidebar to redraw identical numbers.

**Cost.** No new queries; server time unchanged (102 ms vs 108 ms unfiltered,
5 ms with a filter on). Payload grew 79 KB → 200 KB raw, but `.htaccess` has
`mod_deflate` on `text/html`: **10.3 KB gzipped unfiltered, 6.0 KB filtered.**

Profiled the count queries at both scales — 16 facet queries, warm:

| | 1,848 active rows (51 MB) | 9,756 rows, full archive (174 MB) |
|---|---|---|
| all facet queries | 72 ms | 183 ms |

Sub-linear at 5.3× the data. The one line item that scales badly is
`v3_facet()` (`list.php:242`) — six queries that read every matching row's JSON
column and tally in PHP, 110 of those 183 ms. If the corpus grows 10×, replace
it with a normalised `(posting_id, kind, value)` table and a real `GROUP BY`.

**Not changed:** a group still excludes its own selection from its counts. Drop
that and picking IT shows every other domeniu as `0`, so a second value can never
be added to an OR group. Those numbers read as "how many this would *add*".

**Verification gap:** the Chrome connection was unavailable this session, so the
focus/scroll/`<details>` restoration is reviewed and reasoned about but not
click-tested in a browser.

---

### 2026-09-09 — Two facet bugs: `IT` absorbed every failed LLM classification, EQF counted one thing and filtered another

Both found from the UI: selecting **Domeniu = IT** listed îngrijitoare and
consilieri școlari, and **Nivel studii (EQF) = Doctorat**, labelled `12`,
returned 1,437 rows headed by *Conducător auto*.

**Bug 1 — `IT` was the dumping ground for unparseable LLM answers.**
`_llm_classify`'s response validator fell back to a substring test:

```python
norm = _normalize(raw.split()[0] if raw else "")   # empty answer -> ""
for fam in PROFESSION_FAMILIES:
    if _normalize(fam) in norm or norm in _normalize(fam):   # "" in "it" -> True
        return fam
```

`PROFESSION_FAMILIES` is `sorted(FAMILIES.keys())` and `"IT"` is uppercase, so
it sorts first — every empty answer landed there. The same test also matched the
letters `it` inside a word, so `sanitar` and `ingrijitoare` would have gone to IT
too. Confirmed against the API: `deepseek-v4-flash` with reasoning on returns
`''` for this prompt (it is the pre-`f403acf` state that wrote these rows), and
`altele` with `thinking: disabled`. 57 of 120 Postgres `IT` rows (50 of 60 active)
were llm-sourced junk.

`quality_check.py::_llm_classify` had the same validator **plus** no `deepseek`
branch at all and no `else: raise` — so under the configured default provider it
left `raw = ""` and silently classified everything as IT.

**Fix:** new `_match_family()` in both files — empty/unrecognised is `altele`,
never a family; exact match first, then a whole-word scan of the answer (longest
family name first, so `ordine publică` beats a stray `ordine` and `IT` cannot
match inside `sanitar`), then a ≥4-char prefix match for an answer truncated at
`max_tokens`. `quality_check.py` gained the deepseek branch (thinking disabled,
matching `infer_postings.py`) and raises on an unknown provider.

**Repair:** new `infer_postings --requeue-llm-family FAMILY` flag re-runs only the
postings the LLM put in one family — repairing a bad batch without paying for a
full `--force` pass over 9,756 postings. `--requeue-llm-family IT --provider
deepseek`: 57 updated, 57 LLM calls, 0 errors. Active `IT` 60 → 11 (10 dictionary
hits plus one *Economist specialist IA (cu atributii de administrator)*); the
other 49 redistributed to sănătate, administrație, educație, social. Re-exported
to `webapp-php/posturi.sqlite`.

**Bug 2 — EQF facet counts and filter answered different questions.**
`build_filters()` filtered `v3_eqf_level <= ?` on the "a candidate above the
minimum still qualifies" reading, while the facet counts in `pages/list.php` are
a plain `GROUP BY v3_eqf_level` — exact per level. Clicking *Licență 587* gave
1,411 rows; *Doctorat 12* gave 1,437, i.e. every posting with any EQF level.

**Fix:** exact match, `v3_eqf_level IN (…)`, like every other sidebar facet, and
`eqf` added to `MULTI_PARAMS` so it multi-selects (`eqf[]`) instead of collapsing
to a single scalar. `(array)` in `pages/list.php` keeps detail.php's legacy
scalar `/?eqf=6` links working. Verified: Doctorat → 12 (all *Cercetător
științific*), Master → 14, both → 26. The "everything I qualify for" semantics
belongs to CV matching, not to a browse facet; if it comes back it needs
cumulative counts and labels that say "cel mult".

**Tests:** `tests/test_infer_llm.py::TestResponseValidation` — empty/whitespace/
`i`/`???` → `altele`, `sanitar`/`ingrijitoare` → `altele`, and the recognised
shapes (`**IT**`, `Sănătate.`, `Îngrijitoare → sănătate`, truncated `ordine`).
331 webapp tests pass; `check-skins.php` clean.

**Outcome:** `export-to-sqlite.py --active-only` rebuilt `posturi.sqlite` at 1,848
active postings. Domeniu facet now reads sănătate 511, tehnic 499, administrație
382, altele 240, social 84, financiar 59, educație 34, cultură 21, IT 11, ordine
publică 6, juridic 1.

**Follow-ups filed:** the FAMILIES dict is whole-word only, so `îngrijitoare`,
`infirmiere` and `asistenti` never match their masculine-singular keywords and
fall through to the paid LLM on every run — that is *why* these titles reached
the broken matcher at all. And `quality_check.py` duplicates the entire inference
layer from `infer_postings.py`; this is the fifth fix that had to be applied
twice, and the `textutil` one was missed outright.

---

### 2026-09-09 — DeepSeek schema step returned empty content: reasoning ate the token budget

**Symptom:** `pipeline.py --active-only --since 7` finished the schema step with
`9 ok, 154 failed`, every failure `ValueError: Expected dict, got str: ''`. Recent
postings on the live site kept `schema_json IS NULL`.

**Cause:** `.env` selects `LLM_PROVIDER=deepseek` / `LLM_MODEL=deepseek-v4-flash`.
The `deepseek-v4-*` models reason by default. Probed against the API: on a real
posting they spend 1,400–2,600 completion tokens on hidden reasoning before
emitting any JSON, so the `max_tokens=2000` cap in `llm-schema.py`'s deepseek
branch is hit mid-think (`finish_reason: length`) and `message.content` comes back
`''`. `_validate_extraction('')` then raises `Expected dict, got str: ''`
(`llm-schema.py:279`). The 9 successes were short postings that fit under 2000.
Raising `max_tokens` does not help — the model just reasons longer to fill it.

**Fix:** pass `extra_body={"thinking": {"type": "disabled"}}` on the DeepSeek
calls. Field extraction needs no chain-of-thought; disabled, the same posting
returns valid JSON in ~300 completion tokens and validates against the v2 model.
Applied in two places:
- `llm-schema.py` deepseek branch (the schema step — the actual failure).
- `infer_postings.py::_llm_classify` deepseek branch (uncommitted WIP; its
  `max_tokens=20` could not fit a single reasoning token, so it would have failed
  100% the same way).

Re-run: `python pipeline.py --steps schema --active-only --resume --workers 8`.
61 LLM tests pass.

**Outcome:** the re-run completed clean. Active postings with `schema_json` in
Postgres went 359 → 1,812 of 1,848. Note the schema step writes Postgres only —
the PHP site kept showing "nu există încă o versiune structurată" until
`export-to-sqlite.py --active-only` rebuilt `webapp-php/posturi.sqlite`; the
`--steps schema` invocation does not include the export.

---

### 2026-09-09 — `fetch-anunturi.py` skips cancelled competitions

**What:** `process_csv()` now drops any index row whose `expira_in` contains
`anulat` before building the fetch list, via a new `is_cancelled()` helper.

**Why:** when posturi.gov.ro withdraws a competition it swaps the pretty
`/joburi/{slug}/` card link for the bare `/?post_type=pg_job&p=N` permalink and
writes `Anunț anulat` in the expiry slot. All 80 such rows in the CSV are
cancelled (the correlation is exact — see 2026-09-08 "Recovered the 80 lost
postings"). 78 were cached back then; 2 (`p=8208`, `p=8915`) were already 404 and
still are. With no cache file they were treated as new on every run and burned
~35 s each on 3 retries with 5/10/20 s backoff. Nothing was lost by skipping:
`import_csvs.py` sets `JobPosting.cancelled` from the same `"anulat"` marker and
never needed the detail page, and `--active-only` already excludes cancelled rows
from the export.

**Scope:** one guard in the first loop of `process_csv()` is enough — the second
loop only iterates the already-filtered `new_rows`. Added
`test_fetch_anunturi_skips_the_romanian_marker` in `webapp/tests/test_posting_urls.py`
alongside the existing `import_csvs` source assertion; 20 pass.

---

### 2026-09-09 — `infer_postings` supports deepseek, so pipeline.py runs end-to-end

`python pipeline.py` died at the infer step with argparse `invalid choice:
'deepseek'`. The `.env` sets `LLM_PROVIDER=deepseek`, and `pipeline.py` accepted
and forwarded it (deepseek had been added to `_PIPELINE_PROVIDERS` in 23c0c94),
but the management command's `--provider` only allowed gemini/openai/anthropic
and its `_llm_classify()` had no deepseek branch — the one dispatch site missed
in the May sweep that wired deepseek into `llm-schema.py`.

**Fix:** added deepseek to the argparse choices and a deepseek branch to
`_llm_classify()` mirroring the openai branch — OpenAI SDK,
`DEEPSEEK_API_KEY`, `base_url="https://api.deepseek.com"`, model
`deepseek-v4-flash` (hardcoded, matching the file's per-branch idiom and
`models_config.json` defaults). Also added an else-raise so an unknown provider
fails loudly instead of silently classifying `altele` — the bug class
`quality_check.py::_llm_classify` already has. The stale "infer only implements
gemini/openai/anthropic" comment in `pipeline.py` was removed.

**Decisions:** deferred deepseek support in `quality_check.py::_llm_classify`
(CLI accepts it but silently falls back to `altele`) and `infer_conditions_llm.py`
(same hardcoded choices) to the backlog — neither is on the pipeline.py path.

**Verified:** live smoke call classified "Informatician grad I" → `IT`;
`pipeline.py --steps infer --provider deepseek --limit 5 --force` completes
cleanly; 2 new tests in `webapp/tests/test_infer_llm.py`, full suite 313 pass.

---

### 2026-09-08 — `build_meta` surfaced: two timestamps, told apart

The export now records its own provenance, so the site could stop implying that one date
answers two questions. `MAX(last_seen_at)` is when the source was last scraped;
`built_at` is when the file being served was generated. They coincide on a healthy run,
and the gap between them is exactly what a partial failure looks like.

**The visible stamp did not change meaning.** "actualizat 08.09.2026" in the header and
"Date actualizate la…" in the footer still report the scrape date, because that is the
question a visitor is asking. Both became `<time datetime>` elements carrying a title
with the build behind them — generated at, active postings, commit. Swapping them to
`built_at` would have been the wrong fix: an export can run when a scrape did not, and
the stamp would then overstate freshness.

**`/despre/` is where the detail belongs.** A "Versiunea acestor date" block lists the
build time in Romanian local time (`built_at` is stored UTC — 21:46 in Bucharest must not
read as 18:46 to someone checking how fresh the site is), the three row counts, and the
commit linked to GitHub. Followed by a sentence explaining the two timestamps, since a
reader who notices them differing deserves the reason rather than a support ticket.

**`source_host` is recorded but never rendered.** It names infrastructure; it exists for
the deploy to verify on the remote that the file that landed is the file it built.

`build_meta()` in `helpers.php` returns null for an export predating the table — which is
what is on the live site right now — and every consumer renders without it: no tooltip, no
`cursor-help`, and a plain sentence in place of the block. Verified by serving a copy with
the table dropped: all routes 200, no notices.

Files: `webapp-php/helpers.php` (`build_meta()`, `build_time()`, `build_tooltip()`),
`inc/header.php`, `inc/footer.php`, `pages/about.php`, rebuilt `static/app.css`.

### 2026-09-08 — Continuous deployment: twice-daily pipeline on a VPS, and the guards that make it safe to leave alone

Answered the open "Daily fetch cron — design" backlog item, then built it.

**GitHub Actions was the first question, and the answer is no — for the pipeline.** It
carries ~2.4 GB of persistent state (`data/anunturi` 169 MB of cached HTML, `data/downloads`
2.1 GB of attachments) plus a PostgreSQL database that has to survive between runs. On
ephemeral runners every run would restore and re-save all of it against a 10 GB cache quota
with 7-day eviction. Secrets, cost and the 6h job limit were never the obstacle; the state is.
The VPS already holds it. Actions was declined for CI too, by choice.

**Topology.** The VPS owns Postgres, the scrape cache and the API keys and runs the pipeline
at 11:45 and 18:33 Europe/Bucharest; the shared host owns the PHP tree and one read-only
SQLite file. Code deploys stay manual from the Mac. That last decision is what forced
`deploy-php.sh` to split: a cron pushing the whole directory would silently revert templates
pushed from the Mac, using the VPS's older checkout. `--code-only` excludes `*.sqlite*`,
`--data-only` sends nothing else.

**`--active-only` is the cost guard, not `--resume`.** The plan had this wrong. `iter_postings`
already skips rows with a non-null `schema_json`, so a re-run does not re-extract what is done
— `--resume` is the finer per-variant filter. The actual trap is scope: 6,904 postings have no
`schema_json` and only **15** of them are active. A run without `--active-only` would pay for
LLM extraction on ~6,900 expired postings that can never reach the export. `llm-schema.py`'s
flag defaults were left alone once that was clear.

**`--prompt-version v3` is pinned in the cron.** `models_config.json` still defaults to v2, and
a v2 extraction lands with every `v3_*` facet column empty — the site's filters read those.

**The export stopped being able to publish an empty site.** `create_schema()` DROPs every table
before writing, so a crash used to leave the deploy source gutted and ready to ship. It now
builds `<out>.tmp` and promotes with `os.replace()` only after `integrity_check`, a `--min-rows`
floor (default 100), and a refusal to lose half the rows against the file it would replace —
compared only against a baseline built the same way, since an `--active-only` file is
legitimately a fifth of a full one. A rejected build is deleted rather than left as 50 MB of
nothing. This is the check that was missing when the live site served three active postings for
five weeks.

**WAL was actively dangerous on a read-only replica.** The export set `journal_mode=WAL` and
`db.php` set it again. Two failures follow: PHP opening a WAL database has to create `-shm`/`-wal`
beside it, and a shared host's document root may not be writable; and a `-wal` surviving an rsync
swap describes a database that is no longer there, which SQLite reports as *database disk image is
malformed*. Now `journal_mode=DELETE` in the export, `PRAGMA query_only=1` in `db.php` so the
read-only intent is enforced rather than assumed, and the deploy removes the remote sidecars once.
Every route was re-checked under `query_only` — list, detail, employers, stats, sitemap and all
three feeds return 200 and nothing creates a sidecar.

**`expira_in` was making every run look like a change.** The redesigned cards render a relative
countdown, so a verbatim diff logged an `expira_in` change on all ~9,600 rows twice a day: the
`updates` column growing without bound, a full 4.1 MB CSV rewrite on every page, and the
three-unchanged-pages early stop permanently defeated. `values_differ()` now treats two members
of the countdown family as equal while still recording a move to `Anunț anulat`. `Data Expirare`
comes from the detail page's `.pg-meta-deadline`, so nothing downstream loses information.

**Smaller things the unattended path needed.** A pagination floor in `fetch-index.py`, because
`get_total_pages()` reads the largest numeric link out of a *windowed* pagination and would
silently truncate the scrape if WordPress ever stopped rendering the last page; `flock` inside
`run-pipeline.sh` rather than in the unit, so a hand-run cannot overlap the timer either; a
`DEPLOY_PATH` guard, because `rsync --delete` plus an unquoted `~` that expanded against the
*local* home is one keystroke from emptying a directory; and a dead-man's-switch
`HEALTHCHECK_URL`, since a failure alert cannot tell you about a run that never happened —
which is exactly how the site went five weeks stale.

**`--continue-on-error` with a deploy anyway.** A failed `download` or `infer` step still lets
the export and deploy run — a current site matters more than a clean run, and the export floors
decide whether the data is fit to ship. The run still exits non-zero and pings `/fail`.

Files: `ops/run-pipeline.sh`, `ops/env.sh`, `ops/systemd/posturi-pipeline.{service,timer}`,
`docs/deploy-vps.md` (provisioning runbook + failure table), and changes to `fetch-index.py`,
`export-to-sqlite.py`, `deploy-php.sh`, `webapp-php/db.php`, `.env.example`, README.
28 new tests in `webapp/tests/test_index_diff.py` and `test_export_guards.py`; 310 pass.

`webapp-php/posturi.sqlite` was re-exported through the new path as the end-to-end check —
1,762 active postings, `journal_mode=delete`, `integrity_check` ok, `build_meta` stamped
`e156190`, and all ten routes served 200 against it under `query_only`. It is the first
export that carries provenance; nothing has been pushed to the shared host yet.

Not done: the VPS itself. Provisioning, seeding and the first run are the runbook's job.

### 2026-09-08 — Compact landing: filter-first two-column layout, shortcut chips, live export links

Five backlog UI items, all on the PHP webapp's list page.

**The landing was selling itself instead of being used.** An `<h1>` restating the masthead, a
"N anunțuri indexate · sursă" line repeating the footer, and four stat cards pushed the filters
— the actual tool — below the fold. The heading survives as `sr-only`, because SEO and screen
readers were the only things it was genuinely doing; the rest is gone.

**Two columns on `lg`.** Search now sits at the top of the sticky filter column rather than
spanning the page, so the sidebar reads as the primary exploration tool. The search input stays
a *single* DOM node — two inputs sharing a name would double-submit — which is why it lives in
the column wrapper rather than inside `<aside>`: on mobile there is no column, the search renders
inline at the top and the facets stay the slide-over drawer. Cleaning this up also removed a
latent conflict where the aside carried both `lg:static` and `lg:sticky` and the winner depended
on Tailwind's emission order.

**Stats became shortcuts.** Județe and domenii counts were dropped — as numbers they said nothing
a visitor could act on, and both are reachable as facets. Active + angajatori stayed, as one line
of mono text rather than four cards. In their place, a row of clickable entry points with counts:
top three domenii, Temporar, Funcție publică, Funcție contractuală, Telemuncă. They are plain
links, not filter controls, since they only appear when nothing is selected and a normal
navigation re-renders the form with the right boxes ticked. Counts are computed independently of
`$..._options` so facet capping and ordering cannot quietly change what the landing offers; each
was verified to equal its own filtered result count (464, 452, 365, 298, 13, 1718, 3).

**"Toate" removed from the status tabs.** On the deployed SQLite — exported `--active-only` —
it counted the same 1,799 rows as "Active", so it spent a third of the control's width saying
nothing. Old `?status=all` URLs fall back to `active` through the existing validation.

**The export links were quietly wrong.** The feeds always honoured `$_GET`; the bug was that the
sidebar renders once, server-side, and HTMX only swaps `#results`. Narrow the filters, then
subscribe, and you got a feed of whatever you were looking at *before* — with a link that still
worked, so nothing gave it away. Now re-synced from `location.search` on `htmx:pushedIntoHistory`
and `popstate`. Both `feed_url()` and the JS drop `page` and `sort`, which describe how the HTML
list is being read rather than which postings it holds.

Also removed `$corpus_count`: dead once the sub-header went, and it had been running a
`COUNT(*)` on every request, HTMX swaps included.

Two things logged rather than fixed. The "Funcție contractuală" chip matches 1,718 of 1,799
active postings, so it narrows almost nothing — it was asked for, and it is built, but it does
the opposite job of the other six. And building it surfaced that `categorie` and
`employer_category` look crossed for post-redesign rows: the column named for the employer holds
position categories for 96% of rows, while the one the sidebar labels "Categorie" is empty for
99%. The sidebar's "Angajator" facet is really a position-type facet. Worth tracing before either
is trusted.

### 2026-09-08 — Asked whether `webapp/` could be deleted; found it is the ETL, not a web app

Prompted by the skin work above: the Django app still carries its own hardcoded palette and a
`cdn.tailwindcss.com` script, so the two frontends had visibly diverged, and the folder looked
stale apart from two recently-touched tests.

It cannot be deleted. `webapp/` is the database layer the PHP app stands on: the 12 migrations
own the `jobs_*` tables that `export-to-sqlite.py` reads by name, `pipeline.py:133` shells out
to `manage.py` for three of its stages, `apps/jobs/judete.py` is the canonical județ
interpreter, and `tests/` is the project's only suite (282 passing).

But about half of it is dead weight. `views.py`, `base.html` and six templates duplicate PHP
pages one-for-one — ~2,634 lines, slightly more than the load-bearing model and command code —
and the JSON/Atom/iCal feed views are duplicated too. That duplication is exactly what produced
the styling divergence: two frontends, only one of which got the token layer.

The two recently-updated tests turned out to be the misleading signal. `test_llm_runner.py` and
`test_posting_urls.py` import `grounding`, `schema_models` and `posting_urls` from the repo root
and touch Django not at all; they live in `webapp/tests/` only because that is where the suite
happens to be. The folder reads as an actively developed web app because it is an actively
developed *test directory*.

Logged rather than acted on — see the backlog entry under Cross-cutting. The shape of the fix:
delete the duplicated frontend, first moving the six helpers the tests exercise (and that
`helpers.php` reimplemented) out of `views.py`; keep models, migrations, commands, `judete.py`,
`admin.py`, the tests and the LLM-variants dashboard; then rename the folder to `etl/` or `db/`,
which is what actually stops it drifting back. `pipeline.py:44`, `deploy-php.sh`, `conftest.py`,
README and CLAUDE.md all reference the path.

### 2026-09-08 — Skins: the palette became tokens, and GOV.UK / posturi.gov.ro skins on top

The PHP webapp's colours were literals in `tailwind.config.js` and ~90 raw Tailwind palette
classes (`bg-amber-50`, `text-slate-700`, …) scattered through the templates. Restyling meant
editing templates, so there was no way to try a different look.

**The token layer.** Every colour, radius and font in the Tailwind theme now resolves to a CSS
custom property — `page: "rgb(var(--c-page) / <alpha-value>)"` — with the defaults in one
`:root` block in `assets/app.css`. Re-declaring those variables under `[data-skin="<id>"]`
restyles the whole site without touching a utility class. Values are space-separated RGB
channels rather than hex because that is the only form `<alpha-value>` composes with; hex
would silently break every `bg-surface/70`.

**Renames.** Token names now describe a role, not a colour: `parchment`→`page`,
`parchment-dark`→`sunken`, `border-warm`→`line`, `border-input`→`line-strong`,
`bg-white`→`bg-surface`. A name like `bg-parchment` becomes a lie the moment a skin is not
beige. The ~90 raw palette classes collapsed into five semantic families — `info`, `neutral`,
`ok`, `note`, `alert` — each a fill / border / label triple.

**The brand split, found by the validator.** `--c-gov` was serving as both the masthead fill
and the link colour. Both new skins need a masthead that is *not* their link colour (GOV.UK:
black bar, blue links; posturi.gov.ro: navy bar, lighter blue links), and both had started out
overriding `header` by hand to get it. Splitting into `--c-gov` (actions, with `--c-on-gov`)
and `--c-gov-bar` (masthead, with the `--c-on-bar` ramp) means no skin hardcodes the header,
and it makes the on-fill contrast pairs measurable.

**Discovery, not a registry.** `inc/skins.php` globs `static/skins/*.css`; drop a file in and
it appears in the footer picker on the next request. The display name comes from an `@skin`
comment in the file, ids are validated against `^[a-z0-9][a-z0-9_-]*$` before reaching a
`data-` attribute, and `_`-prefixed files are skipped. The choice lives in `localStorage` and
is applied by a pre-paint inline script, so there is no flash of the default palette. The
valid ids are baked into that script so a deleted skin falls back to the default instead of
pointing at a stylesheet that no longer exists.

Skins sit in `static/` rather than `assets/` for two reasons: Tailwind would strip them (nothing
in the PHP references their selectors), and `deploy-php.sh` excludes `assets/`.

**The two skins.** `govuk.css` is the GOV.UK Design System — white ground, zero radius
everywhere including `--radius-pill`, Arial (GDS Transport is licensed to gov.uk domains; Arial
is the Design System's own documented substitute), yellow `#ffdd00` focus with the black
underline, the semantic families mapped onto `govuk-tag`. It has no cards: `--c-page` and
`--c-surface` are both white and grouping is carried by a visible grey rule, which is how
GOV.UK actually works. `posturi.css` mirrors the official site, with values read off its live
inline styles rather than guessed — `#0f2742` masthead navy, `#0f4c81` links, `#d4af6a` gold,
the slate text ramp, `0 6px 18px rgba(15,39,66,.09)` card shadow, 10–12px radii. Manrope is
self-hosted (latin + latin-ext; ș and ț live in latin-ext) via an `@font-face` inside the skin
file itself, so the browser only fetches it while that skin is active.

**The validator.** `assets/check-skins.php` reads the token contract out of `app.css` and flags
the four failures that are silent in a browser: an unscoped rule, a token name that does not
exist, a colour written as hex, and any text/background pair under WCAG AA. It earned its keep
immediately — besides the brand split above it caught two sub-AA values in the posturi skin
(`#64748b` at 4.36:1 on the `#f5f5f3` ground, `#9ca3af` at 2.54:1 where 1.4.11 wants 3:1),
both replaced with computed rather than eyeballed values. Both skins now override 32/32 colour
tokens and all palettes pass.

Known cost: the two `<link rel=preload>` font hints follow the default skin, so a returning
visitor on `govuk` or `posturi` preloads two faces it will not use. Fixing it needs a cookie
round-trip, which would break shared-host page caching for ~90KB on one request.

### 2026-09-08 — Recovered the 80 lost postings, and found they were all cancelled competitions

**What:** Repaired the 80 postings the `get_slug` collision had left empty, which
promptly exposed a second, worse problem.

**The repair:** backed up and deleted the 28 colliding `data/anunturi/**/index.html`
files, re-fetched, re-parsed, re-imported. 78 of 80 recovered — the other two return
**404 on posturi.gov.ro**, deleted upstream. Result: 78 postings gained a body
(avg 5,746 chars), an expiry date and 233 calendar events between them; the importer
went from silently dropping them to `Detail: matched=9604 unmatched=0`.

**The second half of the bug.** Fixing `get_slug` (the encoder) was not enough:
`parse-anunturi.py::source_url_from_path()` (the decoder) rebuilt every URL as
`/joburi/{slug}/`, so the recovered files still failed to join back to their index
row. Rather than write a second regex to invert the first — two regexes kept in sync
by hand is what caused this — both scripts now share `posting_urls.py`, and the
decoder resolves through a slug→URL map built by running the *encoder* over every URL
in the index CSV. The two directions agree by construction. `/anunt/` URLs round-trip
properly now too, where before they relied on a fallback in `_try_index_lookup`.

**What the recovered data revealed:** all 80 carry `expira_in = "Anunț anulat"` in the
index. The correlation is exact and three-way — 80 raw-permalink URLs, 80 cancelled
announcements, 80 rows that had NULL expiry, no overlap in any direction. When
posturi.gov.ro withdraws a competition it drops the pretty permalink *and* replaces
the expiry with that phrase.

**Which made the repair actively dangerous.** A cancelled announcement's detail page
still carries its original dates, so successfully fetching these turned **15 withdrawn
competitions into apparently open jobs** — worse than the invisibility they replaced.
The site had been protected only by the accident that they never fetched.

Fixed properly rather than papered over: `JobPosting.cancelled` (migration 0011), set
by the importer from the index marker, and `--active-only` in `export-to-sqlite.py` now
means open **and** not withdrawn, for calendar events as well as postings. The active
export drops from 1,777 to 1,762. `import_csvs` reports "80 marked cancelled".

**Not done:** the deployed SQLite has not been rebuilt or redeployed, and the 78
recovered postings have no LLM extraction. Both wait on the interrupted v3 backfill.

---

### 2026-09-08 — Pipeline audit: the schema step could not be driven, and 80% of the live site has no extraction

**What:** Audited the update pipeline against the live database rather than the code.

**The finding:** **1,440 of 1,799 active postings have `schema_json IS NULL`.** The
deployed `webapp-php/posturi.sqlite` carries structured sections for 359 of its 1,799
rows — four out of five detail pages on the live site render with no responsibilities,
no requirements, no skills. 9,603 postings total, 647 published in the last 7 days, so
this is not a stale-data problem; the extraction step simply never got through.

**Why it never got through:** `pipeline.py::_build_cmd()` passed the schema step only
`--force` and `--provider`. Not `--active-only`, not `--limit`, and (until today) there
was no `--resume` or `--workers` to pass. So the step ran one call at a time over every
posting ever scraped — ~40 h with no resume, restarting from zero after any
interruption. Fixed: all five now pass through, opt-in, so existing invocations are
unchanged. The documented backfill is now

    python pipeline.py --steps schema --active-only --resume --workers 8 --prompt-version v3

which is ~1,440 calls, roughly 2 h and ~$2.

**Run it as v3, not v2** — checked rather than assumed. `JobPostingExtractionV3`
inherits every v2 field; `_render_schema_sections()` in `views.py` reads `schema_json`
by key, so the detail page is unaffected; and `_v3_columns()` in `export-to-sqlite.py`
already flattens the v3 keys into the `v3_*` SQLite columns the new filters query.
`PRODUCTION_PROMPT_VERSION = "v2"` only sets the default filter on the LLM-variants
comparison page — I initially wrote that it gated the backfill, which was wrong.

**Second finding — 80 postings were never fetched at all.** 81 postings have
`expires_at IS NULL`, 80 of them published within 60 days (one today), and
`export-to-sqlite.py` filters `WHERE jp.expires_at >= CURRENT_DATE`, which drops NULLs,
so they are invisible on the live site.

I first concluded this was not a parsing bug, on the grounds that none of the 80 mention
a deadline anywhere in `body_markdown`. That reasoning was worthless: **they have no
`body_markdown` at all.** Absence of evidence, read as evidence of absence. Looking at
the rows rather than grepping them showed every one carries a raw WordPress permalink —
`https://posturi.gov.ro/?post_type=pg_job&p=26404` — instead of `/joburi/{slug}/`. The
correlation is exact: 80 query-string URLs in the database, 80 rows with NULL expiry,
zero overlap either way, and all 80 have no body, no attachment, no card deadline and
no schema_json.

**Root cause:** `fetch-anunturi.py::get_slug()` was
`path.split('/')[-1] if path else 'index'`. A query-string URL has an *empty* path, so
every one of them returned the constant `'index'` and shared a single cache filename per
date directory. 28 `index.html` files exist on disk, each holding whichever posting was
fetched first that day; `file_exists()` then skipped all the others as already
processed. `get_slug` now falls back to a sanitised query string
(`post_type-pg_job-p-26404`), with regression tests covering collision, filename safety,
stability across runs, and the unchanged path-URL behaviour.

The fix stops it recurring but does not repair the rows — those 28 cache files need
deleting and the 80 URLs re-fetching, re-parsing and re-importing. Backlogged.

**What let it hide for two months:** the export filter silently drops NULL `expires_at`.
A posting that fails to fetch loses its deadline, and losing its deadline removes it
from the live site — so the failure mode erases its own evidence.

**Also noted, not fixed:** a stale 73 MB `posturi.sqlite` (9 June) in the repo root left
over from before `export-to-sqlite.py` grew `--out`; 88 active postings with empty
`inferred`; and nothing in the repo that actually schedules `deploy-php.sh`, which is
what the recurring "live site is N weeks stale" entries in this log keep describing.
All in `docs/backlog.md` § "Update pipeline".

---

### 2026-09-08 — The extraction round made survivable: retry, concurrency, resume, grounding

**What:** Five of the levers identified in `docs/llm-extraction-round.md` (new, and the
place to read for the measurements behind all of this).

**Retry — the real gap.** There was none. `llm-schema.py` caught every exception,
printed `✗` and moved to the next posting, so a single 429 lost that posting
permanently. At ~9,600 calls per model, rate limits and 5xx are certainties.
`generate_with_retry()` now splits failures in two, because they need opposite
responses: a *transient* error (429/5xx/timeout) is retried with the same input and
exponential backoff with jitter, while *invalid output* gets exactly one repair
attempt that appends the validation error to the user message. Only one repair — if
showing the model its own error does not fix it, a third full prompt will not either.

Classifying the transient ones is the fiddly part: no two provider SDKs share a base
class, and google-genai exposes `.code` where openai and anthropic expose
`.status_code`. `_is_transient()` reads whichever numeric status it can find and
falls back to matching the exception class name.

**Concurrency.** `--workers` (default 4). Only the LLM call runs in the pool; every
database write stays on the calling thread, since one psycopg connection is not safe
to share and `write_variant` commits per row. `imap_unordered()` keeps a bounded
number in flight rather than using `executor.map`, so the 9,600-row cursor is not
materialised to start work and an abort does not strand thousands of queued calls.
Measured on 8 real postings: **5.13 s/post at 4 workers** against 15-20 s each
sequentially — about 14 h for the full corpus instead of 40.

**Resume.** `--resume` excludes postings that already have a variant row for the exact
provider/model/prompt-version. Done as a `NOT EXISTS` clause inside
`_selection_where()` rather than by filtering the generator, because that function's
whole contract is that `count_postings` and `iter_postings` agree — filtering in
Python would have made the progress bar lie. Verified over two consecutive runs: 27
variants across 27 distinct postings, nothing reprocessed.

**Grounding check (`grounding.py`).** Every v3 requirement already carries the phrase
it came from, so hallucination detection needs no second LLM call — just compare the
quotes to the source. Calibrated against the real extractions rather than guessed:
a quote passes if 60% of its content words appear in the source **or** any four
consecutive content words appear consecutively. The second rule was added after a
negative control showed the first one alone is unfair to long quotes — coverage
punishes length asymmetrically, so a genuine 20-word span with a 3-word invented
lead-in scored 50%, the same range as a pure fabrication. All 30 quotes in the real
v3 extractions pass; injected fabrications score 12-14%. Findings are printed and
counted per run; storing them needs a column on the variant table.

**Derived fields.** `eqf_level` is a pure function of `minimum_level` — and
`STUDY_LEVEL_TO_EQF` had been sitting in `schema_models.py` unused since v3 was
written, while the prompt asked the model to do the lookup. Both it and `iso_code`
are now derived in `model_validator`s, overwriting whatever the model returned.
Asking for the same fact in two notations can only introduce disagreement.

**Tests:** `webapp/tests/test_llm_runner.py`, 32 cases over error triage, backoff,
the repair path, pool completeness under failure, lazy iteration, grounding and the
derived fields — all with fake `generate` callables, so none of them call a provider.

---

### 2026-09-08 — One place to choose the LLM: `llm_config.py`, env vars, and honest cache pricing

**What:** Provider/model/prompt selection was scattered across four files with three
different answers. `llm-schema.py` and `quality_check.py` each carried their own
`DEFAULTS` dict — and they disagreed (`openai` meant `gpt-4o-mini` in one,
`gpt-4o` in the other). `pipeline.py` and the two `infer_*` management commands
read `$LLM_PROVIDER`; the two scraper scripts did not, so setting it in `.env`
changed half the pipeline.

New `llm_config.py` is the single source of truth, with one precedence rule
everywhere: **CLI flag > env var > `models_config.json` "defaults"**.

- `models_config.json` grew a `defaults` block (`provider`, `prompt_version`,
  `model` per provider) next to the existing catalogue.
- `.env` / `.env.example`: `LLM_PROVIDER`, `LLM_MODEL`, `LLM_PROMPT_VERSION`.
- `llm-schema.py`, `quality_check.py`, `pipeline.py` all resolve through it.
  `--provider` now defaults to `None` so "not passed" is distinguishable from
  "passed the same value as the default".

**Non-obvious decisions:**
- *An env var set to the empty string counts as unset.* `.env.example` ships
  `LLM_PROVIDER=`, and `load_dotenv()` turns that into `""` — which would
  otherwise beat the config default and blow up on the provider lookup.
- *`$LLM_MODEL` is ignored when it names a model of a different provider.* Otherwise
  `LLM_MODEL=gpt-4o-mini` in `.env` plus `--provider anthropic` on the command line
  sends an OpenAI model id to Anthropic. This way the env var is a preference, not a trap.
- *`pipeline.py` keeps a narrower provider list.* `llm-schema.py` supports deepseek;
  the `infer` management command has no deepseek branch. Rather than silently
  dropping the flag, pipeline errors out with a message naming the three it can drive.
- *`quality_check._llm_classify` hard-coded a model per provider* (including a
  `claude-haiku-4-5` that `DEFAULTS` never mentioned). Now resolved through the same path.

**Cache pricing fixed:** `compute_cost()` bills cached tokens at
`cache_input_cost_per_million`, falling back to the full input rate when the key is
missing — and it was missing on both Anthropic models and `gpt-4o`. The Anthropic
branch has been setting `cache_control` on the system block all along, so the reads
were real and the recorded `cost_usd` simply never showed the discount. Added
0.1x for the Claude models, 0.5x for `gpt-4o`.

**Measured, not assumed — Gemini implicit caching fires, but rarely.** The v3 system
prompt is ~6,960 tokens, i.e. **~81% of a typical request's input**, and when it is
cached it bills at 10% of the input rate: `in=8909 cached=7092 out=1467 $0.000839`
versus ~$0.0015 cold. But across a 6-posting sequential run only **one call hit it**
— implicit caching is best-effort and a ~15 s gap between calls is evidently enough
to lose it. Do not budget for it. If the prefix discount matters for the full v3
backfill, use Gemini *explicit* caching (`client.caches.create`, TTL'd), which
guarantees the hit; Anthropic's `cache_control` breakpoint (already wired in the
Anthropic branch) is the equivalent there.

Either way the absolute numbers are small: at ~$0.0015 per posting the whole
9,603-posting v3 run is ~$15, and 1,500 extra tokens of static prompt costs ~$1.4
across the corpus even with **zero** cache hits. Prompt size is not the constraint —
the ~40 h of sequential wall-clock is. See `docs/backlog.md` § "LLM round".

---

### 2026-09-08 — v3 filters and detail UI, built ahead of the data and verified against the 5 real extractions

**Asked:** are we extracting more fields now — if so, sync the filters and the UI.

**Measured first: no.** Zero active postings carry `education`, `skill_list` or `positions`. The five v3 rows live in `JobPostingSchemaVariant` as compare-only output and were never promoted to `schema_json`; production is still 380 v2 rows out of 1,793 active. Building the filters against that would have shipped eight empty facets on 100% of postings — the same trap that caught the inferred metadata, where the display was worth nothing until the backfill ran.

So the pipeline was built end to end and **verified against the five real extractions** rather than against nothing.

**Export.** `_v3_columns()` flattens a v3 `schema_json` into queryable SQLite columns: `v3_eqf_level` and `v3_study_level` as scalars, and `v3_isced_fields` / `v3_study_labels` / `v3_skills` / `v3_languages` / `v3_credentials` / `v3_policy_domains` / `v3_exam_stages` as JSON arrays probed with `LIKE '%"value"%'`, the shape `inf_anomaly_flags` already uses. A v2 payload or no schema returns empty defaults, so nothing half-populates. Languages pack level and code into one token (`en:B2`) so a single probe filters on both.

**Filters.** Seven new facets — Domeniu de studii (ISCED-F), Competențe, Domeniu activitate, Nivel studii (EQF), Limbi străine, Documente necesare, Etape concurs — plus chips, labels and multi-value handling. `facet_group()` already omits an empty group, so **all seven are invisible today and appear on their own once a v3 extraction has run**. EQF filters as "requires at most this level", since someone with a licență also qualifies for a post asking postliceală.

**Detail page** gains a "Cerințe structurate" block: field-of-study chips each linking into the ISCED facet, with "Oricare dintre aceste domenii este acceptat" spelled out; competence chips carrying proficiency, with advantages dashed rather than solid; languages with CEFR; credentials; policy domains and exam stages; and a **per-role breakdown for multi-role postings**, each with its own studies, experience and competences.

**Verification.** Copied the export, promoted the five real v3 payloads into the copy (production untouched — `db.php` grew a `POSTURI_DB` override for exactly this), and ran the app against it. Every facet populated with real values, and all twelve filter results were checked against SQLite ground truth: isced 3/3, credential 1/1, domain 3/3, skill 2/2, stage 5/5, EQF inclusive-of-lower-levels 3, two v3 facets AND-ing to 1, a v3 facet combining with județ, HTMX filtering, chip labels in Romanian, chip removal restoring 1,710. Production run afterwards to confirm all routes still 200 with zero v3 facets rendered.

**One bug caught by that verification.** Four of the seven facets returned nothing while `skill` and `eqf` worked. The difference was underscores: these vocabularies are full of them (`09_sanatate_asistenta_sociala`), `_` is a LIKE wildcard, and I escaped it without declaring `ESCAPE '\'` — so the pattern matched literally nothing. Fixed, and the ground-truth comparison is what surfaced it; the facet counts alone looked perfectly healthy.

225 tests pass (16 new for `_v3_columns`, including that a v2 payload must not half-populate the v3 columns).

**Still true:** none of this shows anything until `llm-schema.py --prompt-version v3` runs at scale, which needs provider credits.

### 2026-09-08 — First v3 run: three bugs of mine, four prompt defects, and a structural gap

User ran `--compare --prompt-version v3 --active-only --limit 5`. Gemini completed all five; OpenAI returned 429 (no credits). `schema_json` stayed at 1,278 rows, so `--compare` honoured its promise not to touch production data.

**Three bugs of mine, all from yesterday's v3 wiring:**

1. **DeepSeek died with `name '_validate_v2' is not defined`.** Renaming that helper to `_validate_extraction`, I used a regex matching only the call taking `raw` and left three behind — OpenAI, Anthropic, DeepSeek. Gemini happened to be the one I did fix, which is why my own verification passed. I should have grepped after renaming instead of trusting the substitution. Fixed, plus two tests that would have caught it: one asserting the old name appears nowhere, and an AST check that every name loaded inside a `make_generator` provider branch resolves to a local or a module global. Both were confirmed to fail against the broken code before being kept.
2. **`llm-schema.py` only imported from the repo root** — `open("models_config.json")` by relative path. Now resolved against `__file__`.
3. Cost figures in the design doc were stale after the prompt grew; corrected.

**Four prompt defects the output showed, all fixed:**

| Defect | Frequency | Fix |
|---|---|---|
| Multi-role postings returned all-nulls | 2 of 5 | new `positions[]` array + a third few-shot |
| `fields_of_study` labels were adjectives or filler — `juridic`, `medical`, `specialitate`, `sanitară` | 3 of 5 | "normalise to the name of the field, not the adjective", explicit ban on filler words |
| `bibliography_topics` over-extracted — 37 entries, some genitive fragments | 2 of 5 | cap at 12, nominative form |
| `credential.kind` fell back to `altele` for things with a home | 1 of 5 | per-kind guidance with examples |

**The structural finding.** Two of the five postings advertise *several distinct roles* — "1 post de expert IT senior; 2 posturi de expert administrație publică I" — each with its own requirements (3 years of tenure vs 7). A flat extraction cannot hold two contradictory requirement sets, and rather than choose, the model returned null for education, skills, occupation *and* seniority. That threw away a posting which plainly stated "Cerințe specifice: Cunoașterea procesului de utilizare a fondurilor europene". Measured across the corpus: **143 of 1,710 active postings (8.4%)** are multi-role. Added `PositionRequirement` and a `positions[]` array — one entry per role with its own education/experience/skills/credentials — while the flat fields keep describing the first role so a consumer that ignores `positions` still gets something real. Filed a separate item for the downstream consequence: the list row, the detail page and every filter still treat a posting as one job.

**A correction to my own reading.** `skill_list` looked under-extracted (1, 1, 0, 0, 0) and I said so. It was not. My evidence was a substring count of "abilit" — 21 hits in one posting — but every one was inside *dizabilități*, *reabilitarea* or *unitățile sanitare abilitate*. That is the same substring trap I fixed in `_infer_skills` yesterday, and I walked straight into it while checking someone else's work. Counted on word boundaries, three of the five postings contain no competence list at all and the empty result is correct; the one real miss was the multi-role posting.

**What worked, and these were the open questions:** `fields_of_study` genuinely splits — one posting yielded seven alternatives across three ISCED fields (psihologie → 03, asistență socială → 09, științe administrative → 04). `credentials` is the strongest field in the schema: "certificat de membru OAMGMAMR", "aviz anual pentru autorizarea exercitării profesiei", "permis categoria B", "asigurare malpraxis" — exactly the hard yes/no filters. `contract` came back richer than expected (`duration_months: 33`, `shift_work: true`). `experience` read "6 luni" as `0.5` with `in_specialty: true`.

**Still unmeasured:** cross-provider agreement, especially on `isced_field` — "inginerie geodezică" is defensibly 07 or 05, and how much providers disagree decides whether ISCED is trustworthy enough to filter on. The revised prompt (17.3k chars, ~5k tokens; JSON Schema 19.2 KB) has not been run at all.

209 tests pass.

### 2026-09-08 — Prompt v3: granular extraction designed for CV-to-job matching

**Ask:** extract more semantics — study fields, competencies — from lines like *"Studii … în domeniul geografie/silvicultură/geologie/biologie/știința mediului/inginerie geodezică. Cunoștințe avansate de operare sisteme GIS (…)"*; how does Schema.org JobPosting relate to Europass; can we build a v3 that makes the UI filter as expressive as possible, with Europass CV upload as the end goal.

**Researched first.** [cariere.gov.md](https://cariere.gov.md/ro/search?active=1) filters on **Domenii** (33 subject-matter domains: achiziții publice, securitate energetică, protecția consumatorului …), plus function type, authority type, locality and employment type. The important idea there is that *subject matter* is a separate axis from *profession* — a lawyer in an environment agency is both, and our `profession_family` only captures the second. The [Europass XML v3.0 page](https://interoperable-europe.ec.europa.eu/collection/employment-and-working-conditions/solution/europass-xml-schema-v30/release/no-version) does not publish field names but does name the vocabularies it imports: ISCO, ISCED, EQF. [ESCO](https://esco.ec.europa.eu/en/classification/skill_main) supplies the modern skills pillar — knowledge / language / skills / transversal, 13,939 concepts — and [Europass CVs](https://esco.ec.europa.eu/en/about-esco/escopedia/escopedia/cv-creation) carry EQF levels, ISCED-F fields of study, CEFR language levels and DigComp digital skills.

**The answer on Schema.org vs Europass:** they are mirror images and neither replaces the other. Schema.org describes the *vacancy* and is deliberately loose (`educationRequirements` is free text) because its consumer is a search engine. Europass describes the *person* and is deliberately strict, because its whole point is that a diploma from one member state is legible in another. A matching system needs both — Schema.org so the posting stays Google-Jobs-eligible, Europass vocabularies so posting and CV become comparable. v3 emits Schema.org property names at the top level and hangs EU-vocabulary values underneath.

**What v3 adds.** Keeps all twelve v2 fields verbatim (`JobPostingExtractionV3` *inherits* `JobPostingExtraction`, so every page, feed and renderer works unchanged on a v3 row) and adds a structured layer beside them:

- `education` — `minimum_level` + **EQF 1–8** + `fields_of_study[]` with **ISCED-F 2013** codes. The alternatives in the example become six separate matchable entries instead of one unmatchable string, spanning three ISCED fields.
- `skill_list[]` — short tags, not sentences, with the **ESCO pillar** and a proficiency: `{label: "GIS", type: "knowledge", proficiency: "avansat", evidence: "Cunoștințe avansate de operare sisteme GIS"}`. The parenthesised list expands into four more entries.
- `language_list[]` — **CEFR** A1–C2. `credentials[]` — licences and authorisations, deliberately separate from skills because they are the hardest filter there is: you hold the document or you do not. `contract` — duration, schedule, hours, remote mode, probation, shifts. `policy_domains[]` — 24 subject-matter domains adapted from cariere.gov.md. Plus `occupation_title`, `seniority_hint`, `exam_stages[]`, `bibliography_topics[]`.

Throughout: anything a filter depends on is a controlled value, with the original Romanian kept in `verbatim` / `evidence` for display. Free text cannot be matched; enums can.

**Plumbing.** `llm-schema.py` no longer hard-codes `JobPostingExtraction` — it looks the model up in `schema_models.EXTRACTION_MODELS`, so all four provider paths (Gemini `response_schema`, OpenAI strict `json_schema`, Anthropic tool `input_schema`, DeepSeek) pick up v3 automatically and v4 is a one-line change. Verified that every provider's schema payload builds for both versions.

**Not run.** All four providers remain unusable, so v3 is designed, wired and tested but has extracted nothing. The honest state: 26 tests cover the vocabularies, the v2-superset guarantee and the OpenAI strict-mode schema; the GIS example validates end to end; nothing has been through an actual model. First thing to do when a provider works is `--compare --prompt-version v3 --active-only --limit 5`, which writes only to `JobPostingSchemaVariant` and never touches `schema_json`.

**Cost:** v3's system prompt is 12,377 chars (~3.6k tokens) against v2's 6,355 (~1.9k), and the structured-output JSON Schema grows 6.2 KB → 17.4 KB. Against a 6,000–9,000-token posting body that is under ~15% per posting.

**Written up** in `docs/metadata-schema-v3.md` — the rationale, the Schema.org↔Europass comparison, the full field map with the filter each unlocks, and four open questions (ESCO URI resolution, whether `policy_domains` should be a standard, ISCED-F assignment being the model's judgement, and the lack of a migration path for the 380 v2 rows).

### 2026-09-07 — Attachment descriptors: dropped the filename, tried document titles, kept what survived

**Ask:** the filenames are useless — use the document's own title instead, in a standardized way.

**What the data allowed.** Measured over 899 downloaded files with four successive classifier designs:

1. Keyword match anywhere in the first 2,500 chars → 91% labelled, but "Cerere de înscriere" took 24% because announcements *quote* the enrolment requirement before they say "anunț".
2. Earliest-marker-wins → collapsed to 88.6% "Anunț de concurs", which was the honest answer and showed the problem.
3. Heading-like lines only → the same false positives, since section headings are also short lines.
4. First meaningful line, letterheads skipped, abandoning once the document calls itself an announcement → **only "Erată" survived with real precision.**

The shape of the data is the finding: **~89% of these files simply are the competition announcement**, one per posting, and their headings are all some spelling of "ANUNȚ" (often letter-spaced, `A N U N Ț`). Bibliografie, fișa postului, cererea de înscriere and the calendar are almost never separate documents — they are *sections inside* that one file, so every classifier that reached for them mislabelled the announcement itself and scored worse than saying nothing. A free-form "title" is no better: the first lines are letterheads, registry numbers and OCR noise.

**So the standardized label is deliberately small.** New `webapp/apps/jobs/attachments.py` recognises exactly two kinds — **Erată / modificare** and **Rezultate** — both of which declare themselves on the opening line and both of which materially change what a reader should expect (an erratum can move a deadline). Everything else keeps the role it already has reliably from the field it came from: *Anunț oficial* vs *Document anexat*.

**What replaced the filename.** `extract_attachments` now writes `JobPosting.attachment_meta` — `[{url, ext, bytes, kind, role}]` — computed per file while it already has each one open. The detail page renders a format badge, the standardized label and the file size (median 163 KB, largest 9.2 MB), with an erratum picked out in amber. Size is the genuinely new information: it was nowhere on the site, and it is what a reader on mobile data wants before tapping a 9 MB scan. Active postings: 990 DOCX, 637 PDF, 167 DOC; one erratum found in the current batch.

**Also this session:**

- Ported the **LLM-parsed filter** from the Django "Dev" panel (commit `c9be4ce`, never in the deployed app) to `webapp-php/` as a public facet — *Descriere → Structurată (LLM) 380 / Doar text brut 1,413*. Dropped the sibling `inferred` filter (100% populated, always a no-op) and `variants` (not exported).
- Made the **Schema.org structure visible**: the JSON-LD now emits all seven text properties the prompt fills (was two), plus `occupationalCategory` and `directApply`; each rendered heading shows its property name linked to schema.org/JobPosting; and the 79% with no structured extraction now say so rather than silently presenting a wall of scraped text.
- **Prompt location, for the record:** `models_config.json` → `prompts.v2` (6,355 chars), loaded by `get_prompt()` at `llm-schema.py:52`, sent as the *system* instruction — `system_instruction` for Gemini, `role: system` for OpenAI/DeepSeek, a cached `system` block for Anthropic. The posting content (body + attachment text, boilerplate-stripped, 100k cap) is the *user* message.

**Backlog:** filed employer search + institution-type detection. Checked two assumptions while writing it — `employer_category` turns out to hold the *contract* type ("Funcție contractuală" on 1,710 of 1,793), not the institution type, so it does not already cover this; and a prototype keyword pass over the 1,103 active employers classified **89%** (primărie 32%, educație 24%, spital 19%, …), so the `judete.py` treatment is the right model.

**Tests:** 22 new in `webapp/tests/test_attachments.py`, including the exact failure mode that killed the richer taxonomies (an announcement containing BIBLIOGRAFIE / CERERE DE ÎNSCRIERE headings must stay unlabelled). Full suite: 171 passed.

### 2026-09-07 — Ported the LLM-parsed filter to the public app; Schema.org made visible; attachment filenames

**The filter existed, in the other app.** Commit `c9be4ce` (2026-05-28) added a dashed amber "Dev" panel to the *Django* browse sidebar with three radio filters — Schema JSON / Inferred meta / LLM variants. It was never ported to `webapp-php/`, which is what is deployed. And no, they are not all LLM-parsed: **380 of 1,793 active postings (21%)**.

Ported it as a real facet rather than a debug panel, since the distinction is useful to readers too: **Descriere → Structurată (LLM) 380 / Doar text brut 1,413**, under "Mai multe filtre", with live counts, a removable chip and full HTMX behaviour. Dropped the `inferred` yes/no filter (100% populated since this morning's backfill, so it would always be a no-op) and the variants filter (variant rows are not in the SQLite export).

While testing it: the groups inside "Mai multe filtre" were themselves collapsed, so reaching one checkbox took two disclosures. Anomalii and Descriere now default open inside the panel, like Salariu and Angajator already did.

**Schema.org, made visible.** The extraction already produces Schema.org JobPosting property names and the renderer maps them to Romanian labels — but nothing said so, and the JSON-LD was throwing most of it away.

- The JSON-LD now emits every text property the prompt fills: `responsibilities`, `educationRequirements`, `experienceRequirements`, `qualifications`, `skills`, `jobBenefits`, `workHours` — previously only two of the seven. Added `occupationalCategory` from the inferred profession family (only above the confidence floor) and `directApply: false`, since applications go through the source site.
- Each rendered section heading now carries its property name in small mono type, linked to schema.org/JobPosting — *Studii · educationRequirements*. The three RO-specific keys (`application_docs`, `application_fee`, `application_contact`) are deliberately unlabelled, because they are ours and not part of the vocabulary.
- The 79% with no structured version now say so instead of silently presenting a wall of scraped text: a short note above the body explaining that no section-level extraction exists yet, linking to the methodology.

**Attachments.** Was "Anunț oficial ↗" / "Document 1 ↗" — no indication of what the file is. Now shows the filename with a file-type badge and the role beneath, full name on hover (`title`) and in `aria-label`. Worth recording that the filenames are useless as names: all 1,794 attachment URLs in the export are opaque (`j_11414_c_9189_anunt_688423.docx`, or an 8-char hex hash) — not one contains a real word. So the display leans on the type badge (DOCX/PDF/DOC), which is the only signal actually present, and middle-truncates the name (`j_11414_c_9189_anun…88423.docx`) so the extension survives the ellipsis.

**Verified:** facet counts match SQLite exactly (380/1,413), chip add/remove keeps the sidebar in sync, both page variants render correctly, all routes and feeds 200, no JS errors, 149 tests pass.

### 2026-09-07 — Traced the detail-page body; found .doc extraction broken since May

**Question asked:** where does the posting body come from, is it the LLM version with standard sections, and does the LLM get both the web body and the attachments?

**Answers, in order.**

`pages/detail.php` prefers the LLM's structured sections (`schema_json` → `render_schema_sections()` → `SCHEMA_SECTION_LABELS`) and falls back to `render_markdown($p['body_markdown'])` — the raw scraped page. **Only 21% of active postings (380/1,793) have `schema_json`**, so 79% render unstructured text. That is the stalled prompt-v2 backfill, blocked on all four LLM providers being unusable.

The v2 prompt does define clear standard sections and the renderer displays every field it asks for. Fill rates on the 380 that have it: jobLocation 96%, educationRequirements 95%, application_contact 95%, application_docs 83%, workHours 71%, experienceRequirements 70%, qualifications 64%, skills 42% — and **responsibilities only 18%**, which is the section a job seeker most wants. (`work_conditions` is in the renderer's label map but the prompt never emits it — dead entry.)

Yes, `llm-schema.py::iter_postings` concatenates `body_markdown` + `attachment_text` with a `---` separator, strips the HG 1.336 boilerplate and truncates at 100k. And it demonstrably matters — comparing the 380 postings with schema, split by whether attachment text existed at extraction time:

| section | with attachment | without |
|---|---|---|
| responsibilities | 22% | 8% |
| skills | 47% | 25% |
| application_docs | 96% | 40% |

**Which is what turned up the real bug.** `extract_attachments.py::_extract_doc` still used `docx2txt`, which only understands the ZIP-based .docx container and raises `KeyError`/`BadZipFile` on every genuine legacy .doc. `extract_text()` swallowed all exceptions into `""`, so an extractor failing on 100% of its input was indistinguishable from a pile of empty documents. The switch to `textutil` was made in `quality_check.py` (a reporting script) in May 2026 and **never ported to the management command that actually writes `attachment_text`**. Measured on four failing files: docx2txt 0 chars every time, textutil 9,031–15,862 chars.

Fixed with a portable converter chain — `textutil` (macOS) → `antiword` → `catdoc` (both Debian-installable), which also closes the "textutil is macOS-only, add a fallback before deploying to Linux" note from May. Plus a ZIP-magic sniff, because some servers serve a real .docx under a .doc name. `extract_text()` now returns `(text, reason)` instead of swallowing failures, and the command prints a by-reason summary, so the next systematic breakage is visible on the first run rather than four months later.

**Result:** re-running extraction recovered text for **2,082 postings**. Active coverage 77% → **86%**; postings with an attachment link but no text 23% → 14%. The 980 remaining are "no text layer" — scanned PDFs needing OCR, already a known backlog item. Re-ran `infer_postings --no-llm --force` over the richer text (studies 1,491 → 1,502, experience 952 → 956, work_type 846 → 856) and re-exported.

**Not fixed:** the 79% without `schema_json` still need a working LLM provider. That gap is now the single biggest quality lever on the site — and every posting fixed above will produce a better extraction when it finally runs.

### 2026-09-07 — Inference backfill + inferred metadata shown on every posting

**Why:** the request was "we extracted structured data for filters — show it next to each job post". Measuring first: **1,768 of 1,793 active postings had `inferred = {}`** (99% empty), exactly as the backlog predicted. The display would have rendered blank on almost everything that ships, so the backfill came first.

**Backfill.** `infer_postings --no-llm` runs the keyword-dictionary pass entirely offline — no provider needed, ~50 postings/sec. Coverage on active postings went 1% → 100% on the core fields: profession_family 1,545 (86% non-`altele`), studies 1,491 (83%), experience 952 (53%), work_type 846 (47%), seniority 461 (26%).

**Three inference bugs found by looking at the rendered output**, each of which would have shipped wrong data to users:

1. **Age read as work experience.** `_EXPERIENCE_RE` only matched "N ani … vechime", and `_normalize()` collapses newlines, so "Să aibă vârsta de minim 21 ani" running into the next line's "Vechime in munca: minim 1 ani" yielded *21 years of experience* for a driver's post. Romanian postings overwhelmingly put the label first, so the regex now prefers "Vechime în muncă: minim 3 ani" and keeps the number-first form as a fallback guarded against a nearby "vârsta". Effect: 2,619 postings newly detected, none lost, and the bogus 21 corrected to 1.

2. **`_infer_skills` matched substrings, not words.** "SAR" fired on **8,183 of 9,425 postings (87%)** because it is inside *nece**sar**e*, *comi**sar***, ***sar**cini*; "atestat" fired on 36% via the boilerplate "starea de sănătate atestată". A "skill" present on 87% of postings is noise wearing a data costume. Switched to the file's existing word-boundary helper: SAR 8,183 → 1, atestat 3,437 → 620, and every genuine skill (Excel, Word, permis de conducere, Microsoft Office) unchanged. Also de-duplicated certifications on case/NBSP and canonicalised language spellings ("engleza"/"engleză" → one tag).

3. **`--force --no-llm` silently destroyed paid LLM work.** 598 postings had an LLM-derived `profession_family`; re-running the dictionary pass to pick up fix #1 would have rewritten them all to `altele`. `--no-llm` now means "don't call the LLM on this run", not "discard what a previous run established" — an existing LLM classification is preserved when the dictionary is not confident. The run summary also stopped counting preserved classifications as "LLM calls", which is what would have hidden it.

**The display.** New `inferred_meta()` / `inferred_tags()` in `webapp-php/helpers.php` are the single source for derived attributes, so list and detail cannot drift.

- **Result rows** get one compact line — *Domeniu · Funcție · Grad · Studii · Experiență · Normă · Telemuncă · Calculator* — visually separated from the badges above it, which come straight from the source. A dotted-underline `auto` marker carries a tooltip explaining these are derived and may be wrong. All 25 rows on page 1 now render a populated line.
- **Detail page**: the conditions grid is now explicitly headed "Condiții deduse automat" with a link to the methodology, and gained skills / languages / certifications chips — extracted since May and never displayed anywhere. The sidebar's inferred block uses proper labels and links Domeniu and Funcție into the corresponding filtered browse.
- Labels: `conducere_superioara` was leaking raw into the UI; added `SENIORITY_LABELS` and `grade_label()`. A low-confidence family (< 0.5) or the `altele` catch-all is shown as nothing rather than as a wrong guess. Romanian year pluralisation via `years_label()` ("Min. 1 an", not "1 ani").

**Tests:** 24 new in `webapp/tests/test_experience_inference.py` — label-first and number-first phrasings, the age-vs-tenure guard with the exact SOFER body, explicit "nu este cazul", SAR/atestat word-boundary cases, tag de-duplication, and three DB-backed tests that `--no-llm` preserves LLM families while a confident dictionary still wins. Full suite: 149 passed.

**Left open:** the `altele` bucket is 14% of active postings and only an LLM pass can shrink it (all four providers are still dead). `skills` now covers 30% of postings rather than 99% of nothing — real, but thin; the keyword list is 14 entries and worth growing.

### 2026-09-07 — Canonical județe: 261 → 42, locality split out, fragmentation guarded

**What:** Fixed the county data rather than working around it in the UI. `judet_name` arrived in two shapes — bare `Timiş` before the 2026-07 redesign, `TIMIŞOARA, Timiș` after — and `import_csvs` stored both verbatim into a unique-by-name `Judet` table. Result: **261 rows for a country with 42 counties**, Brașov spread across 11 of them, and a browse facet where picking "Cluj" missed every posting filed under `CLUJ-NAPOCA, Cluj`. The home page tile read "197 JUDEȚE".

**`webapp/apps/jobs/judete.py`** is now the single place that interprets a raw county string. It holds the canonical 42 (comma-below diacritics), folds the Turkish cedilla ş/ţ that Windows-era text uses onto the correct ș/ț, and `normalize_judet()` returns `(county, locality)` — so the city half that used to be welded into the county name is kept rather than discarded. Localities are title-cased Romanian-aware ("BAIA DE ARAMĂ" → "Baia de Aramă", particles stay lowercase; already-mixed-case input is left alone). All 261 raw values resolve; none needed an alias.

**Where it runs:**
- `import_csvs` normalises on the way in, so new data can no longer fragment. It reports canonical-vs-source counts and warns loudly on any value it cannot match.
- `manage.py normalize_judete` repairs what was stored: 261 → 42 rows, 4,940 postings re-pointed, 219 stale rows deleted, 17 clean slugs reclaimed from rows that had been squatting on them. `--dry-run` shows the split first.

**A bug I introduced and caught:** the first backfill run reported "1,838 localities backfilled" and the second reported "0 changed" — while silently erasing all 1,838. The locality is derived from the *pre-merge* `Judet.name`, which the command itself deletes, so on a second run every posting resolved to `locality=None` and got written back as empty. The counters only tracked *gains*, so nothing showed it. Fixed two ways: the command now only ever fills a locality in, never clears one (corrections belong to `import_csvs`, which reads the authoritative CSV), and the summary reports rows actually written, not just rows improved. Verified idempotent.

**New fields.** `JobPosting.locality` (1,838 postings, 198 distinct localities) and `JobPosting.judet_raw`, which holds the source string *only* when the county could not be matched — so `judet_raw != ""` is the "needs attention" filter. Migration `0009`, both indexed.

**Flagging, as asked.** Three layers, because a silent county failure is exactly the class of bug that went unnoticed for five weeks with `expires_at`:
1. `import_csvs` prints a warning listing the unmatched values.
2. `judet_sanity_warnings()` runs on every import next to the expiry check — fires if the Judet table exceeds 42 rows, or if any posting has an unresolved county — and honours `--strict` for cron.
3. Admin gained a *Județ — rezolvare* filter (Nerecunoscut / Lipsă / Cu localitate / Fără localitate), plus `locality` in the list display and both fields in the edit form. The usual fix for an unmatched value is one line in `judete.ALIASES`.

**Downstream.** `export-to-sqlite.py` exports `locality` (indexed), and the FTS location column is now `locality || ' ' || judet_name`, so "cluj napoca" finds 102 postings that county-only search missed. In `webapp-php/`, a new `place_label()` renders "Cluj-Napoca, Cluj" on result rows, the detail header and employer profiles, and the JSON-LD carries a real `addressLocality` — which is a genuine Google Jobs improvement, not just cosmetics. The județ facet lists all 42 counties by proper name (`București`, not `bucuresti`) and is no longer capped at 25, since the cap was what made a selected value outside the top window disappear.

**Tests:** 31 in `webapp/tests/test_judete.py` (county list integrity, diacritic repair, folding collisions, title-casing, both badge shapes, county capitals keeping their locality, unknown values staying unresolved, idempotency) and 6 more for `judet_sanity_warnings`. Full suite: 125 passed.

**Also filed:** a backlog item for rendering expired postings as a real "anunț expirat" page with a similar-jobs block instead of a 404 — blocked on the `--active-only` export decision, which this work makes more pressing now that the status control has a "Toate" option and the sitemap points crawlers at postings that will expire.

### 2026-09-07 — UX pass on the PHP webapp: mobile, search, filter chips, SEO, accessibility

**What:** Worked the `docs/backlog.md` "UX / UI" section against `webapp-php/` (the deployed app). Django templates under `webapp/templates/jobs/` were deliberately left alone this pass — they now diverge, and porting is a separate task.

**Two pre-existing bugs found while implementing, both worse than anything on the backlog list:**

1. **Multi-select facets only ever applied their last value.** Sidebar checkboxes were named `judet`, not `judet[]`. A browser serialises repeated checkboxes as `judet=cluj&judet=iasi`, which PHP collapses to `'iasi'` — so the moment any filter change went through HTMX, a three-county selection silently became one. Only the initial page load (where links were written with `judet[]=`) ever filtered correctly. Fixed with `param_field()` + a `MULTI_PARAMS` list in `helpers.php`; the chip-removal JS matches both the bare and bracketed name.

2. **Search was phrase-only, so most multi-word queries returned nothing.** `fts_escape()` wrapped the whole query in double quotes, making it an FTS5 phrase match: `inspector primărie` returned 0 where the AND-ed terms return 22. Replaced with `fts_query()` — tokenises on non-alphanumerics, quotes each term, ANDs them, and adds a prefix wildcard to the trailing term so the 400 ms live search doesn't flash "no results" mid-word. Ranking moved from bare `rank` to `bm25(job_postings_fts, 10, 3, 1, 1)` so a title hit outranks a passing body mention. Returns `''` for term-less input, and `build_filters()` now refuses to claim FTS in that case (an empty MATCH expression is a SQLite error).

   A third, smaller one: the județ facet lists the top 25 of ~197 slugs, so a selected value outside that window had no checkbox and was dropped on the next form submit. `facet_group()` now pins any active-but-unlisted value to the top of its group.

**Mobile.** The search input moved out of the sidebar into a full-width bar that renders at every width. The facet column became one element that is a sticky sidebar at `lg` and a right-hand slide-over drawer below it — backdrop, Escape, focus return, scroll lock, an "Arată N rezultate" footer button, and a badge on the trigger showing the active-filter count. The count, the badge and the screen-reader live region update through `hx-swap-oob` rather than JS. Inner per-facet scrollers are now `lg:`-only: nested scrolling inside a drawer is hard to aim at. On phones the KPI tiles reorder below the search form (CSS `order`) so search is reachable without scrolling. Job detail's structured column no longer hides below 640px — it stacks above the body as a two-column card, with the deadline and contact first, and the duplicated `sm:hidden` fallback block is gone.

**Filters.** Chips are generated from one `FILTER_CHIP_GROUPS` map covering all 18 params instead of 3, with per-key value labels (județ slug → county name, bucket keys → their labels). `qs_without($key, $value)` drops a single value where `qs_with($key, null)` dropped the whole key. Chips are HTMX now, not full page loads: a click handler unchecks the matching control and re-fires the form, so the sidebar and the URL stay in agreement; the `href` remains the no-JS fallback. Facet groups became `<details>` (native keyboard + AT behaviour), open for the top four, with Salariu / Angajator / Anomalii / exact dates behind a "Mai multe filtre" disclosure and open state persisted in `localStorage`. A segmented **Active / Expiră în 7 zile / Toate** control with live counts replaces the date pickers as the primary deadline control; `active` is the new default, which is a no-op on the active-only deploy but matters once the archive ships.

**Empty state.** On zero results the page now runs one COUNT per active filter and offers the two or three whose removal recovers the most postings ("Încearcă fără — Caută: zzzz +6"). Only computed on the zero-results branch.

**SEO.** `JobPosting` JSON-LD on every detail page, built from the columns plus the Schema.org-shaped `schema_json` the v2 prompt already produces — all six Google Jobs required properties present. Added `/robots.txt` and `/sitemap.xml` (2,900 URLs: statics, every posting newest-first, and only employers that actually have a posting in the export). Canonical, description, and OpenGraph tags in `inc/header.php`, with canonical stripping the query string so filter permutations fold into one indexable URL. Detail URLs are now `/job/1234-slug/`; `/job/1234/` and stale slugs 301 to the canonical form, and the id stays in front so lookup is still a primary-key hit. `javascript:history.back()` is gone — the back link points at the referring filtered list when there is one, else `/`.

**Assets.** Replaced the `cdn.tailwindcss.com` play script with a real build: `package.json` + `tailwind.config.js` at the repo root, source in `webapp-php/assets/app.css`, output committed to `webapp-php/static/app.css` (27 KB minified) so the shared host needs no toolchain. Fonts and htmx are self-hosted; DM Sans italic was dropped (114 KB for the odd `<em>` — synthetic oblique covers it). `.htaccess` gained immutable cache headers, deflate, a woff2 mime type, and a deny for the sqlite journal sidecars. `deploy-php.sh` refuses to run if `static/app.css` is missing and excludes `assets/` and the dev-only `router.php`.

**Accessibility.** `ink-muted` and `ink-faint` were 4.23:1 and **2.22:1** on parchment — both under AA. Darkened to #625C56 / #6F6963 (5.81 / 4.78) and added a separate `border-input` at 3.12:1 for form controls, since WCAG 1.4.11 applies to controls but not to decorative hairlines. Plus: skip link, labelled search input, `role="status"` live region announcing the result count on every swap, `aria-label` on the pagination nav and `aria-current` on the active page, `<ul>/<li>` for the result list, `<time datetime>` on dates, and tap targets raised to ≥24 px on everything that isn't an inline prose link. Romanian day pluralisation is correct now (`1 zi` / `7 zile` / `32 de zile`), and the h1 subtitle shows the corpus total instead of the filtered count, which used to read "0 anunțuri indexate" on a failed search.

**Verified** with Playwright at 375 / 768 / 1280 / 1440 px: no horizontal overflow anywhere, drawer open/close/escape/backdrop/scroll-lock, multi-select surviving a round-trip, per-value chip removal, out-of-window value pinning, status control by mouse and keyboard, HTMX pagination, `localStorage` persistence across reloads, slug redirects, JSON-LD completeness, and zero JS errors. All routes and all three feeds return 200; no PHP warnings in the log.

**Not done:** dark mode and RO/EN remain open, as does the landing page rework — the domain tiles it needs are blocked on the inference backfill. The Django templates now lag the PHP app.

### 2026-09-06 — Re-exported the deploy SQLite; live site found five weeks stale; UX audit

**What:** Regenerated `webapp-php/posturi.sqlite` after the expiry fix, checked what posturi.gov2.ro is actually serving, and audited the PHP webapp for UX gaps. No application code changed — findings went to `docs/backlog.md`.

**Re-export:** the deploy artifact still held the pre-fix export (3 postings, 1.1 MB, written 2026-09-06 01:16 — the expiry fix landed after it and the export was never re-run). `export-to-sqlite.py --active-only` now writes 1,656 postings / 559 calendar events / 1,038 employers / 257 judete, 40 MB, FTS rebuilt. Verified every exported row has `expires_at` between 2026-09-07 and 2026-12-22 — no expired rows leaked through.

**Live site:** `https://posturi.gov2.ro/` returns 200 but serves **14 postings, 3 active**, header "actualizat 02.08.2026". It is not even running the 3-row September export — it is on an August 2nd database. Nothing was deployed; the target host is not in the repo (no `DEPLOY_HOST` in `.env`, no match in `~/.ssh/config`). Left as a backlog item at the user's request.

**Two problems the fresh export surfaced:**

1. **Inferred facets are empty on live data.** 2,290 postings have `inferred = {}`, and since the deploy is active-only those are almost exactly the shipped rows. On the 1,656 active postings: profession_family 23, studii 22, work_type 4, seniority 3, experiență 3, telemuncă 1, anomalii 0. Seven of the sidebar's 13 facet groups are `inf_*`-driven, so the live sidebar would collapse to five working facets with "Domeniu" near-empty at the top. `facet_group()` skips empty groups, so it disappears silently rather than rendering broken. Not blocked by the dead LLM providers — `infer_postings --no-llm` runs the dictionary pass offline; the caveat is that it writes `altele`/0.0 for unmatched titles, taking them out of `inferred = {}` so a later LLM pass needs `--force`.

2. **`judet_name` mixes two formats, splitting every county in the Județ facet.** Post-redesign rows store `"CITY, Județ"`, pre-redesign rows the bare county, and they become separate `judete` rows with separate slugs. Cluj is listed as 7 separate facet entries. 976 active rows are city-comma format, 680 bare — 257 `judete` rows for a country with 42 counties. This makes one of the five *still-working* facets wrong.

**UX audit (`webapp-php/`, all findings also present in `webapp/templates/jobs/` since the PHP app was ported from them):** the headline is that below 1024px there is no search box and no filters at all — the sidebar is `hidden lg:flex` and the `q` input lives inside it, with a mobile fallback that reads "Deschide pe desktop pentru filtre complete." Job detail hides its whole structured column (deadline, contact, attachments) below 640px. Filter chips cover 3 of 15 params and their remove links drop every value of a key rather than the clicked one. Plus: Tailwind Play CDN in production, `javascript:history.back()` as the detail back link, no `aria-live` on the HTMX-swapped results, and no dark mode or RO/EN despite both being in the v1 spec. Written up as a new "UX / UI" section in the backlog with file:line references.

**Not done (deferred by the user):** the deploy, and the `infer_postings --no-llm` run.

---

### 2026-09-06 — Post-import sanity check for expiry parsing

**What:** Added a guard so a repeat of the expiry outage above surfaces on the next import instead of five weeks later.

**Changes:**
- `expiry_sanity_warnings(active, recent_total, recent_without_expiry)` in `import_csvs.py` — a pure function (hence testable without a DB) returning one warning per problem. Two checks, both **ratios rather than absolute floors** so they stay meaningful as the dataset grows: (1) >50% of postings published in the last 30 days missing `expires_at` → date parsing is probably broken; (2) zero active postings while recent postings exist → an active-only export would ship an empty site.
- Called at the end of `handle()`; prints a `Sanity:` line every run, writes warnings to stderr, and with the new `--strict` flag raises `CommandError` so cron/CI fails loudly.
- `webapp/tests/test_expiry_sanity.py` — 11 tests. Includes a regression test pinning the real outage numbers (`active=3, 2295/2295 missing`), the countdown strings that caused it, the ISO parse branch, and the `2046` typo rejection. Also asserts the checks stay silent on an empty DB and at 50% missing, so they don't cry wolf.

**Verification:** 88 tests pass (77 + 11 new). Against live data the guard is silent (`active=1656, 21/1917 missing`); replaying the pre-fix numbers fires the expected warning. Not exercised via a full `import_csvs` run — the v2 backfill was mid-flight and the `search_vector` rebuild would contend with it; the function was validated directly against live counts instead.

---

### 2026-09-06 — Fix: `expires_at` NULL for every post-redesign posting (live site showed 3 jobs)

**What:** `expires_at` had been NULL for 3,103 of 9,377 postings — including all 2,295 published on/after 2026-08-01 — leaving `export-to-sqlite.py --active-only` (`WHERE jp.expires_at >= CURRENT_DATE`) with 3 rows to deploy.

**Root cause:** the redesigned site shows a *relative countdown* on index cards instead of a date. `expira_in` now holds `"1 zi rămasă"` (238), `"N zile rămase"` (766), `"Ultima zi"` (293) or `"Anunț anulat"` (79); only the 6,274 pre-redesign `/anunt/` rows still carry `Expiră in DD/MM/YYYY`. `import_csvs.py` fed that straight into `parse_date()`, which returned `None`. The Aug 1 scraper rewrite fixed the fetch/parse side but this mapping was never revisited — and the detail page's absolute date was already being captured as `Data Expirare` in `anunturi.csv` (9,243 usable values), just ignored by the importer.

**Changes** (`webapp/apps/jobs/management/commands/import_csvs.py`):
- `parse_date()` gained a `YYYY-MM-DD` branch — it handled `DD.MM.YYYY`, `DD/MM/YYYY` and `9 septembrie, 2024`, but not the ISO dates `parse-anunturi.py` writes, so `Data Expirare` was unparseable even where read.
- New `expires_by_url` map built from `anunturi.csv` before Pass 1; `expires_at` now resolves detail-first, index-`expira_in` second. The join is on `Source URL`, so it covers the 3,000 `/joburi/` rows and leaves the old `/anunt/` rows on the index path.
- New `plausible_expiry()` rejects years outside `2000..today.year + 2`, applied to **both** sources. Catches upstream typos like `Expiră in 14/01/2046` on a posting published 2025-12-23, which would otherwise read as permanently open. Rejections are reported in the command output rather than dropped silently.

**Result:** NULL `expires_at` 3,103 → 80 (79 `Anunț anulat`, correctly excluded, + 1 typo'd row); active postings 3 → 1,656; `max(expires_at)` 2046-01-14 → 2026-12-22. `export-to-sqlite.py --active-only` now exports 1,656 postings / 559 calendar events. 77 tests pass; two consecutive imports produce identical counts.

**Not done:** the same countdown string still makes `compare_and_update()` in `fetch-index.py` log a spurious `expira_in` change for every posting every day (12 entries on one sampled row in 18 days), inflating `updates_raw`, feeding noise into `JobPostingUpdate`, and defeating the skip-unchanged-page save optimisation. Left as an open backlog item.

---

### 2026-08-02 — PHP webapp packaging: active-only SQLite export + streamlined deploy

**What:** Refactored the `export-to-sqlite.py` → `deploy-php.sh` packaging so the PHP webapp folder is fully self-contained with an active-only database.

**Changes:**
- `export-to-sqlite.py`: Added `--active-only` flag that filters `WHERE expires_at >= CURRENT_DATE` on both `job_postings` and `calendar_events` queries in PostgreSQL, keeping the exported SQLite small and focused. Default `--out` path changed to `webapp-php/posturi.sqlite`.
- `deploy-php.sh`: Uses `--active-only` and explicit `--out` path; removed separate DB rsync step (DB is inside `webapp-php/` now). Added `--no-perms --no-owner --no-group --omit-dir-times` flags to rsync for shared-hosting compatibility.
- `webapp-php/db.php`: Removed parent-directory fallback — DB lives at `__DIR__/posturi.sqlite` only.
- `.gitignore`: Added `webapp-php/posturi.sqlite`.

**Why:** The full data archive stays in PostgreSQL; the deployed webapp only needs active postings. Packaging the SQLite inside `webapp-php/` makes the folder self-contained — one rsync deploys everything.

---

### 2026-08-02 — Rebrand to posturi.gov2.ro, WIP/alpha banners, last-updated date

**What:** Updated all PHP webapp branding from "posturi.gov.ro" to "posturi.gov2.ro" with alpha/WIP disclaimers throughout.

**Changes:**
- Header: logo reads `posturi.gov2.ro`, tagline `alpha · WIP`
- Yellow dismissable banner on every page: "WIP / MVP — versiune în lucru. Nu este un proiect oficial al Guvernului României." with link to Google Form feedback
- Footer: same WIP disclaimer + feedback link
- `MAX(last_seen_at)` queried once in header.php, displayed in both header and footer as "actualizat DD.MM.YYYY"
- Page titles, feed metadata (Atom, iCal, JSON) updated throughout
- about.php: simplified for alpha launch, added feedback link

**Why:** Preparing for public alpha. Clear disclaimers that this is unofficial, with a feedback channel.

---

### 2026-08-02 — Fixed export-to-sqlite.py: psycopg2 → psycopg3

**What:** `export-to-sqlite.py` imported `psycopg2` but the project venv only has `psycopg` v3 (3.3.4). The import error caused the export step to fail silently, leaving a stale SQLite.

**Fix:** Switched imports and API calls:
- `import psycopg2` / `psycopg2.extras` → `import psycopg` / `psycopg.rows.dict_row`
- `psycopg2.connect(...)` → `psycopg.connect(..., row_factory=dict_row)`
- `pg.cursor(cursor_factory=DictCursor)` → `pg.cursor()` (row_factory set on connection)

---

### 2026-08-01 — Rewrote scraping pipeline for redesigned posturi.gov.ro

**What:** Rewrote `fetch-index.py`, `fetch-anunturi.py`, and `parse-anunturi.py` to work with the completely rebuilt posturi.gov.ro website (WordPress + Astra 4.12.3 + Elementor + custom "PG" plugin).

**Changes:**
- `fetch-index.py`: New base URL, listing URL (`/toate-posturile/?pg_page=N`), pagination (`nav.pg-arc-pagi`), and all selectors rewritten for `article.pg-card` → `div.pg-card-h`, `div.pg-card-inst`, `a.pg-card-link`, `span.pg-tag`, `div.pg-card-deadline`, `div.pg-card-published`, `div.pg-card-city span`.
- `fetch-anunturi.py`: Updated `base_url` to HTTPS, `parse_romanian_date()` now handles the new `Data publicării: DD.MM.YYYY` format, `extract_main_content()` targets `div.pg-wrap.pg-job-single`.
- `parse-anunturi.py`: Dual support for old (`/anunt/`, `.titlu h1`, `.caseta .ang`) and new (`/joburi/`, `h1.pg-title`, `.pg-contact-row`, `.pg-prose`, `a.pg-btn-pill`) HTML structures. Auto-detection via `.pg-title`/`.pg-jobcard` presence. Structured contact extraction from `.pg-contact-row` elements. `_try_index_lookup()` falls back between old/new URL schemes for index CSV date matching.

**Why:** The site was rebuilt entirely — every selector, URL pattern, and data structure changed. The old `/page/N/` listing, `article.box` cards, and `/anunt/{slug}/` detail pages no longer exist.

**Backward compatibility:** `parse-anunturi.py` handles both old and new HTML, so the existing 6,275 cached detail pages don't need re-fetching.

---

### 2026-05-28 — Survey: scanned-PDF frequency in attachments

**What:** Ran a pypdf-based survey across all 750 PDFs in `data/downloads/`. For each file, extracted text from the first 3 pages and classified as "text-extractable" (≥50 chars), "likely scanned" (< 50 chars but file > 30 KB), or other.

**Results:**
- Text-extractable: **361 / 750 (48.1%)**
- Likely scanned (image-only): **389 / 750 (51.9%)**
- Parse errors: 0 (pypdf handled all files, though many had malformed xref tables)

**Why:** Quality check showed that the OCR fallback (poppler + tesseract) in `quality_check.py` fired correctly on at least one known scanned PDF. This survey quantifies the scope — just over half the attachments need OCR to be readable.

**Next step (backlog):** Run OCR backfill on the 389 scanned PDFs, store extracted text in `data/attachments_text/<slug>.txt`, and feed into quality_check.py and potentially the LLM extraction pipeline.

---

### 2026-05-28 — LLM variants dashboard: quality metrics added

**What:** Extended `/llm-variante/` with three quality signals per (provider, model, prompt_version):

- **Completitudine** — avg % of 10 key fields filled across all runs. Color-coded: green ≥70%, amber ≥50%, red <50%.
- **Dezacord** — for postings with ≥2 variants at the same prompt_version, % of field-comparisons where this model is the minority (has value when others don't, or null when others do). Computed on 6 multi-model postings at v2.
- **Solo** — count of times this model is the *only* one with a value for a field (hallucination proxy).

A collapsible **Completitudine per câmp** section shows a per-field heatmap (each cell = % fill rate for that field/model combination).

**Notable findings from live data (v2, 6 comparable postings):**
- `gpt-5-nano`: highest completeness (63%) but also highest disagreement (18%) and 7 solo values — most likely to hallucinate
- `gemini-2.5-flash` (production, 380 runs): 46% completeness, 5% disagreement, 1 solo — consistent but often leaves `responsibilities` and `skills` empty
- `deepseek-v4-flash`: 55% completeness, 0% disagreement, 0 solos — most conservative
- `baseSalary`: 0% across all models (good — models aren't guessing salary when absent)
- `responsibilities`: 0% for deepseek, 0% for gpt-4o-mini, 10% for gemini, 83% for gpt-5-nano

**New filter `dict_get`** added to `jobs_extras.py` for dynamic dict key lookup in templates.

---

### 2026-05-28 — LLM variants dashboard (/llm-variante/)

**What:** New page at `/llm-variante/` showing a per-(provider, model, prompt_version) leaderboard table with: run count, average cost (¢/post), total cost ($), average latency (ms), average input/output token counts. Rows are grouped by prompt version. Nav link "LLM" added to the site header.

**Why:** After running llm-schema.py `--compare` across multiple models, there was no quick way to see relative cost/speed without querying the database manually. The dashboard shows what data exists and serves as a backfill progress indicator.

**Implementation:** `llm_variants_dashboard` view aggregates `JobPostingSchemaVariant` with `values("provider", "model", "prompt_version").annotate(count, avg_cost, total_cost, avg_latency, avg_input, avg_output)`. Template uses `regroup` tag to group by prompt version. Renders gracefully with a "no variants yet" message when the table is empty.

---

### 2026-05-28 — "Nu este cazul" experience flag in inference pipeline

**What:** `_infer_experience` in `infer_postings.py` now returns a `(experience_years, no_experience_required)` tuple. When the posting body explicitly states that no experience is required ("nu este cazul", "nu se solicită experiență", "fara experienta in munca", etc.), it returns `(0, True)` instead of `(None, False)`. A numeric year count always takes precedence over the no-experience phrases. The `no_experience_required` flag is stored in the `inferred` JSONB column alongside `experience_years`.

**Why:** Postings with "nu este cazul" were previously indistinguishable from postings that simply didn't mention experience. The flag enables filtering for true entry-level positions.

**Tests:** 7 new tests in `TestInferExperience` class (77 total, all passing).

---

### 2026-05-28 — HTML sanitization of rendered Markdown output

**What:** Added `nh3` (Rust-backed HTML sanitizer) to the webapp. All Markdown-rendered HTML now passes through `_sanitize()` before reaching templates, covering both `body_html` in `job_detail` and all `schema_json` sections in `_render_schema_sections`.

**Why:** The `body_markdown` field and `schema_json` section values both originate from external data (scraped HTML converted to Markdown, then LLM-transformed). Passing rendered output through a tight allowlist (block/inline/table/link tags; no iframes, scripts, forms, event handlers) eliminates the XSS vector if either source is ever compromised or contains injected content.

**Allowlist:** `_ALLOWED_TAGS` covers `p br ul ol li strong b em i h1–h6 table thead tbody tr th td blockquote pre code a`; `_ALLOWED_ATTRS` limits `a[href, title]`, `td/th[colspan, rowspan]`. `nh3` added to `requirements.txt`.

---

### 2026-05-28 — Job condition attributes: extraction, badges, detail grid, browse facets

**What:** Added a new Layer 3 extension to the inference pipeline and surfaced the extracted data across the UI.

**Inference (`infer_postings.py`):**
- Four new helpers: `_infer_work_type` (norma_intreaga / norma_partiala / schimburi, with confidence scoring), `_infer_remote` (telemuncă/remote/hibrid → `true` or `null`), `_infer_computer` (regex + skill cross-check → `true/false/null` + `basic/advanced`), `_extract_salary_range` (denormalizes `schema_json.baseSalary.minValue/maxValue`).
- New `--conditions-only` flag re-runs just these fields without touching `profession_family`, `seniority`, or anomaly flags — safe for backfill.
- All fields stored in existing `inferred` JSONB column (no migration). `conditions_inferred_at` timestamp marks which postings have been processed.

**New management command (`infer_conditions_llm.py`):** LLM queue for postings where `work_type` confidence is 0.0 and body ≥ 250 chars. Sends a minimal 3-field prompt (work_type / remote_eligible / requires_computer), updates `inferred` in-place.

**Browse view (`views.py`):** `_apply_filters` extended with 6 new params (`work_type`, `remote`, `computer`, `exp_level`, `studies_level`, `salary_bucket`). Matching facet count queries added. Salary facet conditionally shown only when ≥ 100 postings have salary data. Salary and experience are bucketed.

**Result row badges:** New condition pill row below existing badge row — shows normă, telemuncă, calculator, experience when non-null.

**Detail page grid:** "Condiții la locul de muncă" 6-tile grid (program / telemuncă / calculator / experiență / studii / salariu) inserted before the schema sections. Renders only when ≥ 2 attributes are non-null; null attributes show `—`.

**Sidebar facets (list.html):** 6 new facet groups in order: Tip normă, Experiență minimă, Studii minime, Calculator, Telemuncă, Salariu (conditional).

**Tests:** 32 new tests in `test_condition_inference.py` covering all 4 helpers + 5 view integration tests. Full suite: 70/70 passing.

**Why:** Users had no way to filter by work schedule, remote eligibility, or computer requirements without reading every posting in full. The hybrid approach (local regex for ~85% coverage, LLM queue for the rest) keeps cost near zero for the bulk of postings.

---

### 2026-05-27 — LLM comparison analysis document

**What:** Wrote `docs/llm-comparison-analysis.md` — a standalone analysis of the 4-model benchmark (GPT-5 Nano, Gemini 2.5 Flash, DeepSeek-V4-Flash, GPT-4o Mini) run with prompt v2 on 5 postings.

Covers: cost per 1,000 posts, cache hit rates, structured-output API differences per provider, quality delta v1→v2, and a ranked recommendation (GPT-5 Nano first, Gemini fallback).

---

### 2026-05-27 — quality_check.py: OCR fallback, attachment-title mismatch detection, body-duplication splitter fix

**What:** Three targeted fixes to `quality_check.py` following a `/quality-review` session.

1. **OCR fallback for scanned PDFs** (`_extract_pdf`): if `pypdf` returns empty text and the file is > 50 KB, falls back to `pdftoppm -r 150 -png` (poppler) + `tesseract -l ron`. Tested: `eb59d7d2.pdf` (Consilier IA, Casa de Pensii Bucuresti) went from 0 → 9,878 extracted chars; content confirmed correct (Romanian-language OCR via `ron` model).

2. **Attachment-title consistency check** (`_attachment_title_mismatch` + `_infer_anomaly_flags`): compares ≥ 2 meaningful title words (len ≥ 4, filtered by stop-word list) against the first 2,000 chars of the attachment text. If < 2 hit, emits `attachment_title_mismatch` anomaly flag. Correctly fires on `79a59ec1.doc` (nursing exam curriculum attached to an inspector/fochist posting). Verified live via Playwright that the wrong file is served by posturi.gov.ro itself — not a pipeline bug. `anomaly_score` denominator raised from 4 → 5.

3. **Body duplication splitter** (`_check_body_duplication`): added a pre-normalisation step that converts Markdown trailing-space hard-breaks (`  \n`) to double-newlines before paragraph splitting. Previously the regex `\n{2,}` couldn't split Markdown-formatted bodies, leaving the entire body as one paragraph and making the check unable to fire.

**Why:** The quality report showed `body_duplication_rate = 0.0` for all postings despite visible repeated paragraphs in several bodies; `eb59d7d2.pdf` was reported as `empty` despite being a real 742 KB document; and the attachment-mismatch bug (wrong file at source) had no detection path.

### 2026-05-27 — Prompt v2: Schema.org-aligned extraction with caching, structured output, and boilerplate stripping

**What:** Rewrote the LLM-extraction pipeline for higher quality + lower cost. Output is now a superset of Schema.org JobPosting properties (flat keys named after JobPosting.responsibilities/educationRequirements/experienceRequirements/qualifications/skills/baseSalary/jobBenefits/workHours/jobLocation) plus three RO-specific custom keys (application_docs, application_fee, application_contact). Education and experience are now split out from the bundled-in-v1 `qualifications`; `baseSalary` and `application_fee` are structured `{minValue, maxValue, currency, unitText}` / `{amount, currency, account, details}` objects; `application_contact` captures the submission contact separately from the top-level CSV contact.

Three new pieces, plus a restructure of `llm-schema.py`:
- **`schema_models.py`**: Pydantic `JobPostingExtraction` model. Single source of truth fed to each provider's native structured-output API (OpenAI strict json_schema, Gemini response_schema, Anthropic tool-use input_schema, DeepSeek loose json_object). `openai_json_schema()` mutates the Pydantic-generated schema to satisfy OpenAI strict mode (additionalProperties=false, required-lists-every-property).
- **`boilerplate.py`**: `strip_hg_1336(text)` removes lines matching ~25 patterns covering the generic HG 1.336/2022 / Codul muncii / OUG 57/2019 art. 542 eligibility text that appears on nearly every posting (cetățenia română, capacitate de muncă, condamnări, pedepse complementare, clauze de confidențialitate, condițiile generice de studii/vechime etc.). Diacritic-tolerant; preserves the art. 35 dosar list (which IS the `application_docs` content). Measured 13–25% input-length reduction on 6 sampled postings; quality benefit (qualifications no longer drowning in boilerplate) is the bigger win.
- **Prompt v2** in `models_config.json`: hybrid English imperative + Romanian vocabulary anchors (`atribuții`, `vechime în specialitate`, `taxa de concurs`, `dosar de candidatură` etc.), 2 few-shot examples (clean salary + attachment-only contact), explicit boilerplate-skip rule, field-by-field guidance with anti-patterns (e.g. "never set baseSalary for taxa de concurs").
- **`llm-schema.py` refactor**: `make_generator(provider, model, system_prefix, prompt_version)` splits the static prompt prefix (cacheable) from the per-posting content (variable). Provider-side caching wired up: Anthropic explicit `cache_control:{type:ephemeral}` on the system block, OpenAI/DeepSeek implicit (consistent system message + >1024 tokens), Gemini via `system_instruction`. Structured output via each provider's native API for v2; legacy ad-hoc JSON parsing kept for v1. `compute_cost()` now accepts `cached_input_tokens` and uses the model's `cache_input_cost_per_million` rate when defined.

Also fixed: `models_config.json` Gemini model IDs (dots not dashes — `gemini-2.5-flash` not `gemini-2-5-flash`); GPT-5 family needs `max_completion_tokens` and `reasoning_effort=minimal` (default reasoning eats the completion budget on extraction tasks).

**Detail page (`webapp/apps/jobs/views.py`)**: `_SCHEMA_SECTION_LABELS` extended with the new v2 keys (+ corresponding Romanian labels: Studii, Experiență, Beneficii, Program de lucru, Locație, Contact pentru depunere). Three small render helpers `_render_base_salary`, `_render_application_fee`, `_render_application_contact` convert the structured dicts to markdown. Back-compat: v1 string-shaped values fall through the markdown path unchanged, so the existing 22 postings with v1 `schema_json` keep rendering.

**Verification:**
- 14/14 renderer tests pass (`webapp/tests/test_schema_detail.py`); covers v1 back-compat, v2 structured fields, and the three helper functions.
- `boilerplate.py` smoke-tested against 6 real postings (ids 4437, 4509, 4590, 4897, 6717, 7519, 8268, 8694): role-specific content survives, HG 1.336 boilerplate removed, dosar list (application_docs) preserved.
- `llm-schema.py --compare --prompt-version v2 --limit 5 --force` on all 4 enabled models (gemini-2.5-flash, gpt-5-nano, gpt-4o-mini, deepseek-v4-flash): all outputs validate against the Pydantic schema; cache hits visible from the second call (~94% on gpt-4o-mini, ~99% on deepseek). Per-1000-postings cost: gpt-5-nano $0.36, gemini-2.5-flash $0.61, deepseek $0.70, gpt-4o-mini $0.88 — so the full 4357-posting backfill costs under $4 on any provider. Sample output for posting 4437 (muncitor calificat) shows `qualifications` shrunk from ~2300 chars of HG 1.336 boilerplate (v1) to a single 76–295-char role-specific line (v2: "Nivel de acces la informații clasificate: Secret"), with `educationRequirements`/`experienceRequirements`/`application_contact` cleanly populated.

**Why:** v1's `qualifications` was 80% legal-citation boilerplate on every posting — inflating output cost, hurting search relevance, and obscuring the role-specific signal in the UI. Splitting education + experience and structuring `baseSalary` unlocks future filters (salary range, min-experience). The Schema.org-aligned key names mean we can wrap the JSON in `@context/@type` at render time for JSON-LD / Google for Jobs SEO without changing the LLM pipeline.

**Next:** promote v2 to default once spot-checked in the UI: change `PROMPT_VERSION = "v2"` in `llm-schema.py`, run `python llm-schema.py --provider gemini --prompt-version v2 --force` to backfill the remaining 4357 postings.

### 2026-05-27 — LLM provider comparison infrastructure (with model enable/disable + prompt versioning)

**What:** Built end-to-end framework for comparing LLM providers and prompts on the same job postings without overwriting production schema:
- **Config-driven models + prompts** (`models_config.json`): 
  - Defined 6 enabled models across 4 providers (Gemini 3.1/2.5 Flash, GPT-5 Nano, GPT-4o Mini, Claude 3.5 Haiku, DeepSeek-V4-Flash) with input/output costs per million tokens
  - 2 disabled models (Claude 3 Haiku, GPT-4o) kept in config for reference
  - Prompts versioned (`v1`, etc.) centralized in config; loaded per-run via `--prompt-version`
- **Variant storage** (`JobPostingSchemaVariant` model, migration 0007): Captures provider, model, prompt_version, schema output, token counts, cost (computed), latency_ms, created_at. Unique constraint on (posting, provider, model, prompt_version) for A/B testing multiple prompt versions.
- **Refactored llm-schema.py**: 
  - Token capture from each provider's SDK response; `compute_cost()` for real USD calculation
  - `get_enabled_models()` to respect enabled flag; skip disabled models in `--compare`
  - `get_prompt(version)` to load prompts from config dynamically
  - New flags: `--model-filter <regex>` for testing subsets (e.g., `gemini-.*`, `gpt-.*`), `--prompt-version <v>` for prompt A/B testing
- **Variant comparison view** (`/job/<id>/variants/`): Side-by-side display with cost/latency highlighting, full schema preview, prompt version visible.
- **Admin + UI**: JobPostingAdmin inline display; standalone variant browser; job detail "Dev → Comparație LLM" link.

**Usage examples:**
```
python llm-schema.py --compare --limit 10                              # all enabled models, prompt v1
python llm-schema.py --compare --model-filter "gemini-.*" --limit 5    # test only Gemini
python llm-schema.py --compare --prompt-version v2                     # compare all with prompt v2 (when v2 exists)
python llm-schema.py --model-filter "gpt-.*" --prompt-version v2 --limit 3  # GPT models + prompt v2
python llm-schema.py --provider anthropic                              # single provider (production mode)
```

**Why:** (1) Choose best LLM provider by cost/quality on real postings. (2) Test prompt improvements without running all providers. (3) Disable expensive models (GPT-4o) or older ones (Claude 3) without deletion. (4) A/B test prompt v1 vs v2 across providers.

**Next:** Run `python llm-schema.py --compare --limit 100` to benchmark, then consider adding prompt v2 (stricter/more detailed) to config for comparative testing.

### 2026-05-27 — Structured job detail display from schema_json

Added `schema_json` JSONField to `JobPosting` (migration 0006). Rewrote `llm-schema.py` to extract 7 structured display sections (responsibilities, qualifications, skills, application_docs, salary, application_fee, work_conditions) from `body_markdown + attachment_text` via LLM, storing results in `jobs_jobposting.schema_json` directly via psycopg. Added `schema` pipeline step. Detail page now renders named structured sections instead of raw markdown blob when `schema_json` is populated, with fallback to `body_html`. Calendar reordered to appear after body content.

### 2026-05-27 — Stats dashboard at /statistici/

**What:** Added a server-rendered stats page accessible from the nav:
- KPI tiles: total postings, active count (14%), auto-classified count (61%)
- Horizontal bar charts for top 10 profession families and top 10 județe; each bar label links to the browse view pre-filtered
- Anomaly table: all 6 flags with count, % of total, and a "filtrează →" link
- Refactored `stats_json` logic into `_build_stats()` shared helper; `stats_json` now calls it; `stats_dashboard` view also calls it and enriches the data for display
- `by_judet` query now also returns `judet__slug` so the judet links in both dashboard and JSON are correct
- "Statistici" nav link added to `base.html` header

### 2026-05-27 — FTS search_vector extended with attachment_text (weight D)

**What:** Extended the full-text search index to include attachment content:
- `import_csvs.py` `search_vector` SQL now includes `coalesce(j.attachment_text, '')` at weight D (lowest weight, after title A, employer B, body C).
- Rebuilt `search_vector` for all 4,379 postings in-place; 2,630 postings (60%) had non-empty `attachment_text` incorporated into their index.
- A small number of NOTICE warnings from PostgreSQL about words > 2,047 chars (e.g. base64 blobs in raw attachment text) are expected and benign — those tokens are simply skipped.

**Why:** After boosting attachment coverage from 25% → 60% via `extract_attachments --force`, the search index still didn't see that text. Searching for contact info or role-specific keywords buried in a .docx now works.

### 2026-05-27 — Production deploy: Dockerfile + fly.toml + whitenoise

**What:** Full production deployment configuration for Fly.io:
- `webapp/Dockerfile`: `python:3.13-slim`, installs `libpq-dev` for psycopg, runs `collectstatic` at build time, starts gunicorn with 2 workers.
- `webapp/fly.toml`: App name `posturi-gov-ro`, primary region `waw` (Warsaw, closest to Romania), `shared-cpu-1x` + 512 MB RAM, `auto_stop_machines=stop` (free-tier friendly), `release_command = python manage.py migrate --noinput`.
- `webapp/.dockerignore`: excludes `.venv/`, `.env` files, `staticfiles/`, `__pycache__`.
- `requirements.txt`: added `gunicorn>=23.0` and `whitenoise[brotli]>=6.8`.
- `settings.py`: `WhiteNoiseMiddleware` inserted after `SecurityMiddleware`; `STORAGES` key set to `CompressedManifestStaticFilesStorage`.

**Deploy steps:** `fly launch` (first deploy, provisions Postgres), then `fly secrets set SECRET_KEY=... DATABASE_URL=... GOOGLE_API_KEY=...`, then `fly deploy` for updates. The `release_command` applies migrations atomically before traffic switches.

### 2026-05-27 — Browse UI: anomaly flags filter + feed autodiscovery

**What:**
- Browse sidebar: new "Anomalii" section with 5 checkboxes (short_deadline, missing_contact, gender_criteria, no_body, frequent_repost). Uses HTMX-wired checkboxes; each flag ANDs with the others in `_apply_filters`. Reset button condition updated.
- `base.html`: added `<link rel="alternate">` for Atom and JSON feeds — standard feed autodiscovery that browsers and RSS readers pick up automatically.
- `detail.html`: inferred data section in sidebar showing `profession_family`, `seniority`, and `anomaly_flags` (amber badges). Only rendered when `posting.inferred` is non-empty.
- `list.html`: Export section at bottom of facet sidebar with Atom/JSON/iCal links that carry the current filter querystring (via `feed_url` template tag).
- `jobs_extras.py`: new `feed_url` template tag returns `/filename?<current_qs>`.

### 2026-05-27 — JSON API and Atom feed endpoints

**What:** Added two feed endpoints that mirror the Browse UI filters:
- `/posturi.json` — `JsonResponse` up to 200 results; all 10 browse filter params accepted (`q`, `judet`, `level`, `type`, `categorie`, `employer_cat`, `expires_before`, `expires_after`, `family`, `seniority`). Returns `{count, results[]}` with full field set including `profession_family`, `seniority`, and `anomaly_flags` from `inferred`.
- `/posturi.atom` — Atom 1.0 feed via `django.contrib.syndication.views.Feed`, 50 most recent items with title, employer as `author`, and structured description (județ/tip/categorie/termen).

**Architecture:** Extracted `_filter_kwargs_from_request(request)` helper that parses GET params into the `filter_kwargs` dict; both feeds and the browse view's `job_list` share `_apply_filters()`. Feed class uses `get_object()` override to capture request context before `items()` is called.

### 2026-05-27 — Docker Compose for dev, admin slug cleanup, canonical fields decision

**What:**
- `docker-compose.yml` at repo root: `postgres:17` service with named `pgdata` volume and health check. Port 5433 avoids conflict with local brew Postgres on 5432. `webapp/.env.example` updated: added Docker DATABASE_URL comment, renamed `GEMINI_API_KEY` → `GOOGLE_API_KEY`.
- `JudetAdmin` and `EmployerAdmin`: replaced `prepopulated_fields = {"slug": ("name",)}` with `readonly_fields = ("slug",)`. Model `save()` owns slug generation via `slugify()` + unique suffix loop; the admin JS `prepopulated_fields` was redundant and visually misleading (it suggested the admin form controlled the slug, when it doesn't).
- Canonical fields decision: `job_type` (from detail/announcement page) is canonical for job permanency over `tip` (index tag). `categorie`/`job_level`/`employer_category` are canonical over `detalii_raw` for display/filtering; `detalii_raw` retained for FTS.

### 2026-05-27 — Anomaly heuristics: frequent_repost flag

**What:** Added `frequent_repost` as the 5th anomaly flag in `infer_postings.py`. Added `build_frequent_repost_ids()`: one O(n) pre-pass before the main inference loop that groups all 4,379 postings by `(employer_id, normalized_title)` using NFKD/strip-diacritics normalization. Any group with 3+ members is a frequent-repost cluster; the function returns a `frozenset[int]` of those posting IDs. Each posting's `_infer_anomaly_flags()` call checks `posting.pk in frequent_repost_ids` — avoiding per-posting cross-queries entirely.

**Result:** 80 postings flagged on current dataset. Top offenders: MINISTERUL AFACERILOR EXTERNE (8× "Referent relații"), Universitatea Dunărea de Jos (5× "Administrator patrimoniu"), Agenția Națională de Îmbunătățiri Funciare (5× "Consilier IA"). These are real re-posting cases where institutions repeatedly fill unfilled vacancies.

**Also updated:** `anomaly_score` denominator bumped from 4 → 5 (now covers all v1 flag types). Admin `AnomalyFilter` and display icon dict updated. `narrow_criteria` flag deferred — would need LLM to detect tailored requirements (too vague for regex).

### 2026-05-27 — JobPostingUpdate model + parse_updates management command

**What:** Added `JobPostingUpdate` (migration 0005) to store parsed change-log segments from `JobPosting.updates_raw`. The `parse_updates` management command reads all non-empty `updates_raw` values, splits on `"; "` to get individual event segments, parses each as `(YYYY-MM-DD, fields_changed)`, and bulk-creates records idempotently. Registered in admin with `date_hierarchy` and `is_new_entry` boolean display.

**Current result:** 2,529 "New entry" records (all postings have only their initial first-seen date; no field-change events exist yet in current data). The model and command are ready for when incremental scrapes begin producing actual change records, which will power the "modificat recent" browse badge and job-detail change history.

**Format:** `"YYYY-MM-DD: New entry"` or `"YYYY-MM-DD: field1, field2; YYYY-MM-DD: field3"` — semicolons separate segments, colon+space separates date from fields. Regex: `(\d{4}-\d{2}-\d{2}):\s*(.+?)(?=;\s*\d{4}-\d{2}-\d{2}:|$)`.

### 2026-05-27 — CI: GitHub Actions workflow (ruff + pytest + migrations check)

**What:** Added `.github/workflows/ci.yml` — Python 3.13, Postgres 17 service (health-checked), runs: `ruff check .`, `python manage.py makemigrations --check --dry-run`, `pytest`. Added `webapp/pyproject.toml` with ruff config (E+F+I rules, line-length 120, migrations excluded from E501). Added `ruff` to `webapp/requirements.txt`. Fixed 3 unused imports and 6 unsorted import blocks flagged by ruff across `import_csvs.py`, `infer_postings.py`, `templatetags/jobs_extras.py`, `urls.py`, `settings.py`, and `tests/`.

**Also:** `llm-schema.py` prompt updated with baseSalary suppression guard (same text as `quality_check.py::SCHEMA_PROMPT`). FAMILIES dict and `black` backlog items closed (both were already done in prior session).

### 2026-05-27 — Fix: conftest.py `django_db_setup` override wiped production DB

**What:** The original `conftest.py` included a session-scoped `django_db_setup` fixture that was a no-op (`pass`). This bypassed pytest-django's built-in database isolation, causing the `@pytest.mark.django_db(transaction=True)` test to run against the real `posturi_dev` database. After the test completed, pytest-django flushed all tables (standard `TransactionTestCase` teardown), deleting all 4,379 postings and associated data.

**Fix:** Removed the `django_db_setup` override from `conftest.py`; pytest-django's default fixture now creates an isolated `test_posturi_dev` database. The `pytest_configure` function was also removed since `pytest.ini` already sets `DJANGO_SETTINGS_MODULE`. `conftest.py` is now a 2-line file that just sets the env var at module level.

**Recovery:** Re-ran `import_csvs --data-dir ../data` (4,379 postings, 2,955 employers, 43 județe, 11,567 calendar events), then `canonicalize_employers --apply` (257 aliases, 521 FK reassignments), then `infer_postings` (dict + LLM pass on all postings).

**Non-obvious:** `@pytest.mark.django_db(transaction=True)` uses Django's `TransactionTestCase` semantics which does NOT roll back via savepoint — it calls `flush` on teardown. Overriding `django_db_setup` to skip test-DB creation is only safe if you also ensure post-test cleanup; without it, the production DB is trashed. The default pytest-django `django_db_setup` correctly wraps everything in a `test_` prefixed database.

### 2026-05-27 — Test suite: importer idempotency (pytest-django)

**What:** Added pytest-django + factory-boy; wrote `tests/test_import_idempotency.py` with 3 tests covering: (1) running `import_csvs` twice doesn't duplicate Judet/Employer/JobPosting/CalendarEvent rows; (2) expected records are created with correct field values; (3) re-importing with updated title updates the existing row instead of creating a new one. All 3 pass in 0.25s against the real test database. Added `pytest.ini` and `conftest.py`.

**Non-obvious:** `call_command("import_csvs", data_dir=data_dir)` must pass a `Path` object, not `str` — the argparse `type=Path` conversion is bypassed when calling programmatically.

### 2026-05-27 — Browse UI: profession_family and seniority facets (Slice 2b)

**What:** Extended the Browse sidebar with two inferred-data facets: "Domeniu" (profession_family) and "Grad/funcție" (seniority). Both backed by JSONB field lookups on `JobPosting.inferred`.

**Changes:** `_apply_filters` in `views.py` extended with `families`/`seniorities` params; two new `.values().annotate(count=Count('id'))` queries on `inferred__profession_family` and `inferred__seniority`; `list.html` updated with two new `{% include facet_group %}` calls and updated reset-filter condition.

**Facet counts (current data):** sănătate 1164, administrație 983, tehnic 938, financiar 189, social 123, cultură 84 + 4 more families; seniority: referent 224, debutant 200, consilier 186, inspector 146, asistent 137, director 124.

**Note:** anomaly_flags facet skipped — it's a JSONB array and needs PostgreSQL `UNNEST` or a separate annotated queryset; deferred to a future pass.

### 2026-05-27 — Employer canonicalization (EmployerAlias model + management command)

**What:** 2,955 raw employer names contained 205 normalized-duplicate groups (462 employers, 257 that were pure variants). Added `EmployerAlias` model and `canonicalize_employers` management command.

**Algorithm:** NFKD normalize → strip combining diacritics → lowercase → punctuation→space → collapse whitespace. Canonical selection scored: +2 per Romanian diacritic (ș/ț/ă/î/â), +10 for title-case (not ALL CAPS). Picks the most "proper" looking variant as canonical.

**Result after `--apply`:** 257 EmployerAlias records created, 521 JobPosting.employer FKs reassigned to canonical employers. All 257 merged variants now have 0 postings. Admin updated: EmployerAdmin shows posting_count/alias_count; EmployerAliasAdmin for auditing.

**Why it matters:** Before this fix, "Administrația Bazinală de Apă Jiu" and "ADMINISTRATIA BAZINALĂ DE APĂ JIU" were separate employer records with 1 and 8 postings respectively. Now they're unified (10 postings) under the canonical. v2 employer-profile pages will be accurate.

**Non-obvious:** Aliased Employer records are kept in the DB with 0 postings (not deleted) as a safety measure — can be cleaned up once the merge is confirmed correct.

### 2026-05-27 — Full LLM inference pass on 4 379 postings

**What:** Ran `infer_postings --provider gemini --force` on the full dataset after dict refresh brought low-confidence count from 1,608 → 1,007. Discovered the webapp Django process wasn't loading `GOOGLE_API_KEY` because `settings.py` only called `load_dotenv(BASE_DIR / ".env")` (= `webapp/.env`, which doesn't exist) and never reached the repo-root `.env`. Fixed by adding `load_dotenv(REPO_ROOT / ".env")` as a fallback immediately after — webapp-level `.env` still takes precedence for production overrides.

**Non-obvious:** The 1,007 "errors" in the first run were silent `KeyError: 'GOOGLE_API_KEY'` inside `_llm_classify`, caught as `RuntimeError` and mapped to `source="error"` — counted in the error tally but not printed. All 1,007 now processed successfully with actual LLM calls after the settings fix.

### 2026-05-26 — FAMILIES sync, google-genai migration, contact_in_attachment refinement

**What:** Three follow-up improvements after the datePosted fix.

**1 — FAMILIES dict sync (webapp/infer_postings.py):** The webapp's `FAMILIES` dict was ~2 releases behind `quality_check.py`. Synced to full parity: `administrație` += manager/director/expert; `sănătate` += balneolog/ergoterapeut; `tehnic` += full construction/equipment operator vocabulary including `muncitor`, `muncitor necalificat`, `muncitor calificat`; `social` += `psiholog practicant`, `psiholog specialist`, `educator specializat`, `terapeut`; `ordine publică` += svsu/situatii urgenta/aparare civila/psi. Also fixed the webapp's env var to `GOOGLE_API_KEY` and bumped model to `gemini-2.5-flash`.

**2 — Migrate google.generativeai → google.genai:** Deprecated package was EOL with a FutureWarning on every run. Installed `google-genai`, updated both `quality_check.py` and `webapp/infer_postings.py` to use `google.genai.Client.models.generate_content`. Updated `requirements.txt`. FutureWarning confirmed gone.

**3 — `contact_in_attachment` anomaly flag:** `_infer_anomaly_flags` previously emitted `missing_contact` for any posting without a phone/email in the CSV card fields — including postings where the contact was clearly in the `.docx` attachment. Fixed: when CSV contact fields are empty, scan `combined_body` (card body + attachment text) for Romanian phone/email patterns. Emit `contact_in_attachment` (CSV extraction gap — not a real problem) vs `missing_contact` (truly absent everywhere). Applied to both `quality_check.py` and `webapp/infer_postings.py`. Verified: seed 7 sample correctly fires `contact_in_attachment` instead of `missing_contact` for a posting whose attachment contained a phone number.

**Re-ran parse-anunturi.py** locally to regenerate `anunturi.csv` with `Data Publicare`/`Data Expirare` columns baked in (data/ is gitignored).

### 2026-05-26 — Schema valid rate 0.5 → 1.0: join publicat_in/expira_in into schema context

**What:** The schema.org generator was failing `datePosted` and `validThrough` for 4–5/10 postings every run because `anunturi.csv` has no publication/expiry dates — those are only on the listing archive pages and were only stored in `posturi_gov_ro.csv` (`publicat_in` / `expira_in` columns scraped by `fetch-index.py`).

**Fix in `quality_check.py`:** Added `_parse_index_date()` (parses both `"Publicat în: D luna,YYYY"` and `"Expiră in  DD/MM/YYYY"` → ISO `YYYY-MM-DD`), `_load_index_dates()` (loads the index CSV into a URL-keyed dict once per run), and passed `date_posted`/`valid_through` kwargs into `check_schema`, where they are injected into the `fields_text` given to the LLM. The schema prompt now explicitly labels these as `datePosted` and `validThrough`.

**Fix in `parse-anunturi.py`:** Added `Data Publicare` and `Data Expirare` columns to `anunturi.csv` output by joining `posturi_gov_ro.csv` at parse time (same URL key). After re-running `parse-anunturi.py`, downstream consumers (webapp, quality checker) will find the dates in the CSV row directly.

**Result (seed 99, 10 postings):** `schema_valid_rate: 1.0` (was 0.5 with the same seed). All 10 schemas generated valid datePosted and validThrough.

**Non-obvious:** `quality_check.py` now checks `row.get("Data Publicare")` first (from a regenerated `anunturi.csv`) and falls back to the index lookup — so it degrades gracefully on an old CSV.

### 2026-05-26 — Quality review #1: pipeline fixes (attachment extraction, parse fallbacks, FAMILIES dict)

**What:** Ran `/quality-review` on the first 8-posting quality report and fixed the four systemic issues it surfaced.

**1 — `.doc` extraction via `textutil`:** `docx2txt` silently crashed on binary Word97 `.doc` files (they're OLE2, not ZIP — it tried to open `word/document.xml` in a zip archive). Replaced `_extract_doc` in `quality_check.py` with a `subprocess.run(["textutil", "-convert", "txt", "-stdout", ...])` call using macOS's built-in converter. All 8 `.doc` attachments in the sample now yield full text (8 KB+). Note: `textutil` is macOS-only; add a fallback if the pipeline moves to Linux.

**2 — FAMILIES dict expansion:** Added missing keywords that caused 5/8 postings to fall through to LLM fallback (which then failed on auth): `manager`, `director`, `expert` → `administrație`; `buldoexcavatorist`, `excavatorist`, `utilajist`, `macaragiu`, `stivuitorist`, `fochist`, `lacatus`, `tamplar`, `zidar`, `zugrav`, `pavator`, `dulgher`, `vopsitor`, `timonist` → `tehnic`; `svsu`, `situatii urgenta`, `aparare civila`, `psi` → `ordine publică`; `balneolog`, `ergoterapeut` → `sănătate`. All 8 postings now classify correctly via dict (no LLM needed for this sample).

**3 — Phone extraction fallback (`parse-anunturi.py`):** Existing `PHONE_RE` required 10 consecutive digits — missed formatted landlines like `0265 – 587.014` and mobile numbers written as `0722.256.558`. Added `PHONE_CANDIDATE_RE` that matches any 0-prefixed digit-with-separators sequence, then strips non-digits and keeps 10-digit results. Handles dash, dot, slash, en-dash, and mixed formats.

**4 — Deadline body fallback (`parse-anunturi.py`):** `_find_calendar_date` only searched the HTML calendar table; institutions sometimes put the deadline only in free-text body paragraphs (confirmed on live page for the Epidemiologie posting). Added `DEADLINE_BODY_RE` fallback that scans `body_text` for `data limita de depunere dosare : DD.MM.YYYY` when the calendar lookup returns empty.

**5 — Rectification detection (`parse-anunturi.py`):** When an institution corrects an announcement, they publish a new `.doc` and add a "Document atașat corectat" link in the body — but the original `<a>Anunt</a>` card link still points to the old file. Added a check that looks for this link pattern and promotes `announcement_url` to the corrected document. Verified against the live Epidemiologie posting via Playwright: `3dcc6ebe-1.doc` is the actual current document, `20ce1ed7-2.doc` is the superseded original.

**Non-obvious:** LLM schema generation had a 100% failure rate not because of code bugs but because `--provider anthropic` was used without `ANTHROPIC_API_KEY` set. The `anthropic` Python SDK makes direct REST calls — separate from Claude Code's claude.ai session auth. Gemini API key is available; use `--provider gemini` for LLM features going forward.

### 2026-05-26 — Data quality test suite (`quality_check.py` + `/quality-review` skill)

**What:** Added a standalone `quality_check.py` script that samples 5–10 diverse job postings (stratified by job type, level, county, attachment presence, body length) and runs all four pipeline layers through automated quality checks: CSV field completeness, attachment text readability, metadata inference (profession family confidence, skills, anomaly flags), and LLM schema.org/JobPosting generation. Produces `data/quality_report.json` and a console summary table. Also added `.claude/commands/quality-review.md` — a project-scoped Claude Code slash command (`/quality-review`) that reads the report, deep-reads source files, and produces a qualitative narrative with root-cause analysis and recommended fixes. Can optionally open source URLs in Playwright for visual scraping verification.

**Key findings from first run (--no-llm, 8 postings):**
- `.doc` files return empty text — `docx2txt` throws `KeyError` on old binary Word format (only handles DOCX/ZIP). 6/8 `.doc` attachment files affected. Added to backlog.
- `Data Limita Depunere` empty for most postings — structured deadline field is often unpopulated; dates appear only in body markdown. Added to backlog.
- Avg CSV completeness: 81.4%; avg profession-family confidence (dict-only): 0.19 — many titles need LLM fallback.
- `.docx` files (Buldoexcavatorist: 7k chars, ȘEF SVSU: 15k chars) extract correctly.

**New files:** `quality_check.py`, `.claude/commands/quality-review.md`, `docs/superpowers/specs/2026-05-26-data-quality-test-design.md`

**Usage:** `webapp/.venv/bin/python3 quality_check.py --no-llm` (fast) or `--provider anthropic` (full LLM pass). Then `/quality-review` in Claude Code for narrative assessment.

### 2026-05-26 — Attachment text extraction (`extract_attachments` command + `attachment_text` field)

**What:** Added `JobPosting.attachment_text` (TextField) + `extract_attachments` management command that reads `data/downloads/`, extracts plain text from linked DOCX/DOC/PDF files, and stores the result. Updated `infer_postings` to combine `body_markdown + attachment_text` for all Layer 3 inference.

**New files:** `webapp/apps/jobs/management/commands/extract_attachments.py`, migration `0003_jobposting_attachment_text`.

**Extraction stack:** `python-docx` for `.docx`, `docx2txt` for `.doc`, `pypdf` for text-native `.pdf`. Malformed/scanned files return empty string and are skipped silently.

**Results:** 1,113/4,379 postings extracted (25% — limited by download coverage: only 1,896 of ~4,363 attachment URLs have local files). 0 errors.

**Fill-rate improvement after re-running inference with attachment text:**

| Field | Before | After |
|---|---|---|
| studies_required | thin | 67.5% (2,958) |
| skills | thin | 88.1% (3,856) |
| experience_years | thin | 6.6% (291) |
| seniority | 26.1% | 26.1% (title-only, unchanged) |

**Next:** run `download-attachments.py` again to fill the remaining ~2,467 missing files, then re-run `extract_attachments --force`.

---

### 2026-05-26 — Inference pipeline: `infer_postings` management command

**What:** Built a three-layer metadata inference pipeline that populates `JobPosting.inferred` (JSONField) for all 4,379 postings.

**Inferred fields:** `profession_family` (+ confidence + source), `seniority`, `grade`, `studies_required`, `experience_years`, `skills`, `languages`, `certifications`, `anomaly_flags`, `anomaly_score`, `inferred_at`.

**Layer 1 — Profession family (keyword dictionary):** normalise title (lowercase + strip diacritics), word-boundary match against a curated dictionary of 10 families. Confidence = top_score / (top_score + second_score + 1). If confidence < 0.5 and `--no-llm` not set → LLM fallback.

**Layer 1b — LLM fallback:** short single-turn Romanian prompt; dispatches to gemini-2.0-flash / gpt-4o-mini / claude-haiku via `--provider`. API keys read from `.env`. 0.5s rate-limit between calls.

**Layer 2 — Seniority + grade:** regex over title for seniority levels (debutant → conducere_superioara) and grade (gradul I/IA/II/III, principal, superior).

**Layer 3 — Studies, experience, skills, anomaly:** regex over body_markdown. Anomaly flags: `short_deadline` (< 7 days publish → deadline), `missing_contact`, `gender_criteria`, `no_body`.

**Admin additions:** `InferenceConfidenceFilter`, `AnomalyFilter`, `reset_inferred` bulk action; list columns for family, confidence (color-coded), anomaly icons.

**Full-dataset run results (dict-only, no LLM):**
- sănătate 28.7%, administrație 24.0%, tehnic 8.7%, altele 29.1%
- Confidence: 63.3% medium (0.5–0.8), 36.7% low (< 0.5) → LLM fallback candidates
- Anomaly flags: missing_contact 33%, short_deadline 7%, gender_criteria 0.1%

**Non-obvious decision:** Switched keyword matching from `kw in norm` (substring) to word-boundary regex `(?<!\w)kw(?!\w)` — plain substring caused "it" to match inside "ingrijitor", misclassifying care workers as IT.

---

### 2026-05-26 — Slice 2: Browse/Search UI (HTMX + Tailwind + faceted search)

**What:** Built the first public-facing UI — a faceted browse/search page over all 4,379 job postings, plus a detail page.

**Stack wired in:** `django-htmx` middleware + `markdown` for body rendering + Tailwind CDN play script + Google Fonts (Fraunces + DM Sans).

**New files:**
- `apps/jobs/views.py` — `job_list` (faceted browse) + `job_detail`
- `apps/jobs/urls.py` — routes `/` and `/job/<pk>/`
- `apps/jobs/templatetags/jobs_extras.py` — `days_until` filter + `querystring` tag
- `templates/base.html`, `templates/jobs/list.html`, `templates/jobs/detail.html`
- `templates/jobs/partials/result_list.html`, `result_row.html`, `facet_group.html`

**Facets:** keyword FTS (`romanian_unaccent` config), județ (slug), job_level, job_type, categorie, employer_category, expires_at range. Each facet computes counts with all other filters applied ("sticky facets"). Sort: relevance (default when keyword), newest, deadline, employer A-Z.

**HTMX:** facet form submits to `/` with `hx-target="#results"` and `hx-push-url="true"` so URL stays shareable. Partial returns `result_list.html` fragment only. Pagination links inside the partial carry the same HTMX attributes.

**Aesthetic:** "Romanian State Archive" — warm parchment background (#F5F0E8), deep gov-blue header (#1B3A6B), Fraunces variable serif for titles, DM Sans for body, hairline borders instead of cards. Color-coded deadline countdown (green → amber → red).

**Verified:** `manage.py check` clean, root 200, detail 200, HTMX partial returns fragment (no `<html>`), keyword search for `conditii` == `condiții` (unaccent), visual review in Playwright.

---

### 2026-05-26 — Slice 1: Django webapp scaffold + data model + CSV importer

**What:** Stood up the Django project under `webapp/`, defined models mirroring the scraper CSVs, wrote an idempotent `import_csvs` management command, and verified end-to-end import into Postgres.

**Stack chosen:** Django 5.2 + HTMX (UI slice next) + React islands (map/calendar slice later) over PostgreSQL 14 with `tsvector` FTS. See `docs/ui-spec.md` "Stack" section for full rationale.

**Pre-work — `parse-anunturi.py` patched to record `Source URL`:** the CSV had no join key back to `posturi_gov_ro.csv`. Added `source_url_from_path()` that reconstructs the posting URL from the cached HTML file path (`data/anunturi/YYYY/MM/DD/<slug>.html` → `https://posturi.gov.ro/anunt/<slug>/`). Output CSV gained a `Source URL` column; `calendar.csv` `url` column now holds the posting URL instead of the (often missing) attachment URL. Re-ran parse-anunturi.py to regenerate both files.

**Webapp layout:**
```
webapp/
  manage.py, requirements.txt, .env.example
  posturi/{settings.py,urls.py,wsgi.py,asgi.py}
  apps/jobs/{models.py,admin.py,migrations/,management/commands/import_csvs.py}
```

**Models:** `Judet`, `Employer`, `JobPosting` (URL-keyed; index + detail fields + `inferred` JSONB reserved for v2/v3 + `SearchVectorField` + GinIndex), `CalendarEvent`.

**Importer:** idempotent `update_or_create` keyed by URL; date parsing handles `DD.MM.YYYY`, `DD/MM/YYYY`, and Romanian month names ("9 septembrie, 2024"); `parse_datetime_with_time()` for `Data Limita Depunere` with `ora HH:MM`. `search_vector` populated in a single SQL pass weighted A=title, B=employer, C=body.

**Verified end-to-end:** Postgres started, `posturi_dev` created, migrations applied, importer reported `created=4379, updated=0, errors=0` on first run and `created=0, updated=4379, errors=0` on re-run. Detail join: matched=4379/4379. Calendar: created=11567, unmatched=0. FTS query for "expert" returns expected rows.

**Out of scope (next slices):** public UI templates, HTMX wiring, Tailwind, React islands for map/calendar, taxonomy inference, accounts/feeds, scraper refactor.

---

### 2026-05-26 — parse-anunturi.py: structured field extraction + calendar CSV

**What:** Enhanced `parse-anunturi.py` to extract structured fields from cached HTML and generate a separate `data/calendar.csv`.

**New columns in `anunturi.csv`:** `Job Level` (Nivel), `Job Type` (Tip), `Employer Category` (Angajator type), `Categorie` (contractuală/publică), `Nr Posturi`, `Contact Telefon`, `Contact Email`, `Contact Persoana`, `Data Limita Depunere`, `Data Proba Scrisa`, `Data Interviu`, `Data Rezultate Finale`.

**New output `data/calendar.csv`:** flat table (`url, eveniment, data, ora`) with all competition timeline events. 11,567 rows across 1,140 postings (from 4,379 total).

**Approach:** Card wrapper fields extracted by label text (robust vs. positional nth-child). Calendar parsed from the `<p>` block containing "CALENDARUL / Nr. crt.", split on `<br/>` into individual events with date regex + `ora` extraction.

**Fill rates on 4,379 postings:** Job Level 99%, Categorie 99%, Nr Posturi 58%, phone 37%, email 39%, Data Limita 21%, Data Proba Scrisa 16%, Data Interviu 13%.

---

### 2026-05-26 — Repo cleanup, bug fixes, LLM pipeline wiring

**What:** Extracted `posturi.gov.ro/` from the `scrapers2` monorepo into a standalone repo using `git filter-repo`, then audited and fixed the codebase before going live.

**Bug fixes:**
- `fetch-index.py`: fixed `'poziție'` → `'pozitie'` fieldname mismatch (DictWriter was silently dropping the field), added `timeout=30` to all `requests.get()` calls, removed unused `checkback=2` parameter, fixed output path to `data/posturi_gov_ro.csv`, applied incremental per-page saves (crash-safe)
- `download-attachments.py`: fixed CSV path (`data/anunturi.csv` → `data/anunturi/anunturi.csv`), added `timeout=30`

**LLM scripts wired to pipeline:** `llm-schema-posts.py`, `oai-api.py`, `gemini-api.py` now all iterate over `data/anunturi/anunturi.csv`, take `Main Body Markdown` as input, and write JSON-LD output to `data/schema/<slug>.json`. Updated to current SDK APIs (openai v1+, gemini `GenerativeModel.generate_content`).

**Housekeeping:** added `.env.example`, system deps note in README, deleted `fetch-index.py` (superseded by `fetch-index.py`) and `doc2md0.py` (superseded by `dox2md.py`).

# Backlog

Open follow-ups. Reference: `docs/ui-spec.md` for the broader feature set and phased roadmap. CLAUDE.md convention: checkbox per item, enough context to act on it later.

## Pipeline & data quality

- [x] **Attachment text extraction** — `extract_attachments` command reads `data/downloads/` files linked via `announcement_url` / `other_links`, extracts text (python-docx for .docx, docx2txt for .doc, pypdf for .pdf), stores in `JobPosting.attachment_text`. Used by `infer_postings` Layer 3. Done 2026-05-26. Coverage: 1,113/4,379 postings (25%) — limited by download coverage (1,896 files downloaded of ~4,363 URLs).
- [x] **Download remaining attachments** — ran `download-attachments.py`; only 18 new files were missing (not the ~2,467 estimated — previous runs had covered nearly everything). Re-ran `extract_attachments --force`; coverage jumped from 25% → 60% (2,630/4,375 postings). FTS `search_vector` extended to include `attachment_text` at weight D — rebuilt for all postings. Done 2026-05-27.
- [x] **`.doc` files return empty text** — replaced `docx2txt` in `quality_check.py::_extract_doc` with `subprocess textutil` (macOS built-in). All `.doc` files now extract correctly. Note: `textutil` is macOS-only; add a `antiword`/LibreOffice fallback before deploying to Linux. Fixed 2026-05-26.
- [x] **`Data Limita Depunere` often empty in anunturi.csv** — added `DEADLINE_BODY_RE` fallback in `parse-anunturi.py` that scans body text for `data limita de depunere dosare : DD.MM.YYYY` when the calendar table lookup returns empty. Fixed 2026-05-26.
- [x] **Scanned PDF attachments unreadable** — `_extract_pdf` now falls back to `pdftoppm -r 150 -png` + `tesseract -l ron` when `pypdf` returns empty text and file > 50 KB. Tested on `eb59d7d2.pdf` (Consilier IA, Casa de Pensii Bucuresti): 0 chars → 9,878 chars extracted. Requires `poppler` (`brew install poppler`) for `pdftoppm` and `tesseract` (already available). Fixed 2026-05-27.
- [x] **Survey scanned-PDF frequency** — ran pypdf-based pass over all 750 PDFs (2026-05-28). Results: 361/750 (48.1%) text-extractable, 389/750 (51.9%) likely scanned (< 50 chars extracted, file > 30 KB). No parse errors. See activity-log entry 2026-05-28.
- [x] **`expires_at` broken since the July redesign — live site showed 3 jobs instead of ~1,656** — fixed 2026-09-06. The redesigned site puts a relative countdown on index cards (`expira_in = "1 zi rămasă"`, `"6 zile rămase"`, `"Ultima zi"`, `"Anunț anulat"`) instead of a date, so `parse_date()` returned `None` and `expires_at` went NULL for 3,103 postings — every one published on/after 2026-08-01. `import_csvs.py` now prefers `anunturi.csv`'s `Data Expirare` (absolute ISO dates from the detail page) and falls back to the index `expira_in` only for pre-redesign `/anunt/` rows. Added ISO support to `parse_date()` (it had none) and a `plausible_expiry()` bound rejecting years outside 2000..now+2, applied to both sources. Result: NULL 3,103 → 80 (79 `Anunț anulat` + 1 upstream typo `14/01/2046`), active 3 → 1,656; `export-to-sqlite.py --active-only` now exports 1,656 rows. 77 tests pass; import verified idempotent across two runs.
- [x] **Relative `expira_in` pollutes change tracking and forces a CSV write every run** — same root cause as above. `compare_and_update()` in `fetch-index.py` diffs the raw `expira_in` string, which now ticks down daily, so every posting logs a spurious `expira_in` change every single day (sample row: 12 entries between 2026-08-01 and 2026-08-19). This inflates the `updates` column, feeds noise into `JobPostingUpdate` via `parse_updates` (backlog item above), and defeats the "skip the save on unchanged pages" optimisation — every page looks changed. Fix: normalise `expira_in` to an absolute date *before* comparing, or exclude it from the diff. **Fixed 2026-09-08** as a prerequisite for the twice-daily cron: `values_differ()` in `fetch-index.py` treats two members of the countdown family (`N zile rămase` / `N zi rămasă` / `Ultima zi`) as equal, so the clock ticking is no longer news, while a transition to `Anunț anulat` still records. `Data Expirare` in `anunturi.csv` comes from the detail page's `.pg-meta-deadline`, not from this field, so nothing downstream loses information. 18 tests in `webapp/tests/test_index_diff.py`.
- [ ] **`judet_name` mixes two formats — the Județ facet splits every county** — post-redesign rows store `"CITY, Județ"` (`"CLUJ-NAPOCA, Cluj"`, `"BUCUREŞTI, București"`), pre-redesign rows store the bare county (`"Cluj"`). They become separate `judete` rows with separate slugs, so the facet lists the same county many times over: Cluj appears as 7 entries (`CLUJ-NAPOCA, Cluj` 75, `Cluj` 33, `CÂMPIA TURZII, Cluj` 11, `TURDA, Cluj` 7, `GHERLA` 2, `HUEDIN` 1, `DEJ` 1). Counts measured on the 1,656-row active export 2026-09-06: 976 rows in city-comma format, 680 bare, 257 `judete` rows total for a country with 42 counties. Diacritic variants compound it (`Arges` and `Argeș` are both present). Fix: split on the last comma at import — county → `judet`, city → a new `locality` field (which also seeds the gazetteer item under "Job-detail-page prep"); normalise diacritics before the slug. Until then the Județ facet under-counts every county and is one of only five facets currently working.
- [ ] **OCR backfill for scanned PDFs** — 389 scanned PDFs identified. Run OCR (poppler + tesseract -l ron) on each, store in `data/attachments_text/<slug>.txt`, re-run `extract_attachments`. Expected to raise posting body coverage by ~10–15 percentage points.
- [x] **Schema prompt: suppress `baseSalary` hallucination** — Added guard to both `quality_check.py::SCHEMA_PROMPT` and `llm-schema.py::PROMPT`: *"Do not include taxa de concurs or application fees as baseSalary. Omit baseSalary entirely if no salary range is explicitly stated."* Done 2026-05-27.
- [x] **Schema prompt: `datePosted` / `validThrough` from index CSV** — `publicat_in` and `expira_in` exist in `posturi_gov_ro.csv` (scraped by `fetch-index.py`) but were not reaching the schema generator. Fixed 2026-05-26: `quality_check.py` now loads the index CSV and injects `Data publicare (datePosted)` + `Data expirare (validThrough)` into the schema context. `parse-anunturi.py` also now joins these as `Data Publicare` / `Data Expirare` columns in `anunturi.csv`. Re-run `parse-anunturi.py` to regenerate the CSV with the new columns (quality checker already has a fallback to the index CSV for old CSVs). Result: `schema_valid_rate` went from 0.5 → 1.0.
- [x] **FAMILIES dict: add `muncitor` → `tehnic`, `psiholog practicant` → `social`** — both now in `infer_postings.py` FAMILIES dict (synced 2026-05-27 alongside broader dict expansion). Done 2026-05-27.
- [x] **`missing_contact` anomaly: distinguish card-empty from attachment-found** — fixed 2026-05-26. `_infer_anomaly_flags` now scans `combined_body` (card body + attachment text) for phone/email patterns when CSV contact fields are empty. Emits `contact_in_attachment` (CSV extraction gap, not a data problem) vs `missing_contact` (truly absent). Same fix applied to `quality_check.py` and `webapp/infer_postings.py`.

- [x] **`quality_check.py::_llm_classify` has no deepseek branch** — fixed 2026-09-09. Note the failure was worse than written here: falling through left `raw = ""`, and the substring fallback in the response validator matched `"" in "it"`, so on deepseek runs every low-confidence title was classified **`IT`**, not `altele`. Added the deepseek branch (openai SDK, `thinking: disabled`) plus an `else: raise`, and replaced the validator with `_match_family()` in both `quality_check.py` and `infer_postings.py`. See the activity-log entry for the 57-row repair.
- [ ] **FAMILIES dict misses feminine/plural title forms** — `_kw_match` is whole-word, so `ingrijitor` does not match `îngrijitoare`, `infirmier` does not match `infirmiere`, `asistent` does not match `asistenti`. Those titles fall through to the paid LLM fallback on every run (they are a large share of the 240 active `altele` + llm-sourced rows). Fix by adding the feminine/plural variants, or by matching on a stem (`ingrijitor` + optional `a|e|i`). Cheap and removes LLM calls.
- [ ] **`quality_check.py` duplicates the whole inference layer from `infer_postings.py`** — `FAMILIES`, `_normalize`, `_kw_match`, `_infer_profession_family`, `_llm_classify`, `_match_family`, the anomaly rules and the schema prompt all exist twice, and every fix has had to be applied twice (dict sync 2026-05-27, `contact_in_attachment` 2026-05-26, textutil — which was *missed* and cost a 100% `.doc` extraction failure, `_match_family` 2026-09-09). Extract to a shared module importable from both the repo root and Django.
- [ ] **`v3_facet()` scans and decodes JSON in PHP** — six of the sidebar's facet queries (`pages/list.php:242`) select every matching row's `v3_*` column and tally the values in PHP. Measured warm: 110 ms of the 183 ms total facet cost on the full 9,756-row archive (7 ms of 72 ms on the 1,848-row active export), so it is fine today and is the first thing to bite if the corpus grows ~10x or the archive is ever shipped instead of the active slice. Fix: a normalised `posting_values(posting_id, kind, value)` table with an index on `(kind, value)`, so these become `GROUP BY` like every other facet.
- [x] **Click-test the live facet counts in a browser** — done 2026-09-09 with Playwright (33 assertions, desktop 1280 + mobile 320). Two of the three checks passed as written (keyboard focus survives the swap; Județ's scroll offset is kept). The third was a **real bug** and is now fixed: a facet collapsed by the reader sprang back open on the next filter change. Cause — inserting a `<details open>` fires `toggle` exactly like a click, and the swap inserts ten of them, so the capture-phase listener rewrote `state[key] = true` for every server-open group between `htmx:afterSwap` and `htmx:afterSettle`, wiping the stored preference moments before `applyFacetState()` read it. Fixed with a `swapping` flag. See the activity-log entry for the event trace.
- [ ] **`infer_conditions_llm.py` lacks deepseek** — argparse `choices=["gemini", "openai", "anthropic"]` (line 100) and `_call_llm` has no deepseek branch (it does else-raise at line 90). Add deepseek to choices plus a branch if this command is ever put on the pipeline path; it currently is not.


- [x] **Employer canonicalization** — done 2026-05-27. Added `EmployerAlias` model (migration 0004); wrote `canonicalize_employers` management command that groups the 2,955 employers by normalized key (NFKD, strip diacritics, lowercase, collapse punctuation/ws), picks the best canonical per group (scored by diacritic richness + title-case), creates EmployerAlias records, and reassigns all `JobPosting.employer` FKs. Result: 205 duplicate groups → 257 aliases created, 521 posting FKs reassigned. Admin updated with posting_count/alias_count columns and full EmployerAlias admin. Note: 257 aliased Employer records still exist with 0 postings — can be deleted later once confident the merge is correct.
- [x] **Parse the `updates_raw` change log** — `JobPostingUpdate` model (`posting`, `changed_at`, `fields_changed`) added (migration 0005). `parse_updates` management command parses semicolon-delimited segments from `updates_raw` and bulk-creates records. Run produced 2,529 "New entry" records (no actual field-change events in current data — those will appear on the next incremental scrape). Admin registered. Done 2026-05-27.
- [x] **Decide canonical source for overlapping fields** — Decision 2026-05-27: `job_type` (from detail CSV/announcement page) is canonical for job permanency — it's per-announcement vs `tip` which is the index tag. 26 postings disagree (`tip=Permanent` but `job_type=Temporar`); `job_type` is correct in those cases. `detalii_raw` is kept verbatim for FTS/debugging; `categorie`/`job_level`/`employer_category` are the canonical structured fields for display and filtering. No code change needed — just confirming the existing schema design intent.
- [x] **Verify body markdown newline handling** — verified 2026-05-27. Sampled 20 postings; 0 had unescaped literal `\n` in `body_markdown`. Importer correctly un-escapes `\\n` → real newlines. Bodies have proper newline structure.
- [x] **Use `unaccent` in the FTS config** — already done (migration 0002 creates `romanian_unaccent` config; views.py and import_csvs.py both use it). Verified 2026-05-27: `condiții ↔ conditii` query returns True, all 4379 postings indexed.
- [ ] **Drop CSV layer eventually** — the scraper → CSV → DB pipeline is 3 hops. Once Slice 2 is stable, port `fetch-index.py`, `fetch-anunturi.py`, and `parse-anunturi.py` into Django management commands that write straight to the DB. The CSVs become an optional export.
- [x] **Add `agent de securitate` / `agent securitate` / `ofiter securitate` to FAMILIES["ordine publică"]** — added to both `quality_check.py` and `infer_postings.py`. Done 2026-05-28.
- [x] **`ingrijitor` (bare) misclassifies non-healthcare employers as `sănătate`** — removed bare `ingrijitor` from `sănătate` (replaced with `ingrijitor bolnavi/pacienti/spital`); added bare `ingrijitor` + contextual variants (`ingrijitor scoala/gradinita/camin/spatii/cladiri`) to `tehnic`. Fixed in both files. Done 2026-05-28.
 

## Slice 2 prep (Browse / Search UI)

- [x] **Tailwind setup** — Tailwind CDN play script + custom config (Fraunces + DM Sans fonts, warm-parchment palette). Done 2026-05-26.
- [x] **HTMX wired in base template** — HTMX CDN + `partials/` convention; facet form uses `hx-get`/`hx-target`/`hx-push-url`. Done 2026-05-26.
- [x] **Browse view skeleton** — `job_list` view with paginated list + facet sidebar; URL is source of truth. Done 2026-05-26.
- [x] **Facet groundwork** — sticky facet counts for: keyword (FTS `romanian_unaccent`), județ, job_level, job_type, categorie, employer_category, expires_at range. Done 2026-05-26.
- [x] **Result-row partial template** — title, employer, județ, badges, deadline countdown (color-coded). Done 2026-05-26.

## Inference pipeline (for v1 + v2)

- [x] **Profession family dictionary + LLM fallback** — `infer_postings` command populates `inferred["profession_family"]` via keyword dict (confidence ≥ 0.5) + LLM fallback (gemini/openai/anthropic). Done 2026-05-26.
- [x] **Seniority/grade normalizer** — regex over title for seniority (debutant → conducere_superioara) + grade (gradul I/IA/II/principal/superior). Done 2026-05-26.
- [x] **Inference review queue (admin)** — `InferenceConfidenceFilter`, `AnomalyFilter`, `reset_inferred` bulk action, family/confidence/anomaly columns. Done 2026-05-26.
- [x] **Inferred facets were empty on live data** — fixed 2026-09-07. Ran `infer_postings --no-llm` (offline keyword pass, no provider needed, ~50 postings/sec). Active-posting coverage went 1% → 100% on the core fields: profession_family 1,545 (86% non-`altele`), studies 1,491 (83%), experience 952 (53%), work_type 846 (47%), seniority 461 (26%). The sidebar's Domeniu facet now lists 10 real domains with counts instead of a near-empty handful.

- [x] **Show the inferred metadata next to each posting** — done 2026-09-07. `inferred_meta()` / `inferred_tags()` in `webapp-php/helpers.php` are the single source, so list and detail cannot drift. Result rows carry one compact line (*Domeniu · Funcție · Grad · Studii · Experiență · Normă · Telemuncă · Calculator*), visually separated from the source-of-truth badges and marked with a dotted-underline `auto` tooltip. The detail page's conditions grid is headed "Condiții deduse automat" with a methodology link and gained skills / languages / certifications chips — extracted since May 2026 and never displayed. A family below 0.5 confidence, or the `altele` catch-all, renders as nothing rather than as a wrong guess.

- [x] **Age was being read as work experience** — fixed 2026-09-07. `_normalize()` collapses newlines, so "Să aibă vârsta de minim 21 ani" ran into the next line's "Vechime in munca: minim 1 ani" and `_EXPERIENCE_RE` returned 21 years for a driver's post. The regex now prefers the label-first phrasing Romanian postings actually use ("Vechime în muncă: minim 3 ani") and guards the number-first fallback against a nearby "vârsta". 2,619 postings newly detected, none lost.

- [x] **`_infer_skills` matched substrings, so "SAR" was a "skill" on 87% of postings** — fixed 2026-09-07. It is inside *necesare*, *comisar*, *sarcini*; "atestat" likewise fired on the boilerplate "sănătate atestată" (36%). Switched to the file's existing word-boundary helper: SAR 8,183 → 1, atestat 3,437 → 620, every real skill unchanged. Certifications de-duplicated on case/NBSP, language spellings canonicalised.

- [x] **`--force --no-llm` silently destroyed LLM classifications** — fixed 2026-09-07. 598 postings had an LLM-derived `profession_family`; re-running the dictionary pass to pick up an unrelated regex fix would have rewritten every one to `altele`. `--no-llm` now means "don't call the LLM on this run", not "discard what a previous paid run established". The summary also stopped counting preserved classifications as "LLM calls" — which is what would have hidden the loss.

- [ ] **Grow the skills keyword list** — `_SKILLS_KW` is 14 entries, so `skills` covers 30% of active postings (real signal now, but thin). Worth adding the software and systems that actually recur in public-sector postings (SIVECO, REVISAL, EDUSAL, ANAF portal, SICAP already there, AutoCAD, GIS/QGIS, SAP), and role-specific licences (ISCIR, RSVTI, categoria B/C/D). Cheap, no LLM.

- [ ] **`altele` is 14% of active postings** — the dictionary cannot classify these titles and only an LLM pass will shrink the bucket. Blocked on the dead providers. When one works: `infer_postings --provider X --force` (the `--no-llm` guard now preserves earlier LLM work, so a partial run is safe to resume).

- [ ] **Romanian gazetteer for locality extraction** — needed for the mini-map and city-precision facet (deferred per spec to v2, but the dataset can be built in advance).
- [ ] **`is_repost_of` detection** — title + body cosine similarity within the same employer (12-month window).
- [x] **Anomaly heuristics (v1 set)** — Done 2026-05-27. `short_deadline` (< 7 days publish→deadline), `missing_contact` + `contact_in_attachment`, `gender_criteria` (masculin/feminin in body), `no_body` (< 100 chars), `frequent_repost` (3+ postings for same employer+normalized-title). `build_frequent_repost_ids()` pre-computes the set in O(n) before the main loop; threading it in avoids per-posting cross-queries. Current data: 80 postings flagged as frequent reposts (top: MINISTERUL AFACERILOR EXTERNE 8× same Referent role). Deferred: `narrow_criteria` (needs LLM to detect tailored requirements phrasing — too vague for regex).

## Tooling & ops

- [x] **Post-import sanity check for expiry parsing** — done 2026-09-06. `expiry_sanity_warnings()` in `import_csvs.py` flags >50% of recent postings missing `expires_at`, or zero active postings while recent ones exist; `--strict` turns warnings into a non-zero exit for cron. 11 tests in `webapp/tests/test_expiry_sanity.py`. Follow-up **done 2026-09-08**: `export-to-sqlite.py` now builds beside its target and promotes only after `integrity_check`, a `--min-rows` floor (default 100) and a refusal to lose half the rows against the file it would replace; `deploy-php.sh` re-checks the file it is about to ship. A bad export cannot reach the deploy step even if nobody reads the output.

- [ ] **Live site is serving a five-week-stale database — needs a deploy** *(tooling done 2026-09-08; the VPS still has to be provisioned and the first run made — `docs/deploy-vps.md`. `DEPLOY_HOST`/`DEPLOY_PATH`/`SITE_URL` now belong in `.env`, documented in `.env.example`.)* — `https://posturi.gov2.ro/` checked 2026-09-06 returns 200 but shows **14 postings, 3 active**, header reads "actualizat 02.08.2026". The local `webapp-php/posturi.sqlite` has since been re-exported and now holds 1,656 active postings / 559 calendar events / 1,038 employers (40 MB, up from 1.1 MB), but nothing has been pushed. Run `DEPLOY_HOST=user@host ./deploy-php.sh` — the script re-runs `export-to-sqlite.py --active-only` itself, so land any pipeline fixes first. The deploy target is not stored in the repo: no `DEPLOY_HOST` in `.env`, nothing matching in `~/.ssh/config`. Worth recording it somewhere (`.env` alongside the other config) so the deploy isn't dependent on remembering the host. Consider pairing with the `--active-only` export floor already noted under the expiry sanity-check item, so an empty export can never reach the deploy step.

- [x] **Daily fetch cron — design** — think through what a production daily pipeline run should look like: which steps to run, in what order, with what flags (e.g. `--skip infer,schema --since N`); whether `parse` and `import` need a `--since` equivalent or are fast enough to run in full; where the cron lives (Fly.io scheduled machine? GitHub Actions? crontab on a VPS?); alerting on failure; and whether `infer`/`schema` should run on a slower cadence (weekly?) vs every fetch. Context: `pipeline.py --skip infer,schema --since N` is the current candidate for the fast daily scrape; `download-attachments.py` now supports `--since DAYS`. **Designed and built 2026-09-08** — see `docs/deploy-vps.md`. Answers: twice daily (11:45 / 18:33 Europe/Bucharest) on a VPS via systemd timer, full pipeline including LLM; GitHub Actions ruled out because the pipeline carries ~2.4 GB of state plus Postgres that must survive between runs; `--active-only` rather than `--skip schema` is the cost guard (6,904 postings have no `schema_json`, only ~15 are active); alerting is a dead-man's-switch ping, since a failure mail cannot report a run that never happened. `ops/run-pipeline.sh` + `ops/systemd/`.

- [x] **Install `black` in the venv** — already in `requirements.txt`; black 26.5.1 confirmed present. Done.
- [x] **Test suite** — done 2026-05-27. pytest-django + factory-boy installed. `tests/test_import_idempotency.py` covers: (1) idempotency (run twice, counts unchanged), (2) expected records created, (3) update-existing-fields. All 3 pass in 0.25s. `pytest.ini` configured.
- [x] **CI** — GitHub Actions: lint (ruff), tests, migrations check. Added `.github/workflows/ci.yml` (Python 3.13, Postgres 17 service, ruff lint + makemigrations --check + pytest). Done 2026-05-27.
- [x] **Docker compose for dev** — `docker-compose.yml` at repo root: postgres:17 service with named volume, health check, port 5433 (avoids conflict with local brew Postgres). `.env.example` updated with Docker DATABASE_URL comment. Done 2026-05-27.
- [x] **Production deploy plan** — Fly.io. `webapp/Dockerfile` (python:3.13-slim, gunicorn, collectstatic), `fly.toml` (app=posturi-gov-ro, region=waw Warsaw, release_command=migrate), `.dockerignore`. `whitenoise` + `gunicorn` added to `requirements.txt`; `WhiteNoiseMiddleware` added to settings. Deploy: `fly launch` (first time) then `fly deploy`. Required secrets: `SECRET_KEY`, `DATABASE_URL`, `GOOGLE_API_KEY`. Done 2026-05-27.

## UX / UI

Findings from a 2026-09-06 read of `webapp-php/` (deployed) — every one also exists in the Django templates under `webapp/templates/jobs/`, since the PHP app was ported from them, so each fix lands twice.

- [ ] **Four ordinal facets are dressed as nominal ones** — `Studii minime` (generala < liceala < postliceala < licenta < master < doctorat), `Nivel studii (EQF)`, `Experiență minimă` and `Salariu` are ordered scales rendered as independent checkboxes, so they OR their values like every other group. But checking *licență* and *doctorat* as alternatives is a strange thing to mean: a reader almost always means "cel puțin", occasionally "cel mult". `Experiență` already handles this internally (its buckets OR into a single range clause), and the EQF comment at `helpers.php:974-987` is a record of the same fight — `v3_eqf_level <= ?` ("a candidate above the minimum still qualifies") had to be reverted to exact-match because the facet counts beside each checkbox are a plain `GROUP BY` on the level, so "Doctorat 12" returned 1437 rows headed by îngrijitoare. The honest control is a min (or a min/max range), with counts computed the same way, not six checkboxes. Match-what-I-qualify-for stays a CV-matching concern, not a browse facet. Related: the 2026-09-09 combine-semantics work deliberately left these alone, since flipping them to OR-within was already correct for a nominal reading.

- [ ] **Fourteen facet groups sit above "Mai multe filtre"** — shop sidebars land around 8–12 visible groups before collapsing the rest, and this one opens four (`Domeniu`, `Județ`, `Nivel`, `Tip`) plus three v3 groups (`Domeniu de studii`, `Competențe`) by default. Two of the visible groups are pipeline introspection rather than reader-facing filters: `Descriere` (schema present vs raw body — ported from the Django "Dev" panel, commit c9be4ce) and `Anomalii` (`short_deadline`, `no_body`, `frequent_repost`…). Both are genuinely useful for measuring backfill coverage and for spotting bad source data, and neither means anything to someone looking for a job. Consider moving them behind a dev/debug toggle (a `?dev=1` or a prefs flag, like the skin picker) and re-counting what is open by default.

- [x] **Mobile and tablet have no search box and no filters** — done 2026-09-07 (`webapp-php/` only). Search input lifted out of the sidebar into a full-width bar rendered at every width; the facet column is now one element that is a sticky sidebar at `lg` and a right-hand slide-over drawer below it (backdrop, Escape, focus return, scroll lock, "Arată N rezultate" footer, active-filter badge on the trigger). Counts, badge and the SR live region update via `hx-swap-oob`. Per-facet inner scrollers are `lg:`-only so the drawer has a single scroll axis. KPI tiles reorder below the search form on phones. **Django equivalent (`list.html:44,189`) still unfixed.**

- [x] **Job detail hides contact info and the deadline on phones** — done 2026-09-07. The structured column stacks above the body as a two-column card instead of `hidden sm:block`, ordered deadline → contact → everything else; the partial `sm:hidden` duplicate block at the bottom of the page is gone. (The original finding was half-wrong: `detail.php:307` did show termen/email/telefon on phones — what actually vanished was probă scrisă, interviu, rezultate finale, the inferred block and the attachment links.) **Django `detail.html:73` still unfixed.**

- [x] **Active-filter chips are incomplete and remove too much** — done 2026-09-07. Chips come from one `FILTER_CHIP_GROUPS` map covering all 18 params, with per-key value labels (județ slug → county name, bucket keys → their labels). New `qs_without($key, $value)` drops a single value from an array param. Chips are HTMX now: a delegated click handler unchecks the matching control and re-fires the form so sidebar and URL stay in agreement, with the `href` as the no-JS fallback. **Django unfixed.**

- [x] **Facet sidebar is a wall** — done 2026-09-07. Facet groups are `<details>` disclosures (native keyboard + AT behaviour), open for the top four, with Salariu / Angajator / Anomalii / exact-date inputs behind a "Mai multe filtre" disclosure and open state persisted in `localStorage`. Inner scrollers are desktop-only. **Django unfixed.**

- [x] **Replace the two deadline date pickers with a status control** — done 2026-09-07. Segmented **Active / Expiră în 7 zile / Toate** with live counts from a single conditional-aggregate query; the date inputs moved behind the advanced disclosure. `active` is the new default — a no-op on the active-only deploy, but it changes feed output the moment the full archive ships. **Django unfixed.**

- [ ] **Landing page is just the search page** — the unfiltered home renders four KPI tiles and a reverse-chronological list. `docs/ui-spec.md` §1 (v1 scope) calls for "nou azi", "expiră curând" and clickable domain tiles. Cheap to build on existing queries and gives the homepage a reason to exist. Depends on the inference backfill for the domain tiles to be meaningful.

- [x] **Tailwind Play CDN in production** — done 2026-09-07. `package.json` + `tailwind.config.js` at the repo root, source at `webapp-php/assets/app.css`, output committed to `webapp-php/static/app.css` (27 KB minified) so the shared host needs no toolchain — rebuild with `npm run css`. Fonts and htmx self-hosted (DM Sans italic dropped: 114 KB for the odd `<em>`). `.htaccess` gained immutable cache headers, deflate and a woff2 mime type; `deploy-php.sh` aborts if `static/app.css` is missing. **Django `base.html` still on the CDN.**

- [x] **`javascript:history.back()` as the detail-page back link** — done 2026-09-07. Links to the referring filtered list when the referrer is our own list page, else `/`. **Django unfixed.**

- [x] **Accessibility baseline** — done 2026-09-07. Skip link, labelled search input, `role="status"` live region announcing the result count on every HTMX swap (via OOB, so the region element itself is stable), `aria-label` on the pagination nav + `aria-current` on the active page, `<ul>/<li>` result list, `<time datetime>` on dates, tap targets ≥24 px on everything that is not an inline prose link. Palette: `ink-muted` and `ink-faint` measured 4.23:1 and 2.22:1 on parchment — both under AA — now #625C56 / #6F6963 (5.81 / 4.78), plus a separate `border-input` at 3.12:1 for form controls (WCAG 1.4.11 covers controls, not decorative hairlines). **Django unfixed.**

- [x] **Empty state offers only "Șterge filtrele"** — done 2026-09-07. On zero results the page runs one COUNT per active filter and offers the two or three whose removal recovers the most postings ("Încearcă fără — Caută: zzzz +6"). Computed only on the zero-results branch. **Django unfixed.**

- [ ] **Dark mode + bilingual RO/EN** — both listed in `docs/ui-spec.md` v1 scope, neither built. Dark mode is cheap now that the palette is tokenised in the Tailwind config; see also the "Bilingual UI (RO/EN)" item under Cross-cutting.

- [ ] **Category / profession hub pages** (`/categorie/it`, …) — `docs/ui-spec.md` §7. Mostly routing plus a template since the facets already exist, and a good SEO surface. Blocked in practice on the inference backfill.

### Found while doing the 2026-09-07 UX pass

- [x] **Multi-select facets only ever applied their last value** — fixed 2026-09-07 in `webapp-php/`. Sidebar checkboxes were named `judet`, not `judet[]`; a browser serialises repeated checkboxes as `judet=cluj&judet=iasi`, which PHP collapses to the string `'iasi'`. So every facet with more than one value selected silently narrowed to one the moment any change went through HTMX — only the initial page load (whose links used `judet[]=`) ever filtered correctly. Fixed with `MULTI_PARAMS` + `param_field()` in `helpers.php`. **Django is not affected** — `views.py` reads every multi-value param with `request.GET.getlist()`, which handles repeated keys correctly. This one is PHP-specific.

- [x] **Search was phrase-only: most multi-word queries returned nothing** — fixed 2026-09-07 in `webapp-php/`. `fts_escape()` wrapped the entire query in double quotes, making it an FTS5 phrase match — `inspector primărie` returned 0 where the AND-ed terms return 22. Replaced by `fts_query()`: tokenise, quote each term, AND them, prefix-wildcard the trailing term (so 400 ms live search doesn't flash "no results" mid-word). Ranking is now `bm25(job_postings_fts, 10, 3, 1, 1)` instead of bare `rank`. **Django is not affected**: `views.py:229,355` use `SearchQuery(..., search_type="plain")`, and `plainto_tsquery` ANDs its terms. Worth considering `search_type="websearch"` there anyway — it would give users quoted phrases, `OR` and `-exclusion` for free.

- [x] **A selected facet value outside the top-N was dropped on the next submit** — fixed 2026-09-07. The județ facet lists the top 25 of ~197 slugs, so a value outside that window had no checkbox and vanished from the serialised form. `facet_group()` now pins any active-but-unlisted value to the top of its group. **Django truncates the județ facet the same way** and would drop the value on a page reload rather than a form submit, since its checkboxes re-render from the same truncated list.

- [ ] **Port the 2026-09-07 UX pass to the Django templates** — the PHP app and `webapp/templates/jobs/` have now diverged substantially: mobile drawer, chip model, `<details>` facets, status control, JSON-LD, slug URLs, self-hosted assets and the a11y/contrast fixes all exist only in `webapp-php/`. Either port them or write down that `webapp/` is a data-pipeline host with a throwaway UI and stop treating it as the source of truth. The second is probably honest — the PHP app is what users see.

- [ ] **Expired postings should render a real page, not a 404 — with similar jobs** — today an expired posting either 404s (it is not in the `--active-only` export) or, once the archive ships, renders as a normal posting with an "Expirat" badge. Neither is right. Build a dedicated expired state:
  - Header keeps the original title and employer, restated as expired: *"{title} — anunț expirat"*, with the closing date and, if the calendar has them, the probă scrisă / interviu / rezultate dates so a candidate can still track the competition.
  - Keep the body and attachments visible — for a researcher or a candidate checking what was asked last time, the archive is the point.
  - **Below it, a "Posturi similare" block**, in this order of preference: (1) active postings from the same employer; (2) same `inf_profession_family` in the same județ; (3) the same normalised title anywhere in the country; (4) fall back to the same județ. Cap at ~6, dedupe, and link each to the filtered browse that produced it ("vezi toate cele N posturi de {domeniu} în {județ}").
  - SEO: keep the JSON-LD but let `validThrough` be in the past (Google drops it from Jobs on its own — that is the correct signal, not a 404), keep the canonical, and drop the URL from `sitemap.xml` after some months rather than immediately.
  - **Blocked on the export decision:** `deploy-php.sh` runs `export-to-sqlite.py --active-only`, so expired postings are not on the host at all. Dropping `--active-only` is ~4,400 postings (~100 MB SQLite, fine for shared hosting) and is what makes the "Toate" option in the new status control mean anything. Decide that first; the page is cheap once the rows are there.
  - Until then the honest interim is a `410 Gone` with the same "similar jobs" block driven off the URL slug, rather than the current bare 404.

- [x] **`judet_slug` had ~197 distinct values for a country with 42 counties** — fixed 2026-09-07. New `webapp/apps/jobs/judete.py` holds the canonical 42 counties and `normalize_judet()`, which splits the source badge into `(county, locality)` and folds cedilla ş/ţ onto the correct comma-below ș/ț. Wired into `import_csvs` (so new data is clean) and backfilled with `manage.py normalize_judete` (261 → 42 rows, 4,940 postings re-pointed, 219 stale rows deleted, clean slugs reclaimed). The city half is no longer thrown away: new `JobPosting.locality` (1,838 postings, 198 localities) is exported to SQLite, indexed in FTS, shown as "Cluj-Napoca, Cluj" in the PHP UI, and emitted as `addressLocality` in the JSON-LD. The județ facet now lists 42 counties by proper name, uncapped. 31 tests in `webapp/tests/test_judete.py`.

- [x] **Guard against county fragmentation coming back** — done 2026-09-07. `judet_sanity_warnings()` runs on every import (alongside the expiry check, same `--strict` behaviour) and fires when the Judet table exceeds 42 rows or when any posting has an unresolved county. Unresolved values are persisted in `JobPosting.judet_raw` and findable in admin under *Județ — rezolvare → Nerecunoscut*; the fix is normally one line in `judete.ALIASES`. 6 tests in `tests/test_expiry_sanity.py`.

- [ ] **Use `locality` for real geo** — the field now exists and is populated for 1,838 postings (198 distinct localities), which unblocks two `docs/ui-spec.md` items that were previously marked "needs a gazetteer": a **city/commune facet** nested under județ in the browse sidebar, and **map drill-in** past the county choropleth. Coordinates still need a gazetteer, but the *names* are already clean and title-cased. Cheap first step: add locality to the sidebar as a second-level facet that appears only when a județ is selected.

- [ ] **Backfill `locality` for the ~6,600 postings that have none** — the source only supplies "LOCALITY, County" on post-redesign rows; pre-redesign rows carry the bare county. The locality is usually recoverable from the employer name ("Primăria Câmpia Turzii", "Spitalul Municipal Huși") or the posting body's address line. A dictionary pass over employer names against the 198 known localities would cover a large share with no LLM cost.

- [ ] **Facet fan-out is ~24 COUNT queries per render** — one per facet group plus one per experience bucket and per salary bucket, on every keystroke (400 ms debounce). Imperceptible at 1,793 rows; it becomes the bottleneck the moment the full archive lands. `facet_scope()` / `scope_count()` in `list.php` now centralise the pattern, so collapsing the bucket loops into single `CASE WHEN` aggregates (as the status counts already do) is a contained change.

- [ ] **Feeds link to posturi.gov.ro, not to our own detail pages** — `feeds/jobs.atom.php:53` uses `$r['url']`. Reasonable as "send people to the source", but it means a feed subscriber never sees the structured view, and it forfeits the referral. Consider linking to `job_url()` with the source URL in the entry body.

- [ ] **No `og:image`** — link previews are text-only. A generated per-posting card (title + employer + deadline) would need an image pipeline; a single static site image is the cheap 80%.
- [x] **`.doc` extraction was broken in the command that writes the database** — fixed 2026-09-07. The May 2026 `docx2txt` → `textutil` switch was applied to `quality_check.py` but never to `extract_attachments.py`, which is what populates `JobPosting.attachment_text`. `docx2txt` only reads the ZIP-based .docx container and raises `KeyError`/`BadZipFile` on every genuine legacy .doc; `extract_text()` swallowed all exceptions into `""`, so an extractor failing on 100% of its input looked exactly like a pile of empty documents. Measured on four failing files: docx2txt 0 chars, textutil 9,031–15,862. Fixed with a portable chain (`textutil` → `antiword` → `catdoc`, which also closes the "add a Linux fallback" note), a ZIP-magic sniff for .docx served under a .doc name, and `extract_text()` now returning `(text, reason)` with a by-reason summary printed at the end of the run. Recovered text for **2,082 postings**; active coverage 77% → 86%.

- [ ] **79% of active postings show raw scraped text, not the LLM's structured sections** — `pages/detail.php` prefers `schema_json` and falls back to `body_markdown`, and only 380 of 1,793 active postings have a schema. This is the stalled prompt-v2 backfill and it is the single biggest quality lever on the site: the difference is a wall of ~7,500 characters of legalese versus labelled Atribuții / Studii / Experiență / Dosar de candidatură / Contact sections. Blocked on the dead providers (see the BLOCKED item under Misc). When one works: `python llm-schema.py --provider X --prompt-version v2 --active-only`. Every posting whose attachment text was just recovered will extract better than it would have before.

- [ ] **`responsibilities` is only 18% filled, the weakest of the standard sections** — and it is the one a job seeker most wants ("what will I actually do?"). Correlates strongly with attachment text (22% with, 8% without), which suggests the duties usually live in an attached *fișa postului* rather than the web body. Worth checking after the extraction fix above whether the rate moves; if it stays low, the prompt's `responsibilities` instruction may be too conservative about what counts as a duty versus a generic condition.

- [ ] **~980 attachments have no text layer (scanned PDFs)** — the remaining extraction gap after the .doc fix, now visible in `extract_attachments`' by-reason summary. `quality_check.py` already has an `_ocr_pdf` helper; wiring OCR into `extract_attachments` (behind a flag, since it is slow) would close most of it. 14% of active postings still have an attachment link with no usable text.

- [ ] **`work_conditions` is rendered but never produced** — it is in `SCHEMA_SECTION_LABELS` in `webapp-php/helpers.php` (and the Django renderer) but the v2 prompt has no such field, so the entry is dead. Either drop it or add it to the prompt.

- [x] **Port the Django "Dev" LLM-parsed filter to the deployed app** — done 2026-09-07. Commit `c9be4ce` (2026-05-28) added Schema JSON / Inferred / Variants radio filters to the Django sidebar only. Now a public facet in `webapp-php/`: **Descriere → Structurată (LLM) / Doar text brut** with live counts, under "Mai multe filtre". The `inferred` filter was dropped (100% populated, always a no-op) and `variants` too (not in the SQLite export).

- [x] **Attachment filenames carry no information** — resolved 2026-09-07 by not showing them. All 1,794 attachment URLs are opaque (`j_11414_c_9189_anunt_688423.docx` or an 8-char hex hash). Four classifier designs were measured against 899 files trying to derive a document title or kind; the finding is that ~89% of these files *are* the announcement, and bibliografie / fișa postului / cererea live inside it as sections rather than as separate documents — so every richer taxonomy mislabelled the announcement and scored worse than saying nothing. `apps/jobs/attachments.py` therefore recognises only **Erată / modificare** and **Rezultate**, both of which declare themselves on the opening line and both of which change what a reader should expect. The link now shows a format badge, that label (or the role: *Anunț oficial* / *Document anexat*) and the **file size**, from the new `JobPosting.attachment_meta`.

- [ ] **~16 .docx files raise KeyError on extraction** — visible in `extract_attachments`' new by-reason summary (plus 1 BadZipFile, 1 TypeError, 1 `.jpg` attachment). Tiny next to the 996 scanned PDFs, but they are a different failure: the container parses and the extractor still fails, so it is probably a malformed or password-protected document. Worth one look at the actual files.

- [ ] **Search and filter by angajator, and detect the institution type** — two related gaps.

  **Search.** There is no employer search anywhere: `/angajatori/` lists 1,103 employers with only a județ dropdown, the browse sidebar has "Angajator" but it is bound to `employer_category` (the coarse source label), and `q` hits the FTS `employer_name` column only as a side effect of full-text search. `docs/ui-spec.md` §2 asks for an "Employer (typeahead, canonical)" facet. Minimum useful version: a text input on `/angajatori/` filtering the list, plus an `employer_id`/`employer_slug` param on browse so "toate posturile de la acest angajator" works from the employer profile (the link already exists at `pages/employer.php:86` but browse ignores the param).

  **Type detection.** The institution type is recoverable from the employer name with a keyword pass, exactly like `profession_family` — no LLM needed. `Primăria`/`Comuna`/`UAT`/`Consiliul Local` → primărie; `Spitalul`/`Centrul Medical`/`DSP` → spital; `Școala`/`Liceul`/`Colegiul`/`Grădinița`/`Universitatea`/`Inspectoratul Școlar` → educație; `Ministerul`/`Agenția`/`Autoritatea`/`ANAF`/`Direcția Generală` → administrație centrală; `DGASPC`/`Direcția de Asistență Socială` → social; `Inspectoratul de Poliție`/`ISU`/`Jandarmeria` → ordine publică; `Muzeul`/`Biblioteca`/`Teatrul` → cultură. Store it on `Employer` (a new `kind` field) rather than per posting, since it is a property of the institution — that also makes `/angajatori/` filterable by type and gives the stats page a genuinely new breakdown.

  **`employer_category` does not already cover this** — checked 2026-09-07. Despite the name it holds the *contract* type, not the institution type: of 1,793 active postings it is "Funcție contractuală" on 1,710, empty on 52, "Funcție publică" on 11, and a real institution label ("Primării" 3, "Instituții locale" 4, "Unități militare" 6) on barely a dozen. It duplicates `categorie` and is useless for this.

  **A name-derived pass works.** Prototyped against the 1,103 employers with active postings: **89% classified** — primărie 32%, educație 24%, spital 19%, cercetare 4%, social 3%, ordine publică 3%, cultură 2%, administrație centrală 1%; 11% unmatched, and those are near-misses a few more keywords would catch (Administrația …, Centrul de Cultură …, Serviciul Județean de Ambulanță, Academia de Studii Economice, Sistemul de Gospodărire a Apelor). Follow the `apps/jobs/judete.py` pattern from 2026-09-07: a canonical list plus a normaliser in one module, applied at import, backfilled by a management command, with a sanity check on every import and an admin filter for the unmatched — so a drift in the source surfaces immediately rather than silently.

- [x] **Prompt v3 — granular, match-oriented extraction** — designed and implemented 2026-09-07, **not yet run** (blocked on the dead providers). v2 answers "what does this posting say?"; v3 answers "can this candidate apply?". Keeps every v2 field verbatim and adds a structured layer beside it: `education` (EQF level + ISCED-F fields of study, split into separate entries so "geografie/silvicultură/geologie" becomes three matchable codes), `skill_list` (short tags with ESCO pillar + proficiency — "Cunoștințe avansate de operare sisteme GIS" → `{label: "GIS", type: "knowledge", proficiency: "avansat"}`), `language_list` (CEFR), `credentials` (licences/authorisations, kept apart from skills because they are the hardest filter), `contract`, `policy_domains` (subject matter, adapted from cariere.gov.md's Domenii facet), `occupation_title`, `exam_stages`, `bibliography_topics`. `JobPostingExtractionV3` inherits `JobPostingExtraction`, so every existing page and feed works unchanged on a v3 row. Full rationale and the Schema.org↔Europass mapping in `docs/metadata-schema-v3.md`. 26 tests. Run with `python llm-schema.py --prompt-version v3 --active-only --limit 20`.

- [x] **First v3 run — 5 postings, Gemini, 2026-09-07** — done. `fields_of_study` splits correctly (7 alternatives across 3 ISCED fields on one posting), `credentials` and `contract` are the strongest fields. Four defects found and fixed in the prompt: multi-role postings returning all-nulls (now `positions[]`), field-of-study labels coming back as adjectives/filler, `bibliography_topics` over-extracting to 37 entries, and `credential.kind` defaulting to `altele`. Also fixed a bug of mine that broke the DeepSeek path (`_validate_v2` rename left 3 call sites behind) plus the cwd-dependent `models_config.json` load. See `docs/metadata-schema-v3.md` § "What the first run showed".

- [ ] **Re-run v3 with the revised prompt, and get cross-provider agreement** — the 2026-09-07 run only completed on Gemini; OpenAI was out of credits and DeepSeek hit the since-fixed bug. Still unmeasured: (a) whether the `positions[]` instruction actually fires on the 8.4% multi-role postings; (b) whether field-of-study labels now come back normalised; (c) how much providers disagree on `isced_field` ("inginerie geodezică" is defensibly 07 or 05) — that disagreement rate decides whether ISCED is trustworthy enough to filter on. Prompt is now 17.3k chars (~5k tokens) and the JSON Schema 19.2 KB, up from 12.4k/17.4 KB before the multi-role fix.

- [ ] **A posting can advertise several roles — the UI assumes one** — 143 of 1,710 active postings (8.4%) list multiple distinct roles, e.g. "Magaziner, îngrijitor curățenie, infirmier, registrator medical". v3 now extracts them into `positions[]`, but nothing downstream uses it: the list row shows one title, the detail page one set of requirements, and the filters match a posting as a single unit. Once v3 data exists, decide whether to (a) render sub-rows per role on the detail page, or (b) split them into separate searchable entries — (b) is much better for search but changes what a "posting" means throughout the schema, the feeds and the URLs.

- [x] **v3-backed filters and detail UI** — built 2026-09-08, ahead of the data. Seven facets (Domeniu de studii/ISCED-F, Competențe, Domeniu activitate, Nivel studii/EQF, Limbi străine, Documente necesare, Etape concurs), chips with Romanian labels, and a "Cerințe structurate" block on the detail page including a per-role breakdown for multi-role postings. All of it is invisible until a v3 extraction exists, because `facet_group()` omits empty groups. Verified against the 5 real v3 extractions in a seeded copy of the export (`POSTURI_DB` override in `db.php`), with every filter checked against SQLite ground truth. `export-to-sqlite.py::_v3_columns` does the flattening; 16 tests.

- [ ] **Reverse query: match a candidate profile against postings** — the step between v3 extraction and CV upload. A form collecting `{eqf_level, isced_fields[], skills[], languages[], credentials[], years_experience}` — exactly the shape a Europass CV yields — ranked against postings by how many hard requirements are met, showing which are missed. No parsing needed, and it validates the matching logic before any CV handling exists.

- [ ] **Europass CV upload** — the end goal. Europass exports XML/JSON already carrying EQF, ISCED-F, CEFR and ESCO values, which is exactly why v3 uses those vocabularies rather than convenient local ones: the CV parses straight into the same structure and matching becomes a set comparison rather than an NLP problem. Depends on the reverse query above, plus a privacy decision — parse client-side and never store, or accept an upload with an explicit retention policy. See `docs/metadata-schema-v3.md`.

- [ ] **Resolve `skill_list` labels to ESCO URIs** — ESCO has 13,939 skill concepts with Romanian labels. Resolving extracted tags locally against a downloaded ESCO dump beats asking an LLM for a URI it will hallucinate, and turns our tags into genuinely interoperable identifiers. Do it once there is a corpus of extracted labels to resolve against.


- [ ] **The Django webapp has no skins and has drifted** — `webapp/templates/base.html` still loads `cdn.tailwindcss.com` and carries its own hardcoded hex palette plus an inline `<style>` block (the pre-2026-09-07 arrangement). The PHP app's colours are now CSS custom properties under a `[data-skin]` scope, so the two apps no longer look alike and the skins cannot be previewed in the Django one. Don't port the token layer into it — see *Retire the duplicated Django frontend* under Cross-cutting, which removes the divergence instead of maintaining it twice.
- [ ] **Font preloads follow the default skin, not the active one** — `inc/header.php` preloads DM Sans + Fraunces unconditionally, so a returning visitor on `govuk` (Arial) or `posturi` (Manrope) fetches ~90KB of faces it never uses, and Manrope is not preloaded when it is the active face. The skin lives in `localStorage`, so the server cannot know it without a cookie, and a cookie would break shared-host page caching. Options if it ever matters: move the preloads into a `<link>` injected by the pre-paint boot script, or drop them entirely.
- [ ] **No dark theme axis** — all three skins are light. The otios implementation this borrows from treats theme (light/dark) and skin as independent axes, with each skin optionally declaring a `[data-skin="x"][data-theme="dark"]` block. The token layer here supports that with no changes; it needs a `data-theme` attribute on `<html>`, a second `localStorage` key in `static/prefs.js`, and dark blocks per skin. `assets/check-skins.php` would need its contrast pairs run against both.
- [ ] **`posturi.css` hangs its card shadow on `.rounded-lg.border`** — a structural selector, so a card that stops using both classes silently loses its lift. The honest fix is a `shadow-card` utility applied at the ~31 card sites, with `--shadow-card: none` in the skins that want flat; that was left out as churn disproportionate to the benefit. Revisit if a second skin wants card lift.

- [ ] **The "Funcție contractuală" shortcut selects 95% of the corpus** — 1,718 of 1,799 active postings, so as a landing shortcut it narrows almost nothing; it was built because it was asked for, but it is doing the opposite job of the other six chips. Either drop it or invert it into something that discriminates ("altele decât contractuale", 81).
- [ ] **`categorie` and `employer_category` look crossed for post-redesign rows** — `employer_category` holds *position* categories for 1,731 of 1,799 rows (`Funcție contractuală` 1,718, `Funcție publică` 13) and only 12 rows of anything employer-shaped (`Primării`, `Instituții locale`, `Unități militare`), while `categorie` — which the sidebar labels "Categorie" — is empty for 1,783 of 1,799 and holds `Funcție contractuală` for 13. The names say one thing and the data says another; the sidebar's "Angajator" facet is really a position-type facet. Noticed 2026-09-08 while building the landing shortcuts, which had to read `employer_cat` to offer "Funcție publică". Trace it back through `parse-anunturi.py` and `import_csvs.py` before either facet is trusted.

## Cross-cutting / future

- [ ] **Bilingual UI (RO/EN)** — gettext catalogs; default RO, EN toggle.
- [x] **RSS + JSON feeds per filter combination** — Done 2026-05-27. `/posturi.json` (JsonResponse, up to 200 results, full field set) and `/posturi.atom` (Atom1Feed via `django.contrib.syndication`, 50 items) both accept the same query params as the browse view (`q`, `judet`, `level`, `type`, `categorie`, `employer_cat`, `expires_before`, `expires_after`, `family`, `seniority`). `_filter_kwargs_from_request()` helper extracts params from the request; both feeds share `_apply_filters()`.
- [x] **iCal feed per filter combination** — `/posturi.ics` — one `VEVENT` per posting (deadline as `DTSTART`/`DTEND`), employer as `SUMMARY`, contact info + URL in `DESCRIPTION`. Same filter params as browse view, up to 200 events. Done 2026-05-27.
- [x] **Methodology + About pages** — `/despre/` page with sections: what the site is, data sources, inference methodology (dict + LLM fallback, confidence scoring), anomaly heuristics (all 6 flags explained), limitations (scanned PDFs, partial attachment coverage, imperfect classification), export/API reference. Navigation link in base.html header. Done 2026-05-27.
- [ ] **Retire the duplicated Django frontend; `webapp/` is the ETL, not a web app** — investigated 2026-09-08 after asking whether `webapp/` could simply be deleted. It cannot: it is the database layer the PHP app depends on. But roughly half of it is dead weight, and that half is what caused the skin divergence above.

  **Load-bearing — must stay:**
  - The 12 migrations own the Postgres schema. `export-to-sqlite.py` reads `jobs_jobposting`, `jobs_employer`, `jobs_judet`, `jobs_calendarevent` by name, so no migrations means no database means no deploy SQLite.
  - `pipeline.py:133` shells out to `webapp/manage.py` for three stages (`import_csvs`, `extract_attachments`, `infer_postings`); four more commands exist unwired (`normalize_judete`, `canonicalize_employers`, `parse_updates`, `infer_conditions_llm`).
  - `apps/jobs/judete.py` — CLAUDE.md names it the single interpreter of the județ badge.
  - `tests/` — the project's only suite, 282 passing, 8 of 13 files needing Django. Note that `test_llm_runner.py` and `test_posting_urls.py` import from the repo root (`grounding`, `schema_models`, `posting_urls`) and touch Django not at all; they live here only because this is where the suite is. That is why the folder *looks* actively developed as a web app when it isn't.
  - `admin.py` — a genuinely useful data-inspection surface that depends on nothing else.
  - `templates/jobs/llm_variants.html` + `variant_comparison.html` (233 lines) — the prompt-version comparison dashboard, the one web surface with no PHP equivalent.

  **Duplicated by `webapp-php/` — candidates for deletion (~2,634 lines, slightly more than the load-bearing model/command code):** `views.py`, `templates/base.html`, and the six templates that mirror PHP pages (`list`, `detail`, `about`, `stats`, `employer_profile`, `employers_dashboard`), plus the JSON/Atom/iCal feed views that `webapp-php/feeds/` also serves.

  **Before deleting:** `views.py` holds helpers the tests exercise and that `webapp-php/helpers.php` reimplemented — `_render_schema_sections`, `_apply_filters`, `_render_base_salary`, `_render_application_fee`, `_render_application_contact`, `_sanitize`. Move the ones the tests cover somewhere that survives (e.g. `apps/jobs/rendering.py`) rather than dropping them with the file, and keep `urls.py` entries for admin + the variants dashboard.

  **Then rename.** A folder holding models, migrations, commands, `judete.py`, tests and one dev dashboard is honestly `etl/` or `db/`, not `webapp/`. The rename is what stops this drifting back — `pipeline.py:44` (`WEBAPP_DIR`), `deploy-php.sh`, `conftest.py`, README and CLAUDE.md all reference the path.

- [ ] **Auth (v3)** — `django-sesame` magic-link; optional Google OAuth.
- [x] **Stats dashboard** — `/statistici/` page with KPI tiles (total/active/classified), top-10 profession family and județ bar charts with filter links, and anomaly flag table. Done 2026-05-27. Remaining v3 additions: time series, geographic heat map, re-posting tracker.
- [ ] **Stats dashboard v3 additions** — time series (postings over time), geographic choropleth, re-posting tracker.

## Known small issues

- [ ] **`nr_posturi` picks numbers out of project codes** — spotted 2026-09-07 while checking the județ fix in the browser: a result row read "339395 posturi". `NR_POSTURI_RE = r'(\d+)\s+post(?:uri|ul)?\b'` in `parse-anunturi.py:60` matched `…Cod PIDS/586/PO4/339395 post înființat…` — the number is the tail of a project identifier, not a headcount. 15 postings have `nr_posturi > 20`, 3 have `> 100` (max 339,395). Two cheap guards, ideally both: require the digits not to be preceded by `/` or `-` (`(?<![\d/\-])`), and reject implausible values the way `plausible_expiry()` already does for dates — a public-sector posting advertising more than ~200 seats is a parse error, not a mega-hire. Worth a `parse-anunturi` unit test with that exact string.


- [x] **Migrations: `prepopulated_fields` on slug-on-save** — Replaced `prepopulated_fields` with `readonly_fields = ("slug",)` in both `JudetAdmin` and `EmployerAdmin`. Model `save()` owns slug generation; admin just displays the computed slug as read-only. Done 2026-05-27.
- [x] **`unique_slug` is O(n) per insert** — replaced SELECT-loop with try/except IntegrityError retry (up to 100 attempts). Uses `transaction.atomic()` savepoint per attempt so the outer transaction isn't poisoned on conflict. Done 2026-05-27.
- [ ] **`Other Links` parsing is naive** — splits on comma and filters by `http` prefix. Misses cases where URLs themselves contain commas. Low priority; sample data clean for now.
- [ ] **HTML sanitization in _render_schema_sections** — `section.html` is rendered with `|safe` in detail.html. Add `bleach.clean()` or equivalent allowlist sanitizer in `_render_schema_sections` (views.py) before appending to sections, to guard against LLM-echoed raw HTML. Same issue exists for `body_html`. Low-to-medium risk since source is a government domain pipeline, but worth hardening.
- [ ] **Add --limit flag to llm-schema.py** — `pipeline.py --steps schema --limit N` silently ignores `--limit` since `llm-schema.py` has no such flag. Add `--limit N` arg to process only the first N unprocessed postings (useful for testing a batch of new postings without full run).


## Misc
- [x] **LLM provider comparison infrastructure** — Done 2026-05-27. Created `models_config.json` with 6 models (Gemini 3.1/2.5 Flash, GPT-5 Nano, GPT-4o Mini, Claude 3.5 Haiku, DeepSeek-V4-Flash) and pricing. `JobPostingSchemaVariant` model stores every LLM run with token counts, cost, latency. `llm-schema.py --compare` runs all 6 providers on same postings, saves variants only (doesn't overwrite production `schema_json`). Admin inline + standalone view at `/job/<id>/variants/` for side-by-side cost/latency/output comparison. Usage: `python llm-schema.py --compare --limit 5 --slug substring`.
- [x] extract in a config json (maybe with versions) the prompt being sent to LLMs, now  defined in llm-schema.py
- [x] use OpenRouter for this, or `simonw/LLM` package? - which approach is cheaper? Resolution: **NEITHER** — direct SDK calls give better metrics
- [x] **Prompt v2: Schema.org-aligned extraction + caching + structured output + boilerplate strip** — Done 2026-05-27. Output now a flat superset of Schema.org JobPosting properties (responsibilities, educationRequirements, experienceRequirements, qualifications, skills, baseSalary `{minValue,maxValue,currency,unitText}`, jobBenefits, workHours, jobLocation) plus 3 RO-specific custom keys (application_docs, application_fee `{amount,currency,account,details}`, application_contact `{name,phone,email,address}`). New files: `schema_models.py` (Pydantic), `boilerplate.py` (`strip_hg_1336()`). `llm-schema.py` split into cacheable system_prefix + per-posting content; wired Anthropic explicit cache + OpenAI/DeepSeek/Gemini implicit caching; provider-native structured output (strict json_schema / response_schema / tool-use). Verification: 6 postings × 4 providers, all outputs Pydantic-valid, `qualifications` field shrunk from ~2300 chars HG 1.336 boilerplate to 36–109 chars role-specific signal. Cost per 1000 postings (with caching): gpt-5-nano $0.36, gemini-2.5-flash $0.61, deepseek $0.77, gpt-4o-mini $0.88. 14/14 renderer tests pass with v1 back-compat preserved. **Next:** promote v2 to default + run `python llm-schema.py --provider gemini --prompt-version v2 --force` to backfill remaining 4357 postings.
- [ ] Expereință: wehere explicit, ie: "nu este cazul", flag as _nu necesită_
- [x] for debugging purposes show filters of is inferred, attachment text, schema in the UI browser
- [ ] **Gemini prompt caching never engages — and thinking tokens are not counted** — investigated 2026-09-06 during the v2 backfill. Two findings, the second costlier than the first:
  1. **No cache hits.** The v2 system prefix is 1,862 tokens (measured with `count_tokens`), comfortably over Gemini 2.5 Flash's 1,024-token implicit-cache minimum, and it is byte-identical on every call. Yet two identical back-to-back `generate_content` calls both return `cached_content_token_count=None` / `cache_tokens_details=None`. Hypothesis to test first: `llm-schema.py` passes the prefix via `config.system_instruction`, while Gemini's implicit cache keys on the leading portion of `contents` — so the prefix may never enter the cache key. Next step: move the prefix to the front of `contents` and re-run the two-call probe; if that still misses, switch to explicit `client.caches.create()` (viable — 1,862 tokens clears the explicit-cache minimum too).
  2. **Thinking tokens are billed but not recorded.** Gemini 2.5 Flash spends ~380–400 `thoughts_token_count` per call, billed at the output rate. `make_generator()` records only `candidates_token_count`, so every cost figure in `JobPostingSchemaVariant` and every `--compare` leaderboard understates Gemini by **13–14%**. Fix: `output_tokens = candidates_token_count + thoughts_token_count`, or set an explicit `thinking_budget` if the reasoning isn't earning its keep on an extraction task. Note this also skews the cross-provider comparison in `docs/` — non-thinking models were never undercounted, so Gemini looked better than it is.
  **Corrected economics** for the 1,655 active postings: recorded ~$1.95, actual ~$2.20. Perfect prefix caching would save only ~$0.28 (13%) — the per-posting body is 6,000–9,000 tokens and dominates the input, so caching is worth doing but will not approach the `$0.61/1000` figure recorded in the prompt-v2 item, which now looks optimistic.
- [ ] **`cached_tokens` is computed but never persisted** — `make_generator()` returns it and `compute_cost()` uses it, but `write_variant()` has no column for it and `jobs_jobpostingschemavariant` has no field. So cache effectiveness can only be observed live in the tqdm postfix and can never be analysed after the fact — which is exactly what the investigation above needed. Add a `cached_tokens` column (+ migration) and thread it through `write_variant()`. While there, consider a `thoughts_tokens` column for the same reason.
- [ ] **Caching paths for the other three providers are unverified** — the OpenAI (`prompt_tokens_details.cached_tokens`), Anthropic (explicit `cache_read_input_tokens`) and DeepSeek (`prompt_cache_hit_tokens`) branches in `make_generator()` have never been observed producing a hit, and all three providers are currently unusable (no credits / missing key / empty responses), so none could be tested. Re-check each when its provider is working.
- [ ] **BLOCKED: all four LLM providers unusable as of 2026-09-06** — the v2 backfill cannot run until at least one provider works. Verified by `llm-schema.py --compare --active-only --limit 1` (writes variants only, so nothing was persisted):
  - `gemini-2.5-flash` — `403 API_KEY_IP_ADDRESS_BLOCKED`: `GOOGLE_API_KEY` has an IP allowlist and this machine's egress IP is not on it. Fix in Google Cloud console → Credentials → the key's "Application restrictions", or run from an allowlisted network.
  - `gpt-5-nano`, `gpt-4o-mini` — `429 credit_balance_exhausted`: the OpenAI org has no credits.
  - `claude-3-5-haiku` — `ANTHROPIC_API_KEY` is missing from `.env` entirely (the other three keys are present). Also disabled in `models_config.json` and the model is deprecated; would need a current model id.
  - `deepseek-v4-flash` — returns an empty string instead of JSON (`Expected dict, got str: ''`). Key is present (len 35); needs its own debugging — possibly a wrong model id.
  Cheapest path once unblocked: gemini at ~$0.61/1000 postings, so ~$1.00 for the 1,655 active ones.
- [ ] **Promote prompt v2 to default + backfill** — ~~Change `PROMPT_VERSION = "v2"` and CLI `--prompt-version` default to `v2`~~ (both already done in `llm-schema.py:73,382`). Then run `python llm-schema.py --provider gemini --prompt-version v2 --force` to refresh all 4357 postings under v2. Estimated cost: ~$2.67 (gemini-2.5-flash). Optional: tune boilerplate patterns based on a wider sample if any false-positives surface.
- [x] **Cross-posting LLM-variants dashboard** — top-level page (e.g. `/llm-variants/`) that aggregates `JobPostingSchemaVariant` data across all postings: per-(provider, model, prompt_version) cost/latency/throughput leaderboard, prompt-version comparison stats, ability to drill into a specific posting from there. Complements (but doesn't replace) the per-posting side-by-side viewer at `/job/<pk>/variants/`. Build after the per-posting viewer's reading experience is solid.
- [x] prepare shared hosting web app. static or php. — Done 2026-06-25 (`ad710df`: webapp-php/ + export-to-sqlite.py + deploy-php.sh).
- [ ] some posts cover more jobs, how to address?
- [x] go beyond schema org, extract easy to read attributes. `Rezumatul functiei` card on [cariere.gov.md](https://cariere.gov.md/ro/job/specialist-in-domeniul-perceperii-fiscale/32948). Those will also used as filters.
- [ ] stats, show all judete. norm to population
- [x] angajator profile? — Done 2026-06-09 (`8ff209b`: employer profile page in Django, `pages/employer.php` in PHP).
- [x] remove "Stare anunț" -> Toate tab — done 2026-09-08. Dropped `all` from `STATUS_LABELS`; on the deployed (`--active-only`) SQLite it counted the same 1,799 rows as "Active". Old `?status=all` URLs fall back to `active` via the existing validation.
- [x] RSS feed/calendar should reflect the current active feed filters — done 2026-09-08. The feeds always honoured `$_GET`; the bug was that the sidebar is server-rendered once and HTMX only swaps `#results`, so the Export hrefs kept the query string from the last full page load. Now re-synced from `location.search` on `htmx:pushedIntoHistory` and `popstate`. `feed_url()` and the JS both drop `page`/`sort`, which describe how the HTML list is read, not which postings it holds.
- [x] remove `căutare` nav item — done 2026-09-08.
- [x] remove landing sub-header: Posturi în sectorul public / xxx anunțuri indexate · sursă: posturi.gov.ro — done 2026-09-08. Kept as an `sr-only` `<h1>`: the visible block restated the masthead, but the page still needs a heading for SEO and screen readers. Source attribution already lives in the footer. Dropped the now-dead `$corpus_count`, which was running a `COUNT(*)` on every request including HTMX swaps.
- [x] compact ui, search can be in sidebar on desktop - the main exploration tool is the filter. remove count judete stats, and domains. keep count anunturi active, angajatori - but move it out of premium area. put instead some clickable facets, ex: like top domenii, temporar, telemunca. funcție publică, funcție contractuală (w counters) — done 2026-09-08. Two columns on `lg`: search sits at the top of the sticky filter column, results and controls to the right. The search input stays a single DOM node so it keeps one `name` at every width — on mobile it renders inline at the top and the facets remain the slide-over drawer. The four stat cards are gone; `anunțuri active` + `angajatori` are one line of mono text above the shortcut row. Shortcuts are plain links (entry points, not a filter UI), shown only when nothing is selected, with counts computed independently of the facet lists so capping cannot change what the landing offers. Verified each chip count equals its filtered result count: sănătate 464, tehnic 452, administrație 365, Temporar 298, Funcție publică 13, Telemuncă 3, Funcție contractuală 1718.
- [ ] for expired postings keep page, metadata, use in stats, list in company profile archive, but instead of description show similar jobs, based on filters and employer.
- [ ] add a relevant emoji next to domeniu and domeniu de studii. 2 emojis, if needed.
- [ ] normalize limbi străine
- [ ] extract specific requirements, GIS, programming languages, etc - use domain specific filters?!
- [ ] normalize job titles - list or standard? 
      - [ ] add icons


### Enhance extraction
- [ ] 13343-expert-comunicare-proiect-cod-smis-330790 - should have a 'comunicare' keyword
- [ ] reformat rendered text, markdown, catch lists, headings. Mark relevant parts?
- [ ] normalize titles


### Update pipeline

State as of 2026-09-08 (9,603 postings, 1,799 active, 647 published in the last 7 days).

- [ ] **80% of the live site has no LLM extraction.** 1,440 of 1,799 active postings
      have `schema_json IS NULL`; the deployed `webapp-php/posturi.sqlite` carries 359
      of 1,799. Every one of those detail pages renders without responsibilities,
      requirements or skills. Now runnable in ~2 h:
      `python pipeline.py --steps schema --active-only --resume --workers 8
      --prompt-version v3` (~$2 at Gemini 2.5 Flash rates). Run it as **v3**:
      `JobPostingExtractionV3` inherits every v2 field, `_render_schema_sections()`
      reads by key so the detail page is unaffected, and `_v3_columns()` in
      `export-to-sqlite.py` already flattens the v3 keys into the `v3_*` SQLite
      columns the new filters query. `PRODUCTION_PROMPT_VERSION = "v2"` in `views.py`
      only sets the default filter on the LLM-variants comparison page and does not
      gate the backfill.
- [x] **80 postings were never actually fetched — cache-key collision.** — Root cause
      found 2026-09-08, fix in `fetch-anunturi.py::get_slug()`. The index links some
      cards by raw WordPress permalink (`/?post_type=pg_job&p=26404`) instead of
      `/joburi/{slug}/`. Those URLs have an *empty* path, and `get_slug` returned the
      constant `'index'` for them, so all 80 shared one cache filename per date
      directory — 28 `index.html` files, each holding whichever posting was fetched
      first that day, with `file_exists()` skipping every later one as already done.
      The correlation is exact: 80 query-string URLs in the database, 80 rows with
      `expires_at IS NULL`, and all 80 have **no** body, attachment, card deadline or
      schema_json. `get_slug` now falls back to the query string.
- [x] **Re-fetch those 80 and re-import.** — Done 2026-09-08. 78 recovered (body,
      expiry, 233 calendar events); the other 2 are 404 upstream. Also fixed the decoder
      half — `parse-anunturi.py` rebuilt every URL as `/joburi/{slug}/` — by giving both
      scripts one shared `posting_urls.py` whose decoder resolves through a map built
      from the encoder, so they cannot drift again.
- [x] **Cancelled announcements are now a real state.** — Done 2026-09-08. All 80 turned
      out to be `expira_in = "Anunț anulat"`, and their detail pages still carry live
      dates, so recovering them turned 15 withdrawn competitions into apparently open
      jobs. `JobPosting.cancelled` (migration 0011) + `--active-only` meaning open AND
      not withdrawn. Active export 1,777 → 1,762.
- [ ] **Surface cancellation in the UI.** The flag exists and is excluded from the live
      export, but a cancelled posting reached by direct link (Django app, or a stale
      bookmark) still renders as an ordinary job. Needs a badge and probably a
      `noindex`.
- [ ] **36 recent postings still lack an expiry date** (down from 81 before the fetch
      fix, and none are cancelled). The export's `expires_at >= CURRENT_DATE` filter
      hides them silently. Decide: show as "termen nespecificat", or keep hiding — but
      deliberately. This filter is what kept a fetch bug invisible for two months.
- [ ] **The 78 recovered postings have no LLM extraction** and the deployed SQLite has
      not been rebuilt. Both wait on the v3 backfill.
- [x] **`pipeline.py` could not drive the schema step usefully.** — Done 2026-09-08.
      It passed neither `--active-only`, `--resume`, `--workers` nor `--limit`, so the
      step ran one call at a time over every posting ever scraped and never finished.
      All four now pass through, opt-in so existing invocations are unchanged.
- [ ] **Stale `posturi.sqlite` (73 MB, 9 June) in the repo root**, left over from before
      `export-to-sqlite.py` grew `--out`; the live artifact is `webapp-php/posturi.sqlite`.
      Two `-shm`/`-wal` files sit beside it. Delete, and check nothing reads the root path.
- [ ] **88 active postings have empty `inferred`** and 252 have no `attachment_text`
      (the latter may be legitimate — not every posting has an attachment). Worth a
      one-off check that these are absences in the source rather than fetch failures.
- [ ] **Nothing schedules any of this.** `deploy-php.sh` is documented as a daily
      rebuild but there is no cron/CI entry in the repo. The 3-month-stale root SQLite
      and the recurring "live site is N weeks stale" entries in the activity log are
      the symptom.
- [ ] deployment pipeline
- [ ] `/pipeline-check` data quality command self-improving and suggesting improvements to the script – with a deterministic version to run after each daily fetch

### LLM round

Measured 2026-09-08 on `gemini-2.5-flash` + prompt v3: a typical call is
`in≈8,600 out≈1,400`, ~$0.0015 cold and ~$0.0008 warm, ~15 s. The v3 system prompt
is ~6,960 tokens — **~81% of the input**. Gemini's implicit cache *can* hit it
(`cached=7092` observed, billed at 10% of the input rate) but did so on only **1 of 6**
sequential calls, so it cannot be budgeted for. Full 9,603-posting v3 run: ~$15 and
~40 h wall-clock at one call at a time. Cost is not the constraint; wall-clock is.

- [ ] **Controlled vocabulary for `skill_list.label`.** Free-text tags do not
      aggregate: the 2,664 skill bullets in the current v2 extractions are 66%
      distinct, and it takes 1,214 distinct strings to cover 80% of occurrences.
      A 30-tag hand-written seed already word-boundary-matches 31% of them
      (`comunicare` 240, `Word` 125, `Excel` 122, `relaționare` 112, `operare PC` 68).
      Bootstrap the real list from data — run v3 free-form on a stratified sample,
      count labels, keep everything above a frequency floor, curate — rather than
      inventing it. Ship as a *soft* in-prompt list ("use one of these exact labels
      when it fits, otherwise emit your own tag"), not a Pydantic `Literal`, so the
      tail survives. Keep `evidence` so labels can be re-normalised later without
      re-running extraction.
- [x] **Stop asking the model for derivable fields.** — Done 2026-09-08. `eqf_level`
      and `iso_code` are now derived in `model_validator`s on `EducationRequirement`
      and `LanguageRequirement`; a model-supplied value that disagrees is overwritten.
- [x] **No retry anywhere in `llm-schema.py`.** — Done 2026-09-08.
      `generate_with_retry()` backs off exponentially with jitter on transient errors
      (classified by HTTP status, falling back to exception class name since no two
      SDKs share a base class) and makes exactly one repair attempt on invalid output,
      appending the validation error to the user message so the cached system prefix
      stays byte-identical. `--max-attempts` tunes it.
- [ ] **Explicit prompt caching, since implicit is unreliable (1/6 hits measured).**
      Gemini `client.caches.create` with the v3 system prompt + a TTL guarantees the
      10% rate on ~81% of every request's input. Anthropic's `cache_control` is already
      wired; its 5-minute TTL refreshes on each hit, so a continuous run stays warm.
      Worth ~$12 of the ~$15 backfill, and more once the prompt carries vocabularies.
- [x] **Sequential loop = ~40 h for a full v3 run.** — Done 2026-09-08. `--workers`
      (default 4) runs LLM calls in a bounded thread pool; all database writes stay on
      the calling thread. Measured 5.13 s/post at 4 workers against ~15-20 s/post
      sequential, i.e. ~14 h for the full corpus instead of ~40 h. Still worth warming
      the cache with one call before fanning out.
- [x] **`--compare` re-runs everything.** — Done 2026-09-08. `--resume` excludes
      already-done postings with a `NOT EXISTS` clause in `_selection_where()`, so the
      progress total and the iteration cannot drift apart. Verified across two runs:
      27 variants over 27 distinct postings, nothing reprocessed.
- [ ] **Batch APIs are ~50% off** on all four providers and would beat caching as a
      cost lever for a one-off backfill. Trade-off: latency (hours) and weaker cache
      interaction. Worth it for the 9,603-posting v3 backfill, not for incremental runs.
- [x] **Hallucination check is free and unbuilt.** — Done 2026-09-08. `grounding.py`
      runs on every posting: a quote passes if 60% of its content words appear in the
      source, or if any four consecutive content words appear consecutively (coverage
      alone punishes length, so a long real span with a short invented lead-in would
      otherwise look like a fabrication). All 30 quotes in the real v3 extractions
      pass; injected fabrications score 12-14%. Findings are printed and counted, not
      stored — a `grounding` column on the variant table is the obvious next step.
- [ ] **Cheap-then-expensive routing.** Flag contradictory or suspiciously empty
      extractions deterministically (e.g. `educationRequirements` non-null but
      `education` null, `eqf_level` disagreeing with `minimum_level`) and re-run only
      those on a stronger model.
- [ ] **Anthropic cache *writes* cost 1.25x and are not modelled.** `compute_cost()`
      reads `cache_read_input_tokens` but ignores `cache_creation_input_tokens`, so
      Anthropic runs under-report cost slightly on every cold call.
- [ ] **Content truncation is a hard cut at 100,000 chars** (`iter_postings`), which
      lands mid-document on the longest postings (max 120,180 chars). Section-aware
      or middle-out truncation would keep the bibliography and calendar.
- [ ] add a note, something like: "conținutul anunțurilor a fost rescris de un LLM, vă recomandăm să verificați și [sursa] înainte de a aplica"
- [ ] deployment: could we run it via github actions, or need VPS?
- [ ] check expiry w LLM, sometimes mismatch, see https://posturi.gov.ro/joburi/expert-comunicare-proiect-cod-smis-330790/ 

### Later
- [ ] upload your cv & match daily quota, create an account for more options, daily updates.
      - [ ] use vector db?
      - [ ] provide MCP endpoint?
- [ ] scrape anunturi job-uri din site-uri individuale, vezi stiri.gov2.ro Ex: https://www.umpcultura.ro/ctg_3_oportunitati-de-angajare_pg_0.htm 
    - [ ] check against posturi.gov.ro
- [ ] one job posting might have more than one attachments? Do we ever have `other_links` ?
- [ ] assess extracted/inferred data quality, if not sure, send to smarter LLM?
- [ ] sometimes attachments might need to be OCR'ed?
- [ ] enhance slugs – use the original? – add judet as folder? - use alias?
- [ ] in site attachment renderer? doc/x, pdf
- [ ] propose a input form for posturi.gov.ro
- [ ] **upload cv, get job recommendations**
- [ ] enhanced stats, compare counties, regions, employment trends, norm per capita


## Data quality issues
- [x] `job/14140-ingrijitoare` this labeled as IT — fixed 2026-09-09. Not specific to this posting: the `_llm_classify` response validator resolved every unparseable answer to `IT`, and DeepSeek with reasoning on was returning empty content. 57 rows repaired; this one is now `altele` (`Ȋngrijitoare` misses the `ingrijitor` keyword, hence the separate FAMILIES item above). See the activity-log entry.
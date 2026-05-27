# Activity Log

## 2026

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

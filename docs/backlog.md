# Backlog

Open follow-ups. Reference: `docs/ui-spec.md` for the broader feature set and phased roadmap. CLAUDE.md convention: checkbox per item, enough context to act on it later.

## Pipeline & data quality

- [x] **Attachment text extraction** — `extract_attachments` command reads `data/downloads/` files linked via `announcement_url` / `other_links`, extracts text (python-docx for .docx, docx2txt for .doc, pypdf for .pdf), stores in `JobPosting.attachment_text`. Used by `infer_postings` Layer 3. Done 2026-05-26. Coverage: 1,113/4,379 postings (25%) — limited by download coverage (1,896 files downloaded of ~4,363 URLs).
- [ ] **Download remaining attachments** — run `download-attachments.py` again; ~2,467 attachment URLs in the CSV have no local file yet. After download, re-run `extract_attachments --force` to capture the rest.
- [x] **`.doc` files return empty text** — replaced `docx2txt` in `quality_check.py::_extract_doc` with `subprocess textutil` (macOS built-in). All `.doc` files now extract correctly. Note: `textutil` is macOS-only; add a `antiword`/LibreOffice fallback before deploying to Linux. Fixed 2026-05-26.
- [x] **`Data Limita Depunere` often empty in anunturi.csv** — added `DEADLINE_BODY_RE` fallback in `parse-anunturi.py` that scans body text for `data limita de depunere dosare : DD.MM.YYYY` when the calendar table lookup returns empty. Fixed 2026-05-26.
- [ ] **Scanned PDF attachments unreadable** — confirmed via `pypdf` on `d4e146ab-10.pdf` (5 pages, 0 chars on all). PDFs from some institutions (e.g. Consiliul Economic și Social) are photographed/printed documents with no text layer. Fix: add OCR fallback in `quality_check.py::_extract_pdf` using `pytesseract` + `pdf2image`. Requires `brew install tesseract poppler`. Affects a subset of PDF attachments; frequency unknown — needs a survey pass over `data/downloads/*.pdf`.
- [x] **Schema prompt: suppress `baseSalary` hallucination** — Added guard to both `quality_check.py::SCHEMA_PROMPT` and `llm-schema.py::PROMPT`: *"Do not include taxa de concurs or application fees as baseSalary. Omit baseSalary entirely if no salary range is explicitly stated."* Done 2026-05-27.
- [x] **Schema prompt: `datePosted` / `validThrough` from index CSV** — `publicat_in` and `expira_in` exist in `posturi_gov_ro.csv` (scraped by `fetch-index.py`) but were not reaching the schema generator. Fixed 2026-05-26: `quality_check.py` now loads the index CSV and injects `Data publicare (datePosted)` + `Data expirare (validThrough)` into the schema context. `parse-anunturi.py` also now joins these as `Data Publicare` / `Data Expirare` columns in `anunturi.csv`. Re-run `parse-anunturi.py` to regenerate the CSV with the new columns (quality checker already has a fallback to the index CSV for old CSVs). Result: `schema_valid_rate` went from 0.5 → 1.0.
- [x] **FAMILIES dict: add `muncitor` → `tehnic`, `psiholog practicant` → `social`** — both now in `infer_postings.py` FAMILIES dict (synced 2026-05-27 alongside broader dict expansion). Done 2026-05-27.
- [x] **`missing_contact` anomaly: distinguish card-empty from attachment-found** — fixed 2026-05-26. `_infer_anomaly_flags` now scans `combined_body` (card body + attachment text) for phone/email patterns when CSV contact fields are empty. Emits `contact_in_attachment` (CSV extraction gap, not a data problem) vs `missing_contact` (truly absent). Same fix applied to `quality_check.py` and `webapp/infer_postings.py`.


- [x] **Employer canonicalization** — done 2026-05-27. Added `EmployerAlias` model (migration 0004); wrote `canonicalize_employers` management command that groups the 2,955 employers by normalized key (NFKD, strip diacritics, lowercase, collapse punctuation/ws), picks the best canonical per group (scored by diacritic richness + title-case), creates EmployerAlias records, and reassigns all `JobPosting.employer` FKs. Result: 205 duplicate groups → 257 aliases created, 521 posting FKs reassigned. Admin updated with posting_count/alias_count columns and full EmployerAlias admin. Note: 257 aliased Employer records still exist with 0 postings — can be deleted later once confident the merge is correct.
- [x] **Parse the `updates_raw` change log** — `JobPostingUpdate` model (`posting`, `changed_at`, `fields_changed`) added (migration 0005). `parse_updates` management command parses semicolon-delimited segments from `updates_raw` and bulk-creates records. Run produced 2,529 "New entry" records (no actual field-change events in current data — those will appear on the next incremental scrape). Admin registered. Done 2026-05-27.
- [x] **Decide canonical source for overlapping fields** — Decision 2026-05-27: `job_type` (from detail CSV/announcement page) is canonical for job permanency — it's per-announcement vs `tip` which is the index tag. 26 postings disagree (`tip=Permanent` but `job_type=Temporar`); `job_type` is correct in those cases. `detalii_raw` is kept verbatim for FTS/debugging; `categorie`/`job_level`/`employer_category` are the canonical structured fields for display and filtering. No code change needed — just confirming the existing schema design intent.
- [x] **Verify body markdown newline handling** — verified 2026-05-27. Sampled 20 postings; 0 had unescaped literal `\n` in `body_markdown`. Importer correctly un-escapes `\\n` → real newlines. Bodies have proper newline structure.
- [x] **Use `unaccent` in the FTS config** — already done (migration 0002 creates `romanian_unaccent` config; views.py and import_csvs.py both use it). Verified 2026-05-27: `condiții ↔ conditii` query returns True, all 4379 postings indexed.
- [ ] **Drop CSV layer eventually** — the scraper → CSV → DB pipeline is 3 hops. Once Slice 2 is stable, port `fetch-index.py`, `fetch-anunturi.py`, and `parse-anunturi.py` into Django management commands that write straight to the DB. The CSVs become an optional export.

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
- [ ] **Run LLM fallback on the 36.7% low-confidence postings** — `python manage.py infer_postings --provider gemini --force` once GEMINI_API_KEY is set in .env. Estimated ~1,600 API calls.
- [x] **Extend Browse UI with inferred facets** — done 2026-05-27. Added `family` (profession_family) and `seniority` facets to the Browse sidebar (Slice 2b). Views.py: `_apply_filters` extended with `families`/`seniorities` params, two new JSONB-backed facet count queries, context updated. Template: "Domeniu" facet at top of sidebar, "Grad/funcție" at bottom. anomaly_flags skipped (array faceting needs UNNEST; deferred to a future pass).

## Job-detail-page prep (Slice 3)

- [ ] **Romanian gazetteer for locality extraction** — needed for the mini-map and city-precision facet (deferred per spec to v2, but the dataset can be built in advance).
- [ ] **`is_repost_of` detection** — title + body cosine similarity within the same employer (12-month window).
- [x] **Anomaly heuristics (v1 set)** — Done 2026-05-27. `short_deadline` (< 7 days publish→deadline), `missing_contact` + `contact_in_attachment`, `gender_criteria` (masculin/feminin in body), `no_body` (< 100 chars), `frequent_repost` (3+ postings for same employer+normalized-title). `build_frequent_repost_ids()` pre-computes the set in O(n) before the main loop; threading it in avoids per-posting cross-queries. Current data: 80 postings flagged as frequent reposts (top: MINISTERUL AFACERILOR EXTERNE 8× same Referent role). Deferred: `narrow_criteria` (needs LLM to detect tailored requirements phrasing — too vague for regex).

## Tooling & ops

- [x] **Install `black` in the venv** — already in `requirements.txt`; black 26.5.1 confirmed present. Done.
- [x] **Test suite** — done 2026-05-27. pytest-django + factory-boy installed. `tests/test_import_idempotency.py` covers: (1) idempotency (run twice, counts unchanged), (2) expected records created, (3) update-existing-fields. All 3 pass in 0.25s. `pytest.ini` configured.
- [x] **CI** — GitHub Actions: lint (ruff), tests, migrations check. Added `.github/workflows/ci.yml` (Python 3.13, Postgres 17 service, ruff lint + makemigrations --check + pytest). Done 2026-05-27.
- [x] **Docker compose for dev** — `docker-compose.yml` at repo root: postgres:17 service with named volume, health check, port 5433 (avoids conflict with local brew Postgres). `.env.example` updated with Docker DATABASE_URL comment. Done 2026-05-27.
- [ ] **Production deploy plan** — pick host (Fly.io vs Railway vs small VPS), decide on managed Postgres, set up backups.

## Cross-cutting / future

- [ ] **Bilingual UI (RO/EN)** — gettext catalogs; default RO, EN toggle.
- [x] **RSS + JSON feeds per filter combination** — Done 2026-05-27. `/posturi.json` (JsonResponse, up to 200 results, full field set) and `/posturi.atom` (Atom1Feed via `django.contrib.syndication`, 50 items) both accept the same query params as the browse view (`q`, `judet`, `level`, `type`, `categorie`, `employer_cat`, `expires_before`, `expires_after`, `family`, `seniority`). `_filter_kwargs_from_request()` helper extracts params from the request; both feeds share `_apply_filters()`.
- [x] **iCal feed per filter combination** — `/posturi.ics` — one `VEVENT` per posting (deadline as `DTSTART`/`DTEND`), employer as `SUMMARY`, contact info + URL in `DESCRIPTION`. Same filter params as browse view, up to 200 events. Done 2026-05-27.
- [x] **Methodology + About pages** — `/despre/` page with sections: what the site is, data sources, inference methodology (dict + LLM fallback, confidence scoring), anomaly heuristics (all 6 flags explained), limitations (scanned PDFs, partial attachment coverage, imperfect classification), export/API reference. Navigation link in base.html header. Done 2026-05-27.
- [ ] **Auth (v3)** — `django-sesame` magic-link; optional Google OAuth.
- [ ] **Stats dashboard (v3)** — KPI tiles, time series, geographic, anomaly index, re-posting tracker.

## Known small issues

- [x] **Migrations: `prepopulated_fields` on slug-on-save** — Replaced `prepopulated_fields` with `readonly_fields = ("slug",)` in both `JudetAdmin` and `EmployerAdmin`. Model `save()` owns slug generation; admin just displays the computed slug as read-only. Done 2026-05-27.
- [ ] **`unique_slug` is O(n) per insert** — fine for ~3k employers, would scale poorly. Consider a deferred-uniqueness approach (try-except IntegrityError) if employer count ever explodes.
- [ ] **`Other Links` parsing is naive** — splits on comma and filters by `http` prefix. Misses cases where URLs themselves contain commas. Low priority; sample data clean for now.

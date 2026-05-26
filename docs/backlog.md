# Backlog

Open follow-ups. Reference: `docs/ui-spec.md` for the broader feature set and phased roadmap. CLAUDE.md convention: checkbox per item, enough context to act on it later.

## Pipeline & data quality

- [x] **Attachment text extraction** — `extract_attachments` command reads `data/downloads/` files linked via `announcement_url` / `other_links`, extracts text (python-docx for .docx, docx2txt for .doc, pypdf for .pdf), stores in `JobPosting.attachment_text`. Used by `infer_postings` Layer 3. Done 2026-05-26. Coverage: 1,113/4,379 postings (25%) — limited by download coverage (1,896 files downloaded of ~4,363 URLs).
- [ ] **Download remaining attachments** — run `download-attachments.py` again; ~2,467 attachment URLs in the CSV have no local file yet. After download, re-run `extract_attachments --force` to capture the rest.
- [x] **`.doc` files return empty text** — replaced `docx2txt` in `quality_check.py::_extract_doc` with `subprocess textutil` (macOS built-in). All `.doc` files now extract correctly. Note: `textutil` is macOS-only; add a `antiword`/LibreOffice fallback before deploying to Linux. Fixed 2026-05-26.
- [x] **`Data Limita Depunere` often empty in anunturi.csv** — added `DEADLINE_BODY_RE` fallback in `parse-anunturi.py` that scans body text for `data limita de depunere dosare : DD.MM.YYYY` when the calendar table lookup returns empty. Fixed 2026-05-26.
- [ ] **Scanned PDF attachments unreadable** — confirmed via `pypdf` on `d4e146ab-10.pdf` (5 pages, 0 chars on all). PDFs from some institutions (e.g. Consiliul Economic și Social) are photographed/printed documents with no text layer. Fix: add OCR fallback in `quality_check.py::_extract_pdf` using `pytesseract` + `pdf2image`. Requires `brew install tesseract poppler`. Affects a subset of PDF attachments; frequency unknown — needs a survey pass over `data/downloads/*.pdf`.
- [ ] **Schema prompt: suppress `baseSalary` hallucination** — LLM (Gemini) encoded `taxa de concurs` (150 RON application fee) as `baseSalary` in schema for 49e420ba-1.doc (Șef Secție, Spitalul Ploiești). Add to `SCHEMA_PROMPT`: *"Do not include taxa de concurs or application fees as baseSalary. Omit baseSalary entirely if no salary range is explicitly stated."* Seen in quality review run #2.
- [x] **Schema prompt: `datePosted` / `validThrough` from index CSV** — `publicat_in` and `expira_in` exist in `posturi_gov_ro.csv` (scraped by `fetch-index.py`) but were not reaching the schema generator. Fixed 2026-05-26: `quality_check.py` now loads the index CSV and injects `Data publicare (datePosted)` + `Data expirare (validThrough)` into the schema context. `parse-anunturi.py` also now joins these as `Data Publicare` / `Data Expirare` columns in `anunturi.csv`. Re-run `parse-anunturi.py` to regenerate the CSV with the new columns (quality checker already has a fallback to the index CSV for old CSVs). Result: `schema_valid_rate` went from 0.5 → 1.0.
- [ ] **FAMILIES dict: add `muncitor` → `tehnic`, `psiholog practicant` → `social`** — both cause LLM fallback and return wrong family (`administrație`). `muncitor necalificat` hit twice in quality run #2. Quick dict addition eliminates the fallback for these high-frequency patterns.
- [x] **`missing_contact` anomaly: distinguish card-empty from attachment-found** — fixed 2026-05-26. `_infer_anomaly_flags` now scans `combined_body` (card body + attachment text) for phone/email patterns when CSV contact fields are empty. Emits `contact_in_attachment` (CSV extraction gap, not a data problem) vs `missing_contact` (truly absent). Same fix applied to `quality_check.py` and `webapp/infer_postings.py`.


- [x] **Employer canonicalization** — done 2026-05-27. Added `EmployerAlias` model (migration 0004); wrote `canonicalize_employers` management command that groups the 2,955 employers by normalized key (NFKD, strip diacritics, lowercase, collapse punctuation/ws), picks the best canonical per group (scored by diacritic richness + title-case), creates EmployerAlias records, and reassigns all `JobPosting.employer` FKs. Result: 205 duplicate groups → 257 aliases created, 521 posting FKs reassigned. Admin updated with posting_count/alias_count columns and full EmployerAlias admin. Note: 257 aliased Employer records still exist with 0 postings — can be deleted later once confident the merge is correct.
- [ ] **Parse the `updates_raw` change log** — currently stored verbatim from the scraper's `updates` column. Needed for the "modificat recent" badge in browse + the change-history audit log on job detail. Schema: a `JobPostingUpdate` model (`posting`, `changed_at`, `field`, `old`, `new`).
- [ ] **Decide canonical source for overlapping fields** — `tip` (index CSV) vs `Job Type` (detail CSV); `detalii_raw` vs the structured `job_level / job_type / categorie / employer_category`. Slice 1 stores both; pick one as canonical and either drop or alias the other.
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
- [ ] **Anomaly heuristics (v1 set)** — short deadline (< X days), missing contact, narrow criteria phrasing, frequent re-posting. Each as a separate rule with its own flag and `why` message.

## Tooling & ops

- [ ] **Install `black` in the venv** — silences the harmless Django migration-formatting warning.
- [x] **Test suite** — done 2026-05-27. pytest-django + factory-boy installed. `tests/test_import_idempotency.py` covers: (1) idempotency (run twice, counts unchanged), (2) expected records created, (3) update-existing-fields. All 3 pass in 0.25s. `pytest.ini` configured.
- [ ] **CI** — GitHub Actions: lint (ruff), tests, migrations check.
- [ ] **Docker compose for dev** — Postgres + Redis (when we add Celery) so contributors don't need brew services.
- [ ] **Production deploy plan** — pick host (Fly.io vs Railway vs small VPS), decide on managed Postgres, set up backups.

## Cross-cutting / future

- [ ] **Bilingual UI (RO/EN)** — gettext catalogs; default RO, EN toggle.
- [ ] **RSS + JSON feeds per filter combination** — start with browse-URL → feed-URL via `django.contrib.syndication` + a JSON view.
- [ ] **iCal feed per filter combination** (v2 calendar slice) — `icalendar` lib + a single view.
- [ ] **Methodology + About pages** — open about scraping cadence, taxonomy inference, anomaly heuristics, limitations.
- [ ] **Auth (v3)** — `django-sesame` magic-link; optional Google OAuth.
- [ ] **Stats dashboard (v3)** — KPI tiles, time series, geographic, anomaly index, re-posting tracker.

## Known small issues

- [ ] **Migrations: `prepopulated_fields` on slug-on-save** — `JudetAdmin` and `EmployerAdmin` use `prepopulated_fields = {"slug": ("name",)}` but the models also auto-slug on save. Slightly redundant; harmless. Decide which approach owns slug generation.
- [ ] **`unique_slug` is O(n) per insert** — fine for ~3k employers, would scale poorly. Consider a deferred-uniqueness approach (try-except IntegrityError) if employer count ever explodes.
- [ ] **`Other Links` parsing is naive** — splits on comma and filters by `http` prefix. Misses cases where URLs themselves contain commas. Low priority; sample data clean for now.

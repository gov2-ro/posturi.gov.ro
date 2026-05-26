# Backlog

Open follow-ups. Reference: `docs/ui-spec.md` for the broader feature set and phased roadmap. CLAUDE.md convention: checkbox per item, enough context to act on it later.

## Pipeline & data quality

- [x] **Attachment text extraction** — `extract_attachments` command reads `data/downloads/` files linked via `announcement_url` / `other_links`, extracts text (python-docx for .docx, docx2txt for .doc, pypdf for .pdf), stores in `JobPosting.attachment_text`. Used by `infer_postings` Layer 3. Done 2026-05-26. Coverage: 1,113/4,379 postings (25%) — limited by download coverage (1,896 files downloaded of ~4,363 URLs).
- [ ] **Download remaining attachments** — run `download-attachments.py` again; ~2,467 attachment URLs in the CSV have no local file yet. After download, re-run `extract_attachments --force` to capture the rest.
- [ ] **`.doc` files return empty text** — `docx2txt` expects DOCX/ZIP format; old binary `.doc` files throw a `KeyError` on `word/document.xml` (silently caught, returns `""`). Fix: add a LibreOffice-based fallback in `extract_text()` (`soffice --headless --convert-to docx`) or use `antiword`. Affects ~75% of attachment files based on `quality_check.py` sample (6/8 `.doc` files empty). See also: `extract_attachments.py::_extract_doc`.
- [ ] **`Data Limita Depunere` often empty in anunturi.csv** — quality_check.py sample shows most postings have an empty structured deadline field; the date appears only in the body markdown. May need a regex extraction pass in `parse-anunturi.py` to populate the structured field from body text. Cross-reference with `calendar.csv` which has competition dates.


- [ ] **Employer canonicalization** — 2,955 raw employer names imported in Slice 1; many are likely duplicates differing in whitespace, casing, "judeţul" vs "județul", trailing commas, or wording ("Primăria Comunei X" vs "Primaria com. X"). Plan: an `EmployerAlias` table + a normalization pass (Unicode NFKC, collapse whitespace, lowercase compare, optional fuzzy match) that links variants to a canonical `Employer`. Required before v2 employer-profile pages are meaningful.
- [ ] **Parse the `updates_raw` change log** — currently stored verbatim from the scraper's `updates` column. Needed for the "modificat recent" badge in browse + the change-history audit log on job detail. Schema: a `JobPostingUpdate` model (`posting`, `changed_at`, `field`, `old`, `new`).
- [ ] **Decide canonical source for overlapping fields** — `tip` (index CSV) vs `Job Type` (detail CSV); `detalii_raw` vs the structured `job_level / job_type / categorie / employer_category`. Slice 1 stores both; pick one as canonical and either drop or alias the other.
- [ ] **Verify body markdown newline handling** — `parse-anunturi.py` escapes newlines as literal `\n` in the CSV; importer un-escapes via `.replace("\\n", "\n")`. Spot-check a few postings to confirm every row renders cleanly, especially ones that may contain a real backslash-n in body text.
- [ ] **Use `unaccent` in the FTS config** — extension is installed but `search_vector` currently uses raw `simple`. Switch to a Romanian-friendly config that applies `unaccent` so `condiții` ↔ `conditii` queries match.
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
- [ ] **Extend Browse UI with inferred facets** — add profession_family, seniority, anomaly_flags to the facet sidebar and queryset filters in `views.py`. Slice 2b.

## Job-detail-page prep (Slice 3)

- [ ] **Romanian gazetteer for locality extraction** — needed for the mini-map and city-precision facet (deferred per spec to v2, but the dataset can be built in advance).
- [ ] **`is_repost_of` detection** — title + body cosine similarity within the same employer (12-month window).
- [ ] **Anomaly heuristics (v1 set)** — short deadline (< X days), missing contact, narrow criteria phrasing, frequent re-posting. Each as a separate rule with its own flag and `why` message.

## Tooling & ops

- [ ] **Install `black` in the venv** — silences the harmless Django migration-formatting warning.
- [ ] **Test suite** — pytest-django + factories; first test: importer idempotency (run twice, assert counts).
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

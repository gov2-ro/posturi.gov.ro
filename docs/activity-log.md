# Activity Log

## 2026

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

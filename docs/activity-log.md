# Activity Log

## 2026

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

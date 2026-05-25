# Activity Log

## 2026

### 2026-05-26 — Repo cleanup, bug fixes, LLM pipeline wiring

**What:** Extracted `posturi.gov.ro/` from the `scrapers2` monorepo into a standalone repo using `git filter-repo`, then audited and fixed the codebase before going live.

**Bug fixes:**
- `fetch-index.py`: fixed `'poziție'` → `'pozitie'` fieldname mismatch (DictWriter was silently dropping the field), added `timeout=30` to all `requests.get()` calls, removed unused `checkback=2` parameter, fixed output path to `data/posturi_gov_ro.csv`, applied incremental per-page saves (crash-safe)
- `download-attachments.py`: fixed CSV path (`data/anunturi.csv` → `data/anunturi/anunturi.csv`), added `timeout=30`

**LLM scripts wired to pipeline:** `llm-schema-posts.py`, `oai-api.py`, `gemini-api.py` now all iterate over `data/anunturi/anunturi.csv`, take `Main Body Markdown` as input, and write JSON-LD output to `data/schema/<slug>.json`. Updated to current SDK APIs (openai v1+, gemini `GenerativeModel.generate_content`).

**Housekeeping:** added `.env.example`, system deps note in README, deleted `fetch-index.py` (superseded by `fetch-index.py`) and `doc2md0.py` (superseded by `dox2md.py`).

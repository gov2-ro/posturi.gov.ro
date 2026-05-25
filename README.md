# posturi.gov.ro scraper

Scrapes [posturi.gov.ro](http://posturi.gov.ro) — the Romanian government job listings portal — and tracks changes over time. Pipeline: index → cache announcement pages → extract structured data → (optional) LLM-generated schema.org JSON.

## Setup

```bash
pip install -r requirements.txt
```

For LLM scripts, create a `.env` file:
```
OPENAI_API_KEY=...
ANTHROPIC_API_KEY=...
GOOGLE_API_KEY=...
```

## Pipeline

Run scripts in order:

| Step | Script | Output |
|------|--------|--------|
| 1 | `fetch-index-check-changes.py` | `data/posturi_gov_ro.csv` |
| 2 | `fetch-anunturi.py` | `data/anunturi/**/*.html` |
| 3 | `parse-anunturi.py` | `data/anunturi/anunturi.csv` |
| 4 | `download-attachments.py` | `data/downloads/` |
| 5 | `llm-schema-posts.py` / `oai-api.py` / `anthropic-api.py` | schema.org JSON |

## Data

**`data/posturi_gov_ro.csv`** — job index, one row per listing, keyed by URL:

| Field | Description |
|-------|-------------|
| `pozitie` | Job title |
| `url` | Listing URL (primary key) |
| `angajator` | Employer |
| `detalii` | Details (comma-separated tags) |
| `publicat_in` | Publication date |
| `expira_in` | Expiry date |
| `judet` | County |
| `url_judet` | County filter URL |
| `tip` | Listing type |
| `updates` | Semicolon-separated log of field changes with dates |

`data/` is gitignored.

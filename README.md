# posturi.gov.ro scraper

Scrapes [posturi.gov.ro](http://posturi.gov.ro) — the Romanian government job listings portal — and tracks changes over time. Pipeline: index → cache announcement pages → extract structured data → (optional) LLM-generated schema.org JSON.

## Setup

```bash
pip install -r requirements.txt
```

For LLM scripts, copy `.env.example` to `.env` and fill in your API keys.

`dox2md.py` also requires system packages:
```bash
brew install libreoffice pandoc tesseract
```

## Pipeline

Run scripts in order:

| Step | Script | Output |
|------|--------|--------|
| 1 | `fetch-index.py` | `data/posturi_gov_ro.csv` |
| 2 | `fetch-anunturi.py` | `data/anunturi/**/*.html` |
| 3 | `parse-anunturi.py` | `data/anunturi/anunturi.csv` + `data/calendar.csv` |
| 4 | `download-attachments.py` | `data/downloads/` |
| 5 | `llm-schema.py` | `data/schema/*.json` |

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

**`data/anunturi/anunturi.csv`** — one row per cached announcement:

| Field | Description |
|-------|-------------|
| `Job Title` | Position title |
| `Employer` | Hiring organisation |
| `Location` | County / locality |
| `Job Level` | Funcții de execuție / Funcții de conducere |
| `Job Type` | Permanent / Temporar |
| `Employer Category` | Angajator type (Primării, Instituții locale, Guvern și ministere, etc.) |
| `Categorie` | Funcție contractuală / Funcție publică |
| `Announcement URL` | Link to attached document or posting page |
| `Main Body Markdown` | Full announcement text converted to markdown |
| `Other Links` | Comma-separated attachment URLs |
| `Nr Posturi` | Number of vacancies |
| `Contact Telefon` | Phone number extracted from body text |
| `Contact Email` | Email address extracted from body text |
| `Contact Persoana` | Contact person name |
| `Data Limita Depunere` | Application deadline (DD.MM.YYYY, ora HH:MM) |
| `Data Proba Scrisa` | Written test date |
| `Data Interviu` | Interview date |
| `Data Rezultate Finale` | Final results date |

**`data/calendar.csv`** — flat competition timeline table, one row per event:

| Field | Description |
|-------|-------------|
| `url` | Announcement URL |
| `eveniment` | Event description (e.g. "Depunerea dosarelor", "Proba scrisă") |
| `data` | Date in `DD.MM.YYYY` format |
| `ora` | Time in `HH:MM` format (empty if not specified) |

`data/` is gitignored.

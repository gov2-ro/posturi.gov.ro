# posturi.gov.ro scraper

Alternative browser / explorer for [posturi.gov.ro](http://posturi.gov.ro). Scrapes the Romanian government job listings portal — and tracks changes over time. Pipeline: index → cache announcement pages → extract structured data → (optional) LLM-generated schema.org JSON.

See [initial specs](https://docs.google.com/document/d/11NXWd4yJII3obPwNsVSJPu7Ue98SqNFQ/) gdocs

## Pipeline

### Quick start — run everything

```bash
python pipeline.py
```

### Selective runs

```bash
python pipeline.py --steps fetch-index,parse,import   # specific steps
python pipeline.py --skip download,infer               # skip slow steps
python pipeline.py --steps infer --provider gemini     # LLM inference only
python pipeline.py --no-llm --force                    # re-run dict-only inference
python pipeline.py --continue-on-error                 # log failures, keep going
```

### Steps

| Step | Script / command | Output |
|------|-----------------|--------|
| `fetch-index` | `fetch-index.py` | `data/posturi_gov_ro.csv` |
| `fetch-detail` | `fetch-anunturi.py` | `data/anunturi/**/*.html` |
| `parse` | `parse-anunturi.py` | `data/anunturi/anunturi.csv` + `data/calendar.csv` |
| `download` | `download-attachments.py` | `data/downloads/` |
| `import` | `manage.py import_csvs` | Postgres `jobs_jobposting` table |
| `extract` | `manage.py extract_attachments` | `JobPosting.attachment_text` |
| `infer` | `manage.py infer_postings` | `JobPosting.inferred` JSONB |

`--force` re-processes already-done rows for `import`, `extract`, and `infer`.
`--limit N` restricts `infer` to N postings (useful for testing).
`--provider gemini|openai|anthropic` sets the LLM used by the `infer` step (default: `gemini`).

## Data quality

`quality_check.py` samples 5–10 diverse postings and runs all four pipeline layers through automated checks, then writes `data/quality_report.json` and a console summary table.

```bash
# Fast pass — no API calls (CSV fields, attachment extraction, dict-only inference)
webapp/.venv/bin/python3 quality_check.py --no-llm

# Full pass — includes LLM infer fallback + schema.org generation
webapp/.venv/bin/python3 quality_check.py --provider anthropic

# Check specific postings by slug
webapp/.venv/bin/python3 quality_check.py --slugs 2a66f376.doc,67438cc9.docx
```

Use the webapp venv because it has `docx2txt`, `python-docx`, and `pypdf`. After running, invoke `/quality-review` in Claude Code for a narrative assessment with root-cause analysis and recommended fixes.

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

## Setup

```bash
pip install -r requirements.txt
```

For LLM scripts and the webapp, copy `.env.example` to `.env` and fill in your API keys.

`dox2md.py` also requires system packages:
```bash
brew install libreoffice pandoc tesseract
```

### Webapp setup

```bash
cd webapp
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python manage.py migrate
.venv/bin/python manage.py runserver
```


# posturi.gov.ro scraper

&rarr; [posturi.gov2.ro](https://posturi.gov2.ro/) [WIP]

Alternative browser / explorer for [posturi.gov.ro](https://posturi.gov.ro). Scrapes the Romanian government job listings portal — and tracks changes over time. Pipeline: index → cache announcement pages → extract structured data → LLM-extracted structured display sections stored in Postgres.

Derivative work: [mariuscomper.uk/posturi-publice](https://mariuscomper.uk/posturi-publice/)

## Pipeline

```mermaid
flowchart LR
    web(["posturi.gov.ro"])

    subgraph scrape["① Scrape"]
        direction TB
        fetchIdx["fetch-index.py"]
        indexCSV[/"posturi_gov_ro.csv"/]
        fetchDetail["fetch-anunturi.py"]
        htmlCache[/"anunturi/\n**∕*.html"/]
        download["download-attachments.py"]
        dlFiles[/"downloads/\n*.docx *.pdf"/]

        fetchIdx --> indexCSV --> fetchDetail --> htmlCache
        htmlCache --> download --> dlFiles
    end

    subgraph parse["② Parse"]
        direction TB
        parseScript["parse-anunturi.py"]
        anunturiCSV[/"anunturi.csv"/]
        calendarCSV[/"calendar.csv"/]
        parseScript --> anunturiCSV & calendarCSV
    end

    subgraph db_layer["③ Import → Postgres"]
        direction TB
        importCSVs["import_csvs"]
        extractCmd["extract_attachments"]
        inferCmd["infer_postings"]
        pg[("jobs_jobposting\nbody_markdown\nattachment_text\ninferred JSONB")]

        importCSVs --> pg
        extractCmd -->|"attachment_text"| pg
        pg --> inferCmd -->|"inferred"| pg
    end

    subgraph enrich["④ Enrich"]
        llmSchema["llm-schema.py"]
    end

    subgraph serve["⑤ Serve"]
        direction TB
        pg[/"PostgreSQL"/]
        sqliteExport["export-to-sqlite.py\n--active-only"]
        sqliteDB[/"posturi.sqlite\n(active only)"/]
        webapp["Django webapp\n(local dev)"]
        phpApp["PHP webapp\n(shared hosting)"]
        browser(["browser"])
        
        pg --> sqliteExport --> sqliteDB --> phpApp
        pg --> webapp
        phpApp --> browser
        webapp --> browser
    end

    web -->|"/toate-posturile/?pg_page=N"| fetchIdx
    web -->|"/joburi/{slug}/"| fetchDetail
    web -->|"wp-content/uploads/"| download

    htmlCache --> parseScript
    anunturiCSV & calendarCSV --> importCSVs
    dlFiles --> extractCmd

    pg -->|"body_markdown\n+ attachment_text"| llmSchema
    llmSchema -->|"schema_json"| pg
    pg --> webapp
```

### Quick start — run everything

```bash
python pipeline.py
```

### Full update → deploy

```bash
# Scrape, parse, import, LLM enrich, export SQLite — then push to shared host
python pipeline.py && ./deploy-php.sh user@host ~/posturi.gov2.ro

# Quick refresh: skip slow steps that haven't changed
python pipeline.py --skip download,extract,infer,schema && ./deploy-php.sh user@host ~/posturi.gov2.ro
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
| `import` | `manage.py import_csvs` | Postgres `jobs_jobposting` table (normalises județe — see below) |
| `extract` | `manage.py extract_attachments` | `JobPosting.attachment_text` |
| `infer` | `manage.py infer_postings` | `JobPosting.inferred` JSONB |
| `schema` | `llm-schema.py` | `JobPosting.schema_json` JSONB |

`--force` re-processes already-done rows for `import`, `extract`, `infer`, and `schema`.
`--limit N` restricts `infer` to N postings (useful for testing).
`--provider gemini|openai|anthropic|deepseek` sets the LLM used by the `infer` and `schema` steps (default: `gemini`).
`--no-llm` skips the LLM portion of `infer` only — the `schema` step is always LLM-driven; use `--skip schema` to omit it.

### Județe (counties)

The source badge has two shapes — `Timiş` before the 2026-07 redesign,
`TIMIŞOARA, Timiș` after — and storing both verbatim once gave the database 261
`Judet` rows for a country with 42 counties, which quietly broke the județ
filter. `webapp/apps/jobs/judete.py` is now the single place that interprets a
raw county string:

- `normalize_judet(raw)` → `(county, locality)`, folding the Turkish cedilla
  `ş/ţ` onto Romanian `ș/ț` and splitting the city out into `JobPosting.locality`.
- `import_csvs` applies it on the way in, so new data cannot fragment.
- `manage.py normalize_judete [--dry-run]` repairs data imported before the fix.
  Idempotent.

**When a county cannot be matched**, the raw value is kept in
`JobPosting.judet_raw` and the posting gets no county — so it answers to no
county filter. Three things surface that:

1. `import_csvs` prints a warning listing the unmatched values.
2. `judet_sanity_warnings()` runs on every import (honours `--strict`) and fires
   if the `Judet` table exceeds 42 rows or any posting is unresolved.
3. Django admin → *Anunțuri* → filter *Județ — rezolvare* → **Nerecunoscut**.

The fix is normally one line in `judete.ALIASES`.

### LLM Provider Comparison

Compare multiple LLM providers and prompt versions on the same postings without overwriting production results:

```bash
# Run all enabled models (respects enable/disable flags in models_config.json)
python llm-schema.py --compare --limit 10

# Test specific models by regex
python llm-schema.py --compare --model-filter "gemini-.*" --limit 5
python llm-schema.py --compare --model-filter "gpt-.*" --limit 5

# Test different prompt versions (when multiple versions exist in config)
python llm-schema.py --compare --prompt-version v2 --limit 10

# Combine: test GPT models with prompt v2
python llm-schema.py --model-filter "gpt-.*" --prompt-version v2 --limit 3
```

Each variant is stored in `JobPostingSchemaVariant` with:
- Provider, model, prompt version
- Token counts (input/output)
- Cost (USD) calculated from config pricing
- Latency (ms)

**View results:**
- Django admin: `Admin → Schema LLM variants` (filter by provider/model/date)
- Job detail page: click "Dev → Comparație LLM" to see all variants for a posting side-by-side

**Configuration** (`models_config.json`):
- Models: enable/disable flag per model, pricing (including `cache_input_cost_per_million` for cache-hit billing)
- Prompts: versioned prompts (v1, v2, etc.) centralized in config
- `get_enabled_models()` respects `"enabled": true/false` flags

### Prompt v2 (Schema.org-aligned)

The `v2` prompt extracts a flat superset of [Schema.org JobPosting](https://schema.org/JobPosting) properties — keys named after JobPosting properties where they exist (`responsibilities`, `educationRequirements`, `experienceRequirements`, `qualifications`, `skills`, `baseSalary`, `jobBenefits`, `workHours`, `jobLocation`), plus three RO-government-specific custom keys (`application_docs`, `application_fee`, `application_contact`). `baseSalary`, `application_fee`, and `application_contact` are structured dicts; the rest are markdown strings or `null`.

Pydantic models in `schema_models.py` are the single source of truth and feed each provider's native structured-output API:
- **OpenAI**: `response_format={"type":"json_schema","strict":True,...}`
- **Gemini**: `response_schema=JobPostingExtraction`
- **Anthropic**: tool-use with `input_schema`
- **DeepSeek**: `response_format={"type":"json_object"}` (loose) + Pydantic post-validation

A cacheable system prefix (instructions + 2 few-shot examples) is sent on every call so providers can hit their prompt cache — measured ~94–99% input-cache hit rate by the 2nd call on OpenAI/DeepSeek. **Gemini is the exception**: its implicit cache is best-effort and hit only 1 of 6 sequential v3 calls (2026-09-08), so do not budget for it there. See `docs/llm-extraction-round.md`.

### Choosing the model, and long runs

Provider, model and prompt version resolve the same way in `llm-schema.py`, `pipeline.py` and `quality_check.py` — **CLI flag > environment variable > `models_config.json` "defaults"**, implemented once in `llm_config.py`:

```bash
# .env
LLM_PROVIDER=gemini            # gemini | openai | anthropic | deepseek
LLM_MODEL=gemini-2.5-flash     # ignored if it is not a model of the selected provider
LLM_PROMPT_VERSION=v3          # v1 | v2 | v3
```

A backfill is ~9,600 calls per model, so the runner is built for that:

```bash
# concurrent, restartable, retrying
python llm-schema.py --prompt-version v3 --workers 8 --resume
```

- `--workers N` (default 4) — concurrent LLM calls; database writes stay single-threaded. Measured 5.13 s/post at 4 workers against 15–20 s sequential.
- `--resume` — skip postings that already have a variant row for this exact provider/model/prompt-version.
- `--max-attempts N` (default 4) — transient errors (429/5xx/timeout) back off exponentially; invalid output gets one repair attempt that shows the model its own validation error.

Every run also checks that quoted `evidence`/`verbatim` values actually appear in the posting (`grounding.py`) and reports unsupported quotes — a hallucination check with no extra LLM call.

### Boilerplate stripping

Before sending to the LLM, `boilerplate.py::strip_hg_1336()` removes generic eligibility lines from HG 1.336/2022 / Codul muncii / OUG 57/2019 art. 542 (cetățenia română, capacitate de muncă, condamnări, pedepse complementare, condițiile generice de studii/vechime etc.). These appear nearly verbatim on every posting and otherwise drown the `qualifications` field with legal citation. The art. 35 dosar list survives intact (it's the `application_docs` content). Measured 13–25% input-length reduction on sampled postings; the bigger win is `qualifications` becoming role-specific signal (76–295 chars) instead of ~2300 chars of legal boilerplate. Toggle off with `python llm-schema.py --no-strip`.

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

Use the webapp venv because it has `docx2txt`, `python-docx`, and `pypdf`. PDF OCR fallback requires system tools: `brew install poppler tesseract tesseract-lang` (poppler provides `pdftoppm`; `tesseract-lang` installs `ron` for Romanian). After running, invoke `/quality-review` in Claude Code for a narrative assessment with root-cause analysis and recommended fixes.

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
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python webapp/manage.py migrate
.venv/bin/python webapp/manage.py runserver
```

## PHP webapp (shared hosting)

A lightweight PHP frontend that runs on commodity shared hosting (cPanel). Reads from a read-only SQLite database — no Python, no web server config, just PHP + SQLite.

### Export & deploy

```bash
# Everything: rebuild the SQLite from Postgres, push code and data
./deploy-php.sh user@host '~/posturi.gov2.ro'

# Just the PHP tree — after a template or stylesheet change
./deploy-php.sh --code-only

# Just the database, already built by pipeline.py — what the cron runs
./deploy-php.sh --data-only --no-export

# See what would move, change nothing
./deploy-php.sh --dry-run
```

Set `DEPLOY_HOST`, `DEPLOY_PATH` and `SITE_URL` in `.env` and the arguments become
optional. Quote a leading `~`: unquoted it expands against the *local* home, and the
script refuses the result rather than rsyncing to a path the remote host has never
heard of.

The deploy script:
1. Aborts if `webapp-php/static/app.css` is missing (see *Stylesheet* below), or if `DEPLOY_PATH` looks like a home directory — it runs `rsync --delete`
2. Runs `export-to-sqlite.py --active-only` — pulls active postings (expires_at >= today) from PostgreSQL into `webapp-php/posturi.sqlite`, unless `--no-export`
3. Refuses to ship a database that fails `integrity_check` or has no postings
4. **Code**: rsyncs `webapp-php/` with `--delete`, minus `assets/` (Tailwind source), `router.php` (dev only) and `*.sqlite*` — excluding the database also protects it from the deletion pass
5. **Data**: rsyncs `posturi.sqlite` alone, no `--delete` and no `--inplace`, so rsync's write-temp-then-rename swaps it atomically and a request mid-transfer still sees the whole previous database
6. Checks `SITE_URL` returns HTTP 200

The full archive stays in PostgreSQL; the deployed SQLite only contains currently active postings.

The export builds beside its target and only replaces it once it opens, passes
`integrity_check`, clears `--min-rows` (default 100) and has not lost half its rows
against the file it would replace. `--force` overrides the row floors. This is the guard
that was missing when the live site served three active postings for five weeks.

### Continuous deployment

The pipeline runs unattended on a VPS twice a day (11:45 and 18:33 Europe/Bucharest) and
pushes a fresh database to the shared host. Code deploys stay manual from the development
machine, which is why the two rsyncs above are separate — a cron that pushed the whole
directory would revert templates from the VPS's older checkout.

```
Mac (dev) ──git push──> GitHub ──git pull (manual)──> VPS
  │                                                    │
  │  ./deploy-php.sh --code-only     ops/run-pipeline.sh (systemd timer)
  └──────────────> shared host (PHP + posturi.sqlite) <┘
```

| Path | What it is |
|------|------------|
| `ops/run-pipeline.sh` | the unattended entry point: flock, pipeline, data deploy, healthcheck ping |
| `ops/systemd/posturi-pipeline.{service,timer}` | the two daily slots |
| `ops/env.sh` | `.env` reader shared by the shell scripts (it is never sourced — it holds API keys) |
| `docs/deploy-vps.md` | provisioning runbook, operating commands, failure table |

Full setup — packages, seeding Postgres and the scrape cache from the Mac, SSH keys,
installing the timer — is in **[docs/deploy-vps.md](docs/deploy-vps.md)**.

### Stylesheet

The app has no build step *on the host* — the compiled CSS is committed. Rebuild it
whenever you touch a template's classes:

```bash
npm install          # once
npm run css          # webapp-php/assets/app.css -> webapp-php/static/app.css (minified)
npm run css:watch    # while editing templates
```

Fonts (`static/fonts/*.woff2`) and htmx (`static/htmx.min.js`) are self-hosted; nothing
is fetched from a CDN at runtime.

### Skins

The whole palette is one `:root` block of CSS custom properties in
`webapp-php/assets/app.css`. Tailwind's theme resolves every colour, radius and font
utility to one of those variables, so re-declaring them under `[data-skin="<id>"]`
restyles the entire site without touching a single utility class. That is all a skin is.

Three ship today, chosen with the picker in the footer (stored in `localStorage`, applied
before first paint so there is no flash):

| id        | what it is                                                              |
| --------- | ----------------------------------------------------------------------- |
| `hartie`  | the built-in default — parchment, Fraunces, DM Sans. No file; it *is* `:root`. |
| `govuk`   | GOV.UK Design System — white, square, Arial, yellow focus, black masthead. |
| `posturi` | the official posturi.gov.ro — navy + gold, Manrope, rounded, lifted cards. |

**Adding one:** copy `webapp-php/static/skins/_template.css` to `<name>.css` and it
appears in the picker on the next request — no registry, no build step. The id is the
filename; `inc/skins.php` discovers it and reads the display name from the `@skin` comment.
Files starting with `_` are skipped.

Two rules the template explains at length:

1. **Scope every rule** under `[data-skin="<id>"]`. All skin files load on every page, so
   an unscoped rule leaks into the others. `@font-face` is the exception — it declares a
   family rather than applying one, and is how a skin ships its own typeface.
2. **Colours are space-separated RGB channels** (`245 240 232`), not hex. That is the only
   form Tailwind's alpha modifiers compose with; a hex value silently breaks every
   `bg-surface/70` on the page.

Skins live in `static/` rather than `assets/` because they need no build — Tailwind would
strip them, since nothing in the PHP references their selectors — and because `deploy-php.sh`
excludes `assets/`.

Validate before committing:

```bash
php webapp-php/assets/check-skins.php
```

It reads the token contract out of `app.css` and flags the four failures that are silent in
a browser: an unscoped rule, a token name that does not exist, a colour written as hex, and
any text/background pair under WCAG AA.

### Previewing another export

`db.php` honours a `POSTURI_DB` environment variable, so a test or a preview can
point at a different SQLite file without touching the deployed one:

```bash
POSTURI_DB=/tmp/other.sqlite php -S localhost:8000 -t webapp-php webapp-php/router.php
```

Unset in production.

### Local preview

```bash
php -S localhost:8000 -t webapp-php webapp-php/router.php
```

`router.php` exists only for PHP's built-in server — it serves static files and mirrors
the `.htaccess` deny rules. Apache never loads it.

### Structure

| Path | Purpose |
|------|---------|
| `index.php` | Front controller — routes `/`, `/job/123-slug/`, `/angajatori/`, `/statistici/`, `/despre/`, `/robots.txt`, `/sitemap.xml` |
| `db.php` | PDO singleton for `posturi.sqlite` |
| `helpers.php` | Markdown rendering, date formatting, filter builder, facet queries, FTS query builder, active-filter chips, URL helpers |
| `pages/` | List, detail, employer list, employer detail, stats, about |
| `feeds/` | Atom, JSON API, iCal endpoints |
| `partials/` | Result list partial (HTMX-compatible) |
| `inc/` | Header/footer HTML, `<head>` metadata (canonical, OpenGraph, JSON-LD hook) |
| `assets/` | Tailwind source — **not deployed** |
| `static/` | Compiled `app.css`, self-hosted fonts, htmx |
| `router.php` | Dev-server router — **not deployed** |
| `.htaccess` | URL rewriting, asset caching, blocks direct access to `*.sqlite*` |


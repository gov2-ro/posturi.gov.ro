# Plan: Pipeline enhancements based on site audit

## Context
Browsed posturi.gov.ro with Playwright to understand what data is available vs what we currently capture. The goal is to gather everything useful for a future UI/browser experience.

---

## What we see on the site vs what we capture today

### Index listing (already captured)
`pozitie`, `angajator`, `publicat_in`, `expira_in`, `judet`, `tip` (Permanent/Temporar), `detalii` (comma-joined tags), `url`

### Detail page — NOT captured, high value
Each job posting page contains much more than what's in the attached .doc/.docx:

**Structured header fields** (visible in the `.caseta` block):
- `nivel` — Funcții de execuție / Funcții de conducere
- `tip_angajator` — Primării / Instituții locale / Instituții județene / Consilii județene / Guvern și ministere / Instituții naționale
- `categorie` — Funcție contractuală / Funcție publică

**Body text fields** (structured, extractable via CSS selectors or regex):
- `nr_posturi` — number of vacancies
- `studii_necesare` — required education level
- `vechime_necesara` — required years of experience
- `alte_conditii` — specific conditions (driver's license, certifications, etc.)
- `contact_persoana` — contact person name
- `contact_telefon` — phone number
- `contact_email` — email address
- `adresa_depunere` — submission address / location

**Competition calendar** (most valuable for UI — currently captured zero of these):
- `data_limita_depunere` — application deadline (often with exact hour, e.g. 08.06.2026 16:00)
- `data_selectie_dosare` — file selection date
- `data_proba_scrisa` — written test date
- `data_interviu` — interview date
- `data_rezultate_finale` — final results date

**Key insight:** the body text on the page IS the announcement — most postings embed the full content directly in HTML, not just in the attachment. This makes `download-attachments.py` + `dox2md.py` redundant for these postings.

---

## Implementation plan (user decisions)

**Scope:** Enhancements 1 + 2 (structured field extraction + calendar CSV). Archive scraper deferred.
**Calendar format:** Separate `data/calendar.csv` flat table (`url, eveniment, data, ora`).

---

## Step 1 — Enhance `parse-anunturi.py`

**File:** `parse-anunturi.py`

Current behaviour: reads cached HTML per posting, converts to markdown, writes one CSV row.
New behaviour: additionally extract structured fields and append them as extra columns.

**New columns in `anunturi.csv`:**
- `nivel` — from `.caseta` block (Funcții de execuție / conducere)
- `tip_angajator` — from `.caseta` block
- `categorie` — Funcție contractuală / Funcție publică
- `nr_posturi` — regex `(\d+)\s+post(uri)?` near start of body
- `studii_necesare` — text following "Nivelul studiilor" / "studii necesare"
- `vechime_necesara` — text following "vechime"
- `contact_telefon` — regex `\b0[0-9]{9}\b`
- `contact_email` — regex `[\w.+-]+@[\w-]+\.[a-z]{2,}`
- `contact_persoana` — text following "persoana de contact" / "responsabil"

**Extraction approach:**
- `.caseta` block fields: BeautifulSoup CSS selector, parse label/value pairs
- Body regex fields: search full text of `<div class="content">` or equivalent
- Unknown/missing fields → empty string (never fail hard)

## Step 2 — Generate `data/calendar.csv`

Written inside the same `parse-anunturi.py` run (no separate script needed).

**Approach:** After parsing each posting's body HTML, look for a calendar table or list:
- Find a `<table>` or `<ul>` whose heading text matches "CALENDARUL" / "Nr. crt." / "calendar"
- For each row/item: extract the event name (`eveniment`) + date string + hour if present
- Normalise date to `DD.MM.YYYY` and time to `HH:MM` (or empty)
- Append rows to `data/calendar.csv` with `url, eveniment, data, ora`

Calendar CSV is **appended incrementally** per posting (same crash-safe pattern as index scraper) — or written fresh at end of run if rebuilding from scratch. Simpler: collect all rows in memory, write at end of parse run.

## Verification

1. Run `python parse-anunturi.py` on the existing cached HTML in `data/anunturi/`
2. Check `data/anunturi/anunturi.csv` has the new columns populated for postings that have that data
3. Check `data/calendar.csv` has rows with valid dates for postings that have a calendar table
4. Spot-check a known posting (e.g. `asistent-medical-comunitar-508`) against the live page

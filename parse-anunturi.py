folder_path = 'data/anunturi'
output_csv_path = 'data/anunturi/anunturi.csv'
output_calendar_path = 'data/calendar.csv'
index_csv_path = 'data/posturi_gov_ro.csv'

import argparse
import csv
import hashlib
import json
import os
import re
import sys
import time
from datetime import datetime
import glob
from bs4 import BeautifulSoup
from markdownify import markdownify as md

from posting_urls import build_slug_index, url_from_slug

#: Bump this whenever a change to this file can alter what a given HTML page
#: parses to — a selector, a regex, a date rule, the markdown conversion, a CSV
#: column. The incremental parse (LATER-05) reuses the previous rows of every
#: page whose sidecar hash is unchanged, so without a bump the old output would
#: silently survive a parser fix. A bump invalidates the whole cache and the next
#: run re-parses everything (`--full` does the same on demand). Pure refactors,
#: comments and log changes do not need one.
PARSER_VERSION = 1

#: Name of the reuse cache, written next to the anunturi CSV.
CACHE_NAME = '.parse-cache.json'

_RO_MONTHS = {
    'ianuarie': 1, 'februarie': 2, 'martie': 3, 'aprilie': 4,
    'mai': 5, 'iunie': 6, 'iulie': 7, 'august': 8,
    'septembrie': 9, 'octombrie': 10, 'noiembrie': 11, 'decembrie': 12,
}


def _parse_index_date(raw):
    """Parse 'Publicat în: 9 septembrie,2024' or 'Expiră in  16/09/2024' → 'YYYY-MM-DD'."""
    m = re.search(r'(\d{1,2})/(\d{2})/(\d{4})', raw)
    if m:
        return f"{m.group(3)}-{int(m.group(2)):02d}-{int(m.group(1)):02d}"
    m = re.search(r'(\d{1,2})\s+(\w+),\s*(\d{4})', raw)
    if m:
        month_num = _RO_MONTHS.get(m.group(2).lower())
        if month_num:
            return f"{m.group(3)}-{month_num:02d}-{int(m.group(1)):02d}"
    # New format: "Data publicării: DD.MM.YYYY"
    m = re.search(r'(\d{2})\.(\d{2})\.(\d{4})', raw)
    if m:
        return f"{m.group(3)}-{m.group(2)}-{m.group(1)}"
    return ''


def _load_index_dates():
    """Return {url: (date_posted_iso, valid_through_iso)} from posturi_gov_ro.csv."""
    result = {}
    if not os.path.exists(index_csv_path):
        return result
    with open(index_csv_path, newline='', encoding='utf-8') as f:
        for row in csv.DictReader(f):
            url = row.get('url', '').strip()
            if url:
                result[url] = (
                    _parse_index_date(row.get('publicat_in', '')),
                    _parse_index_date(row.get('expira_in', '')),
                )
    return result


_INDEX_DATES = _load_index_dates()

#: slug → source URL, built by running the fetch step's own naming function over
#: every URL the index knows. Without this the parser guessed `/joburi/{slug}/`,
#: which is wrong for the raw-permalink postings (`/?post_type=pg_job&p=26404`)
#: and silently detached them from their index row — no dates, no join key.
_SLUG_INDEX = build_slug_index(_INDEX_DATES)

DATE_RE = re.compile(r'\b(\d{1,2}\.\d{2}\.\d{4})\b')
TIME_RE = re.compile(r'ora\s+(\d{1,2}[:.]\d{2})', re.IGNORECASE)
PHONE_RE = re.compile(r'\b(0[0-9]{9})\b')
PHONE_CANDIDATE_RE = re.compile(r'\b0[\d\s.\-–/]{9,14}\d\b')
EMAIL_RE = re.compile(r'[\w.+-]+@[\w.-]+\.[a-z]{2,}')
NR_POSTURI_RE = re.compile(r'(\d+)\s+post(?:uri|ul)?\b', re.IGNORECASE)
DEADLINE_BODY_RE = re.compile(
    r'data\s+limit[aă]\s+(?:de\s+)?(?:depunere|inscriere|depunerii)[^:\n]{0,40}:\s*(\d{1,2}\.\d{2}\.\d{4})',
    re.IGNORECASE,
)


# --- HTML version detection ---

def _is_new_html(soup):
    """Detect new site HTML (pg-* classes) vs old site HTML."""
    return bool(
        soup.select_one('.pg-title')
        or soup.select_one('.pg-jobcard')
        or soup.select_one('.pg-wrap')
    )


# --- Card field extraction ---

def _extract_card_fields_old(soup):
    """Extract labeled card fields from old site HTML."""
    fields = {}
    for wrapper in soup.select('.card-job-post-category-wrapper'):
        label_el = wrapper.select_one('.card-job-post-category-title-wrapper')
        value_el = wrapper.select_one('.card-job-post-category-text')
        if not label_el or not value_el:
            continue
        label = label_el.get_text(strip=True).lower()
        value = value_el.get_text(strip=True)
        fields[label] = value
    return fields


def _extract_card_fields_new(soup):
    """Extract card fields from new site HTML (pg-* classes).

    Pills appear in order: [Permanence, Employment type, Function level]
    e.g.: "Permanent", "Funcție contractuală", "Funcții de execuție"
    """
    fields = {}

    # Location from subline (employer · county)
    subline = soup.select_one('.pg-subline')
    if subline:
        fields['locatie'] = subline.get_text(' ', strip=True)

    # Pills
    pills = soup.select('.pg-pills span.pg-pill')
    pill_texts = [p.get_text(strip=True) for p in pills]

    # Permanence (tip): first pill or one matching Permanent/Temporar
    for pt in pill_texts:
        if pt.lower() in ('permanent', 'temporar'):
            fields['tip'] = pt
            break
    if 'tip' not in fields and pill_texts:
        fields['tip'] = pill_texts[0]

    # Employment type (angajator category): "Funcție contractuală" / "Funcție publică"
    for pt in pill_texts:
        if pt.lower().startswith('funcție'):
            fields['angajator'] = pt
            break

    # Function level (nivel): "Funcții de execuție" / "Funcții de conducere"
    for pt in pill_texts:
        if pt.lower().startswith('funcții'):
            fields['nivel'] = pt
            break

    # Categorie is not directly represented in new pills
    fields['categorie'] = ''

    return fields


def extract_card_fields(soup):
    """Extract card fields, dispatching on old vs new HTML."""
    if _is_new_html(soup):
        return _extract_card_fields_new(soup)
    else:
        return _extract_card_fields_old(soup)


# --- Calendar extraction ---

def _p_lines(p):
    """Split a <p> tag into lines on <br/> boundaries."""
    lines = []
    current = []
    for child in p.children:
        if hasattr(child, 'name') and child.name == 'br':
            lines.append(''.join(current).strip())
            current = []
        else:
            current.append(child.get_text() if hasattr(child, 'get_text') else str(child))
    if current:
        lines.append(''.join(current).strip())
    return [l for l in lines if l]


def _as_date(value):
    """`DD.MM.YYYY` as a date, or None. Used only to sanity-check ranges."""
    try:
        return datetime.strptime(value, "%d.%m.%Y").date()
    except (ValueError, TypeError):
        return None


def extract_calendar(body_soup):
    """Return list of (eveniment, data, ora) from the competition calendar."""
    rows = []
    if not body_soup:
        return rows
    cal_p = None
    for p in body_soup.find_all('p'):
        txt = p.get_text(strip=True)
        if re.search(r'CALENDAR|Nr\.\s*crt', txt, re.IGNORECASE):
            cal_p = p
            break
    if not cal_p:
        return rows

    header_skipped = False
    for line in _p_lines(cal_p):
        if not header_skipped:
            if re.search(r'CALENDAR|Nr\.\s*crt', line, re.IGNORECASE):
                header_skipped = True
            continue
        line = re.sub(r'^\d+\.?\s*', '', line).strip()
        if not line:
            continue
        date_m = DATE_RE.search(line)
        if not date_m:
            continue
        date_str = date_m.group(1)
        time_m = TIME_RE.search(line[date_m.start():])
        time_str = time_m.group(1) if time_m else ''
        eveniment = line[:date_m.start()].strip().rstrip(',.').strip()
        # A submission row often states a window: "Depunerea dosarelor de la
        # 11.09.2026 până la 30.09.2026". The row's own date stays the first one
        # (when the stage begins), but the closing date is what a candidate has
        # to act on, so it is carried for `_find_deadline` to prefer.
        all_dates = DATE_RE.findall(line)
        end_str = all_dates[-1] if len(all_dates) > 1 else ''
        # A window that closes before it opens is a typo in the announcement,
        # not a date. One real posting reads "-20.05.2025-03.06.2024-": taking
        # the closing date faithfully would publish a deadline a year in the
        # past. Fall back to the row's own date and let it be merely imprecise.
        if end_str and _as_date(end_str) and _as_date(date_str):
            if _as_date(end_str) < _as_date(date_str):
                end_str = ''
        if end_str:
            # "între orele 8.00 – 16.00" — if the closing date is taken, the
            # closing hour has to come with it, or the deadline reads eight
            # hours earlier than it is.
            all_times = TIME_RE.findall(line)
            if len(all_times) > 1:
                time_str = all_times[-1]
        if eveniment:
            rows.append((eveniment, date_str, time_str, end_str))
    return rows


# --- Contact extraction ---

def _extract_contact_structured(soup):
    """Extract contact info from new pg-contact-row elements."""
    telefon = ''
    email = ''
    angajator_contact = ''
    for row in soup.select('.pg-contact-row'):
        key_el = row.select_one('.pg-mini-k')
        val_el = row.select_one('.pg-mini-v')
        if not key_el or not val_el:
            continue
        key = key_el.get_text(strip=True).lower()
        val = val_el.get_text(strip=True)
        if key == 'telefon':
            telefon = val
        elif key == 'email':
            email = val
        elif key == 'angajator':
            angajator_contact = val
    return telefon, email, angajator_contact


def extract_contact(body_text):
    """Extract contact info from body text via regex (used for old HTML or fallback)."""
    phones = PHONE_RE.findall(body_text)
    if not phones:
        for m in PHONE_CANDIDATE_RE.finditer(body_text):
            digits = re.sub(r'\D', '', m.group(0))
            if len(digits) == 10:
                phones.append(digits)
    emails = EMAIL_RE.findall(body_text)
    persoana = ''
    m = re.search(
        r'persoan[aă]\s+de\s+contact\s*:?\s*([A-ZĂÎȘȚÂ][^\n,;.]{2,50})',
        body_text, re.IGNORECASE
    )
    if m:
        persoana = m.group(1).strip()
    return (
        phones[0] if phones else '',
        emails[0] if emails else '',
        persoana,
    )


# --- Calendar date helpers ---

def _find_calendar_date(calendar_rows, keywords, exclude_deadline_rows=False):
    """First calendar row whose label matches a keyword, or ''.

    `exclude_deadline_rows` (used for the results probe) skips rows that look
    like submission-deadline rows — "Data finală de depunere" is a deadline,
    not the date final results are published, and `final` alone used to match
    it. For the written-test probe, 'scris' must not match 'înscris/înscrisă'
    ("candidații înscriși"), so it requires a non-'în' prefix.
    """
    for eveniment, data, ora, _end in calendar_rows:
        text = eveniment.lower()
        if exclude_deadline_rows:
            if any(kw in text for kws in _DEADLINE_KEYWORDS for kw in kws):
                continue
        for kw in keywords:
            kw = kw.lower()
            if kw == 'scris':
                if re.search(r'(?<!în)' + re.escape(kw), text):
                    return data + (f', ora {ora}' if ora else '')
            elif kw in text:
                return data + (f', ora {ora}' if ora else '')
    return ''


#: Rows that mention dosare but are not the submission deadline. `dosar` alone
#: matched "Selectarea dosarelor de concurs" and "Afişarea rezultatelor
#: selecţiei dosarelor", so the scraped deadline could land on the selection
#: date — days after applications had actually closed.
_NOT_A_DEADLINE = re.compile(
    r'selec[tțţ]|afi[sșş]|rezultat|contesta[tțţ]|solu[tțţ]ion|proba|interviu|'
    r'evaluar|sus[tțţ]iner',
    re.IGNORECASE)

#: Most specific first. A row that says "limită" is the deadline; one that only
#: says "depunere" may be the opening of the window; "înscriere" is weaker
#: still. Taking the first row that matched any of them, as this used to, meant
#: the answer depended on the order the institution happened to write its table.
_DEADLINE_KEYWORDS = (
    ('limita', 'limită'),
    ('depunere', 'depunerea', 'se depun'),
    ('inscriere', 'înscriere', 'inscrierea', 'înscrierea'),
    ('dosar',),
)


def _find_deadline(calendar_rows):
    """The date applications close, from the competition calendar.

    Two things this has to get right, both of which it used to get wrong:
    pick the row that is actually the deadline rather than the first one
    mentioning a dossier, and take the *closing* date when the row states a
    window rather than the day submissions opened.
    """
    for depth, tier in enumerate(_DEADLINE_KEYWORDS):
        last_tier = depth == len(_DEADLINE_KEYWORDS) - 1
        for eveniment, data, ora, end in calendar_rows:
            label = eveniment.lower()
            # Exclusions guard the specific tiers. The final `dosar` fallback
            # drops them: a row combining submission with selection still
            # carries a usable date, and no date at all helps nobody.
            if not last_tier and _NOT_A_DEADLINE.search(label):
                continue
            if not any(kw in label for kw in tier):
                continue
            return (end or data) + (f', ora {ora}' if ora else '')
    return ''


# --- URL reconstruction ---

def source_url_from_path(file_path):
    """Reconstruct the posting URL from the cached HTML path.

    Resolved through `_SLUG_INDEX`, which is built by applying the fetch step's
    own naming function to every URL in the index CSV — so the two directions
    agree by construction rather than by two regexes kept in sync by hand.
    Handles /anunt/, /joburi/ and raw `?post_type=pg_job&p=N` permalinks alike.
    Unknown slugs fall back to the /joburi/{slug}/ shape.
    """
    slug = os.path.splitext(os.path.basename(file_path))[0]
    return url_from_slug(slug, _SLUG_INDEX)


def _read_sidecar(file_path):
    """The fetch step's per-cache metadata (fetch-anunturi.py), or None.

    Carries fetched_at/content_hash/status from the *successful* retrieval.
    Legacy caches predating FIX-03 have no sidecar — their provenance stays
    unknown, never "fetched now".
    """
    meta_path = file_path[:-5] + '.meta.json'  # <slug>.html → <slug>.meta.json
    try:
        with open(meta_path, 'r', encoding='utf-8') as f:
            import json as _json

            data = _json.load(f)
        return data if isinstance(data, dict) else None
    except (OSError, ValueError):
        return None


def _detail_status(soup):
    """The detail page's own status marker: 'anulat' | 'live' | '' (unknown).

    New site: `.pg-status` with `is-off` (withdrawn — "Anulat") or `is-live`.
    The sidecar (written from the full page at fetch time) is authoritative;
    this fallback covers legacy caches whose extraction happened to contain
    the marker. Unknown markup is unknown — never implicitly live.
    """
    el = soup.select_one('.pg-status')
    if el is None:
        return ''
    classes = set(el.get('class') or [])
    text = el.get_text(' ', strip=True).lower()
    if 'is-off' in classes or 'anulat' in text:
        return 'anulat'
    if 'is-live' in classes:
        return 'live'
    return ''


def _try_index_lookup(src_url):
    """Try to find index dates with URL fallback (old /anunt/ → new /joburi/)."""
    if src_url in _INDEX_DATES:
        return _INDEX_DATES[src_url]
    # Fallback: try the other URL scheme
    if '/joburi/' in src_url:
        alt_url = src_url.replace('/joburi/', '/anunt/')
        if alt_url in _INDEX_DATES:
            return _INDEX_DATES[alt_url]
    elif '/anunt/' in src_url:
        alt_url = src_url.replace('/anunt/', '/joburi/')
        if alt_url in _INDEX_DATES:
            return _INDEX_DATES[alt_url]
    return ('', '')


# --- Main job extraction ---

def extract_job_details(file_path):
    with open(file_path, 'r', encoding='utf-8') as f:
        html_content = f.read()

    soup = BeautifulSoup(html_content, 'html.parser')
    is_new = _is_new_html(soup)

    # --- Source retrieval provenance (FIX-03) ---
    sidecar = _read_sidecar(file_path)
    detail_fetched_at = (sidecar or {}).get('fetched_at', '') or ''
    detail_content_hash = (sidecar or {}).get('content_hash', '') or ''
    # The sidecar saw the full page at fetch time; the cached extraction is
    # the fallback for legacy files without one.
    detail_status = (sidecar or {}).get('status', '') or _detail_status(soup)

    # --- Job title ---
    job_title = ''
    if is_new:
        title_el = soup.select_one('h1.pg-title')
        if title_el:
            job_title = title_el.text.strip()
    else:
        try:
            job_title = soup.select_one('.titlu h1').text.strip()
        except AttributeError:
            job_title = ''

    # --- Employer ---
    employer = ''
    if is_new:
        # From structured contact row
        for row in soup.select('.pg-contact-row'):
            key_el = row.select_one('.pg-mini-k')
            val_el = row.select_one('.pg-mini-v')
            if key_el and val_el and key_el.get_text(strip=True).lower() == 'angajator':
                employer = val_el.get_text(strip=True)
                break
        # Fallback: from subline
        if not employer:
            subline = soup.select_one('.pg-subline')
            if subline:
                spans = subline.find_all('span')
                if spans:
                    employer = spans[0].get_text(strip=True)
    else:
        try:
            employer = soup.select_one('.caseta .ang').text.strip()
        except AttributeError:
            employer = ''

    # --- Card fields ---
    card = extract_card_fields(soup)
    location = card.get('locatie', '')
    nivel = card.get('nivel', '')
    tip = card.get('tip', '')
    tip_angajator = card.get('angajator', '')
    categorie = card.get('categorie', '')

    # --- Announcement URL ---
    announcement_url = ''
    if is_new:
        # New site: a.pg-btn-pill with href to wp-content/uploads
        btn = soup.select_one('a.pg-btn-pill')
        if btn:
            announcement_url = btn.get('href', '')
    else:
        # Old site: <a> with text "Anunt"
        announcement_link_tag = soup.find('a', string='Anunt')
        if announcement_link_tag:
            announcement_url = announcement_link_tag['href']

    # Corrected document override (both old and new)
    corrected_link = soup.find('a', string=re.compile(r'Document\s+ata[şș]at\s+corectat', re.IGNORECASE))
    if corrected_link:
        href = corrected_link.get('href', '')
        if href.startswith('https://posturi.gov.ro/wp-content/uploads/'):
            announcement_url = href

    # --- Main body markdown ---
    main_body_markdown = ''
    if is_new:
        prose = soup.select_one('.pg-prose')
        if prose:
            main_body_markdown = md(str(prose)).replace('\n', '\\n')
    else:
        announcement_link_tag = soup.find('a', string='Anunt')
        if announcement_link_tag:
            main_body_html = ''
            for sibling in announcement_link_tag.find_all_next():
                if sibling.name == 'nav':
                    break
                main_body_html += str(sibling)
            main_body_markdown = md(main_body_html).replace('\n', '\\n')

    # --- Other links ---
    other_links = []
    for link in soup.find_all('a', href=True):
        url = link['href']
        if url.startswith('https://posturi.gov.ro/wp-content/uploads/') and url != announcement_url:
            other_links.append(url)

    # --- Body text and contact ---
    if is_new:
        prose = soup.select_one('.pg-prose')
        body_text = prose.get_text('\n') if prose else ''
        # Try structured contact first
        s_phone, s_email, s_ang = _extract_contact_structured(soup)
        # Fall back to regex for phone/email/person if structured is empty
        contact_telefon = s_phone
        contact_email = s_email
        if not contact_telefon or not contact_email:
            r_phone, r_email, r_pers = extract_contact(body_text)
            if not contact_telefon:
                contact_telefon = r_phone
            if not contact_email:
                contact_email = r_email
        contact_persoana = ''
        # Still regex for person name
        m = re.search(
            r'persoan[aă]\s+de\s+contact\s*:?\s*([A-ZĂÎȘȚÂ][^\n,;.]{2,50})',
            body_text, re.IGNORECASE
        )
        if m:
            contact_persoana = m.group(1).strip()
    else:
        body_soup = soup.select_one('.entry-content')
        body_text = body_soup.get_text('\n') if body_soup else ''
        contact_telefon, contact_email, contact_persoana = extract_contact(body_text)

    # --- Nr posturi ---
    nr_posturi_m = NR_POSTURI_RE.search(body_text[:2000])
    nr_posturi = nr_posturi_m.group(1) if nr_posturi_m else ''

    # --- Calendar ---
    if is_new:
        prose = soup.select_one('.pg-prose')
        calendar_rows = extract_calendar(prose)
    else:
        body_soup = soup.select_one('.entry-content')
        calendar_rows = extract_calendar(body_soup)

    data_limita = _find_deadline(calendar_rows)
    if not data_limita:
        m = DEADLINE_BODY_RE.search(body_text)
        if m:
            data_limita = m.group(1)
    data_scrisa = _find_calendar_date(calendar_rows, ['scrisa', 'scrisă', 'scris'])
    data_interviu = _find_calendar_date(calendar_rows, ['interviu'])
    # Exclude deadline-shaped rows: "Data finală de depunere" is a deadline,
    # not the results announcement.
    data_rezultate = _find_calendar_date(calendar_rows, ['final', 'rezultat final', 'rezultate finale'],
                                         exclude_deadline_rows=True)

    # --- Index dates ---
    src_url = source_url_from_path(file_path)
    date_posted, valid_through = _try_index_lookup(src_url)

    # --- Also try to get dates from new detail page meta line ---
    if not valid_through and is_new:
        deadline_el = soup.select_one('.pg-meta-deadline')
        if deadline_el:
            valid_through = deadline_el.get_text(strip=True)
    if not date_posted and is_new:
        meta_line = soup.select_one('.pg-meta-line')
        if meta_line:
            m = re.search(r'Publicat pe (\d{4}-\d{2}-\d{2})', meta_line.get_text())
            if m:
                date_posted = m.group(1)

    return {
        'source_url': src_url,
        'job_title': job_title,
        'employer': employer,
        'location': location,
        'job_level': nivel,
        'job_type': tip,
        'employer_category': tip_angajator,
        'categorie': categorie,
        'announcement_url': announcement_url,
        'main_body_markdown': main_body_markdown,
        'other_links': other_links,
        'nr_posturi': nr_posturi,
        'contact_telefon': contact_telefon,
        'contact_email': contact_email,
        'contact_persoana': contact_persoana,
        'data_limita_depunere': data_limita,
        'data_proba_scrisa': data_scrisa,
        'data_interviu': data_interviu,
        'data_rezultate_finale': data_rezultate,
        'data_publicare': date_posted,
        'data_expirare': valid_through,
        'detail_status': detail_status,
        'detail_fetched_at': detail_fetched_at,
        'detail_content_hash': detail_content_hash,
        '_calendar_rows': calendar_rows,
    }


CSV_HEADERS = [
    'Source URL',
    'Job Title', 'Employer', 'Location', 'Job Level', 'Job Type',
    'Employer Category', 'Categorie', 'Announcement URL',
    'Main Body Markdown', 'Other Links',
    'Nr Posturi', 'Contact Telefon', 'Contact Email', 'Contact Persoana',
    'Data Limita Depunere', 'Data Proba Scrisa', 'Data Interviu', 'Data Rezultate Finale',
    'Data Publicare', 'Data Expirare',
    'Status', 'Detail Fetched At', 'Detail Content Hash',
]
CALENDAR_HEADERS = ['url', 'eveniment', 'data', 'ora']


def job_row(d):
    """One parsed posting as an `anunturi.csv` row (keyed by `CSV_HEADERS`)."""
    return {
        'Source URL': d['source_url'],
        'Job Title': d['job_title'],
        'Employer': d['employer'],
        'Location': d['location'],
        'Job Level': d['job_level'],
        'Job Type': d['job_type'],
        'Employer Category': d['employer_category'],
        'Categorie': d['categorie'],
        'Announcement URL': d['announcement_url'],
        'Main Body Markdown': d['main_body_markdown'],
        'Other Links': ', '.join(d['other_links']),
        'Nr Posturi': d['nr_posturi'],
        'Contact Telefon': d['contact_telefon'],
        'Contact Email': d['contact_email'],
        'Contact Persoana': d['contact_persoana'],
        'Data Limita Depunere': d['data_limita_depunere'],
        'Data Proba Scrisa': d['data_proba_scrisa'],
        'Data Interviu': d['data_interviu'],
        'Data Rezultate Finale': d['data_rezultate_finale'],
        'Data Publicare': d.get('data_publicare', ''),
        'Data Expirare': d.get('data_expirare', ''),
        'Status': {'anulat': 'Anulat', 'live': 'Live'}.get(d.get('detail_status'), ''),
        'Detail Fetched At': d.get('detail_fetched_at', ''),
        'Detail Content Hash': d.get('detail_content_hash', ''),
    }


def calendar_csv_rows(d):
    """The posting's competition-timeline rows as `calendar.csv` rows."""
    url = d['source_url']
    return [
        {'url': url, 'eveniment': eveniment, 'data': data_str, 'ora': ora}
        for eveniment, data_str, ora, _end in d['_calendar_rows']
    ]


def _write_csv(path, headers, rows):
    """Write a CSV via temp file + rename, so a crash never leaves half a file
    (and the reuse cache, which fingerprints the finished file, stays honest)."""
    os.makedirs(os.path.dirname(path) or '.', exist_ok=True)
    tmp = path + '.tmp'
    with open(tmp, mode='w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=headers)
        writer.writeheader()
        for row in rows:
            writer.writerow(row)
    os.replace(tmp, path)


def save_to_csv(data_list, path):
    _write_csv(path, CSV_HEADERS, (job_row(d) for d in data_list))


def save_calendar(data_list, path):
    _write_csv(path, CALENDAR_HEADERS,
               (r for d in data_list for r in calendar_csv_rows(d)))


def find_html_files(directory):
    # Sorted: glob order is filesystem order, which differs between machines and
    # between a fresh checkout and a long-lived cache. A stable order makes the
    # CSVs reproducible and a full and an incremental run comparable byte for byte.
    return sorted(glob.glob(os.path.join(directory, '**/*.html'), recursive=True))


# --- Incremental parse (LATER-05) ---

def _file_sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def _read_rows(path):
    """All rows of a CSV as dicts, or None when it is missing/unreadable."""
    csv.field_size_limit(sys.maxsize if sys.maxsize < 2**31 else 2**31 - 1)
    try:
        with open(path, newline='', encoding='utf-8') as f:
            return list(csv.DictReader(f))
    except (OSError, ValueError, csv.Error):
        return None


def _load_cache(cache_path, csv_path, calendar_path):
    """The reuse cache, or None when it cannot be trusted.

    Trusted only if it was written by this parser version for exactly the CSVs
    now on disk (their SHA-256 are recorded in it): a hand-edited, truncated or
    swapped output, or a crash between writing the CSVs and the cache, therefore
    falls back to a full parse instead of reusing rows that do not match it.
    """
    try:
        with open(cache_path, encoding='utf-8') as f:
            cache = json.load(f)
        if (cache.get('parser_version') != PARSER_VERSION
                or cache.get('csv_headers') != CSV_HEADERS
                or not isinstance(cache.get('entries'), dict)):
            return None
        if (cache.get('anunturi_sha256') != _file_sha256(csv_path)
                or cache.get('calendar_sha256') != _file_sha256(calendar_path)):
            return None
        return cache
    except (OSError, ValueError, AttributeError):
        return None


def _page_key(file_path, sidecar):
    """Everything outside the HTML bytes that the parsed row depends on.

    `content_hash` stands for the HTML (sidecar `bytes` is checked against the
    file size as a cheap guard against a page/sidecar pair torn by a crash);
    the rest is what `extract_job_details` takes from elsewhere: the posting URL
    (slug index), the index CSV's dates and the sidecar's status. `fetched_at`
    is a pure pass-through, so it is overlaid on reuse rather than keyed.
    Returns None when the page has no usable hash (legacy) — always parse.
    """
    content_hash = (sidecar or {}).get('content_hash') or ''
    if not content_hash:
        return None
    size = (sidecar or {}).get('bytes')
    try:
        if size is not None and int(size) != os.path.getsize(file_path):
            return None
    except (OSError, ValueError, TypeError):
        return None
    src_url = source_url_from_path(file_path)
    return {
        'url': src_url,
        'hash': content_hash,
        'index': list(_try_index_lookup(src_url)),
        'status': (sidecar or {}).get('status', '') or '',
    }


def process_html_files(directory, output_csv_path, calendar_csv_path=None,
                       full=False, progress_every=500, log=print):
    """Parse the HTML cache into the two CSVs, reusing unchanged pages.

    A page is reused — its previous `anunturi.csv` row and `calendar.csv` rows
    copied verbatim — when its key (`_page_key`) equals the one recorded by the
    last run, which was made by this `PARSER_VERSION` for the CSVs on disk.
    Everything else is parsed: new or changed pages, legacy pages without a
    sidecar hash, a missing/untrusted cache, `full=True`. Pages that vanished
    from the cache directory are dropped, as before. Returns a summary dict.
    """
    calendar_csv_path = calendar_csv_path or output_calendar_path
    cache_path = os.path.join(os.path.dirname(output_csv_path) or '.', CACHE_NAME)
    started = time.monotonic()

    # Loaded even for --full, so "removed" is reported either way; only an
    # incremental run reads the old rows and reuses them.
    old_cache = _load_cache(cache_path, output_csv_path, calendar_csv_path)
    old_entries = (old_cache or {}).get('entries', {})
    old_rows = old_cal = None
    if old_cache is not None and not full:
        old_rows = _read_rows(output_csv_path)
        old_cal = _read_rows(calendar_csv_path)
        if old_rows is None or old_cal is None:
            old_entries = {}

    html_files = find_html_files(directory)
    total = len(html_files)
    rows, cal_rows, entries = [], [], {}
    parsed = reused = 0
    for n, html_file in enumerate(html_files, 1):
        rel = os.path.relpath(html_file, directory)
        sidecar = _read_sidecar(html_file)
        key = _page_key(html_file, sidecar)
        prev = old_entries.get(rel)
        reuse_ok = False
        if (not full and old_rows is not None and key is not None
                and prev is not None and prev.get('key') == key):
            r0, n_cal = prev.get('row'), prev.get('cal')
            c0 = prev.get('cal_start')
            if (isinstance(r0, int) and 0 <= r0 < len(old_rows)
                    and isinstance(c0, int) and isinstance(n_cal, int)
                    and 0 <= c0 and c0 + n_cal <= len(old_cal)):
                row = dict(old_rows[r0])
                row['Detail Fetched At'] = (sidecar or {}).get('fetched_at', '') or ''
                page_cal = old_cal[c0:c0 + n_cal]
                reuse_ok = True
        if reuse_ok:
            reused += 1
        else:
            d = extract_job_details(html_file)
            row, page_cal = job_row(d), calendar_csv_rows(d)
            parsed += 1
        # Legacy pages (key None) are recorded too, so a vanished one is
        # counted as removed; they are never reused.
        entries[rel] = {'key': key, 'row': len(rows),
                        'cal_start': len(cal_rows), 'cal': len(page_cal)}
        rows.append(row)
        cal_rows.extend(page_cal)
        if progress_every and n % progress_every == 0:
            log(f"parse: {n}/{total} files (parsed {parsed}, reused {reused}, "
                f"{time.monotonic() - started:.0f}s)")

    removed = len(set(old_entries) - set(entries))
    _write_csv(output_csv_path, CSV_HEADERS, rows)
    _write_csv(calendar_csv_path, CALENDAR_HEADERS, cal_rows)

    cache = {
        'parser_version': PARSER_VERSION,
        'csv_headers': CSV_HEADERS,
        'anunturi_sha256': _file_sha256(output_csv_path),
        'calendar_sha256': _file_sha256(calendar_csv_path),
        'entries': entries,
    }
    tmp = cache_path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(cache, f, ensure_ascii=False, separators=(',', ':'))
    os.replace(tmp, cache_path)

    summary = {'files': total, 'parsed': parsed, 'reused': reused,
               'removed': removed, 'full': bool(full),
               'seconds': round(time.monotonic() - started, 1)}
    log(f"parse: parsed {parsed}, reused {reused}, removed {removed} "
        f"({total} files, {'full' if full else 'incremental'}, {summary['seconds']}s)")
    return summary


def main(argv=None):
    ap = argparse.ArgumentParser(description="Parse the cached posting pages into anunturi.csv + calendar.csv.")
    ap.add_argument('--full', action='store_true',
                    help="re-parse every page, ignoring the reuse cache (use after a parser change that did not bump PARSER_VERSION)")
    ap.add_argument('--input-dir', default=folder_path, help="HTML cache directory (default: %(default)s)")
    ap.add_argument('--out-dir', default=None,
                    help="write anunturi.csv, calendar.csv and the reuse cache here instead of the default data/ paths")
    ap.add_argument('--progress-every', type=int, default=500, metavar='N',
                    help="print a progress line every N files (0 = none; default %(default)s)")
    args = ap.parse_args(argv)

    if args.out_dir:
        csv_path = os.path.join(args.out_dir, 'anunturi.csv')
        cal_path = os.path.join(args.out_dir, 'calendar.csv')
    else:
        csv_path, cal_path = output_csv_path, output_calendar_path
    process_html_files(args.input_dir, csv_path, cal_path,
                       full=args.full, progress_every=args.progress_every)
    print(f"Data saved to {csv_path}")
    print(f"Calendar saved to {cal_path}")


if __name__ == "__main__":
    # Guarded so the module can be imported for its functions. Without this,
    # `import parse_anunturi` re-parsed all 9,691 cached pages and rewrote both
    # CSVs as a side effect — which is what a test importing it did, and what
    # an interactive import did before that.
    main()


folder_path = 'data/anunturi'
output_csv_path = 'data/anunturi/anunturi.csv'
output_calendar_path = 'data/calendar.csv'

import csv
import os
import re
import glob
from bs4 import BeautifulSoup
from markdownify import markdownify as md

DATE_RE = re.compile(r'\b(\d{1,2}\.\d{2}\.\d{4})\b')
TIME_RE = re.compile(r'ora\s+(\d{1,2}[:.]\d{2})', re.IGNORECASE)
PHONE_RE = re.compile(r'\b(0[0-9]{9})\b')
EMAIL_RE = re.compile(r'[\w.+-]+@[\w.-]+\.[a-z]{2,}')
NR_POSTURI_RE = re.compile(r'(\d+)\s+post(?:uri|ul)?\b', re.IGNORECASE)


def extract_card_fields(soup):
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
        if eveniment:
            rows.append((eveniment, date_str, time_str))
    return rows


def extract_contact(body_text):
    phones = PHONE_RE.findall(body_text)
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


def _find_calendar_date(calendar_rows, keywords):
    for eveniment, data, ora in calendar_rows:
        if any(kw.lower() in eveniment.lower() for kw in keywords):
            return data + (f', ora {ora}' if ora else '')
    return ''


def extract_job_details(file_path):
    with open(file_path, 'r', encoding='utf-8') as f:
        html_content = f.read()

    soup = BeautifulSoup(html_content, 'html.parser')

    try:
        job_title = soup.select_one('.titlu h1').text.strip()
    except AttributeError:
        job_title = ''

    try:
        employer = soup.select_one('.caseta .ang').text.strip()
    except AttributeError:
        employer = ''

    card = extract_card_fields(soup)
    location = card.get('locatie', '')
    nivel = card.get('nivel', '')
    tip = card.get('tip', '')
    tip_angajator = card.get('angajator', '')
    categorie = card.get('categorie', '')

    announcement_link_tag = soup.find('a', string='Anunt')
    announcement_url = announcement_link_tag['href'] if announcement_link_tag else ''

    main_body_markdown = ''
    if announcement_link_tag:
        main_body_html = ''
        for sibling in announcement_link_tag.find_all_next():
            if sibling.name == 'nav':
                break
            main_body_html += str(sibling)
        main_body_markdown = md(main_body_html).replace('\n', '\\n')

    other_links = []
    for link in soup.find_all('a', href=True):
        url = link['href']
        if url.startswith('https://posturi.gov.ro/wp-content/uploads/') and url != announcement_url:
            other_links.append(url)

    body_soup = soup.select_one('.entry-content')
    body_text = body_soup.get_text('\n') if body_soup else ''

    contact_telefon, contact_email, contact_persoana = extract_contact(body_text)
    nr_posturi_m = NR_POSTURI_RE.search(body_text[:2000])
    nr_posturi = nr_posturi_m.group(1) if nr_posturi_m else ''

    calendar_rows = extract_calendar(body_soup)
    data_limita = _find_calendar_date(calendar_rows, ['depunere', 'inscriere', 'dosar', 'limita'])
    data_scrisa = _find_calendar_date(calendar_rows, ['scrisa', 'scrisă', 'scris'])
    data_interviu = _find_calendar_date(calendar_rows, ['interviu'])
    data_rezultate = _find_calendar_date(calendar_rows, ['final', 'rezultat final', 'rezultate finale'])

    return {
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
        '_calendar_rows': calendar_rows,
    }


def save_to_csv(data_list, path):
    headers = [
        'Job Title', 'Employer', 'Location', 'Job Level', 'Job Type',
        'Employer Category', 'Categorie', 'Announcement URL',
        'Main Body Markdown', 'Other Links',
        'Nr Posturi', 'Contact Telefon', 'Contact Email', 'Contact Persoana',
        'Data Limita Depunere', 'Data Proba Scrisa', 'Data Interviu', 'Data Rezultate Finale',
    ]
    with open(path, mode='w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=headers)
        writer.writeheader()
        for d in data_list:
            writer.writerow({
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
            })


def save_calendar(data_list, path):
    with open(path, mode='w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=['url', 'eveniment', 'data', 'ora'])
        writer.writeheader()
        for d in data_list:
            url = d['announcement_url'] or ''
            for eveniment, data_str, ora in d['_calendar_rows']:
                writer.writerow({'url': url, 'eveniment': eveniment, 'data': data_str, 'ora': ora})


def find_html_files(directory):
    return glob.glob(os.path.join(directory, '**/*.html'), recursive=True)


def process_html_files(directory, output_csv_path):
    html_files = find_html_files(directory)
    all_job_details = []
    for html_file in html_files:
        job_details = extract_job_details(html_file)
        all_job_details.append(job_details)
    save_to_csv(all_job_details, output_csv_path)
    save_calendar(all_job_details, output_calendar_path)


process_html_files(folder_path, output_csv_path)
print(f"Data saved to {output_csv_path}")
print(f"Calendar saved to {output_calendar_path}")

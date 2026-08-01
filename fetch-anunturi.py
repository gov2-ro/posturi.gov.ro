indexcsv = 'data/posturi_gov_ro.csv'
base_url = "https://posturi.gov.ro"

import csv, os, time, requests, random, re
from bs4 import BeautifulSoup
from urllib.parse import urlparse
from datetime import datetime
from tqdm import tqdm
from requests.exceptions import RequestException

ROMANIAN_MONTHS = {
    'ianuarie': '01', 'februarie': '02', 'martie': '03', 'aprilie': '04',
    'mai': '05', 'iunie': '06', 'iulie': '07', 'august': '08',
    'septembrie': '09', 'octombrie': '10', 'noiembrie': '11', 'decembrie': '12'
}


def parse_romanian_date(date_string):
    """Parse date strings from the index CSV's 'publicat_in' field.

    Handles two formats:
      Old: 'Publicat în: 9 septembrie,2024'
      New: 'Data publicării: 31.07.2026'
    """
    date_string = date_string.strip()

    # New format: "Data publicării: DD.MM.YYYY"
    m = re.match(r'Data public[aă]rii:\s*(\d{2})\.(\d{2})\.(\d{4})', date_string)
    if m:
        return f"{m.group(3)}/{m.group(2)}/{m.group(1)}"

    # Old format: "Publicat în: D luna,an"
    date_string = date_string.replace('Publicat în: ', '').replace('Publicat in: ', '')
    try:
        day, month_year = date_string.split(' ', 1)
        month, year = month_year.split(',')
        month_num = ROMANIAN_MONTHS[month.lower()]
        date_obj = datetime(int(year), int(month_num), int(day))
        return date_obj.strftime('%Y/%m/%d')
    except (ValueError, KeyError):
        # Fallback: try DD.MM.YYYY format without prefix
        m = re.match(r'(\d{2})\.(\d{2})\.(\d{4})', date_string)
        if m:
            return f"{m.group(3)}/{m.group(2)}/{m.group(1)}"
        print(f"Warning: could not parse date: {date_string}")
        return datetime.now().strftime('%Y/%m/%d')


def fetch_html(url, max_retries=3, base_delay=5):
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.5',
        'Referer': base_url,
        'Connection': 'keep-alive',
        'Upgrade-Insecure-Requests': '1',
        'Cache-Control': 'max-age=0',
        'TE': 'Trailers'
    }

    for attempt in range(max_retries):
        try:
            response = requests.get(url, headers=headers, timeout=30)
            response.raise_for_status()
            return response.text
        except RequestException as e:
            if attempt < max_retries - 1:
                delay = base_delay * (2 ** attempt) + random.uniform(0, 1)
                tqdm.write(f"Error fetching {url}: {str(e)}. Retrying in {delay:.2f} seconds...")
                time.sleep(delay)
            else:
                tqdm.write(f"Failed to fetch {url} after {max_retries} attempts: {str(e)}")
                return None


def extract_main_content(html):
    """Extract the main content area from a detail page.

    Handles both old and new page structures.
    New: <div class="pg-wrap pg-job-single pg-saas"> (the full job detail wrapper)
    Old: <main id="main" class="site-main">
    Falls back to full HTML if neither is found.
    """
    if html is None:
        return ''
    soup = BeautifulSoup(html, 'html.parser')

    # New site: pg-wrap container with all job cards
    pg_wrap = soup.select_one('div.pg-wrap.pg-job-single')
    if pg_wrap:
        return str(pg_wrap)

    # Old site: main#main.site-main
    main_content = soup.find('main', id='main', class_='site-main')
    if main_content:
        return str(main_content)

    # Fallback: return full HTML (stripped of scripts/styles ideally, but functional)
    return html


def get_slug(url):
    parsed_url = urlparse(url)
    path = parsed_url.path.strip('/')
    return path.split('/')[-1] if path else 'index'


def create_directory(date_str):
    directory = os.path.join('data', 'anunturi', date_str)
    os.makedirs(directory, exist_ok=True)
    return directory


def save_html(content, directory, filename):
    file_path = os.path.join(directory, f"{filename}.html")
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(content)


def file_exists(directory, filename):
    file_path = os.path.join(directory, f"{filename}.html")
    return os.path.exists(file_path)


def process_csv(csv_path):
    with open(csv_path, 'r', encoding='utf-8') as csvfile:
        reader = csv.DictReader(csvfile)
        rows = list(reader)

        new_rows = []
        for row in rows:
            url = row['url']
            date_str = row['publicat_in']
            try:
                formatted_date = parse_romanian_date(date_str)
            except Exception:
                formatted_date = datetime.now().strftime('%Y/%m/%d')
            slug = get_slug(url)
            directory = create_directory(formatted_date)

            if not file_exists(directory, slug):
                new_rows.append(row)
            else:
                print(f"Skipping already processed: {url}")

        if not new_rows:
            print("No new URLs to process.")
            return

        print(f"Processing {len(new_rows)} new URLs...")
        for row in tqdm(new_rows, desc="Processing new URLs", unit="URL"):
            url = row['url']
            date_str = row['publicat_in']
            try:
                formatted_date = parse_romanian_date(date_str)
            except Exception:
                formatted_date = datetime.now().strftime('%Y/%m/%d')
            slug = get_slug(url)
            directory = create_directory(formatted_date)

            html = fetch_html(url)
            if html:
                main_content = extract_main_content(html)
                save_html(main_content, directory, slug)
                tqdm.write(f"Processed: {url}")
                sleep_time = random.uniform(2, 5)
                time.sleep(sleep_time)
            else:
                tqdm.write(f"Failed to process: {url}")


if __name__ == "__main__":
    csv_path = indexcsv
    process_csv(csv_path)

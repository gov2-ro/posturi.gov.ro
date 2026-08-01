import requests, csv, random, time, os, re, unicodedata
from bs4 import BeautifulSoup
from datetime import datetime

output_csv = "data/posturi_gov_ro.csv"
base_url = "https://posturi.gov.ro"
listing_url = f"{base_url}/toate-posturile/"
fieldnames = ['pozitie', 'url', 'angajator', 'detalii', 'publicat_in', 'expira_in', 'judet', 'url_judet', 'tip', 'updates']

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.5',
    'Referer': base_url,
    'Connection': 'keep-alive',
    'Upgrade-Insecure-Requests': '1',
    'Cache-Control': 'max-age=0',
    'TE': 'Trailers'
}


def slugify_county(name):
    """Convert a Romanian county display name to the pg_city slug format.

    e.g. 'Bistrița-Năsăud' → 'bistrita-nasaud', 'Satu Mare' → 'satu-mare'
    """
    # Decompose unicode and strip diacritics
    nfkd = unicodedata.normalize('NFKD', name)
    ascii_name = ''.join(c for c in nfkd if not unicodedata.combining(c))
    # Lowercase, replace non-alphanumeric with hyphens, collapse hyphens
    slug = re.sub(r'[^a-z0-9]+', '-', ascii_name.lower()).strip('-')
    return slug


def load_existing_data():
    try:
        existing_data = {}
        with open(output_csv, "r", newline="", encoding="utf-8") as csv_file:
            reader = csv.DictReader(csv_file)
            for row in reader:
                existing_data[row['url']] = row
        return existing_data
    except FileNotFoundError:
        return {}


def save_data(jobs):
    os.makedirs(os.path.dirname(output_csv), exist_ok=True)
    with open(output_csv, "w", newline="", encoding="utf-8") as csv_file:
        writer = csv.DictWriter(csv_file, fieldnames=fieldnames)
        writer.writeheader()
        for job in jobs.values():
            writer.writerow(job)
    print(f"Data saved to {output_csv}")


def write_header():
    if not os.path.exists(output_csv) or os.stat(output_csv).st_size == 0:
        os.makedirs(os.path.dirname(output_csv), exist_ok=True)
        with open(output_csv, "w", newline="", encoding="utf-8") as csv_file:
            writer = csv.DictWriter(csv_file, fieldnames=fieldnames)
            writer.writeheader()


def get_total_pages():
    """Discover the total number of listing pages from pagination nav."""
    url = f"{listing_url}?pg_page=1"
    response = requests.get(url, timeout=30, headers=HEADERS)
    soup = BeautifulSoup(response.content, 'html.parser')

    pagi_nav = soup.select_one('nav.pg-arc-pagi')
    if not pagi_nav:
        print("Warning: pagination nav not found, assuming 1 page")
        return 1

    # Find all numeric page links, excluding prev/next/dots
    page_links = pagi_nav.select('a.page-numbers')
    max_page = 1
    for link in page_links:
        if 'prev' in link.get('class', []) or 'next' in link.get('class', []):
            continue
        text = link.text.strip()
        if text.isdigit():
            max_page = max(max_page, int(text))

    # Also check the current page span
    current_span = pagi_nav.select_one('span.page-numbers.current')
    if current_span and current_span.text.strip().isdigit():
        # Current page might be higher than linked pages (e.g. if on last page)
        pass

    print(f"Discovered {max_page} pages")
    return max_page


def compare_and_update(existing_job, new_job):
    updates = []
    for key in new_job:
        if key != 'updates' and key in existing_job and existing_job[key] != new_job[key]:
            updates.append(key)

    if updates:
        update_info = f"{datetime.now().strftime('%Y-%m-%d')}: {', '.join(updates)}"
        if existing_job.get('updates'):
            existing_job['updates'] += f"; {update_info}"
        else:
            existing_job['updates'] = update_info

        for key in updates:
            existing_job[key] = new_job[key]
        return existing_job, True
    else:
        return existing_job, False


def scrape_and_save_page(page_number, existing_data):
    """Scrape a single listing page, updating existing_data in place."""
    url = f"{listing_url}?pg_page={page_number}"
    response = requests.get(url, headers=HEADERS, timeout=30)
    soup = BeautifulSoup(response.content, 'html.parser')

    new_entries = 0
    updated_entries = 0

    cards = soup.select('article.pg-card')

    for card in cards:
        job = {}

        # Title
        title_el = card.select_one('div.pg-card-h')
        job['pozitie'] = title_el.text.strip() if title_el else ''

        # URL
        link_el = card.select_one('a.pg-card-link')
        job['url'] = link_el['href'] if link_el else ''

        # Employer
        inst_el = card.select_one('div.pg-card-inst')
        job['angajator'] = inst_el.text.strip() if inst_el else ''

        # Tags (detalii): join all tag spans
        tag_els = card.select('span.pg-tag')
        job['detalii'] = ', '.join(t.text.strip() for t in tag_els)

        # Published date
        pub_el = card.select_one('div.pg-card-published')
        job['publicat_in'] = pub_el.text.strip() if pub_el else ''

        # Expiration (relative: "X zile rămase")
        deadline_el = card.select_one('div.pg-card-deadline')
        job['expira_in'] = deadline_el.text.strip() if deadline_el else ''

        # County (display name from the city badge span)
        city_el = card.select_one('div.pg-card-city span')
        county_display = city_el.text.strip() if city_el else ''
        job['judet'] = county_display

        # County filter URL
        if county_display:
            job['url_judet'] = f"{base_url}/toate-posturile/?pg_city={slugify_county(county_display)}"
        else:
            job['url_judet'] = ''

        # Type (permanence: last tag — "Permanent" or "Temporar")
        if tag_els:
            # The last tag is usually the permanence indicator
            last_tag = tag_els[-1].text.strip()
            # Check class for more precision
            tag_classes = ' '.join(tag_els[-1].get('class', []))
            if 'permanent' in tag_classes.lower() or 'temporar' in tag_classes.lower():
                job['tip'] = last_tag
            else:
                # Fallback: use last tag text
                job['tip'] = last_tag
        else:
            job['tip'] = ''

        # Track changes
        if job['url'] in existing_data:
            existing_job = existing_data[job['url']]
            updated_job, was_updated = compare_and_update(existing_job, job)
            existing_data[job['url']] = updated_job
            if was_updated:
                updated_entries += 1
        else:
            job['updates'] = f"{datetime.now().strftime('%Y-%m-%d')}: New entry"
            existing_data[job['url']] = job
            new_entries += 1

    unchanged = len(cards) - new_entries - updated_entries
    print(f"Page {page_number}: {new_entries} new, {updated_entries} updated, {unchanged} unchanged ({len(cards)} total)")
    return new_entries, updated_entries


def scrape_all_pages():
    existing_data = load_existing_data()
    max_pages = get_total_pages()
    total_new = 0
    total_updated = 0
    skip_count = 0
    seen_any_change = False

    for page_number in range(1, max_pages + 1):
        print(f"Scraping page {page_number}/{max_pages}...")
        new_entries, updated_entries = scrape_and_save_page(page_number, existing_data)
        total_new += new_entries
        total_updated += updated_entries

        if new_entries or updated_entries:
            save_data(existing_data)
            skip_count = 0
            seen_any_change = True
        else:
            skip_count += 1
            # Only stop early if we've seen at least one change this run
            # (prevents stopping on pages 1-3 when re-running after a partial scrape)
            if seen_any_change and skip_count >= 3:
                print(f"No changes on last {skip_count} pages, stopping early.")
                break

        time.sleep(random.uniform(0.5, 1.1))

    print(f"Scraping complete. Total: {total_new} new, {total_updated} updated")
    return existing_data


if __name__ == "__main__":
    write_header()
    scrape_all_pages()

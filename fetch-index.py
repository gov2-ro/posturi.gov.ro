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


#: Remembered page count, so a windowed pagination cannot silently truncate a run.
page_count_path = "data/.last_page_count"


def _read_last_page_count():
    try:
        with open(page_count_path, encoding="utf-8") as fh:
            return int(fh.read().strip())
    except (OSError, ValueError):
        return None


def _check_page_count(max_page):
    """Abort when the listing appears to have lost half its pages overnight.

    `get_total_pages()` takes the largest numeric link out of the pagination nav.
    WordPress renders a *window* of page links, and it only happens to include the
    last page today; if that ever changes the scrape would quietly stop a few pages
    in and every posting past the cut would look deleted. A listing genuinely
    shrinking by half between two runs a few hours apart is not a thing, so treat it
    as a scraper failure rather than as data. Set FETCH_INDEX_ALLOW_SHRINK=1 to
    override after checking the site by hand.
    """
    previous = _read_last_page_count()
    if (
        previous
        and max_page < previous * 0.5
        and os.environ.get("FETCH_INDEX_ALLOW_SHRINK") != "1"
    ):
        raise SystemExit(
            f"ERROR: pagination reports {max_page} pages, down from {previous} on the "
            f"last run. Refusing to scrape a truncated listing — check "
            f"{listing_url} by hand, then re-run with FETCH_INDEX_ALLOW_SHRINK=1 if "
            f"the drop is real."
        )
    os.makedirs(os.path.dirname(page_count_path), exist_ok=True)
    with open(page_count_path, "w", encoding="utf-8") as fh:
        fh.write(str(max_page))


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
    _check_page_count(max_page)
    return max_page


#: The redesigned index renders a relative countdown ("6 zile rămase") where the old
#: one printed a date, so the raw string changes every day for every live posting.
#: Diffing it verbatim logged a spurious `expira_in` change on all ~9,600 rows on every
#: run, rewrote the whole CSV on every page, and defeated the unchanged-page early stop
#: below — which is what makes a twice-daily cron affordable. Two countdowns compare
#: equal; "Anunț anulat" is outside the family, so a cancellation is still a real change.
COUNTDOWN_RE = re.compile(
    r'^\s*(\d+\s+zi(?:le)?\s+r[ăa]mas[ăae]|ultima\s+zi)\s*$',
    re.IGNORECASE,
)


def is_countdown(value):
    """True for the relative expiry strings the post-redesign index renders."""
    return bool(COUNTDOWN_RE.match(value or ''))


def values_differ(key, old_value, new_value):
    """Field-aware comparison for compare_and_update()."""
    if old_value == new_value:
        return False
    if key == 'expira_in' and is_countdown(old_value) and is_countdown(new_value):
        return False  # the clock ticked, the posting did not change
    return True


def compare_and_update(existing_job, new_job):
    updates = []
    for key in new_job:
        if key != 'updates' and key in existing_job and values_differ(
            key, existing_job[key], new_job[key]
        ):
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

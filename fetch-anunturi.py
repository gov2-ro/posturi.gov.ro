indexcsv = 'data/posturi_gov_ro.csv'
base_url = "https://posturi.gov.ro"

import csv, os, time, requests, random, re, json, hashlib
from bs4 import BeautifulSoup
from urllib.parse import urlparse
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from posting_urls import slug_for_url
from tqdm import tqdm
from requests.exceptions import RequestException

ROMANIAN_MONTHS = {
    'ianuarie': '01', 'februarie': '02', 'martie': '03', 'aprilie': '04',
    'mai': '05', 'iunie': '06', 'iulie': '07', 'august': '08',
    'septembrie': '09', 'octombrie': '10', 'noiembrie': '11', 'decembrie': '12'
}

#: Refetch a cached page whose successful retrieval is older than this many
#: hours. Cached pages without a sidecar (legacy, pre-FIX-03) count as due —
#: their age is unknown, which is not the same as fresh.
DEFAULT_REFRESH_HOURS = 24

#: Ceiling on re-fetches per run, so the bounded refresh policy cannot turn
#: into a full-archive crawl on a day when everything is due at once.
DEFAULT_MAX_REFRESH = 200

#: Competitions whose index expiry is further back than this are not refreshed:
#: nothing on the site depends on them any more, and the archive is ~10k rows,
#: so without the cut they would spend the cap that open postings need.
DEFAULT_REFRESH_EXPIRED_DAYS = 30

#: Exit nonzero when more than this share of attempted fetches failed (and at
#: least MIN_ATTEMPTS_FOR_FAILURE were attempted) — the FIX-04 convention, so a
#: blocked or broken source cannot pass as a healthy run.
DEFAULT_MAX_FAILURE_SHARE = 0.5
MIN_ATTEMPTS_FOR_FAILURE = 5


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


class FetchFailed(Exception):
    """A fetch that did not yield usable detail content. Carries whether the
    failure is terminal for this URL (404/410) or transient."""

    def __init__(self, message, terminal=False):
        super().__init__(message)
        self.terminal = terminal


def fetch_html(url, max_retries=3, base_delay=5):
    """Fetch a detail page. Returns the response text, or raises FetchFailed.

    A 404/410 (the permalink is gone) is terminal: retrying will not help, and
    the old cache — if any — stays authoritative. Network errors and 5xx are
    transient and retried with backoff.
    """
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
            if response.status_code in (404, 410):
                raise FetchFailed(f"HTTP {response.status_code}", terminal=True)
            response.raise_for_status()
            return response.text
        except FetchFailed:
            raise
        except RequestException as e:
            if attempt < max_retries - 1:
                delay = base_delay * (2 ** attempt) + random.uniform(0, 1)
                tqdm.write(f"Error fetching {url}: {str(e)}. Retrying in {delay:.2f} seconds...")
                time.sleep(delay)
            else:
                tqdm.write(f"Failed to fetch {url} after {max_retries} attempts: {str(e)}")
                raise FetchFailed(str(e))


def extract_main_content(html):
    """Extract the main content area from a detail page.

    Handles both old and new page structures.
    New: <div class="pg-wrap pg-job-single pg-saas"> (the full job detail wrapper)
    Old: <main id="main" class="site-main">
    Returns ('', False) when neither expected structure is present — a login
    page or a portal redirect must not overwrite a good cache.
    """
    if html is None:
        return '', False
    soup = BeautifulSoup(html, 'html.parser')

    # New site: pg-wrap container with all job cards
    pg_wrap = soup.select_one('div.pg-wrap.pg-job-single')
    if pg_wrap:
        return str(pg_wrap), True

    # Old site: main#main.site-main
    main_content = soup.find('main', id='main', class_='site-main')
    if main_content:
        return str(main_content), True

    return '', False


def extract_detail_status(html):
    """The detail page's own status marker: 'anulat' | 'live' | '' (unknown).

    New site: an element with class `.pg-status`, carrying `is-off` (withdrawn
    — "Anulat") or `is-live`. Unknown markup is unknown — '' — never
    implicitly live; callers decide how to fall back.
    """
    if not html:
        return ''
    soup = BeautifulSoup(html, 'html.parser')
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


def get_slug(url):
    """Cache filename for a posting URL — see posting_urls.slug_for_url."""
    return slug_for_url(url)


def create_directory(date_str):
    directory = os.path.join('data', 'anunturi', date_str)
    os.makedirs(directory, exist_ok=True)
    return directory


def _cache_paths(directory, slug):
    html_path = os.path.join(directory, f"{slug}.html")
    meta_path = os.path.join(directory, f"{slug}.meta.json")
    return html_path, meta_path


def _read_meta(meta_path):
    """Sidecar contents, or None when absent/corrupt (legacy cache → unknown)."""
    try:
        with open(meta_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        return data if isinstance(data, dict) else None
    except (OSError, ValueError):
        return None


def _write_meta(meta_path, record):
    """Atomic sidecar write: temp file + rename, like the HTML itself."""
    tmp = meta_path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(record, f, ensure_ascii=False, indent=2)
    os.replace(tmp, meta_path)


def _meta_age_hours(record):
    try:
        fetched = datetime.fromisoformat(record['fetched_at'])
    except (KeyError, ValueError):
        return None
    if fetched.tzinfo is None:
        fetched = fetched.replace(tzinfo=timezone.utc)
    return (datetime.now(timezone.utc) - fetched).total_seconds() / 3600


def index_expiry(row):
    """The index card's expiry ("Expiră in  30/12/2026") as a date, or None."""
    m = re.search(r'(\d{1,2})[./](\d{1,2})[./](\d{4})', row.get('expira_in') or '')
    if not m:
        return None
    try:
        return date(int(m.group(3)), int(m.group(2)), int(m.group(1)))
    except ValueError:
        return None


def is_cancelled(row):
    """A withdrawn competition per the INDEX: "Anunț anulat" in the expiry slot.

    posturi.gov.ro drops the pretty permalink for these and links the bare
    `/?post_type=pg_job&p=N` form, which 404s once the post is removed
    upstream. There is nothing to fetch for an *uncached* row — skip it. The
    detail page's own `.pg-status` marker (see extract_detail_status) is the
    current authority once a cache exists.
    """
    return "anulat" in (row.get('expira_in') or '').lower()


def _save_detail(directory, slug, content, url, status, fetched_at):
    """Write the extracted detail HTML and its sidecar, both atomically.

    Called only after the fetch succeeded AND the expected structure was
    found, so a failed or malformed replacement can never destroy the
    previous good cache.
    """
    html_path, meta_path = _cache_paths(directory, slug)
    digest = hashlib.sha256(content.encode('utf-8')).hexdigest()

    tmp = html_path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(content)
    os.replace(tmp, html_path)

    _write_meta(meta_path, {
        'url': url,
        'fetched_at': fetched_at,
        'content_hash': digest,
        'bytes': len(content.encode('utf-8')),
        'status': status,
    })
    return digest


def process_csv(csv_path, refresh_hours=DEFAULT_REFRESH_HOURS,
                max_refresh=DEFAULT_MAX_REFRESH,
                refresh_expired_days=DEFAULT_REFRESH_EXPIRED_DAYS, today=None):
    """Fetch new detail pages and refresh stale ones under a bounded policy.

    Returns the summary counts. A cached page is refreshed when its sidecar
    says the last successful retrieval is older than `refresh_hours` — or when
    it has no sidecar at all (legacy cache, age unknown). At most `max_refresh`
    re-fetches happen per run; the rest wait for the next run. Due pages are
    ranked before the cap applies — open competitions first, newest first — and
    pages expired more than `refresh_expired_days` ago are not refreshed at all,
    so the cap goes to the postings a reader can still apply to.
    """
    today = today or datetime.now(ZoneInfo('Europe/Bucharest')).date()
    stale_before = today - timedelta(days=refresh_expired_days)
    summary = {
        'fetched_new': 0, 'refreshed': 0, 'changed': 0, 'unchanged': 0,
        'skipped_fresh': 0, 'skipped_cancelled': 0, 'skipped_cap': 0,
        'failed': 0, 'total': 0, 'refresh_attempted': 0, 'legacy': 0,
        'skipped_expired': 0, 'attempted': 0,
    }

    with open(csv_path, 'r', encoding='utf-8') as csvfile:
        rows = list(csv.DictReader(csvfile))

    to_fetch, due = [], []
    for row in rows:
        summary['total'] += 1
        url = row['url']
        try:
            formatted_date = parse_romanian_date(row['publicat_in'])
        except Exception:
            formatted_date = datetime.now().strftime('%Y/%m/%d')
        slug = get_slug(url)
        directory = create_directory(formatted_date)
        html_path, meta_path = _cache_paths(directory, slug)

        if not os.path.exists(html_path):
            if is_cancelled(row):
                # Dead permalink, nothing cached to refresh — skip, as before.
                summary['skipped_cancelled'] += 1
                continue
            to_fetch.append({'row': row, 'slug': slug, 'directory': directory,
                             'existing_hash': None, 'existing_status': '', 'is_new': True})
            continue

        record = _read_meta(meta_path)
        age = _meta_age_hours(record) if record else None
        if age is not None and age <= refresh_hours:
            summary['skipped_fresh'] += 1
            continue
        expiry = index_expiry(row)
        if expiry is not None and expiry < stale_before:
            summary['skipped_expired'] += 1
            continue
        due.append({'row': row, 'slug': slug, 'directory': directory,
                    'existing_hash': (record or {}).get('content_hash'),
                    'existing_status': (record or {}).get('status', ''),
                    'is_new': False,
                    # Unknown expiry ranks as open: it may well be.
                    'rank': (expiry is not None and expiry < today, formatted_date)})

    # Open first (False sorts before True), then newest publication first.
    due.sort(key=lambda d: (d['rank'][0], _neg_date_key(d['rank'][1])))
    for item in due:
        if summary['refresh_attempted'] >= max_refresh:
            summary['skipped_cap'] += 1
            continue
        to_fetch.append(item)
        summary['refresh_attempted'] += 1

    print(f"To fetch: {len(to_fetch)} ({summary['skipped_fresh']} fresh, "
          f"{summary['skipped_cancelled']} index-cancelled, "
          f"{summary['skipped_cap']} over the refresh cap, "
          f"{summary['skipped_expired']} expired >{refresh_expired_days}d)")

    for item in tqdm(to_fetch, desc="Processing URLs", unit="URL"):
        url = item['row']['url']
        slug = item['slug']
        directory = item['directory']
        summary['attempted'] += 1
        try:
            html = fetch_html(url)
            content, ok = extract_main_content(html)
            if not ok:
                raise FetchFailed("no expected detail structure (pg-wrap / main#main)")
            status = extract_detail_status(html)
            fetched_at = datetime.now(timezone.utc).isoformat(timespec='seconds')
            digest = _save_detail(directory, slug, content, url, status, fetched_at)

            if item['is_new']:
                summary['fetched_new'] += 1
                tqdm.write(f"Fetched: {url}")
            elif item['existing_hash'] is None:
                # Legacy cache with no recorded hash: refreshed, but whether the
                # content changed is unknowable — counted separately so it is
                # never mistaken for a confirmed "unchanged".
                summary['refreshed'] += 1
                summary['legacy'] += 1
                tqdm.write(f"Refreshed (legacy, previous content unknown): {url}")
            elif digest == item['existing_hash']:
                summary['refreshed'] += 1
                summary['unchanged'] += 1
                tqdm.write(f"Refreshed (unchanged): {url}")
            else:
                summary['refreshed'] += 1
                summary['changed'] += 1
                tqdm.write(f"Refreshed (changed): {url}")
            time.sleep(random.uniform(2, 5))
        except FetchFailed as e:
            summary['failed'] += 1
            tqdm.write(f"Failed to process {url}: {e}"
                       + (" — keeping the previous cache" if item['existing_hash'] else ""))

    print("Fetch summary: "
          f"{summary['fetched_new']} new, "
          f"{summary['refreshed']} refreshed ({summary['changed']} changed, "
          f"{summary['unchanged']} unchanged, {summary['legacy']} legacy), "
          f"{summary['skipped_fresh']} fresh, "
          f"{summary['skipped_cancelled']} index-cancelled, "
          f"{summary['skipped_cap']} over cap, {summary['skipped_expired']} expired, "
          f"{summary['failed']} failed")
    return summary


def _neg_date_key(formatted_date):
    """Sort key that puts later 'YYYY/MM/DD' strings first."""
    return tuple(-int(p) for p in re.findall(r'\d+', formatted_date or '')) or (0,)


def exit_code(summary, max_failure_share=DEFAULT_MAX_FAILURE_SHARE):
    """0 healthy (including nothing to do), 1 when fetching mostly failed."""
    attempted = summary['attempted']
    if attempted >= MIN_ATTEMPTS_FOR_FAILURE and summary['failed'] / attempted > max_failure_share:
        return 1
    return 0


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="Fetch posting detail pages, refreshing stale caches under a bounded policy.")
    parser.add_argument('--refresh-hours', type=float, default=DEFAULT_REFRESH_HOURS,
                        help=f"Refetch cached pages whose last successful retrieval is "
                             f"older than this many hours (default {DEFAULT_REFRESH_HOURS}).")
    parser.add_argument('--max-refresh', type=int, default=DEFAULT_MAX_REFRESH,
                        help=f"At most this many re-fetches per run (default {DEFAULT_MAX_REFRESH}).")
    parser.add_argument('--refresh-expired-days', type=int, default=DEFAULT_REFRESH_EXPIRED_DAYS,
                        help=f"Do not refresh competitions whose index expiry is more than "
                             f"this many days past (default {DEFAULT_REFRESH_EXPIRED_DAYS}).")
    parser.add_argument('--max-failure-share', type=float, default=DEFAULT_MAX_FAILURE_SHARE,
                        help=f"Exit 1 when more than this share of attempted fetches failed "
                             f"(default {DEFAULT_MAX_FAILURE_SHARE}; needs at least "
                             f"{MIN_ATTEMPTS_FOR_FAILURE} attempts).")
    parser.add_argument('--csv', default=indexcsv, help="Index CSV path.")
    args = parser.parse_args()

    result = process_csv(args.csv, refresh_hours=args.refresh_hours,
                         max_refresh=args.max_refresh,
                         refresh_expired_days=args.refresh_expired_days)
    code = exit_code(result, args.max_failure_share)
    if code:
        print(f"FAILED: {result['failed']} of {result['attempted']} detail fetches failed "
              f"(> {args.max_failure_share:.0%}).")
    raise SystemExit(code)

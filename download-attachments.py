"""Download linked attachments into the validated atomic cache.

Reads anunturi.csv, downloads each Announcement URL / Other Links entry into
data/downloads/ and reports cached/downloaded/replaced/invalid/failed counts.

The cache layout, validation and lookup live in download_manifest.py — this
script is the CLI: CSV walking, source pacing and the summary/exit status.
A batch where more than half of the attempted downloads fail or validate
badly exits nonzero (FIX-04 convention); individual failures do not.

Usage:
    python download-attachments.py [--since DAYS] [--repair-legacy] [--audit]

--repair-legacy validates legacy `<basename>` files against their URLs, moves
the valid ones into the hashed cache with manifests and records the invalid
ones in data/downloads/legacy-invalid.json. Nothing is deleted.
--audit validates every existing cache entry (hashed and legacy) and reports
the reasons without changing anything.
"""

import argparse
import random
import sys
import time
from datetime import datetime, timedelta
from pathlib import Path

import pandas as pd
from tqdm import tqdm

from download_manifest import (
    DownloadSummary,
    adopt_legacy_files,
    download_file,
    resolve_local_path,
)

download_dir = Path('data/downloads')
csv_path = Path('data/anunturi/anunturi.csv')


def collect_urls(since_days=None):
    """Every attachment URL in the CSV, in order, with a bounded --since filter."""
    df = pd.read_csv(csv_path)
    if since_days is not None:
        cutoff = datetime.now() - timedelta(days=since_days)
        df['Data Publicare'] = pd.to_datetime(df['Data Publicare'], errors='coerce')
        before = len(df)
        df = df[df['Data Publicare'] >= cutoff]
        print(f"--since {since_days}d: {len(df)} of {before} rows (published >= {cutoff.date()})")

    urls = []
    for _, row in df.iterrows():
        if pd.notna(row['Announcement URL']):
            urls.append(row['Announcement URL'])
        if pd.notna(row['Other Links']):
            urls.extend(str(row['Other Links']).split(', '))
    return urls


def run_downloads(urls):
    summary = DownloadSummary()
    for url in tqdm(urls, desc="Downloading"):
        result = download_file(url, download_dir)
        summary.record(result, url)
        if result.status in ('downloaded', 'replaced'):
            time.sleep(random.uniform(0.5, 1.1))  # source pacing, unchanged
    return summary


def run_audit(urls):
    """Validate every cache entry this corpus references; change nothing.

    Uses the deep validator (full DOCX container check) — the audit is the
    expensive one-off pass, so the per-request hot path can stay shallow.
    """
    from download_manifest import cache_key, validate_file

    seen = set()
    counts = {'cached': 0, 'cached-legacy': 0, 'invalid': 0, 'missing': 0}
    problems = []
    for url in urls:
        key = cache_key(url)
        if key in seen:
            continue
        seen.add(key)
        r = resolve_local_path(download_dir, url)
        if r.path is None:
            counts[r.status] += 1
            problems.append((url, r.reason))
            continue
        deep_ok, deep_reason = validate_file(r.path, deep=True)
        if deep_ok:
            counts[r.status] += 1
        else:
            counts['invalid'] += 1
            problems.append((url, deep_reason))
    return counts, problems


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--since', type=int, metavar='DAYS', default=None,
                        help='Only process rows published within the last N days (default: all rows)')
    parser.add_argument('--repair-legacy', action='store_true',
                        help='Validate legacy basename files, adopt valid ones into the hashed cache')
    parser.add_argument('--audit', action='store_true',
                        help='Validate existing cache entries and report; download nothing')
    args = parser.parse_args()

    urls = collect_urls(since_days=args.since)
    print(f"{len(urls)} attachment URLs in the CSV selection")

    if args.audit:
        counts, problems = run_audit(urls)
        print(f"Audit: {', '.join(f'{n} {s}' for s, n in sorted(counts.items()))}")
        for url, reason in problems[:20]:
            print(f"  {reason}: {url}")
        if len(problems) > 20:
            print(f"  …and {len(problems) - 20} more")
        # Nonzero only when nothing usable is cached at all — an inventory
        # with a few broken entries is a finding, not a failed run.
        sys.exit(0 if counts['cached'] + counts['cached-legacy'] > 0 else 1)

    if args.repair_legacy:
        counts = adopt_legacy_files(download_dir, urls)
        print(f"Legacy repair: {', '.join(f'{n} {s}' for s, n in sorted(counts.items()))}")
        report = download_dir / 'legacy-invalid.json'
        if report.exists():
            print(f"Invalid legacy files recorded in {report}")

    summary = run_downloads(urls)
    for line in summary.lines():
        print(line)
    for url, reason in summary.failures[:20]:
        print(f"  {reason}: {url}")
    if len(summary.failures) > 20:
        print(f"  …and {len(summary.failures) - 20} more")
    print("Download process completed.")
    sys.exit(1 if summary.unusable() else 0)


if __name__ == '__main__':
    main()

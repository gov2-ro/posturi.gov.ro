import argparse
import os
import random
import time
from datetime import datetime, timedelta

import pandas as pd
import requests
from tqdm import tqdm

download_dir = 'data/downloads'
csv_path = 'data/anunturi/anunturi.csv'


def download_file(url, save_dir):
    filename = os.path.basename(url)
    file_path = os.path.join(save_dir, filename)

    if os.path.exists(file_path):
        return file_path

    try:
        response = requests.get(url, timeout=30, headers={
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Referer': 'https://posturi.gov.ro/',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
            'Cache-Control': 'max-age=0',
            'TE': 'Trailers',
        }, stream=True)
        response.raise_for_status()
        with open(file_path, 'wb') as f:
            for chunk in response.iter_content(chunk_size=8192):
                if chunk:
                    f.write(chunk)
        tqdm.write(f"Downloaded: {filename}")
        return file_path
    except requests.exceptions.RequestException as e:
        tqdm.write(f"Failed to download {url}: {e}")
        return None


def download_from_csv(csv_path, download_dir, since_days=None):
    os.makedirs(download_dir, exist_ok=True)
    df = pd.read_csv(csv_path)

    if since_days is not None:
        cutoff = datetime.now() - timedelta(days=since_days)
        df['Data Publicare'] = pd.to_datetime(df['Data Publicare'], errors='coerce')
        before = len(df)
        df = df[df['Data Publicare'] >= cutoff]
        tqdm.write(f"--since {since_days}d: {len(df)} of {before} rows (published >= {cutoff.date()})")

    for _, row in tqdm(df.iterrows(), total=len(df), desc="Downloading"):
        if pd.notna(row['Announcement URL']):
            download_file(row['Announcement URL'], download_dir)

        if pd.notna(row['Other Links']):
            for link in row['Other Links'].split(', '):
                download_file(link, download_dir)
                time.sleep(random.uniform(0.5, 1.1))


def main():
    parser = argparse.ArgumentParser(description="Download attachments from anunturi.csv")
    parser.add_argument(
        '--since',
        type=int,
        metavar='DAYS',
        default=None,
        help='Only process rows published within the last N days (default: all rows)',
    )
    args = parser.parse_args()
    download_from_csv(csv_path, download_dir, since_days=args.since)
    tqdm.write("Download process completed.")


if __name__ == '__main__':
    main()
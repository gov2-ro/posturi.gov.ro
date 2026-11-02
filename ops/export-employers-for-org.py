#!/usr/bin/env python3
"""Export the posturi employers as a source for org.gov2.ro (DATA-ORG-01).

Reads the posturi SQLite export and writes one row per employer to the org.gov2.ro input
`data/posturi/employers.csv`. org.gov2.ro links each row to an institution by county and name.

Columns: slug, name, county, locality, postings, first_seen, last_seen.
- `county` and `locality` are the most frequent non-empty values over the postings of the employer.
  On a tie, the value of the newest posting wins.
- `postings`, `first_seen` and `last_seen` count and date the postings in the file. An employer
  with no posting in the file gets 0 postings, no dates, and no county.

Use a file that holds all postings (`export-to-sqlite.py` without `--active-only`).
An active-only file gives a county to about one employer in five.

Usage:
    ops/export-employers-for-org.py --db data/_dev/dev.sqlite \
        --out ../institutii-publice/data/posturi/employers.csv
"""
from __future__ import annotations

import argparse
import csv
import sqlite3
import sys
from collections import Counter
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
COLUMNS = ["slug", "name", "county", "locality", "postings", "first_seen", "last_seen"]


def top_value(values):
    """values: [(published_at, value)]. Most frequent non-empty value; a tie goes to the newest posting."""
    counts = Counter(v for _, v in values if v)
    if not counts:
        return ""
    newest = {}
    for published, v in values:
        if v and published >= newest.get(v, ""):
            newest[v] = published
    return max(counts, key=lambda v: (counts[v], newest[v]))


def export(db_path, out_path):
    con = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    employers = con.execute("SELECT id, name, slug FROM employers ORDER BY slug, id").fetchall()
    postings = {}
    for emp, county, locality, published in con.execute(
            "SELECT employer_id, judet_name, locality, COALESCE(published_at, '') "
            "FROM job_postings WHERE employer_id IS NOT NULL"):
        postings.setdefault(emp, []).append((published, (county or "").strip(), (locality or "").strip()))
    rows, no_county = [], 0
    for emp_id, name, slug in employers:
        ps = postings.get(emp_id, [])
        county = top_value([(p, c) for p, c, _ in ps])
        dates = sorted(p for p, _, _ in ps if p)
        no_county += not county
        rows.append({"slug": slug, "name": " ".join(name.split()), "county": county,
                     "locality": top_value([(p, loc) for p, _, loc in ps]), "postings": len(ps),
                     "first_seen": dates[0] if dates else "", "last_seen": dates[-1] if dates else ""})
    out_path.parent.mkdir(parents=True, exist_ok=True)
    with out_path.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS)
        w.writeheader()
        w.writerows(rows)
    return len(rows), no_county


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--db", type=Path, default=REPO / "webapp-php/posturi.sqlite")
    ap.add_argument("--out", type=Path, default=REPO.parent / "institutii-publice/data/posturi/employers.csv")
    args = ap.parse_args()
    if not args.db.exists():
        sys.exit(f"{args.db}: no such file")
    n, no_county = export(args.db, args.out)
    print(f"{args.out}: {n} employers, {no_county} without a county")


if __name__ == "__main__":
    main()

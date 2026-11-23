#!/usr/bin/env python3
"""Copy the verified employer-to-org links from the ciulei crosswalk into a committed snapshot (DATA-ORG-06).

The pipeline runs on the VPS. The ciulei checkout is on the Mac only. So this script copies
the needed columns into `data/org/employer-org.csv`, and `export-to-sqlite.py` reads that copy.

Keeps only the rows with `status` `linked` or `linked_no_cif`. Never keeps `unmatched`: its
`org_url` points to a proposed `P` record that nobody reviewed. Never copies `candidate_cifs`.
Rejects a row when `cif` is not digits only, or when `org_url` does not start with
`https://org.gov2.ro/`. Prints the counts per status and the number of rejected rows.

Usage:
    ops/build-employer-org.py --from ../ciulei/data/posturi-org/crosswalk.csv
"""
from __future__ import annotations

import argparse
import csv
import sys
from collections import Counter
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "data" / "org" / "employer-org.csv"
COLUMNS = ["slug", "status", "org_id", "cif", "org_url"]
KEEP = ("linked", "linked_no_cif")
URL_PREFIX = "https://org.gov2.ro/"


def build(rows):
    """rows: crosswalk dicts. Returns (kept rows sorted by slug, counts per status, rejected rows)."""
    kept, rejected = [], []
    counts = Counter()
    for r in rows:
        status = (r.get("status") or "").strip()
        if status not in KEEP:
            continue
        cif = (r.get("cif") or "").strip()
        url = (r.get("org_url") or "").strip()
        slug = (r.get("slug") or "").strip()
        org_id = (r.get("org_id") or "").strip()
        ok = (slug and org_id and not org_id.startswith("P") and url.startswith(URL_PREFIX)
              and (cif == "" or cif.isdigit()) and (status == "linked_no_cif" or cif != ""))
        if not ok:
            rejected.append(r)
            continue
        counts[status] += 1
        kept.append({"slug": slug, "status": status, "org_id": org_id,
                     "cif": cif if status == "linked" else "", "org_url": url})
    kept.sort(key=lambda r: r["slug"])
    return kept, counts, rejected


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--from", dest="src", required=True, help="the ciulei crosswalk.csv")
    ap.add_argument("--out", default=str(OUT))
    args = ap.parse_args(argv)
    with open(args.src, encoding="utf-8", newline="") as fh:
        rows = list(csv.DictReader(fh))
    kept, counts, rejected = build(rows)
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with open(out, "w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(kept)
    skipped = Counter((r.get("status") or "").strip() for r in rows if (r.get("status") or "").strip() not in KEEP)
    for status in KEEP:
        print(f"{status}: {counts[status]}")
    print(f"skipped by status: {dict(skipped)}")
    print(f"rejected: {len(rejected)}")
    for r in rejected[:20]:
        why = ("org_id is a proposed P record" if (r.get("org_id") or "").startswith("P")
               else "cif is not digits, or org_url is not on org.gov2.ro")
        print(f"  rejected {r.get('slug')} ({r.get('status')}): {why}", file=sys.stderr)
    print(f"wrote {len(kept)} rows to {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

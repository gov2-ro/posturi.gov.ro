#!/usr/bin/env python3
"""Build the institution snapshot for the employer posting assistant (UX-EMP-03).

Source: org.gov2.ro, data/consolidated/institutii.csv (EUPL v1.2).
Output: webapp-php/static/institutii/<judet-slug>.json (one file for each county) and index.json.

    python3 ops/build-institutii-snapshot.py --from ~/devbox/gov2/institutii-publice/data/consolidated/institutii.csv

Rules:
- The CSV `county` goes through webapp/apps/jobs/judete.py `normalize_judet()`. A row that does not map is
  counted and printed. It is not written to any file.
- A row with no name is left out. The CSV order is kept.
- The locality comes from `uat`. The script removes the type word ("Municipiul", "Comuna", "Orașul") and
  title-cases a SHOUTED name, so that "COMUNA CIUGUD" becomes "Ciugud".
- The address comes from `address`. The script removes the empty parts that the source writes
  ("Bloc/Scara ,", "Sector ,").
- `kind` is `classify.employer_kind(name)`: '' when the name fits no kind. The title picker (UX-EMP-04) uses it.
- Rebuilding is the sync with org.gov2.ro. Commit the generated files.
Standard library only.
"""
import argparse
import csv
import gzip
import importlib.util
import json
import re
import sys
import unicodedata
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import classify  # noqa: E402  (stdlib only)
OUT_DIR = ROOT / "webapp-php" / "static" / "institutii"


def load_judete():
    """judete.py loaded by path: it needs no Django."""
    spec = importlib.util.spec_from_file_location("judete_canonical", ROOT / "webapp" / "apps" / "jobs" / "judete.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["judete_canonical"] = mod
    spec.loader.exec_module(mod)
    return mod


def slug(name):
    s = unicodedata.normalize("NFKD", name)
    s = "".join(c for c in s if not unicodedata.combining(c)).lower()
    return re.sub(r"[^a-z0-9]+", "-", s).strip("-")


_TYPE_WORD = re.compile(r"^(municipiul|comuna|orasul|orașul|oraşul|sectorul)\s+", re.I)


def clean_locality(uat, judete):
    uat = (uat or "").strip()
    if not uat:
        return ""
    uat = _TYPE_WORD.sub("", uat, count=1).strip()
    return judete.titlecase_locality(uat)


_EMPTY_PART = re.compile(r"^(bloc/scara|sector|scara|bloc)\s*$", re.I)


def clean_address(addr):
    parts = [p.strip() for p in (addr or "").split(",")]
    return ", ".join(p for p in parts if p and not _EMPTY_PART.match(p))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--from", dest="src", required=True, help="path of institutii.csv")
    ap.add_argument("--out", default=str(OUT_DIR), help="output directory")
    ap.add_argument("--consolidated-at", help="date of the source (default: dataset.generated_at of metadata.json next to the CSV)")
    args = ap.parse_args()

    src = Path(args.src).expanduser()
    judete = load_judete()
    consolidated = args.consolidated_at
    meta = src.with_name("metadata.json")
    if not consolidated and meta.exists():
        consolidated = str(json.loads(meta.read_text(encoding="utf-8")).get("dataset", {}).get("generated_at", ""))[:10]
    if not consolidated:
        sys.exit("No source date: pass --consolidated-at YYYY-MM-DD.")

    by_county = {c: [] for c in judete.COUNTIES}
    unmapped = Counter()
    no_name = 0
    total = 0
    with src.open(encoding="utf-8", newline="") as fh:
        for row in csv.DictReader(fh):
            total += 1
            name = (row.get("name") or "").strip()
            if not name:
                no_name += 1
                continue
            county = judete.normalize_judet(row.get("county")).judet
            if county is None:
                unmapped[row.get("county") or ""] += 1
                continue
            by_county[county].append({
                "id": (row.get("id") or "").strip(),
                "name": name,
                "cif": (row.get("cif") or "").strip(),
                "locality": clean_locality(row.get("uat"), judete),
                "address": clean_address(row.get("address")),
                "phone": (row.get("phone") or "").strip(),
                "email": (row.get("email") or "").strip(),
                "category": (row.get("category") or "").strip(),
                "kind": classify.employer_kind(name),
            })

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for old in out.glob("*.json"):
        old.unlink()
    counts = {}
    raw_total = gz_total = 0
    kinds = Counter()
    for county in judete.COUNTIES:
        rows = by_county[county]
        text = json.dumps(rows, ensure_ascii=False, separators=(",", ":"))
        (out / (slug(county) + ".json")).write_text(text, encoding="utf-8")
        counts[county] = len(rows)
        kinds.update(r["kind"] for r in rows)
        raw_total += len(text.encode("utf-8"))
        gz_total += len(gzip.compress(text.encode("utf-8"), 9))
    index = {
        "source": "org.gov2.ro (institutii-publice, data/consolidated/institutii.csv)",
        "licence": "EUPL-1.2",
        "consolidated_at": consolidated,
        "built_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "counts": counts,
        "slugs": {c: slug(c) for c in judete.COUNTIES},
    }
    (out / "index.json").write_text(json.dumps(index, ensure_ascii=False, indent=1), encoding="utf-8")

    written = sum(counts.values())
    print(f"rows read: {total}; written: {written}; no name: {no_name}; unmapped county: {sum(unmapped.values())}")
    for value, n in unmapped.most_common():
        print(f"  unmapped county {value!r}: {n} rows")
    print("kinds: " + ", ".join(f"{k or '(none)'}={n}" for k, n in kinds.most_common()))
    print(f"files: {len(counts)} counties + index.json; size: {raw_total} bytes raw, {gz_total} bytes gzip")
    if written + no_name + sum(unmapped.values()) != total:
        sys.exit("Row count mismatch.")


if __name__ == "__main__":
    main()

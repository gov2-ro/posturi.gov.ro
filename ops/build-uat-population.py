#!/usr/bin/env python3
"""Build data/uat-populatie.csv, the population table uat.py loads (DATA-06).

Source: ro.wikipedia "Lista orașelor din România", which carries the 2021 census
(Recensământul Populației și Locuințelor, INS, 1 Dec 2021) for every municipiu and
oraș (319). Communes are not in it; add them later as extra rows.

The download is untrusted data: it is parsed, never executed. Fetch the raw
wikitext once and keep it with its revision id:

    curl -sSL -A "posturi-gov-ro-pipeline/1.0" -o raw.wikitext \\
      "https://ro.wikipedia.org/w/index.php?title=Lista_ora%C8%99elor_din_Rom%C3%A2nia&action=raw"
    curl -sSL -A "posturi-gov-ro-pipeline/1.0" \\
      "https://ro.wikipedia.org/w/api.php?action=query&prop=revisions&titles=Lista_ora%C8%99elor_din_Rom%C3%A2nia&rvprop=ids|timestamp&format=json"

    python -I ops/build-uat-population.py raw.wikitext --revid 17993785 \\
        --revdate 2026-09-14

Columns written (uat.py reads only nume, populatie; DictReader ignores the rest):
    nume, judet, populatie, resedinta_judet, an, sursa
`judet` is the canonical county from webapp/apps/jobs/judete.py; Bucharest is one
row, judet "București" (uat.py bands the six sectors by type, not by this table).
The wikitext does not say municipiu vs oraș, so there is no `tip` column.
"""
from __future__ import annotations

import argparse
import csv
import importlib.util
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "data" / "uat-populatie.csv"
SOURCE_URL = "https://ro.wikipedia.org/wiki/Lista_ora%C8%99elor_din_Rom%C3%A2nia"
VINTAGE = "2021"  # RPL 1 Dec 2021, INS table 1.03 (published 2023-05-31)


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    sys.modules[name] = mod
    spec.loader.exec_module(mod)
    return mod


judete = _load("judete_ref", ROOT / "webapp" / "apps" / "jobs" / "judete.py")
uat = _load("uat_ref", ROOT / "uat.py")

_REF = re.compile(r"<ref[^>/]*?/>|<ref[^>]*>.*?</ref>", re.S)
_COMMENT = re.compile(r"<!--.*?-->", re.S)
_LINK = re.compile(r"\[\[([^\]|]+)(?:\|([^\]]*))?\]\]")


def _clean(cell: str) -> str:
    cell = _COMMENT.sub("", _REF.sub("", cell))
    cell = re.sub(r"\{\{[^{}]*\}\}", "", cell)
    return cell.strip()


def _link_text(cell: str) -> str:
    """Display text of the first wikilink; plain text if there is none."""
    cell = _clean(cell).replace("'''", "")
    m = _LINK.search(cell)
    if m:
        return (m.group(2) if m.group(2) is not None else m.group(1)).strip()
    return cell.strip()


def parse(wikitext: str) -> list[dict]:
    start = wikitext.index('{|class="wikitable sortable"')
    end = wikitext.index("\n|}", start)
    rows = []
    for block in re.split(r"\n\|-[^\n]*\n", wikitext[start:end])[1:]:
        # Drop header block (starts with "!").
        if block.lstrip().startswith("!"):
            continue
        cells = []
        for line in block.split("\n"):
            if line.startswith("|") and not line.startswith("|}"):
                # "| align="right" | 1.2" -> the last field after a lone pipe
                body = line[1:]
                if "|" in body and not body.lstrip().startswith("'''[["):
                    # attribute prefix only when text before first pipe has "="
                    head, _, tail = body.partition("|")
                    if "=" in head and "[[" not in head:
                        body = tail
                cells.append(body)
            elif cells:
                cells[-1] += "\n" + line
        if len(cells) < 3:
            continue
        raw_name, raw_county, raw_pop = cells[0], cells[1], cells[2]
        rows.append({
            "raw_name": raw_name,
            "nume": _link_text(raw_name),
            "bold": "'''" in raw_name,
            "county_raw": _link_text(raw_county),
            "pop_raw": _clean(raw_pop),
        })
    return rows


#: Towns that are in the page's alphabetical lists but missing from its main table
#: at revision 17993785 (the table has 317 rows, the lists 319). Values are the
#: 2021 census figures quoted in each town's own ro.wikipedia article.
SUPPLEMENT = [
    {"nume": "Sebiș", "judet": "Arad", "populatie": 5410, "resedinta_judet": "nu",
     "origine": "ro.wikipedia Sebiș, rev 18045612 (2026-10-15), RPL 2021"},
    {"nume": "Turceni", "judet": "Gorj", "populatie": 6891, "resedinta_judet": "nu",
     "origine": "ro.wikipedia Turceni, rev 16965067 (2025-05-10), RPL 2021"},
]


def build(wikitext: str):
    out, failures = [], []
    for r in parse(wikitext):
        pop = re.sub(r"[.\s ]", "", r["pop_raw"])
        if not pop.isdigit():
            failures.append((r["nume"], f"population {r['pop_raw']!r}"))
            continue
        if r["nume"] == "București":
            county = "București"
        else:
            county = judete.normalize_judet(r["county_raw"]).judet
            if county is None:
                failures.append((r["nume"], f"county {r['county_raw']!r}"))
                continue
        out.append({"nume": r["nume"], "judet": county, "populatie": int(pop),
                    "resedinta_judet": "da" if r["bold"] and county != "București"
                    and r["nume"] != "București" else "nu", "origine": ""})
    have = {(uat.norm(r["nume"]), r["judet"]) for r in out}
    out += [dict(r) for r in SUPPLEMENT if (uat.norm(r["nume"]), r["judet"]) not in have]
    return out, failures


def sanity(rows, failures, wiki_rows_expected=319) -> bool:
    ok = True

    def check(label, cond, detail=""):
        nonlocal ok
        ok &= bool(cond)
        print(f"  [{'ok' if cond else 'FAIL'}] {label} {detail}")

    print(f"rows: {len(rows)}  (unparsed: {len(failures)})")
    for name, why in failures:
        print(f"  UNMAPPED {name}: {why}")
    check("row count == 319", len(rows) == wiki_rows_expected, f"({len(rows)})")
    dup = [k for k, n in Counter((uat.norm(r["nume"]), r["judet"]) for r in rows).items() if n > 1]
    check("no duplicate (nume, judet)", not dup, str(dup))
    # uat.load_populations() keys on the name alone, so a repeat across counties
    # would silently let the last row win.
    dupn = [k for k, n in Counter(uat.norm(r["nume"]) for r in rows).items() if n > 1]
    print(f"  [{'ok' if not dupn else 'warn'}] names shared across counties "
          f"(uat keys on name only; omitted from the CSV): {dupn}")
    by = {uat.norm(r["nume"]): r["populatie"] for r in rows}
    missing = [n for n in sorted(uat.OVER_200K) if by.get(n, 0) <= 200_000]
    check("all 8 uat.OVER_200K > 200,000", not missing, f"missing/low: {missing}")
    extra = sorted(n for n, p in by.items() if p > 200_000 and n not in uat.OVER_200K)
    check("no other UAT > 200,000", not extra, str(extra))
    total = sum(r["populatie"] for r in rows)
    check("total 9M..12M (2021 census: 19.0M resident, ~10M in towns)",
          9_000_000 <= total <= 12_000_000, f"({total:,})")
    check("counties all canonical",
          all(r["judet"] in judete.COUNTIES for r in rows))
    print(f"  counties covered: {len({r['judet'] for r in rows})}/42; "
          f"county seats flagged: {sum(r['resedinta_judet'] == 'da' for r in rows)}")
    print("  spot checks (2021 census):")
    for n, lo, hi in [("București", 1_700_000, 1_730_000), ("Cluj-Napoca", 280_000, 290_000),
                      ("Iași", 265_000, 275_000), ("Turda", 40_000, 50_000),
                      ("Ianca", 7_000, 12_000), ("Oradea", 180_000, 186_000)]:
        p = by.get(uat.norm(n))
        check(f"{n}", p is not None and lo <= p <= hi, f"-> {p}")
    return ok


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("wikitext", type=Path, help="saved raw wikitext")
    ap.add_argument("--revid", default="", help="Wikipedia revision id of the download")
    ap.add_argument("--revdate", default="", help="revision timestamp (YYYY-MM-DD)")
    ap.add_argument("--out", type=Path, default=OUT)
    ap.add_argument("--no-write", action="store_true")
    args = ap.parse_args()

    rows, failures = build(args.wikitext.read_text(encoding="utf-8"))
    print(f"source: {SOURCE_URL}\n  revision {args.revid or '?'} ({args.revdate or '?'}), "
          f"population vintage: census {VINTAGE}")
    ok = sanity(rows, failures)
    if not ok:
        print("sanity checks FAILED; not writing", file=sys.stderr)
        return 1
    if args.no_write:
        return 0
    sursa = f"ro.wikipedia Lista orașelor din România, rev {args.revid}, RPL {VINTAGE}"
    # uat.load_populations() keys on the normalised name alone, so a name shared by
    # two towns would let the last row win and give one of them the other's band.
    # Leave both out: uat.py then falls back to the honest type-based band set.
    shared = {k for k, n in Counter(uat.norm(r["nume"]) for r in rows).items() if n > 1}
    omitted = [r for r in rows if uat.norm(r["nume"]) in shared]
    rows = [r for r in rows if uat.norm(r["nume"]) not in shared]
    for r in omitted:
        print(f"omitted (ambiguous name): {r['nume']} / {r['judet']} / {r['populatie']}")
    rows.sort(key=lambda r: (r["judet"], r["nume"]))
    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, ["nume", "judet", "populatie", "resedinta_judet", "an", "sursa"])
        w.writeheader()
        for r in rows:
            w.writerow({**{k: r[k] for k in ("nume", "judet", "populatie", "resedinta_judet")},
                        "an": VINTAGE, "sursa": r.get("origine") or sursa})
    print(f"wrote {args.out} ({len(rows)} rows)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

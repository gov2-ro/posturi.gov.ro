#!/usr/bin/env python3
"""Build data/uat-populatie.csv, the population table uat.py loads (DATA-06).

Two sources, both the 2021 census (RPL, INS, 1 Dec 2021):

  towns (319)     ro.wikipedia "Lista orașelor din România" -> `tip` = oras
                  (municipiu and oraș alike; the wikitext does not tell them apart)
  communes (2862) Wikidata, every item that is an instance of "commune of Romania"
                  (Q659103) with a population (P1082) at point in time 2021, its
                  county (P131 -> instance of county of Romania Q1776764) and SIRUTA
                  code (P843) -> `tip` = comuna. 2862 is the legal number of communes.
                  Cross-check: 58 of the 68 communes >= 9.000 quote the same figure in
                  their own ro.wikipedia article (two differ by 2-4 inhabitants, the
                  rest give no 2021 figure); no band changes either way.

The downloads are untrusted data: they are parsed, never executed. Fetch once and
keep them with their revision / retrieval date:

    curl -sSL -A "posturi-gov-ro-pipeline/1.0" -o raw.wikitext \\
      "https://ro.wikipedia.org/w/index.php?title=Lista_ora%C8%99elor_din_Rom%C3%A2nia&action=raw"
    curl -sSL -A "posturi-gov-ro-pipeline/1.0" \\
      "https://ro.wikipedia.org/w/api.php?action=query&prop=revisions&titles=Lista_ora%C8%99elor_din_Rom%C3%A2nia&rvprop=ids|timestamp&format=json"
    curl -sS -G -A "posturi-gov-ro-pipeline/1.0" -H "Accept: application/sparql-results+json" \\
      --data-urlencode query@communes.rq https://query.wikidata.org/sparql -o communes.json

where communes.rq is the query in `COMMUNES_SPARQL` below.

    python -I ops/build-uat-population.py raw.wikitext --revid 17993785 \\
        --revdate 2026-09-14 --communes communes.json --communes-date 2026-10-16

Columns written (uat.py reads nume, judet, tip, populatie):
    nume, judet, tip, populatie, resedinta_judet, an, sursa, siruta
`judet` is the canonical county from webapp/apps/jobs/judete.py; Bucharest is one
row, judet "București" (uat.py bands the six sectors by type, not by this table).
`siruta` is filled for communes only. A name is not unique (Slatina is a municipiu
in Olt and a commune in Suceava; Ștefănești is a town in two counties), so uat.py
matches on `tip` and, when it knows it, the county; this script keeps every row.
"""
from __future__ import annotations

import argparse
import csv
import importlib.util
import json
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "data" / "uat-populatie.csv"
SOURCE_URL = "https://ro.wikipedia.org/wiki/Lista_ora%C8%99elor_din_Rom%C3%A2nia"
VINTAGE = "2021"  # RPL 1 Dec 2021, INS table 1.03 (published 2023-05-31)
COMMUNES_SOURCE = "https://query.wikidata.org/ (SPARQL, Wikidata P1082 at 2021-12-01)"
COMMUNES_SPARQL = """
SELECT ?c ?cLabel ?pop ?time ?countyLabel ?siruta WHERE {
  ?c wdt:P31 wd:Q659103 .
  OPTIONAL { ?c wdt:P131 ?county . ?county wdt:P31 wd:Q1776764 . }
  ?c p:P1082 ?st . ?st ps:P1082 ?pop . ?st pq:P585 ?time .
  FILTER(YEAR(?time)=2021)
  OPTIONAL { ?c wdt:P843 ?siruta }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "ro,en". }
}
"""
EXPECTED_TOWNS = 319
EXPECTED_COMMUNES = 2862


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
        out.append({"nume": r["nume"], "judet": county, "tip": "oras", "populatie": int(pop),
                    "resedinta_judet": "da" if r["bold"] and county != "București"
                    and r["nume"] != "București" else "nu", "origine": ""})
    have = {(uat.norm(r["nume"]), r["judet"]) for r in out}
    out += [{**r, "tip": "oras"} for r in SUPPLEMENT
            if (uat.norm(r["nume"]), r["judet"]) not in have]
    return out, failures


def build_communes(raw: str):
    """Rows from the Wikidata SPARQL JSON. Returns `(rows, failures)`."""
    out, failures, seen = [], [], set()
    for b in json.loads(raw)["results"]["bindings"]:
        name = b["cLabel"]["value"]
        pop = b["pop"]["value"]
        county = judete.normalize_judet(b.get("countyLabel", {}).get("value")).judet
        if re.fullmatch(r"Q\d+", name) or not re.fullmatch(r"\d+(\.0+)?", pop) or county is None:
            failures.append((name, f"pop {pop!r} county {b.get('countyLabel')}"))
            continue
        if b["c"]["value"] in seen:
            failures.append((name, "duplicate Wikidata item"))
            continue
        seen.add(b["c"]["value"])
        out.append({"nume": name, "judet": county, "tip": "comuna", "populatie": int(float(pop)),
                    "resedinta_judet": "nu", "origine": "", "siruta": b.get("siruta", {}).get("value", "")})
    return out, failures


def _checker():
    state = {"ok": True}

    def check(label, cond, detail=""):
        state["ok"] &= bool(cond)
        print(f"  [{'ok' if cond else 'FAIL'}] {label} {detail}")
    return state, check


def sanity(rows, failures, wiki_rows_expected=EXPECTED_TOWNS) -> bool:
    """Checks on the town rows (ro.wikipedia)."""
    state, check = _checker()

    print(f"towns: {len(rows)}  (unparsed: {len(failures)})")
    for name, why in failures:
        print(f"  UNMAPPED {name}: {why}")
    check(f"town count == {wiki_rows_expected}", len(rows) == wiki_rows_expected, f"({len(rows)})")
    dup = [k for k, n in Counter((uat.norm(r["nume"]), r["judet"]) for r in rows).items() if n > 1]
    check("no duplicate (nume, judet)", not dup, str(dup))
    dupn = [k for k, n in Counter(uat.norm(r["nume"]) for r in rows).items() if n > 1]
    print(f"  [info] town names shared across counties (kept; uat.py needs a county or "
          f"falls back to the type rule): {dupn}")
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
    return state["ok"]


def sanity_communes(rows, failures, towns, expected=EXPECTED_COMMUNES) -> bool:
    """Checks on the commune rows (Wikidata), including collisions with the towns."""
    state, check = _checker()
    print(f"communes: {len(rows)}  (unparsed: {len(failures)})")
    for name, why in failures[:20]:
        print(f"  UNMAPPED {name}: {why}")
    check(f"commune count == {expected}", len(rows) == expected and not failures, f"({len(rows)})")
    keys = Counter((uat.norm(r["nume"]), r["judet"]) for r in rows)
    check("no duplicate (nume, judet) among communes", all(n == 1 for n in keys.values()),
          str([k for k, n in keys.items() if n > 1]))
    check("no commune in București", all(r["judet"] != "București" for r in rows))
    check("all 41 other counties have communes",
          len({r["judet"] for r in rows}) == 41, f"({len({r['judet'] for r in rows})})")
    check("every commune has a SIRUTA code", all(r["siruta"].isdigit() for r in rows))
    sir = Counter(r["siruta"] for r in rows)
    check("SIRUTA codes unique", all(n == 1 for n in sir.values()))
    top = max(r["populatie"] for r in rows)
    check("largest commune < 60,000 (Florești ~52,700)", top < 60_000, f"({top:,})")
    total = sum(r["populatie"] for r in rows)
    check("total 8M..10M (2021 rural population ~9.1M)", 8_000_000 <= total <= 10_000_000,
          f"({total:,})")
    town_keys = {(uat.norm(r["nume"]), r["judet"]) for r in towns}
    same_county = sorted(k for k in keys if k in town_keys)
    print(f"  [info] communes sharing (name, county) with a town: {same_county}")
    town_names = {uat.norm(r["nume"]) for r in towns}
    shared = sorted({r["nume"] for r in rows if uat.norm(r["nume"]) in town_names})
    print(f"  [info] commune names that are also a town's name elsewhere: {shared}")
    print("  spot checks (2021 census):")
    by = {(uat.norm(r["nume"]), r["judet"]): r["populatie"] for r in rows}
    for n, c, lo, hi in [("Florești", "Cluj", 50_000, 55_000), ("Chiajna", "Ilfov", 40_000, 46_000),
                         ("Dumbrăvița", "Timiș", 18_000, 22_000), ("Slatina", "Suceava", 2_000, 6_000)]:
        p = by.get((uat.norm(n), c))
        check(f"{n} ({c})", p is not None and lo <= p <= hi, f"-> {p}")
    return state["ok"]


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("wikitext", type=Path, help="saved raw wikitext (towns)")
    ap.add_argument("--revid", default="", help="Wikipedia revision id of the town download")
    ap.add_argument("--revdate", default="", help="revision timestamp (YYYY-MM-DD)")
    ap.add_argument("--communes", type=Path, default=None,
                    help="saved Wikidata SPARQL JSON (communes); omit for towns only")
    ap.add_argument("--communes-date", default="", help="retrieval date of --communes (YYYY-MM-DD)")
    ap.add_argument("--out", type=Path, default=OUT)
    ap.add_argument("--no-write", action="store_true")
    args = ap.parse_args()

    towns, failures = build(args.wikitext.read_text(encoding="utf-8"))
    print(f"source: {SOURCE_URL}\n  revision {args.revid or '?'} ({args.revdate or '?'}), "
          f"population vintage: census {VINTAGE}")
    ok = sanity(towns, failures)
    communes = []
    if args.communes:
        communes, cfail = build_communes(args.communes.read_text(encoding="utf-8"))
        print(f"source: {COMMUNES_SOURCE}\n  retrieved {args.communes_date or '?'}")
        ok &= sanity_communes(communes, cfail, towns)
    if not ok:
        print("sanity checks FAILED; not writing", file=sys.stderr)
        return 1
    if args.no_write:
        return 0
    sursa_town = f"ro.wikipedia Lista orașelor din România, rev {args.revid}, RPL {VINTAGE}"
    sursa_comm = f"Wikidata SPARQL {args.communes_date}, RPL {VINTAGE}"
    rows = [{**r, "sursa": r.get("origine") or sursa_town, "siruta": ""} for r in towns]
    rows += [{**r, "sursa": sursa_comm} for r in communes]
    rows.sort(key=lambda r: (r["judet"], r["tip"] != "oras", r["nume"]))
    args.out.parent.mkdir(parents=True, exist_ok=True)
    fields = ["nume", "judet", "tip", "populatie", "resedinta_judet", "an", "sursa", "siruta"]
    with args.out.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fields)
        w.writeheader()
        for r in rows:
            w.writerow({**{k: r[k] for k in fields if k in r}, "an": VINTAGE})
    by_tip = Counter(r["tip"] for r in rows)
    print(f"wrote {args.out} ({len(rows)} rows: {dict(by_tip)})")
    return 0


if __name__ == "__main__":
    sys.exit(main())

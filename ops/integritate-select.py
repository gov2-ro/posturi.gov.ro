#!/usr/bin/env python3
"""Pick the pay line of the declared post, one per declaration. Spike code (2026-11-20); see docs/audits/2026-11-20-salary-declaratii-kibana.md.

Input: the raw dump of ops/integritate-dump.{py,js} (one row per holder salary line).
Output: one row per declaration, with `match` =
    inst      the line names the declaring institution (largest such line)
    single    one holder line only, and it does not name the institution
and the counts of the dropped declarations.

The institution match compares "core" tokens: the generic words (PRIMARIA,
MUNICIPIUL, COMUNA, ...) and the address (after the first comma) are removed.
Parent bodies count as a match for police and army units.

Usage: .venv/bin/python ops/integritate-select.py data/integritate/<raw>.csv.gz data/integritate/selected.csv.gz
"""
from __future__ import annotations

import collections
import csv
import gzip
import re
import sys
import unicodedata

GENERIC = set("""
PRIMARIA PRIMARIEI MUNICIPIUL MUNICIPIULUI COMUNA COMUNEI ORASUL ORASULUI ORAS JUDETUL JUDETULUI
CONSILIUL CONSILIULUI LOCAL LOCALE JUDETEAN JUDETENE AL A ALE DE DIN SI PENTRU LA PE LANGA IN CU
SECTORUL SECTOR NR STR STRADA BD BULEVARDUL SC SA SRL RA UAT APARATUL DE SPECIALITATE
INSTITUTIA INSTITUTIEI SERVICIUL SERVICIULUI DIRECTIA DIRECTIEI GENERALA GENERALE
""".split())

# A declaring institution that matches these words is paid by the parent body named in `sursa`.
PARENTS = [
    (re.compile(r"\b(UNITATEA MILITARA|U ?M|UM)\b"), re.compile(r"APARARII|MAPN|M AP N|UNITATEA MILITARA|\bUM\b")),
    (re.compile(r"POLITIE|POLITIA"), re.compile(r"POLITIE|POLITIA|AFACERILOR INTERNE|\bMAI\b|\bIPJ\b|\bIGPR\b")),
    (re.compile(r"JANDARM"), re.compile(r"JANDARM|AFACERILOR INTERNE|\bMAI\b")),
    (re.compile(r"SITUATII DE URGENTA|\bISU\b|\bI S U\b|IGSU"), re.compile(r"SITUATII DE URGENTA|\bISU\b|IGSU|AFACERILOR INTERNE")),
    (re.compile(r"PARCHET|PROCURORI"), re.compile(r"PARCHET|MINISTERUL PUBLIC|\bDNA\b|\bDIICOT\b")),
    (re.compile(r"VAMAL"), re.compile(r"VAMAL|FINANTELOR PUBLICE|\bANAF\b|ADMINISTRARE FISCALA")),
    (re.compile(r"PENITENCIAR"), re.compile(r"PENITENCIAR|ANP")),
]


def norm(s: str) -> str:
    s = unicodedata.normalize("NFD", (s or "").upper())
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"\s+", " ", re.sub(r"[^A-Z0-9 ]", " ", s)).strip()


# A type word in the head of `sursa` that the institution name lacks means another employer
# ("Scoala Gimnaziala ... Poienile de sub Munte" is not "COMUNA POIENILE DE SUB MUNTE").
TYPES = re.compile(r"\b(SCOAL|LICEU|GRADINIT|COLEGIU|SPITAL|COMPANI|SOCIETAT|SRL|SA|SC|UNIVERSITAT|ASOCIATI|FUNDATI|REGIA|CLUB)")


def type_words(s: str) -> set[str]:
    return set(TYPES.findall(norm(s)))


def core(s: str) -> set[str]:
    """Distinctive tokens, cut to 6 letters ("SANITAR" = "SANITARA")."""
    return {t[:6] for t in norm(s).split() if t not in GENERIC and len(t) > 1}


def names_institution(sursa: str, inst_norm: str) -> bool:
    head = (sursa or "").split(",")[0]
    a, b = norm(head), norm(inst_norm)
    if not a or not b:
        return False
    if a in b or b in a:
        return True
    full = norm(sursa)
    if any(pi.search(b) and ps.search(full) for pi, ps in PARENTS):
        return True
    if type_words(head) - type_words(inst_norm):
        return False
    ca, cb = core(head), core(inst_norm)
    # One core token (a city name) is too weak when the head has other words.
    if len(cb) < 2 and ca != cb:
        return False
    # The place can sit after the comma ("..., Aleea Sadoveanu 10 Iasi").
    return bool(cb) and (cb <= core(sursa) or len(ca & cb) / len(ca | cb) >= 0.6)


def main(src: str, out: str) -> None:
    by_decl: dict[tuple, list[dict]] = collections.defaultdict(list)
    with gzip.open(src, "rt", encoding="utf-8", newline="") as f:
        for r in csv.DictReader(f):
            by_decl[(r["source"], r["aid"])].append(r)

    stats = collections.Counter()
    cols = None
    with gzip.open(out, "wt", encoding="utf-8", newline="") as f:
        w = None
        for lines in by_decl.values():
            ok = [l for l in lines if (l["valuta"] in ("RON", "null", "")) and _num(l["suma"]) > 0]
            if not ok:
                stats["drop_no_ron_amount"] += 1
                continue
            m = [l for l in ok if names_institution(l["sursa"], l["institutie_norm"])]
            if m:
                pick, kind = max(m, key=lambda l: _num(l["suma"])), "inst"
            elif len(lines) == 1:
                pick, kind = ok[0], "single"
            else:
                stats["drop_several_no_match"] += 1
                continue
            stats[kind] += 1
            row = {k: v for k, v in pick.items() if k not in ("valuta",)}
            row["match"] = kind
            row["suma"] = _num(pick["suma"])
            if w is None:
                cols = list(row)
                w = csv.DictWriter(f, fieldnames=cols)
                w.writeheader()
            w.writerow(row)
    total = sum(stats.values())
    print(f"declarations {total:,}")
    for k, v in stats.most_common():
        print(f"  {k:24} {v:>9,} {v / total:6.1%}")


def _num(s: str) -> float:
    try:
        return float(s)
    except (TypeError, ValueError):
        return 0.0


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])

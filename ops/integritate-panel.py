#!/usr/bin/env python3
"""Year-to-year pay growth of the same person in the same post, and the net/gross mix.
Spike code (2026-11-20); see docs/audits/2026-11-20-salary-declaratii-kibana.md.

Input: the output of ops/integritate-select.py on a dump that has the `person` column.

1. Dedupe: one declaration per (person, an); keep the latest filing.
2. Panel: the same person, the same institution, the same job title, in filing
   years Y and Y+1 (pay of Y-1 and Y). Growth = pay(Y) / pay(Y-1).
3. Net/gross: the ratio of each value to the median of its group
   (an, institution, title; n >= 5), in log bins. Net pay is about 0.585 of gross.

Usage: .venv/bin/python ops/integritate-panel.py data/integritate/selected.csv.gz [--backtest]
"""
from __future__ import annotations

import collections
import csv
import gzip
import math
import re
import statistics
import sys

ELECTED = re.compile(
    r"consilier local|consilier jude|ale[sș]i|^primar|viceprimar|pre[sș]edinte(le)? consiliu|"
    r"membru.*(consiliu|administra)|deputat|senator", re.I)


def family(r: dict) -> str:
    f = r["functie"].lower()
    i = r["institutie_norm"].upper()
    if ELECTED.search(f):
        return "ales"
    if re.search(r"fonduri externe|proiect", f):
        return "proiect"
    if re.search(r"judec[aă]tor|procuror", f):
        return "magistrat"
    if re.search(r"TRIBUNAL|JUDECATORI|CURTEA DE APEL|PARCHET|INALTA CURTE", i):
        return "justitie-personal"
    if re.search(r"politist local|politia local", f) or "POLITIA LOCALA" in i or "POLITIE LOCALA" in i:
        return "politie-locala"
    if re.search(r"POLITIE|JANDARM|SITUATII DE URGENTA|UNITATEA MILITARA|\bU ?M\b|PENITENCIAR|POLITIA DE FRONTIERA|AFACERILOR INTERNE|APARARII", i):
        return "mai-mapn"
    if re.search(r"UNIVERSITATE|ACADEMIA DE STUDII|POLITEHNIC", i):
        return "universitate"
    if re.search(r"medic|asistent medical|infirmier", f) or re.search(r"SPITAL|AMBULANT", i):
        return "sanatate"
    if re.search(r"profesor|[iî]nv[aă][tț][aă]tor|educatoare|inspector [sș]colar|director unitate", f) or \
            re.search(r"SCOALA|LICEU|COLEGIU|GRADINITA|INSPECTORATUL SCOLAR|PALATUL COPIILOR", i):
        return "educatie"
    return {"LOCAL": "admin-local", "JUDETEAN": "admin-judetean", "NATIONAL": "admin-central"}.get(
        r["tip_institutie"], "altul")


def q(xs: list[float], p: float) -> float:
    xs = sorted(xs)
    return xs[int(p * (len(xs) - 1))]


def main(path: str) -> None:
    best: dict[tuple, dict] = {}
    dup = 0
    floor = 12_000  # yearly; drops part-year and odd values (about 1,000 lei a month)
    with gzip.open(path, "rt", encoding="utf-8", newline="") as f:
        for r in csv.DictReader(f):
            if r["match"] != "inst" or not r["person"] or not r["an"]:
                continue
            if float(r["suma"]) < floor:
                continue
            k = (r["person"], r["an"])
            if k in best:
                dup += 1
                if r["data_completare_iso"] <= best[k]["data_completare_iso"]:
                    continue
            best[k] = r
    print(f"one per (person, an): {len(best):,} kept, {dup:,} repeats dropped")

    # --- 2. panel
    pairs = collections.defaultdict(list)  # (family, pay years) -> growth
    for (person, an), r in best.items():
        nxt = best.get((person, str(int(an) + 1)))
        if not nxt or nxt["functie"].lower() != r["functie"].lower():
            continue
        g = float(nxt["suma"]) / float(r["suma"])
        pairs[(family(r), f"{int(an) - 1}->{int(an)}")].append(g)
        pairs[("TOATE", f"{int(an) - 1}->{int(an)}")].append(g)

    print("\nGrowth of yearly pay, same person, same institution, same title")
    print(f"{'family':20} {'pay years':10} {'n':>8} {'p25':>6} {'median':>7} {'p75':>6} {'±5%':>6} {'~0.585':>7} {'~1.71':>6}")
    for (fam, yrs), gs in sorted(pairs.items(), key=lambda kv: (kv[0][1], -len(kv[1]))):
        if len(gs) < 200:
            continue
        near = lambda t: sum(1 for g in gs if abs(math.log(g / t)) < 0.08) / len(gs)
        print(f"{fam:20} {yrs:10} {len(gs):>8,} {q(gs, .25):6.3f} {statistics.median(gs):7.3f} {q(gs, .75):6.3f} "
              f"{near(1.0):6.1%} {near(0.585):7.1%} {near(1 / 0.585):6.1%}")

    # --- 3. net/gross mix inside groups
    groups = collections.defaultdict(list)
    for r in best.values():
        groups[(r["an"], r["institutie_id"], r["functie"].lower())].append(float(r["suma"]))
    bins = collections.Counter()
    n = 0
    for vals in groups.values():
        if len(vals) < 5:
            continue
        med = statistics.median(vals)
        for v in vals:
            bins[round(math.log(v / med) / 0.1) * 0.1] += 1
            n += 1
    print(f"\nValue / group median (groups of an+institution+title, n>=5; {n:,} values). "
          "Net/gross mixing shows as bumps at 0.585 and 1.71.")
    for b in sorted(bins):
        if -1.0 <= b <= 1.0:
            print(f"  {math.exp(b):5.2f} {bins[b] / n:6.1%} {'#' * int(200 * bins[b] / n)}")


if __name__ == "__main__" and len(sys.argv) == 2:
    main(sys.argv[1])


# INS net average pay by CAEN section, yearly mean of the months (FOM106D, Rev.2).
# 2023/2022: O 5,964/5,310, P 4,624/3,860, Q 4,480/4,212. Measured 2026-11-20 with duckdb on
# ../tempo-ins-dump/data/corpus/parquet/FOM106D.parquet.
INS_2022_2023 = {"O": 5964 / 5310, "P": 4624 / 3860, "Q": 4480 / 4212}
FAMILY_SECTION = {
    "admin-local": "O", "admin-central": "O", "admin-judetean": "O", "mai-mapn": "O",
    "politie-locala": "O", "justitie-personal": "O", "magistrat": "O", "ales": "O",
    "educatie": "P", "universitate": "P", "sanatate": "Q",
}


def backtest(path: str, ins: bool = False) -> None:
    """Predict the 2023 pay of a group (institution + title) from its 2022 pay.

    Prediction = group median of filing year 2023 (pay 2022) x the median person growth
    of the family (from the panel, all institutions). Error = predicted / actual median
    of filing year 2024 (pay 2023). Groups with n >= 5 in both years. In-sample for the
    factor, so this measures how much an institution departs from its family, which is
    the error a family-wide legal factor would carry forward to 2026.
    """
    best: dict[tuple, dict] = {}
    with gzip.open(path, "rt", encoding="utf-8", newline="") as f:
        for r in csv.DictReader(f):
            if r["match"] != "inst" or not r["person"] or r["an"] not in ("2023", "2024"):
                continue
            if float(r["suma"]) < 12_000:
                continue
            k = (r["person"], r["an"])
            if k not in best or r["data_completare_iso"] > best[k]["data_completare_iso"]:
                best[k] = r
    growth = collections.defaultdict(list)
    for (p, an), r in best.items():
        if an == "2023" and (p, "2024") in best and best[(p, "2024")]["functie"].lower() == r["functie"].lower():
            growth[family(r)].append(float(best[(p, "2024")]["suma"]) / float(r["suma"]))
    factor = {k: statistics.median(v) for k, v in growth.items() if len(v) >= 100}
    if ins:  # out of sample: the INS sector factor, not the panel
        factor = {k: INS_2022_2023[FAMILY_SECTION[k]] for k in factor if k in FAMILY_SECTION}
    groups = collections.defaultdict(lambda: {"2023": [], "2024": [], "fam": None})
    for (p, an), r in best.items():
        g = groups[(r["institutie_id"], r["functie"].lower())]
        g[an].append(float(r["suma"]))
        g["fam"] = family(r)
    errs = collections.defaultdict(list)
    for g in groups.values():
        if len(g["2023"]) >= 5 and len(g["2024"]) >= 5 and g["fam"] in factor:
            e = statistics.median(g["2023"]) * factor[g["fam"]] / statistics.median(g["2024"]) - 1
            errs[g["fam"]].append(e)
            errs["TOATE"].append(e)
    print("\nBacktest: group median 2023 = group median 2022 x "
          + ("INS sector factor (out of sample)" if ins else "family factor from the panel (in sample)"))
    print(f"{'family':20} {'factor':>7} {'groups':>7} {'med |err|':>9} {'p90 |err|':>9} {'within 10%':>10} {'bias':>7}")
    for fam, es in sorted(errs.items(), key=lambda kv: -len(kv[1])):
        a = [abs(e) for e in es]
        print(f"{fam:20} {factor.get(fam, float('nan')):7.3f} {len(es):>7,} {statistics.median(a):9.1%} {q(a, .9):9.1%} "
              f"{sum(1 for x in a if x <= .10) / len(a):10.1%} {statistics.median(es):+7.1%}")


if __name__ == "__main__" and len(sys.argv) > 2 and sys.argv[2] == "--backtest":
    backtest(sys.argv[1])
    backtest(sys.argv[1], ins=True)

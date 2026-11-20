#!/usr/bin/env python3
"""Build data/salarii/declaratii-grupuri.csv, the group table of ADR 0008 rule 9 (SALARY-DECLARATII).

Input : data/integritate/selected.csv.gz (output of ops/integritate-select.py; stays on the Mac, out of git).
Output: data/salarii/declaratii-grupuri.csv. One row per group (employer + job title) with at
        least 5 declarants. The row holds group figures only: no name, no person key, no single
        declaration.

Rows used (the rules of the backtest in ops/integritate-panel.py): match = inst, filing year 2024
(pay of 2023), yearly amount >= 12,000 lei, one declaration per person (the latest filing).
Families kept: admin-local, admin-judetean, admin-central, politie-locala, mai-mapn.

Factor 2023 -> 2026: INS net average pay, section O. Mean of the 2026 months of FOM106G (CAEN
Rev.3, section P) / mean of the 12 months of 2023 of FOM106D (CAEN Rev.2, section O). The
script stops when the value is not 1.150 (ADR 0008 rule 9): INS may have published new months,
and the owner decides.

Percentiles: p25 and p75 pick the value at index int(p * (n - 1)) of the sorted amounts, as
q() of integritate-panel.py does; the median is statistics.median.

Usage: .venv/bin/python ops/build-declaratii-grupuri.py [--tempo PATH] [--src PATH] [--out PATH]
"""
from __future__ import annotations

import argparse
import collections
import csv
import gzip
import io
import os
import statistics
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from integritate_common import family  # noqa: E402

DEFAULT_TEMPO = REPO.parent / "tempo-ins-dump" / "data" / "corpus" / "parquet"
DEFAULT_SRC = REPO / "data" / "integritate" / "selected.csv.gz"
DEFAULT_OUT = REPO / "data" / "salarii" / "declaratii-grupuri.csv"

FILING_YEAR = "2024"      # filing year; the income is of the year before
INCOME_YEAR = 2023
YEARLY_FLOOR = 12_000     # lei a year; drops part-year values
MIN_N = 5
EXPECTED_FACTOR = 1.150

#: Backtest median error of the family, percent, out of sample (INS factor, 2022 -> 2023).
#: Audit note 2026-11-20, section "The INS factor, tested one year back".
FAMILY_ERROR = {"admin-local": 5.2, "admin-central": 4.8, "admin-judetean": 6.7,
                "politie-locala": 6.0, "mai-mapn": 6.1}

COLUMNS = ["institutie_id", "institutie", "judet", "functie", "familie", "n", "p25_net_2023",
           "mediana_net_2023", "p75_net_2023", "an_venit", "factor", "factor_sursa", "eroare_tipica"]


def run_duckdb(sql: str) -> list[dict]:
    env = {k: v for k, v in os.environ.items() if k != "AI_AGENT"}
    out = subprocess.run(["duckdb", "-csv", "-c", sql], capture_output=True, text=True, env=env, check=True).stdout
    return list(csv.DictReader(io.StringIO(out)))


def ins_factor(tempo: Path, query=run_duckdb) -> tuple[float, str]:
    """(factor, source text). Mean of the 2026 months of FOM106G section P (Rev.3, public
    administration) / mean of the 2023 months of FOM106D section O (Rev.2)."""
    new = query(f"SELECT TIME_PERIOD, OBS_VALUE FROM '{tempo}/FOM106G.parquet' "
                f"WHERE ECON_ACTIVITY LIKE 'P %' AND TIME_PERIOD LIKE '2026-%' AND OBS_VALUE IS NOT NULL "
                f"ORDER BY TIME_PERIOD")
    old = query(f"SELECT TIME_PERIOD, OBS_VALUE FROM '{tempo}/FOM106D.parquet' "
                f"WHERE ECON_ACTIVITY LIKE 'O %' AND TIME_PERIOD LIKE '2023-%' AND OBS_VALUE IS NOT NULL "
                f"ORDER BY TIME_PERIOD")
    if not new or not old:
        raise SystemExit("no INS months found for the factor")
    mean_new = statistics.fmean(float(r["OBS_VALUE"]) for r in new)
    mean_old = statistics.fmean(float(r["OBS_VALUE"]) for r in old)
    factor = mean_new / mean_old
    months_new = f"{new[0]['TIME_PERIOD']}..{new[-1]['TIME_PERIOD']} ({len(new)} luni)"
    months_old = f"{old[0]['TIME_PERIOD']}..{old[-1]['TIME_PERIOD']} ({len(old)} luni)"
    print(f"factor {factor:.4f}: FOM106G sectiunea P (Rev.3) {months_new}, mean {mean_new:.1f} / "
          f"FOM106D sectiunea O (Rev.2) {months_old}, mean {mean_old:.1f}")
    return factor, f"INS FOM106G P {new[0]['TIME_PERIOD']}..{new[-1]['TIME_PERIOD']} / FOM106D O 2023"


def pct(xs: list[float], p: float) -> float:
    xs = sorted(xs)
    return xs[int(p * (len(xs) - 1))]


def build_groups(src: Path) -> list[dict]:
    best: dict[str, dict] = {}
    with gzip.open(src, "rt", encoding="utf-8", newline="") as f:
        for r in csv.DictReader(f):
            if r["match"] != "inst" or r["an"] != FILING_YEAR or not r["person"]:
                continue
            if float(r["suma"]) < YEARLY_FLOOR:
                continue
            k = r["person"]
            if k not in best or r["data_completare_iso"] > best[k]["data_completare_iso"]:
                best[k] = r
    groups: dict[tuple, dict] = {}
    for r in best.values():
        fam = family(r)
        if fam not in FAMILY_ERROR:
            continue
        g = groups.setdefault((r["institutie_id"], r["functie"].lower()),
                              {"amounts": [], "names": collections.Counter(), "inst": r["institutie"],
                               "judet": r["judet_norm"], "fam": fam})
        g["amounts"].append(float(r["suma"]) / 12)
        g["names"][r["functie"]] += 1
    out = []
    for (inst_id, _), g in groups.items():
        n = len(g["amounts"])
        if n < MIN_N:
            continue
        a = g["amounts"]
        out.append({"institutie_id": inst_id, "institutie": g["inst"], "judet": g["judet"],
                    "functie": g["names"].most_common(1)[0][0], "familie": g["fam"], "n": n,
                    "p25_net_2023": round(pct(a, .25)), "mediana_net_2023": round(statistics.median(a)),
                    "p75_net_2023": round(pct(a, .75)), "an_venit": INCOME_YEAR,
                    "eroare_tipica": FAMILY_ERROR[g["fam"]]})
    out.sort(key=lambda r: (r["institutie_id"], r["functie"].lower()))
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--tempo", type=Path, default=DEFAULT_TEMPO)
    ap.add_argument("--src", type=Path, default=DEFAULT_SRC)
    ap.add_argument("--out", type=Path, default=DEFAULT_OUT)
    args = ap.parse_args()

    factor, source = ins_factor(args.tempo)
    if round(factor, 3) != EXPECTED_FACTOR:
        print(f"STOP: factor {factor:.4f} is not {EXPECTED_FACTOR}. INS may have published new months; "
              "the owner decides (ADR 0008 rule 9).", file=sys.stderr)
        return 2
    rows = build_groups(args.src)
    # Privacy check: no group under 5 declarants, and no column that could name a person.
    bad = [r for r in rows if r["n"] < MIN_N]
    if bad:
        print(f"STOP: {len(bad)} rows with n < {MIN_N}", file=sys.stderr)
        return 3
    assert set(COLUMNS) >= set(rows[0]) if rows else True
    for r in rows:
        r["factor"] = f"{factor:.3f}"
        r["factor_sursa"] = source
    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS)
        w.writeheader()
        w.writerows(rows)
    by_fam = collections.Counter(r["familie"] for r in rows)
    print(f"{len(rows)} groups at {len({r['institutie_id'] for r in rows})} institutions -> {args.out} "
          f"({args.out.stat().st_size:,} bytes)")
    print("  by family: " + ", ".join(f"{k}={v}" for k, v in sorted(by_fam.items())))
    return 0


if __name__ == "__main__":
    sys.exit(main())

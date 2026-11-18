#!/usr/bin/env python3
"""Build data/salarii/ins-context.csv, the INS earnings context of the salary estimator (SALARY-INS-LINE).

The estimator result shows one line: the average net monthly earnings of the domain, from INS
TEMPO. It is context, never the estimate (ADR 0008 rules 5 and 8). INS data stays in
`tempo-ins-dump`: this script reads it and writes only the few rows that the page needs.

Two matrices, one measure ("câștigul salarial mediu net lunar", lei):

  FOM106G  CAEN Rev.3, national, monthly (from 2025-01). The newest period is the national figure.
  FOM106E  CAEN Rev.2, by county, annual (to 2024), one value for each sex. INS publishes no
           both-sexes cell by section and county, and `tempo-ins-dump` drops its "Total" options,
           so the county figure is the mean of the two sexes weighted by FOM104F (the average
           number of employees, same dimensions). This is the recipe of tempo-ins-dump
           (docs/METHODOLOGY.md § 3). The result can differ by 1 leu from a cell that INS publishes.
           `matrice` says so: "FOM106E+FOM104F".

FOM121A is under data/quarantine/ in tempo-ins-dump: this script never reads it.

The parquet files are read with the `duckdb` command (no Python package needed):

    python3 ops/build-ins-context.py                 # reads ../tempo-ins-dump, writes the CSV
    python3 ops/build-ins-context.py --tempo PATH --out PATH
"""

from __future__ import annotations

import argparse
import csv
import importlib.util
import io
import os
import subprocess
import sys
from datetime import date
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
DEFAULT_TEMPO = REPO.parent / "tempo-ins-dump" / "data" / "corpus" / "parquet"
DEFAULT_OUT = REPO / "data" / "salarii" / "ins-context.csv"

COLUMNS = ["matrice", "sectiune_caen", "judet", "perioada", "masura", "valoare", "unitate", "extras_la"]
MEASURE = "castig_net_mediu_lunar"
UNIT = "lei"

# Domain -> CAEN section (Rev. 2). The estimator page holds the same table for its annexes.
# Învățământ -> P; Sănătate și asistență socială -> Q; Cultură -> R;
# Administrație, Justiție, Diplomație -> O; Apărare și ordine publică -> O.
# INS has no finer split that fits: section O is "Administrație publică și apărare; asigurări
# sociale din sistemul public", and TEMPO publishes it as one section.
DOMAIN_CAEN = {
    "Învățământ": "P",
    "Sănătate și asistență socială": "Q",
    "Cultură": "R",
    "Administrație": "O",
    "Justiție": "O",
    "Diplomație": "O",
    "Apărare și ordine publică": "O",
}
SECTIONS = sorted(set(DOMAIN_CAEN.values()))

# FOM106G is CAEN Rev.3: its letters from J on are shifted by one (P is public administration,
# Q education, R health, S culture). The CSV always holds the Rev.2 letter, so the page has one key.
REV2_TO_REV3 = {"O": "P", "P": "Q", "Q": "R", "R": "S"}


def _load_judete():
    """`COUNTIES` and `fold()` of the importer: the one list of canonical county names."""
    path = REPO / "webapp" / "apps" / "jobs" / "judete.py"
    spec = importlib.util.spec_from_file_location("posturi_judete", path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def county_name(ins_name: str, judete) -> str | None:
    """The canonical county of an INS `REF_AREA` ("Arges", "Municipiul Bucuresti"), or None for a region."""
    key = judete.fold(ins_name)
    if key in judete.ALIASES:
        return judete.ALIASES[key]
    for c in judete.COUNTIES:
        if judete.fold(c) == key:
            return c
    return None


def weighted_mean(values: list[tuple[float, float]]) -> float | None:
    """Mean of (value, weight) pairs; None when the weights sum to zero."""
    total = sum(w for _, w in values)
    return sum(v * w for v, w in values) / total if total > 0 else None


def run_duckdb(sql: str) -> list[dict]:
    """Run one query with the `duckdb` command; return the rows as dicts (CSV output)."""
    env = {k: v for k, v in os.environ.items() if k != "AI_AGENT"}
    out = subprocess.run(["duckdb", "-csv", "-c", sql], capture_output=True, text=True, env=env, check=True).stdout
    return list(csv.DictReader(io.StringIO(out)))


def national_rows(parquet: Path, extracted: str, query=run_duckdb) -> list[dict]:
    """One row per section: the newest month of FOM106G."""
    out = []
    for sec in SECTIONS:
        rev3 = REV2_TO_REV3[sec]
        rows = query(f"SELECT TIME_PERIOD, OBS_VALUE FROM '{parquet}/FOM106G.parquet' "
                     f"WHERE ECON_ACTIVITY LIKE '{rev3} %' AND OBS_VALUE IS NOT NULL "
                     f"ORDER BY TIME_PERIOD DESC LIMIT 1")
        if not rows:
            continue
        out.append({"matrice": "FOM106G", "sectiune_caen": sec, "judet": "", "perioada": rows[0]["TIME_PERIOD"],
                    "masura": MEASURE, "valoare": round(float(rows[0]["OBS_VALUE"])), "unitate": UNIT,
                    "extras_la": extracted})
    return out


def county_rows(parquet: Path, extracted: str, query=run_duckdb, judete=None) -> list[dict]:
    """One row per (section, county): the newest year of FOM106E, both sexes, weighted by FOM104F."""
    judete = judete or _load_judete()
    secs = ",".join(f"'{s}'" for s in SECTIONS)
    rows = query(f"""
        SELECT e.ECON_ACTIVITY AS act, e.REF_AREA AS area, e.SEX AS sex, e.TIME_PERIOD AS year,
               e.OBS_VALUE AS pay, w.OBS_VALUE AS staff
        FROM '{parquet}/FOM106E.parquet' e
        JOIN '{parquet}/FOM104F.parquet' w
          ON w.ECON_ACTIVITY = e.ECON_ACTIVITY AND w.SEX = e.SEX AND w.REF_AREA = e.REF_AREA
         AND w.TIME_PERIOD = e.TIME_PERIOD
        WHERE substr(e.ECON_ACTIVITY, 1, 1) IN ({secs}) AND e.ECON_ACTIVITY LIKE '_  %'
          AND e.OBS_VALUE IS NOT NULL AND w.OBS_VALUE IS NOT NULL
    """)
    cells: dict[tuple[str, str, str], list[tuple[float, float]]] = {}
    for r in rows:
        county = county_name(r["area"], judete)
        if county is None:
            continue
        cells.setdefault((r["act"][0], county, r["year"]), []).append((float(r["pay"]), float(r["staff"])))
    # Keep the newest year that has both sexes for the cell.
    best: dict[tuple[str, str], tuple[str, float]] = {}
    for (sec, county, year), pairs in cells.items():
        if len(pairs) != 2:
            continue
        mean = weighted_mean(pairs)
        if mean is None:
            continue
        if (sec, county) not in best or year > best[(sec, county)][0]:
            best[(sec, county)] = (year, mean)
    return [{"matrice": "FOM106E+FOM104F", "sectiune_caen": sec, "judet": county, "perioada": year,
             "masura": MEASURE, "valoare": round(mean), "unitate": UNIT, "extras_la": extracted}
            for (sec, county), (year, mean) in sorted(best.items())]


def build(parquet: Path, extracted: str, query=run_duckdb) -> list[dict]:
    return national_rows(parquet, extracted, query) + county_rows(parquet, extracted, query)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--tempo", type=Path, default=DEFAULT_TEMPO, help="tempo-ins-dump/data/corpus/parquet")
    ap.add_argument("--out", type=Path, default=DEFAULT_OUT)
    args = ap.parse_args()
    for m in ("FOM106E", "FOM106G", "FOM104F"):
        if not (args.tempo / f"{m}.parquet").exists():
            print(f"missing {args.tempo / (m + '.parquet')}", file=sys.stderr)
            return 1
    rows = build(args.tempo, date.today().isoformat())
    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS)
        w.writeheader()
        w.writerows(rows)
    periods = sorted({(r["matrice"], r["perioada"]) for r in rows})
    print(f"{len(rows)} rows -> {args.out}; periods: {periods}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

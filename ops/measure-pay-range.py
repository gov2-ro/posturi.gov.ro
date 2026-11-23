#!/usr/bin/env python3
"""Measure the pay range of ADR 0008 rule 11 on an export (SALARY-GRAD-FOOD, brief step 8).

Reads an export sqlite (`data/posturi.sqlite` or a copy), runs the export function
`_pay_range_columns()` on each posting, and prints:

- the postings per `salary_bucket` value before and after (rule 7: the keys do not change);
- the postings per floor gradation and per kind (stated / law grid / not in scope, with the reason);
- the food at the low end, the high end, both, neither; the postings that an excluded employer drops;
- the method of the law figures (grid rows, or the figure scaled).

It uses the stored `inferred`, `sal_json`, `employer_name` and project columns, so it needs no Postgres. The
result describes the data of that file only: run it on the VPS export for the real numbers.

Usage: python ops/measure-pay-range.py [path/to/posturi.sqlite] [--json]
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import sqlite3
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import salary_net  # noqa: E402
import salary_pay  # noqa: E402

OPEN = ("confirmed_open", "unconfirmed", "unknown")
BUCKETS = [("net-sub-3000", None, 3000), ("net-3000-4000", 3000, 4000), ("net-4000-5000", 4000, 5000),
           ("net-5000-7000", 5000, 7000), ("net-peste-7000", 7000, None)]


def load_export():
    spec = importlib.util.spec_from_file_location("export_to_sqlite_for_measure", ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


def bucket(value) -> str:
    """The `salary_bucket` value of a gross figure, as `salary_bucket_sql()` in helpers.php tests it."""
    if value is None or value <= 0:
        return "fara-estimare"
    for key, lo, hi in BUCKETS:
        if (lo is None or value >= salary_net.gross_for_net(lo)) and (hi is None or value < salary_net.gross_for_net(hi)):
            return key
    return "fara-estimare"


def project_month(r) -> float | None:
    """PROJECT_MONTH_EXPR of helpers.php."""
    amount, unit = r["proj_pay_amount"], r["proj_pay_unit"]
    if not amount or amount <= 0 or not (unit == "luna" or (unit == "ora" and (r["proj_hours_month"] or 0) > 0)):
        return None
    gross = amount * (r["proj_hours_month"] if unit == "ora" else 1)
    return salary_net.gross_for_net(gross) if r["proj_pay_basis"] == "net" else gross


def reason(exp, r, est) -> str:
    """Why a posting with a figure has no range."""
    if r["project_hire"]:
        return "project hire"
    if not est:
        return "no estimate"
    baza = est.get("baza")
    if baza == "declaratii":
        return "declared pay (a2)"
    if baza != "lege_in_vigoare":
        return "draft grid (c)"
    sel = est.get("selector") or {}
    if sel.get("anexa") == "VI" or est.get("lei_functie_min") is not None:
        return "Anexa VI"
    if sel.get("tip_post") == "conducere":
        return "management row"
    return "other"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("db", nargs="?", default=str(ROOT / "data" / "posturi.sqlite"))
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args()
    exp = load_export()
    con = sqlite3.connect(f"file:{args.db}?mode=ro", uri=True)
    con.row_factory = sqlite3.Row
    rows = con.execute("""SELECT j.id, j.employer_name, j.inferred, j.application_status, j.project_hire,
        j.inf_salary_min, j.sal_min, j.proj_pay_amount, j.proj_pay_unit, j.proj_pay_basis, j.proj_hours_month,
        t.sal_json
        FROM job_postings j LEFT JOIN posting_text t ON t.posting_id = j.id""").fetchall()
    out = {}
    for scope in ("open", "all"):
        before, after = Counter(), Counter()
        floor, kind, food, methods, reasons = Counter(), Counter(), Counter(), Counter(), Counter()
        excluded = stated_ranges = n = 0
        for r in rows:
            if scope == "open" and r["application_status"] not in OPEN:
                continue
            n += 1
            try:
                inferred = json.loads(r["inferred"] or "{}")
            except json.JSONDecodeError:
                inferred = {}
            cols = exp._pay_range_columns(inferred, r["sal_json"], r["employer_name"])
            old = project_month(r) if r["project_hire"] else (
                r["inf_salary_min"] if (r["inf_salary_min"] or 0) > 0 else r["sal_min"])
            new = old if (r["project_hire"] or cols[0] is None) else cols[0]
            before[bucket(old)] += 1
            after[bucket(new)] += 1
            if cols[0] is None:
                if old and old > 0:
                    est = json.loads(r["sal_json"]) if r["sal_json"] else None
                    kind["not in scope"] += 1
                    reasons[reason(exp, r, est)] += 1
                else:
                    kind["no figure"] += 1
                continue
            stated = bool(exp._stated_salary(inferred)[0])
            kind["stated" if stated else "law grid"] += 1
            floor[cols[2]] += 1
            lo, hi = cols[4] > 0, cols[5] > 0
            food["both" if lo and hi else "low only" if lo else "high only" if hi else "neither"] += 1
            if salary_pay.excluded_employer(r["employer_name"]):
                excluded += 1
            if stated and exp._stated_salary(inferred)[1] not in (None, exp._stated_salary(inferred)[0]):
                stated_ranges += 1
            if not stated:
                est = json.loads(r["sal_json"])
                rng = salary_pay.pay_range("law", est["lei_min"], est.get("lei_max"), years=inferred.get("experience_years"),
                                           employer=r["employer_name"], local=salary_pay.is_local_range(est),
                                           figure_at=salary_pay.law_figure_at(est, exp.salary_grid.load_grid(exp.salary_grid.LAW_VERSION)))
                methods[rng["method"]] += 1
        out[scope] = {"postings": n, "bucket_before": dict(before), "bucket_after": dict(after),
                      "kind": dict(kind), "not_in_scope_reason": dict(reasons),
                      "floor_gradation": {str(k): v for k, v in sorted(floor.items(), key=lambda kv: (kv[0] is None, kv[0]))},
                      "food": dict(food), "excluded_employer_with_range": excluded,
                      "stated_with_a_range_of_figures": stated_ranges, "law_method": dict(methods)}
    if args.json:
        print(json.dumps(out, indent=1, ensure_ascii=False))
        return 0
    keys = [b[0] for b in BUCKETS] + ["fara-estimare"]
    for scope, d in out.items():
        print(f"\n=== {scope}: {d['postings']} postings")
        print(f"{'salary_bucket':16}{'before':>8}{'after':>8}")
        for k in keys:
            print(f"{k:16}{d['bucket_before'].get(k, 0):>8}{d['bucket_after'].get(k, 0):>8}")
        for name in ("kind", "not_in_scope_reason", "floor_gradation", "food", "law_method"):
            print(f"{name}: {d[name]}")
        print(f"excluded employer (with a range): {d['excluded_employer_with_range']}; "
              f"stated figure that is itself a range: {d['stated_with_a_range_of_figures']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

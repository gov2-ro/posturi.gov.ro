#!/usr/bin/env python3
"""Classify the postings that have no salary figure, by cause. Read-only.

A posting has "no figure" when neither grid gives a figure: the law in force
(`lege153-2026`) and the draft law. The script runs the same code path as
`estimate-salaries.py` (it imports that module and `salary_grid`), then gives
each posting without a figure ONE cause, the first that applies:

    fara-selector      the occupation selector has no `anexa` and no `functie`
    fara-rand-functie  the function name (or `cod`) matches no row in either grid
    filtru-gol         rows match by name, but the narrowing leaves none (step named)
    in-afara-grilei    the employer is outside Legea 153/2017 (see OUTSIDE_EMPLOYER)
    altul              anything else, with a note

`in-afara-grilei` is also computed as a flag for EVERY posting without a figure,
because the first three causes would otherwise hide it. The report prints both.

Note: `Grid.select()` falls back to the wider row set when a narrowing step has no
match, so `filtru-gol` should stay at 0. The script still checks it.

Usage (read-only, no write to the database):
    .venv/bin/python ops/salary-unmatched.py [--json PATH] [--examples 5] [--top 10]
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import psycopg  # noqa: E402
from psycopg.rows import dict_row  # noqa: E402

import salary_grid  # noqa: E402

# estimate-salaries.py has a dash in its name: load it by path.
_spec = importlib.util.spec_from_file_location("estimate_salaries", ROOT / "estimate-salaries.py")
est = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(est)

CAUSES = ["fara-selector", "fara-rand-functie", "filtru-gol", "in-afara-grilei", "altul"]

#: Employers that Legea 153/2017 does not cover: autonomous authorities, state
#: companies, and EU or PNRR project hires. Decided on the normalised employer name
#: and the posting title. A heuristic: the report says so.
OUTSIDE_EMPLOYER = re.compile(
    r"\b(banca nationala|autoritatea de supraveghere financiara|societatea|societate|"
    r"compania|companie|regia autonoma|s a|s c|sa|srl|cn |cnair|romsilva|"
    r"consiliul concurentei|curtea de conturi|consiliul legislativ|avocatul poporului|"
    r"consiliul national pentru combaterea discriminarii|cnsas|casa de asigurari)\b")
OUTSIDE_TITLE = re.compile(r"\b(proiect|pnrr|fse|fonduri europene|finantat)\b")


def outside_scope(posting: dict) -> str:
    """Return the reason the posting is outside Legea 153/2017, or ''."""
    emp = salary_grid.norm(posting["employer_name"])
    m = OUTSIDE_EMPLOYER.search(emp)
    if m:
        return f"angajator: «{m.group(1).strip()}»"
    m = OUTSIDE_TITLE.search(salary_grid.norm(posting["title"]))
    if m:
        return f"titlu: «{m.group(1)}»"
    return ""


def base_rows(grid, selector: dict) -> list:
    """The rows the function name or `cod` names, before any narrowing."""
    keep = {k: selector[k] for k in ("functie", "functie_alt", "cod") if selector.get(k)}
    return grid.select(keep) if keep else []


def classify(posting: dict, draft_grid, law_grid) -> dict | None:
    """Return the cause record of a posting, or None when it has a figure."""
    selector, _ = est.build_selector(posting)
    lsel = est.law_selector(selector, posting, draft_grid)
    draft = draft_grid.estimate(selector, gradation=0)
    law = law_grid.estimate(lsel, gradation=0)
    if draft.lei_min is not None or law.lei_min is not None:
        return None

    outside = outside_scope(posting)
    note = ""
    if not (selector.get("anexa") or selector.get("functie")):
        cause = "fara-selector"
        note = "selector gol" if not posting.get("grid_selector") else "fără anexa și fără functie"
    elif not base_rows(draft_grid, selector) and not base_rows(law_grid, lsel):
        cause = "fara-rand-functie"
        if selector.get("functie"):
            note = f"functie={selector['functie']!r} nu este în nicio grilă"
        else:
            note = f"anexa={selector.get('anexa')!r} dar fără functie și fără cod"
    else:
        # Rows exist by name, yet the estimate is empty: a narrowing step emptied them.
        cause = "filtru-gol"
        note = "pasul nu a fost găsit"
        for key in ("anexa", "regim", "tip_post", "nivel_administrativ",
                    "banda_populatie", "tip_unitate", "studii", "grad_treapta"):
            if selector.get(key):
                note = f"ultimul filtru setat: {key}={selector[key]!r}"
    # `in-afara-grilei` and `altul` stay for the report: today no posting reaches them,
    # because the first three causes take every posting. The `outside` flag carries it.
    return {
        "id": posting["id"], "cause": cause, "note": note, "outside": outside,
        "title": posting["title"], "employer": posting["employer_name"],
        "occupation": posting["canonical"], "selector": est.serialisable(selector),
    }


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--json", metavar="PATH", help="also write the results to this file")
    ap.add_argument("--examples", type=int, default=5, help="examples per cause")
    ap.add_argument("--top", type=int, default=10, help="occupations per cause")
    ap.add_argument("--database-url", default=est.DATABASE_URL)
    args = ap.parse_args()

    draft_grid = salary_grid.load_grid(salary_grid.DEFAULT_VERSION)
    law_grid = salary_grid.load_grid(salary_grid.LAW_VERSION)

    # Read-only: the session refuses every write.
    with psycopg.connect(args.database_url) as conn:
        conn.read_only = True
        with conn.cursor(row_factory=dict_row) as cur:
            cur.execute(est.SELECT_POSTINGS)
            postings = cur.fetchall()
            cur.execute("SELECT count(*), max(updated_at) FROM jobs_jobposting")
            total, last_update = cur.fetchone().values()
            cur.execute("SELECT count(*) AS n FROM jobs_jobposting WHERE occupation_id IS NULL")
            no_occ = cur.fetchone()["n"]

    recs = [r for p in postings if (r := classify(p, draft_grid, law_grid))]
    by_cause: dict[str, list] = defaultdict(list)
    for r in recs:
        by_cause[r["cause"]].append(r)

    report = {
        "postings_total": total, "postings_without_occupation": no_occ,
        "postings_with_occupation": len(postings), "latest_updated_at": str(last_update),
        "no_figure": len(recs),
        "causes": {}, "outside_flag_total": sum(1 for r in recs if r["outside"]),
    }
    print(f"postings in database: {total} (latest updated_at {last_update})")
    print(f"  with an occupation: {len(postings)} | without an occupation: {no_occ}")
    print(f"  with NO figure from either grid: {len(recs)}")
    print(f"  of these, flagged outside Legea 153/2017 (heuristic): {report['outside_flag_total']}\n")
    for cause in CAUSES:
        rs = by_cause.get(cause, [])
        occ = Counter(r["occupation"] for r in rs)
        outside = sum(1 for r in rs if r["outside"])
        print(f"== {cause}: {len(rs)} postings ({outside} also flagged outside the law)")
        for name, n in occ.most_common(args.top):
            print(f"   {n:5d}  {name}")
        for r in rs[:args.examples]:
            print(f"   - #{r['id']} | {r['title'][:70]} | {r['employer'][:40]} | "
                  f"{json.dumps(r['selector'], ensure_ascii=False)} | {r['note']}")
        print()
        report["causes"][cause] = {
            "count": len(rs), "also_outside": outside,
            "top_occupations": occ.most_common(args.top),
            "examples": rs[:args.examples],
        }
    notes = Counter(r["note"] for r in by_cause.get("altul", []))
    if notes:
        print("altul notes:", notes.most_common(10))
    if args.json:
        Path(args.json).write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str))
        print(f"wrote {args.json}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

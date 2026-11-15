#!/usr/bin/env python3
"""Replay the export checks over the recorded run history (FIX-04 calibration).

For each `export-check` record in the run log, in file order, this script:
  1. rebuilds the baselines that check-export.py saw at that moment, from the
     records before it only;
  2. runs check-export's evaluate() on the metrics stored in the record, with the
     clock pinned to the record's `checked_at`;
  3. repeats step 2 for each candidate knob set.

It is read-only. It never writes the run log, a database or an export. It uses an
empty in-memory SQLite connection for the integrity and foreign-key checks, so
those two checks always pass: the replay tests the metric-based checks only.

Usage:
    .venv/bin/python ops/replay-export-checks.py [--run-log PATH]
"""

from __future__ import annotations

import argparse
import importlib.util
import sqlite3
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_RUN_LOG = ROOT / "data" / "pipeline-runs.jsonl"


def _load_check_export():
    spec = importlib.util.spec_from_file_location(
        "check_export_for_replay", ROOT / "ops" / "check-export.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["check_export_for_replay"] = module
    spec.loader.exec_module(module)
    return module


ce = _load_check_export()


@dataclass(frozen=True)
class KnobSet:
    name: str
    schema_floor: float | None
    sustained_drop: float
    intake_max_age_days: float
    note: str = ""


#: A to D come from the brief. E is added to show what the floor adds on its own:
#: it is production before 2026-10-16, when the floor was set.
CANDIDATES = [
    KnobSet("A", 95, 5.0, 2.0, "production"),
    KnobSet("B", 90, 5.0, 2.0),
    KnobSet("C", 95, 3.0, 2.0),
    KnobSet("D", 95, 5.0, 3.0),
    KnobSet("E", None, 5.0, 2.0, "no floor (production before 2026-10-16)"),
]

#: Time windows in which a check should have fired, and the checks that count as
#: a detection. Failed runs (balance abort, import failures, kill) write no
#: export-check record, so the replay cannot test them: see `UNREPLAYABLE`.
INCIDENT_WINDOWS = [
    {
        "name": "schema decline 85.6 -> 60.1%",
        "start": "2026-09-23T15:33:00Z",
        "end": "2026-10-02T15:33:00Z",
        "checks": {"schema_sustained", "schema_floor", "schema_coverage"},
    },
]
UNREPLAYABLE = [
    "2026-10-12 18:33 abort on a low DeepSeek balance (no export-check record)",
    "2026-11-10T11:15Z, 2026-11-10T16:33Z, 2026-11-11T11:15Z import failure (no export-check record)",
    "2026-11-11T16:33Z import killed, exit -9 (no export-check record)",
]


def _parse(stamp: str | None) -> datetime | None:
    if not stamp:
        return None
    try:
        t = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
    except ValueError:
        return None
    return t if t.tzinfo else t.replace(tzinfo=timezone.utc)


def _window_of(run_id: str) -> dict | None:
    t = _parse(run_id)
    if t is None:
        return None
    for w in INCIDENT_WINDOWS:
        if _parse(w["start"]) <= t <= _parse(w["end"]):
            return w
    return None


def replay(records: list[dict], knob_sets=CANDIDATES) -> list[dict]:
    """One row per export-check record: {run_id, trigger, fired: {set: [..]}, ...}.

    `fired[set]` is a list of (check name, level). `error` holds the missing
    metric field when evaluate() could not run on the record.
    """
    runs = {r["run_id"]: r for r in records if r.get("kind") == "run" and "run_id" in r}
    rows = []
    con = sqlite3.connect(":memory:")
    try:
        for i, rec in enumerate(records):
            if rec.get("kind") != "export-check" or not rec.get("metrics"):
                continue
            run_id = rec.get("run_id") or "?"
            now = _parse(rec.get("checked_at")) or _parse(run_id)
            baselines = ce.baselines_from_records(records[:i])
            run = runs.get(run_id, {})
            prompt_version = (run.get("flags") or {}).get("prompt_version")
            row = {
                "run_id": run_id,
                "trigger": run.get("trigger", "?"),
                "recorded_status": rec.get("status"),
                "fired": {},
                "checks": {},
                "error": None,
            }
            for ks in knob_sets:
                try:
                    checks = ce.evaluate(
                        con, rec["metrics"], baselines,
                        prompt_version=prompt_version,
                        max_age_hours=6.0,
                        schema_floor=ks.schema_floor,
                        sustained_drop=ks.sustained_drop,
                        intake_max_age_days=ks.intake_max_age_days,
                        now=now,
                    )
                except KeyError as exc:
                    row["error"] = f"metric field missing: {exc}"
                    break
                row["checks"][ks.name] = {c.name: c for c in checks}
                row["fired"][ks.name] = [(c.name, c.level) for c in checks if not c.ok]
            rows.append(row)
    finally:
        con.close()
    return rows


def fidelity(rows: list[dict], records: list[dict]) -> list[str]:
    """Differences between the replay under set A and what the run recorded.

    Only checks present in both are compared. A difference means the metrics or
    the baselines of the replay do not match production at that time.
    """
    recorded = {r["run_id"]: r for r in records
                if r.get("kind") == "export-check" and "run_id" in r}
    out = []
    for row in rows:
        if row["error"] or "A" not in row["checks"]:
            continue
        rec_checks = {c["name"]: c["ok"] for c in recorded[row["run_id"]].get("checks", [])}
        for name, check in row["checks"]["A"].items():
            if name in rec_checks and rec_checks[name] != check.ok:
                out.append(f"{row['run_id']} {name}: recorded ok={rec_checks[name]}, replay ok={check.ok}")
    return out


def summarise(rows: list[dict], knob_sets=CANDIDATES) -> dict:
    """Per set: incident hits and false positives."""
    summary = {}
    for ks in knob_sets:
        in_window = hits = 0
        first_hit = None
        false_pos = []
        for row in rows:
            if row["error"] or ks.name not in row["fired"]:
                continue
            fired = row["fired"][ks.name]
            window = _window_of(row["run_id"])
            if window:
                in_window += 1
                if any(name in window["checks"] for name, _ in fired):
                    hits += 1
                    first_hit = first_hit or row["run_id"]
            elif fired:
                false_pos.append((row["run_id"], fired))
        summary[ks.name] = {
            "in_window": in_window, "hits": hits,
            "first_hit": first_hit, "false_positives": false_pos,
        }
    return summary


def _fmt(fired: list[tuple[str, str]]) -> str:
    return ",".join(f"{n}({'H' if lvl == 'hard' else 'S'})" for n, lvl in fired) or "-"


def report(rows, records, knob_sets=CANDIDATES, out=None) -> None:
    out = out or sys.stdout
    def p(text=""):
        print(text, file=out)

    p(f"export-check records replayed: {len(rows)}")
    skipped = [r for r in rows if r["error"]]
    p(f"records the replay could not evaluate: {len(skipped)}")
    for r in skipped:
        p(f"  {r['run_id']}: {r['error']}")

    p("\nKnob sets")
    for ks in knob_sets:
        floor = "none" if ks.schema_floor is None else f"{ks.schema_floor:g}"
        p(f"  {ks.name}: schema-floor {floor}, sustained-drop {ks.sustained_drop:g}, "
          f"intake-max-age-days {ks.intake_max_age_days:g}"
          + (f"  [{ks.note}]" if ks.note else ""))

    p("\nPer run: checks that fired (H hard, S soft; '-' none)")
    for row in rows:
        if row["error"]:
            p(f"{row['run_id']}  {row['trigger']:<7} ERROR {row['error']}")
            continue
        a = _fmt(row["fired"]["A"])
        p(f"{row['run_id']}  {row['trigger']:<7} recorded={row['recorded_status']:<5} A: {a}")
        for ks in knob_sets[1:]:
            other = _fmt(row["fired"][ks.name])
            if other != a:
                p(f"{'':>20}  {'':<7} {'':<14} {ks.name}: {other}")

    summary = summarise(rows, knob_sets)
    p("\nSummary per set")
    p("  set  in-window  detected  first detection        false positives")
    for ks in knob_sets:
        s = summary[ks.name]
        p(f"  {ks.name:<4} {s['in_window']:>9}  {s['hits']:>8}  "
          f"{(s['first_hit'] or '-'):<22} {len(s['false_positives'])}")
    for ks in knob_sets:
        fps = summary[ks.name]["false_positives"]
        if fps:
            p(f"\nFalse positives, set {ks.name}")
            for run_id, fired in fps:
                p(f"  {run_id}  {_fmt(fired)}")

    diffs = fidelity(rows, records)
    p(f"\nFidelity: replay under A against the recorded checks, {len(diffs)} difference(s)")
    for d in diffs:
        p(f"  {d}")

    p("\nNot replayable (no export-check record exists):")
    for u in UNREPLAYABLE:
        p(f"  {u}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--run-log", default=str(DEFAULT_RUN_LOG), metavar="PATH",
                    help="Run log to replay (default data/pipeline-runs.jsonl). Read only.")
    args = ap.parse_args()
    path = Path(args.run_log)
    if not path.exists():
        print(f"ERROR: no run log at {path}", file=sys.stderr)
        return 1
    records = ce.read_run_log(path)
    rows = replay(records)
    if not rows:
        print(f"No export-check records in {path}.", file=sys.stderr)
        return 1
    report(rows, records)
    return 0


if __name__ == "__main__":
    sys.exit(main())

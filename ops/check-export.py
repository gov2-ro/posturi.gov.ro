#!/usr/bin/env python3
"""Assert that the SQLite about to ship is fit to ship.

Runs between `export-sqlite` and `deploy-php.sh --data-only`, against the file
the deploy is about to rsync. `export-to-sqlite.py` already refuses a corrupt or
collapsed export; this adds the data-shape and quality layer, and records what it
measured so the next run can compare.

Two severities, because a false abort is worse than a stale-but-correct site:

  HARD — corruption. 42 counties are not 43, a posting cannot appear twice, the
         FTS index cannot be short, and the file must be the one this run built.
         A breach exits non-zero and stops the deploy.
  SOFT — quality drift. Coverage regressions, share step-changes, encoding
         damage. Recorded and printed; exits non-zero only under --strict.

Every check is tied to a regression that actually happened here — see
docs/pipeline-quality-checks.md for the watch-list this implements and
docs/backlog.md for the bugs.

Usage:
    ops/check-export.py                                  # after the export
    ops/check-export.py --db /tmp/copy.sqlite --no-log   # inspect a copy
    ops/check-export.py --strict                         # warnings fail too
"""
from __future__ import annotations

import argparse
import json
import os
import socket
import sqlite3
import sys
from collections import Counter
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DB = ROOT / "webapp-php" / "posturi.sqlite"
DEFAULT_RUN_LOG = ROOT / "data" / "pipeline-runs.jsonl"

#: Fallback when webapp/apps/jobs/judete.py cannot be read. 41 counties + București.
FALLBACK_COUNTIES = 42

#: A posting body under this many characters is a scrape or parse failure, not a
#: terse advert. Matches the `no_body` anomaly flag in infer_postings.
SHORT_BODY_CHARS = 100

#: Window for the expiry-parsing ratio, kept equal to import_csvs.RECENT_WINDOW_DAYS.
RECENT_WINDOW_DAYS = 30


# ---------------------------------------------------------------------------
# Reused thresholds
# ---------------------------------------------------------------------------

def expected_counties() -> int:
    """len(judete.COUNTIES), loaded straight from the file.

    importlib rather than `from apps.jobs.judete import COUNTIES` so the checker
    never drags in the `apps` package (and therefore Django) — it has to run on a
    laptop against a copied export with nothing installed.
    """
    import importlib.util

    path = ROOT / "webapp" / "apps" / "jobs" / "judete.py"
    try:
        spec = importlib.util.spec_from_file_location("_judete", path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return len(module.COUNTIES)
    except Exception:
        return FALLBACK_COUNTIES


def django_sanity_warnings(m: dict) -> tuple[list[str], str | None]:
    """Run import_csvs' two sanity functions over the exported numbers.

    They encode thresholds that already earned their place at import time, so
    they are called rather than restated. Importing them needs Django, which is
    present on the VPS and may not be wherever this is being run by hand —
    hence the second return value, a reason the checks were skipped.
    """
    try:
        sys.path.insert(0, str(ROOT / "webapp"))
        os.environ.setdefault("DJANGO_SETTINGS_MODULE", "posturi.settings")
        import django

        django.setup()
        from apps.jobs.management.commands.import_csvs import (
            expiry_sanity_warnings,
            judet_sanity_warnings,
        )
    except Exception as exc:
        return [], f"{type(exc).__name__}: {exc}"

    warnings = expiry_sanity_warnings(
        m["active"], m["published_recent"], m["published_recent_no_expiry"]
    )
    warnings += judet_sanity_warnings(
        m["judete"], m["postings_without_judet"], m["job_postings"]
    )
    return warnings, None


# ---------------------------------------------------------------------------
# Metrics
# ---------------------------------------------------------------------------

def _pct(part: int, whole: int) -> float:
    return round(100.0 * part / whole, 1) if whole else 0.0


def collect_metrics(con: sqlite3.Connection, db_path: Path) -> dict:
    """Everything the checks need, in one pass over the export."""
    def one(sql: str, *args):
        return con.execute(sql, args).fetchone()[0]

    today = date.today()
    today_s = today.isoformat()
    recent_cutoff = (today - timedelta(days=RECENT_WINDOW_DAYS)).isoformat()
    # Not today.replace(year=…): that raises on 29 February.
    max_expiry = (today + timedelta(days=730)).isoformat()

    m: dict = {
        "job_postings": one("SELECT COUNT(*) FROM job_postings"),
        "employers": one("SELECT COUNT(*) FROM employers"),
        "judete": one("SELECT COUNT(*) FROM judete"),
        "calendar_events": one("SELECT COUNT(*) FROM calendar_events"),
        "fts_rows": one("SELECT COUNT(*) FROM job_postings_fts"),
        "file_bytes": db_path.stat().st_size,
    }
    total = m["job_postings"]

    m["active"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE expires_at >= ?", today_s)
    m["duplicate_urls"] = one(
        "SELECT COUNT(*) FROM (SELECT url FROM job_postings "
        "GROUP BY url HAVING COUNT(*) > 1)")

    # Dates -----------------------------------------------------------------
    m["published_recent"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE published_at >= ?", recent_cutoff)
    m["published_recent_no_expiry"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE published_at >= ? "
        "AND (expires_at IS NULL OR expires_at = '')", recent_cutoff)
    m["expires_out_of_range"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE expires_at IS NOT NULL "
        "AND expires_at != '' AND (expires_at < '2000-01-01' OR expires_at > ?)",
        max_expiry)
    m["published_in_future"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE published_at > ?", today_s)
    m["published_after_expiry"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE published_at IS NOT NULL "
        "AND expires_at IS NOT NULL AND expires_at != '' AND published_at > expires_at")

    # Județe ----------------------------------------------------------------
    m["postings_without_judet"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE judet_id IS NULL OR judet_slug = ''")
    # Cedilla ş/ţ (U+015F/U+0163) where the canonical form is comma-below ș/ț.
    m["judete_cedilla"] = one(
        "SELECT COUNT(*) FROM judete WHERE name LIKE '%ş%' OR name LIKE '%ţ%'")

    # Extraction coverage ---------------------------------------------------
    schema_rows = one(
        "SELECT COUNT(*) FROM job_postings WHERE schema_json IS NOT NULL "
        "AND schema_json NOT IN ('', 'null', '{}')")
    v3_rows = one(
        "SELECT COUNT(*) FROM job_postings WHERE v3_eqf_level IS NOT NULL "
        "OR v3_study_level != '' OR v3_skills != '[]'")
    attach_rows = one(
        "SELECT COUNT(*) FROM job_postings WHERE attachment_meta NOT IN ('', '[]')")
    short_body = one(
        "SELECT COUNT(*) FROM job_postings WHERE length(body_markdown) < ?",
        SHORT_BODY_CHARS)
    m["schema_coverage_pct"] = _pct(schema_rows, total)
    m["v3_coverage_pct"] = _pct(v3_rows, total)
    m["v3_rows"] = v3_rows
    m["schema_rows"] = schema_rows
    m["attachment_coverage_pct"] = _pct(attach_rows, total)
    m["short_body_pct"] = _pct(short_body, total)

    # Inference -------------------------------------------------------------
    families = Counter()
    for fam, n in con.execute(
        "SELECT COALESCE(inf_profession_family, ''), COUNT(*) "
        "FROM job_postings GROUP BY 1"
    ):
        families[fam] = n
    m["family_populated_pct"] = _pct(total - families.get("", 0), total)
    m["family_altele_pct"] = _pct(families.get("altele", 0), total)
    ranked = [(f, n) for f, n in families.most_common() if f not in ("", "altele")]
    m["family_top"] = ranked[0][0] if ranked else ""
    m["family_top_pct"] = _pct(ranked[0][1], total) if ranked else 0.0
    m["family_shares_pct"] = {f: _pct(n, total) for f, n in families.items() if f}

    # Anomaly flags and skills both live in JSON arrays; one pass over the rows.
    flags = Counter()
    skills = Counter()
    for flags_json, skills_json in con.execute(
        "SELECT inf_anomaly_flags, v3_skills FROM job_postings"
    ):
        for column, counter in ((flags_json, flags), (skills_json, skills)):
            try:
                values = json.loads(column or "[]")
            except (json.JSONDecodeError, TypeError):
                continue
            if isinstance(values, list):
                counter.update(v for v in values if isinstance(v, str))
    m["anomaly_pct"] = {f: _pct(n, total) for f, n in flags.items()}
    m["skill_top"] = skills.most_common(1)[0][0] if skills else ""
    m["skill_top_pct"] = _pct(skills.most_common(1)[0][1], v3_rows) if skills else 0.0

    # Encoding --------------------------------------------------------------
    m["mojibake_rows"] = one(
        "SELECT COUNT(*) FROM job_postings WHERE "
        "title LIKE '%Ã%' OR title LIKE '%â€%' OR title LIKE '%�%' OR "
        "employer_name LIKE '%Ã%' OR employer_name LIKE '%â€%' OR "
        "body_markdown LIKE '%â€%' OR body_markdown LIKE '%�%'")
    # A literal backslash-n, i.e. a newline that was escaped instead of written.
    m["literal_newline_rows"] = one(
        r"SELECT COUNT(*) FROM job_postings WHERE body_markdown LIKE '%\n%'")

    # build_meta ------------------------------------------------------------
    try:
        row = con.execute(
            "SELECT built_at, git_sha, source_host, active_only FROM build_meta "
            "WHERE id = 1").fetchone()
    except sqlite3.Error:
        row = None
    m["build_meta"] = (
        {"built_at": row[0], "git_sha": row[1], "source_host": row[2],
         "active_only": bool(row[3])}
        if row else None
    )
    m["last_seen_at_max"] = one(
        "SELECT COALESCE(MAX(last_seen_at), '') FROM job_postings")
    return m


# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

@dataclass
class Check:
    name: str
    level: str          # "hard" | "soft"
    ok: bool
    message: str

    @property
    def label(self) -> str:
        if self.ok:
            return "PASS"
        return "FAIL" if self.level == "hard" else "WARN"


def _age_hours(stamp: str | None) -> float | None:
    if not stamp:
        return None
    try:
        built = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
    except ValueError:
        return None
    if built.tzinfo is None:
        built = built.replace(tzinfo=timezone.utc)
    return (datetime.now(timezone.utc) - built).total_seconds() / 3600


def evaluate(
    con: sqlite3.Connection,
    m: dict,
    previous: dict | None,
    *,
    prompt_version: str | None,
    max_age_hours: float,
) -> list[Check]:
    checks: list[Check] = []

    def hard(name, ok, message):
        checks.append(Check(name, "hard", ok, message))

    def soft(name, ok, message):
        checks.append(Check(name, "soft", ok, message))

    def prev(key, default=None):
        return previous.get(key, default) if previous else default

    # -- HARD: the file is intact ------------------------------------------
    integrity = con.execute("PRAGMA integrity_check").fetchone()[0]
    hard("integrity", integrity == "ok", f"PRAGMA integrity_check = {integrity!r}")

    fk_broken = con.execute("PRAGMA foreign_key_check").fetchall()
    hard("foreign_keys", not fk_broken,
         f"{len(fk_broken)} row(s) point at a missing employer or județ"
         if fk_broken else "every employer_id and judet_id resolves")

    hard("not_empty", m["job_postings"] > 0 and m["employers"] > 0,
         f"{m['job_postings']} postings, {m['employers']} employers")

    hard("no_duplicate_urls", m["duplicate_urls"] == 0,
         f"{m['duplicate_urls']} url(s) appear more than once")

    hard("fts_complete", m["fts_rows"] == m["job_postings"],
         f"FTS index has {m['fts_rows']} rows against {m['job_postings']} postings"
         if m["fts_rows"] != m["job_postings"] else
         f"{m['fts_rows']} rows, matching job_postings")

    # 261 Judet rows for a country with 42 counties silently split every facet.
    n_counties = expected_counties()
    hard("judete_count", m["judete"] == n_counties,
         f"{m['judete']} județ rows, expected exactly {n_counties}"
         if m["judete"] != n_counties else f"{n_counties} counties")

    before = prev("job_postings")
    hard("no_collapse",
         not (before and m["job_postings"] < before * 0.5),
         f"{m['job_postings']} postings, down from {before} last run — that is a "
         f"collapse, not a day's churn" if before and m["job_postings"] < before * 0.5
         else f"{m['job_postings']} postings"
              + (f" vs {before} last run" if before else " (no baseline)"))

    # The deploy ships whatever is on disk; a stale file means the export never ran.
    meta = m["build_meta"]
    age = _age_hours(meta["built_at"]) if meta else None
    hard("build_meta_fresh", meta is not None and age is not None and age <= max_age_hours,
         "no build_meta row — this export predates provenance tracking" if not meta
         else f"built_at {meta['built_at']} is {age:.1f}h old (limit {max_age_hours:g}h)"
         if age is None or age > max_age_hours
         else f"built {age:.1f}h ago on {meta['source_host'] or '?'} "
              f"@ {meta['git_sha'] or '?'}")

    # -- SOFT: the data is plausible ---------------------------------------
    prev_active = prev("active")
    if prev_active:
        ratio = m["active"] / prev_active
        soft("active_band", 0.75 <= ratio <= 1.5,
             f"{m['active']} active, {ratio:.2f}× the {prev_active} of last run")
    else:
        soft("active_band", True, f"{m['active']} active (no baseline)")

    warnings, skip_reason = django_sanity_warnings(m)
    if skip_reason:
        soft("import_sanity", True, f"skipped — Django unavailable ({skip_reason})")
    else:
        soft("import_sanity", not warnings,
             "; ".join(warnings) if warnings
             else "expiry and județ sanity checks pass on the export")

    soft("expiry_range", m["expires_out_of_range"] == 0,
         f"{m['expires_out_of_range']} posting(s) expire outside 2000..today+2y "
         f"(a typo'd year makes a posting permanently active)")
    soft("published_not_future", m["published_in_future"] == 0,
         f"{m['published_in_future']} posting(s) published in the future")
    soft("published_before_expiry", m["published_after_expiry"] == 0,
         f"{m['published_after_expiry']} posting(s) expire before they were published")

    prev_events = prev("calendar_events")
    soft("calendar_events",
         m["calendar_events"] > 0 and not (prev_events and m["calendar_events"] < prev_events * 0.5),
         f"{m['calendar_events']} events"
         + (f" vs {prev_events} last run" if prev_events else ""))

    def coverage(name, key, label, floor_drop=5.0):
        now = m[key]
        was = prev(key)
        drop = (was - now) if was is not None else 0.0
        soft(name, drop <= floor_drop,
             f"{label} {now:.1f}%"
             + (f" vs {was:.1f}% last run ({-drop:+.1f} pts)" if was is not None else ""))

    coverage("schema_coverage", "schema_coverage_pct", "schema_json on")
    coverage("attachment_coverage", "attachment_coverage_pct", "attachment metadata on")
    coverage("v3_coverage", "v3_coverage_pct", "v3 columns on")

    # A v2 payload landing under a --prompt-version v3 run leaves every v3 facet
    # empty and nothing else fails.
    if prompt_version == "v3":
        soft("v3_populated", m["schema_rows"] == 0 or m["v3_rows"] > 0,
             f"{m['v3_rows']} of {m['schema_rows']} extracted postings carry v3 columns"
             + (" — the run pinned v3 but a v2 payload landed" if m["v3_rows"] == 0 else ""))

    prev_altele = prev("family_altele_pct")
    soft("altele_share",
         prev_altele is None or m["family_altele_pct"] - prev_altele <= 10,
         f"altele {m['family_altele_pct']:.1f}%"
         + (f" vs {prev_altele:.1f}% last run" if prev_altele is not None else ""))

    # "IT" once absorbed every unparseable classification.
    prev_top = prev("family_top_pct", 0.0) or 0.0
    soft("family_concentration",
         m["family_top_pct"] < 30 or m["family_top_pct"] <= prev_top + 5,
         f"largest family {m['family_top'] or '—'} at {m['family_top_pct']:.1f}%"
         + (f" vs {prev_top:.1f}% last run" if previous else ""))

    # Substring matching once put "SAR" on 87% of postings.
    soft("skill_concentration", m["skill_top_pct"] <= 40,
         f"most common skill {m['skill_top'] or '—'} on {m['skill_top_pct']:.1f}% "
         f"of v3 rows")

    prev_flags = prev("anomaly_pct") or {}
    jumps = [
        f"{flag} {prev_flags.get(flag, 0.0):.1f}%→{pct:.1f}%"
        for flag, pct in m["anomaly_pct"].items()
        if abs(pct - prev_flags.get(flag, pct)) > 15
    ]
    soft("anomaly_stable", not jumps,
         "; ".join(jumps) if jumps else
         f"{len(m['anomaly_pct'])} flag(s), none moved more than 15 pts")

    prev_short = prev("short_body_pct")
    soft("bodies_present",
         prev_short is None or m["short_body_pct"] - prev_short <= 10,
         f"{m['short_body_pct']:.1f}% of postings have a body under "
         f"{SHORT_BODY_CHARS} chars"
         + (f" vs {prev_short:.1f}% last run" if prev_short is not None else ""))

    soft("encoding",
         m["mojibake_rows"] == 0 and m["literal_newline_rows"] == 0
         and m["judete_cedilla"] == 0,
         f"{m['mojibake_rows']} row(s) with mojibake, "
         f"{m['literal_newline_rows']} with a literal \\n, "
         f"{m['judete_cedilla']} județ name(s) using cedilla ş/ţ")

    return checks


# ---------------------------------------------------------------------------
# Reporting
# ---------------------------------------------------------------------------

#: Printed as a before/after table. Everything else lives in the JSON record.
HEADLINE = [
    ("job_postings", "postings", "{:,.0f}"),
    ("active", "active", "{:,.0f}"),
    ("employers", "employers", "{:,.0f}"),
    ("judete", "județe", "{:,.0f}"),
    ("calendar_events", "calendar events", "{:,.0f}"),
    ("schema_coverage_pct", "schema_json %", "{:.1f}"),
    ("v3_coverage_pct", "v3 columns %", "{:.1f}"),
    ("attachment_coverage_pct", "attachments %", "{:.1f}"),
    ("family_altele_pct", "family=altele %", "{:.1f}"),
    ("short_body_pct", "short bodies %", "{:.1f}"),
    ("file_bytes", "file MB", "{:,.1f}"),
]


def print_report(m: dict, previous: dict | None, checks: list[Check]) -> None:
    print(f"\n{'=' * 72}")
    print("  EXPORT CHECK")
    print("=" * 72)

    print(f"\n  {'metric':<20} {'previous':>12} {'current':>12} {'delta':>12}")
    print(f"  {'-' * 20} {'-' * 12} {'-' * 12} {'-' * 12}")
    for key, label, fmt in HEADLINE:
        now = m.get(key)
        was = previous.get(key) if previous else None
        if key == "file_bytes":
            now = now / 1e6 if now is not None else None
            was = was / 1e6 if was is not None else None
        delta = f"{now - was:+,.1f}" if (now is not None and was is not None) else "—"
        print(f"  {label:<20} {fmt.format(was) if was is not None else '—':>12} "
              f"{fmt.format(now) if now is not None else '—':>12} {delta:>12}")

    print()
    # All on stdout, failures first. The cron log is `>> pipeline.log 2>&1`, where
    # an unbuffered stderr would otherwise print its lines before the table they
    # belong under.
    for check in sorted(checks, key=lambda c: (c.ok, c.level != "hard")):
        print(f"  [{check.label}] {check.name:<24} {check.message}")
    sys.stdout.flush()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def load_previous(run_log: Path) -> dict | None:
    """Metrics from the most recent export-check record, or None."""
    if not run_log.exists():
        return None
    latest = None
    try:
        with run_log.open(encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line:
                    continue
                try:
                    rec = json.loads(line)
                except json.JSONDecodeError:
                    continue
                if rec.get("kind") == "export-check" and rec.get("metrics"):
                    latest = rec["metrics"]
    except OSError as exc:
        print(f"  WARNING: could not read {run_log}: {exc}", file=sys.stderr)
    return latest


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Assert the exported SQLite is fit to deploy.")
    ap.add_argument("--db", default=str(DEFAULT_DB), metavar="PATH",
                    help=f"SQLite export to check (default: "
                         f"{DEFAULT_DB.relative_to(ROOT)})")
    ap.add_argument("--run-log", default=str(DEFAULT_RUN_LOG), metavar="PATH",
                    help="Run log to read the previous run's metrics from and "
                         "append this run's to.")
    ap.add_argument("--no-log", action="store_true",
                    help="Do not append a record (still reads the baseline).")
    ap.add_argument("--strict", action="store_true",
                    help="Exit non-zero on soft warnings too, not just hard failures.")
    ap.add_argument("--prompt-version", default=os.environ.get("LLM_PROMPT_VERSION"),
                    metavar="VERSION",
                    help="Prompt version the run pinned; enables the v3-populated "
                         "check. Defaults to $LLM_PROMPT_VERSION.")
    ap.add_argument("--max-age-hours", type=float, default=6.0, metavar="H",
                    help="Fail if build_meta.built_at is older than this (default 6). "
                         "Catches a deploy of a file the export never rebuilt.")
    args = ap.parse_args()

    db_path = Path(args.db)
    if not db_path.exists():
        print(f"ERROR: no export at {db_path}", file=sys.stderr)
        return 1

    try:
        con = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    except sqlite3.Error as exc:
        print(f"ERROR: cannot open {db_path}: {exc}", file=sys.stderr)
        return 1

    run_log = Path(args.run_log)
    previous = load_previous(run_log)
    try:
        metrics = collect_metrics(con, db_path)
        checks = evaluate(con, metrics, previous,
                          prompt_version=args.prompt_version,
                          max_age_hours=args.max_age_hours)
    except sqlite3.Error as exc:
        print(f"ERROR: the export at {db_path} is unreadable: {exc}", file=sys.stderr)
        return 1
    finally:
        con.close()

    print_report(metrics, previous, checks)

    failed_hard = [c for c in checks if not c.ok and c.level == "hard"]
    warned = [c for c in checks if not c.ok and c.level == "soft"]
    status = "fail" if failed_hard else ("warn" if warned else "ok")

    if not args.no_log:
        # Written even on failure: a blocked deploy is exactly the run you want a
        # record of. Imported here to keep this script standalone-runnable.
        sys.path.insert(0, str(ROOT))
        from pipeline import append_run_record

        append_run_record(run_log, {
            "kind": "export-check",
            "run_id": os.environ.get("POSTURI_RUN_ID") or
                      datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "checked_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "host": socket.gethostname(),
            "db": str(db_path),
            "status": status,
            "strict": args.strict,
            "metrics": metrics,
            "checks": [
                {"name": c.name, "level": c.level, "ok": c.ok, "message": c.message}
                for c in checks
            ],
        })

    print()
    if failed_hard:
        names = ", ".join(c.name for c in failed_hard)
        print(f"  ✗ {len(failed_hard)} hard check(s) failed ({names}) — refusing to "
              f"deploy this export.")
        print("    The previously deployed database stays in place. Investigate "
              "before re-running.")
        print("=" * 72)
        sys.stdout.flush()
        print(f"check-export: FAILED — {names}", file=sys.stderr)
        return 1
    if warned:
        print(f"  {'✗' if args.strict else '⚠'} {len(warned)} warning(s)"
              f"{' — --strict, so this is a failure' if args.strict else ''}.")
        print("=" * 72)
        return 1 if args.strict else 0
    print(f"  ✓ all {len(checks)} checks pass.")
    print("=" * 72)
    return 0


if __name__ == "__main__":
    sys.exit(main())

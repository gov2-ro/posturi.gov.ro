"""FIX-04 calibration — ops/replay-export-checks.py on a synthetic run log.

The log has one slow slide like the one in September 2026 (schema coverage loses
1.5 points per run, so no single run trips the per-run drop) and one failed
candidate that was never deployed.
"""

from __future__ import annotations

import importlib.util
import json
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


@pytest.fixture(scope="module")
def replay():
    spec = importlib.util.spec_from_file_location(
        "replay_under_test", REPO_ROOT / "ops" / "replay-export-checks.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["replay_under_test"] = module
    spec.loader.exec_module(module)
    return module


def _stamp(t: datetime) -> str:
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


def _metrics(t: datetime, *, coverage: float = 100.0, active: int = 100) -> dict:
    return {
        "job_postings": 1000, "employers": 300, "judete": 42, "calendar_events": 500,
        "fts_rows": 1000, "duplicate_urls": 0, "file_bytes": 5_000_000,
        "build_meta": {"built_at": _stamp(t - timedelta(hours=1)), "git_sha": "abc",
                       "source_host": "test", "active_only": True},
        "active": active, "published_recent": 100, "published_recent_no_expiry": 0,
        "postings_without_judet": 0,
        "expires_out_of_range": 0, "published_in_future": 0, "published_after_expiry": 0,
        "schema_coverage_pct": coverage, "attachment_coverage_pct": 50.0,
        "v3_coverage_pct": 100.0, "title_fill_pct": 100.0, "employer_fill_pct": 100.0,
        "published_fill_pct": 100.0, "body_fill_pct": 100.0,
        "family_altele_pct": 5.0, "family_top": "IT", "family_top_pct": 20.0,
        "skill_top": "x", "skill_top_pct": 10.0, "anomaly_pct": {"a": 1.0},
        "short_body_pct": 1.0, "mojibake_rows": 0, "literal_newline_rows": 0,
        "judete_cedilla": 0, "newest_published": t.date().isoformat(),
        "schema_rows": 1000, "v3_rows": 1000,
    }


def _build_log(path: Path) -> list[str]:
    """13 runs: coverage slides from 100 by 1.5 per run from run 3; run 6 is a
    failed candidate (10 active postings against 100) that is never deployed."""
    start = datetime(2026, 9, 22, 11, 15, tzinfo=timezone.utc)   # a Tuesday
    lines, run_ids = [], []
    coverage = 100.0
    for n in range(13):
        t = start + timedelta(hours=6 * n)
        run_id = _stamp(t)
        failed = n == 6
        if n >= 3 and not failed:
            coverage -= 1.5
        active = 10 if failed else 100
        lines.append({"kind": "run", "run_id": run_id, "trigger": "cron",
                      "flags": {"prompt_version": "v4"}})
        lines.append({"kind": "export-check", "run_id": run_id,
                      "checked_at": _stamp(t + timedelta(minutes=30)),
                      "status": "warn" if failed else "ok",
                      "metrics": _metrics(t, coverage=coverage, active=active),
                      "checks": []})
        if not failed:
            lines.append({"kind": "deploy", "run_id": run_id, "deployed": True})
        run_ids.append(run_id)
    path.write_text("".join(json.dumps(r) + "\n" for r in lines), encoding="utf-8")
    return run_ids


def test_replay_flags_the_slow_slide_and_does_not_flag_before_it(replay, tmp_path):
    log = tmp_path / "runs.jsonl"
    run_ids = _build_log(log)
    rows = replay.replay(replay.ce.read_run_log(log))
    by_id = {r["run_id"]: r for r in rows}

    assert len(rows) == 13
    assert not any(r["error"] for r in rows)
    # No single run drops more than 1.5 points: the per-run check never fires.
    assert not any("schema_coverage" in dict(r["fired"]["A"]) for r in rows)
    # Before the slide, nothing fires.
    for run_id in run_ids[:3]:
        assert by_id[run_id]["fired"]["A"] == []
    # The sustained check catches the slide.
    caught = [r["run_id"] for r in rows if "schema_sustained" in dict(r["fired"]["A"])]
    assert caught, "the sustained check never fired on a 1.5 pts/run decline"
    assert caught[0] > run_ids[3]


def test_failed_run_does_not_lower_the_next_baseline(replay, tmp_path):
    log = tmp_path / "runs.jsonl"
    run_ids = _build_log(log)
    by_id = {r["run_id"]: r for r in replay.replay(replay.ce.read_run_log(log))}

    failed, after = by_id[run_ids[6]], by_id[run_ids[7]]
    # The failed candidate (10 active against 100) fires on its own run ...
    assert "active_band" in dict(failed["fired"]["A"])
    # ... but it was never deployed, so the next run compares with run 5, not 6.
    assert "active_band" not in dict(after["fired"]["A"])


def test_summary_counts_hits_in_a_window_and_false_positives_outside(replay, tmp_path):
    log = tmp_path / "runs.jsonl"
    _build_log(log)
    rows = replay.replay(replay.ce.read_run_log(log))

    # The synthetic dates overlap the real September window: runs 5 to 12 are inside it.
    inside = replay.summarise(rows)
    assert inside["A"]["in_window"] == 8   # the first five runs are before the window
    assert inside["A"]["hits"] >= 1
    assert inside["A"]["first_hit"] is not None

    # Move the window away: the same firings are now false positives.
    original = replay.INCIDENT_WINDOWS
    try:
        replay.INCIDENT_WINDOWS = [dict(original[0], start="2030-01-01T00:00:00Z",
                                        end="2030-02-01T00:00:00Z")]
        outside = replay.summarise(rows)
    finally:
        replay.INCIDENT_WINDOWS = original
    assert outside["A"]["in_window"] == 0
    names = {n for _, fired in outside["A"]["false_positives"] for n, _ in fired}
    assert {"schema_sustained", "active_band"} <= names


def test_replay_never_writes_the_run_log(replay, tmp_path, capsys, monkeypatch):
    log = tmp_path / "runs.jsonl"
    _build_log(log)
    before = log.read_bytes()
    monkeypatch.setattr(sys, "argv", ["replay", "--run-log", str(log)])
    assert replay.main() == 0
    assert log.read_bytes() == before
    assert "Summary per set" in capsys.readouterr().out


def test_missing_metric_field_is_reported_not_invented(replay, tmp_path):
    log = tmp_path / "runs.jsonl"
    t = datetime(2026, 9, 22, 11, 15, tzinfo=timezone.utc)
    metrics = _metrics(t)
    del metrics["schema_coverage_pct"]
    log.write_text(json.dumps({"kind": "export-check", "run_id": _stamp(t),
                               "checked_at": _stamp(t), "status": "ok",
                               "metrics": metrics, "checks": []}) + "\n")
    rows = replay.replay(replay.ce.read_run_log(log))
    assert rows[0]["error"] and "schema_coverage_pct" in rows[0]["error"]



def test_missing_fill_rate_is_backfilled_not_skipped(replay, tmp_path):
    """Records from before the fill-rate checks still replay. On 2026-11-15 the VPS
    replay skipped all 47 records of 2026-09-10..10-03 for `title_fill_pct`, so the
    September slide was never evaluated."""
    log = tmp_path / "runs.jsonl"
    _build_log(log)
    records = replay.ce.read_run_log(log)
    for rec in records:
        if rec.get("kind") == "export-check":
            for key in ("title_fill_pct", "body_fill_pct"):
                rec["metrics"].pop(key, None)
    rows = replay.replay(records)
    assert rows and not any(r["error"] for r in rows)
    assert all(set(r["backfilled"]) == {"title_fill_pct", "body_fill_pct"} for r in rows)
    assert any("schema_sustained" in dict(r["fired"]["A"]) for r in rows)

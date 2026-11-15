"""BUG-IMPORT-KILL — `pipeline._run_step` records the peak RSS of each child step."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture
def pipeline():
    spec = importlib.util.spec_from_file_location("pipeline_rss_under_test", REPO_ROOT / "pipeline.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["pipeline_rss_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


def _alloc(mb: int, code: int = 0) -> list[str]:
    script = f"b = bytearray({mb} * 1024 * 1024); b[::4096] = b'x' * len(b[::4096]); raise SystemExit({code})"
    return [sys.executable, "-c", script]


def test_records_peak_rss_of_the_child(pipeline):
    rec = pipeline._run_step("alloc", _alloc(200))
    assert rec["ok"] and rec["exit"] == 0
    assert 200 <= rec["max_rss_mb"] < 400


def test_peak_is_per_step_not_largest_so_far(pipeline):
    big = pipeline._run_step("big", _alloc(300))
    small = pipeline._run_step("small", _alloc(20))
    assert big["max_rss_mb"] >= 300
    assert small["max_rss_mb"] < 150


def test_failed_step_keeps_exit_code_and_rss(pipeline):
    rec = pipeline._run_step("fail", _alloc(50, code=3))
    assert not rec["ok"] and rec["exit"] == 3
    assert rec["max_rss_mb"] >= 50


def test_killed_step_has_negative_exit(pipeline):
    rec = pipeline._run_step("kill", [sys.executable, "-c", "import os, signal; os.kill(os.getpid(), signal.SIGKILL)"])
    assert rec["exit"] == -9 and not rec["ok"]


def test_missing_command_has_no_rss(pipeline):
    rec = pipeline._run_step("nope", ["/nonexistent/cmd"])
    assert rec["exit"] == 127 and "max_rss_mb" not in rec

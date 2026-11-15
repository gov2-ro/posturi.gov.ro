"""OPS-ALERT-CHECK — a delivery failure of an alert leaves a record in the run log.

`ping_health` and `notify_run` in ops/run-pipeline.sh end in `|| true`, so a lost
alert used to leave no trace. These tests cover the record writer, the ntfy side
and the wrapper's healthcheck side, and prove that the record never holds a secret.
"""

from __future__ import annotations

import importlib.util
import json
import os
import shutil
import subprocess
import sys
import urllib.error
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
SECRET_URL = "https://hc.example.org/ping/SECRET-UUID"
SECRET_TOPIC = "secret-topic-name"


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, REPO_ROOT / "ops" / filename)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def rec():
    return _load("record_alert_under_test", "record-alert.py")


@pytest.fixture(scope="module")
def ntfy():
    return _load("ntfy_alert_under_test", "ntfy.py")


def _records(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text().splitlines()]


def test_record_cli_writes_one_alert_record(rec, tmp_path, monkeypatch):
    log = tmp_path / "runs.jsonl"
    monkeypatch.setenv("POSTURI_RUN_ID", "r1")
    assert rec.main(["--channel", "healthcheck", "--event", "fail", "--http-status", "200",
                     "--exit-code", "0", "--run-log", str(log)]) == 0
    (record,) = _records(log)
    assert record["kind"] == "alert" and record["run_id"] == "r1"
    assert record["channel"] == "healthcheck" and record["event"] == "fail"
    assert record["ok"] is True and record["http_status"] == 200 and record["exit_code"] == 0


def test_a_curl_failure_is_ok_false_and_000_is_no_status(rec, tmp_path, monkeypatch):
    log = tmp_path / "runs.jsonl"
    monkeypatch.setenv("POSTURI_RUN_ID", "r1")
    rec.main(["--channel", "healthcheck", "--event", "start", "--http-status", "000",
              "--exit-code", "6", "--run-log", str(log)])
    (record,) = _records(log)
    assert record["ok"] is False and record["http_status"] is None and record["exit_code"] == 6


def test_an_unwritable_log_does_not_raise(rec, tmp_path):
    blocker = tmp_path / "file"
    blocker.write_text("x")
    assert rec.main(["--channel", "ntfy", "--event", "fail", "--exit-code", "0",
                     "--run-log", str(blocker / "sub" / "runs.jsonl")]) == 0


def test_ntfy_run_records_a_delivered_push(ntfy, tmp_path, monkeypatch):
    log = tmp_path / "runs.jsonl"
    monkeypatch.setenv("NTFY_TOPIC", SECRET_TOPIC)
    monkeypatch.setattr(ntfy, "_load_env", lambda: None)
    monkeypatch.setattr(ntfy, "fetch_end_balance", lambda: None)

    class Reply:
        status = 200

        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

    monkeypatch.setattr("urllib.request.urlopen", lambda *a, **k: Reply())
    assert ntfy.main(["run", "--outcome", "ok", "--exit", "0", "--run-id", "r2",
                      "--run-log", str(log)]) == 0
    (record,) = _records(log)
    assert record["kind"] == "alert" and record["channel"] == "ntfy"
    assert record["event"] == "success" and record["ok"] is True
    assert record["http_status"] == 200 and record["run_id"] == "r2"
    assert SECRET_TOPIC not in log.read_text()


def test_ntfy_run_records_a_lost_push_without_changing_the_exit(ntfy, tmp_path, monkeypatch):
    log = tmp_path / "runs.jsonl"
    monkeypatch.setenv("NTFY_TOPIC", SECRET_TOPIC)
    monkeypatch.setattr(ntfy, "_load_env", lambda: None)
    monkeypatch.setattr(ntfy, "fetch_end_balance", lambda: None)

    def refuse(*a, **k):
        raise urllib.error.HTTPError("https://ntfy.sh", 429, "Too Many", {}, None)

    monkeypatch.setattr("urllib.request.urlopen", refuse)
    assert ntfy.main(["run", "--outcome", "failed", "--exit", "1", "--run-id", "r3",
                      "--run-log", str(log)]) == 0
    (record,) = _records(log)
    assert record["event"] == "fail" and record["ok"] is False
    assert record["http_status"] == 429 and record["detail"] == "HTTP 429"
    assert SECRET_TOPIC not in log.read_text()


# --- the wrapper -------------------------------------------------------------

STUB_PYTHON = """#!/usr/bin/env bash
case "$1" in
  webapp/manage.py) exit "${STUB_MIGRATE:-0}" ;;
  ops/record-alert.py) shift; echo "record-alert $*" >> "$STUB_LOG" ;;
esac
exit 0
"""

# Prints a status, as `curl -w '%{http_code}'` does, and exits with CURL_EXIT.
STUB_CURL = """#!/usr/bin/env bash
echo "curl $*" >> "$STUB_LOG"
printf '%s' "${CURL_HTTP:-200}"
exit "${CURL_EXIT:-0}"
"""


@pytest.fixture
def root(tmp_path):
    (tmp_path / "ops").mkdir()
    (tmp_path / "bin").mkdir()
    shutil.copy(REPO_ROOT / "ops" / "run-pipeline.sh", tmp_path / "ops" / "run-pipeline.sh")
    shutil.copy(REPO_ROOT / "ops" / "env.sh", tmp_path / "ops" / "env.sh")
    for path, body in ((tmp_path / "python-stub", STUB_PYTHON),
                       (tmp_path / "deploy-php.sh", "#!/usr/bin/env bash\nexit 0\n"),
                       (tmp_path / "bin" / "curl", STUB_CURL)):
        path.write_text(body)
        path.chmod(0o755)
    return tmp_path


def _run(root: Path, **extra) -> tuple[int, list[str], str]:
    log = root / "calls.log"
    env = {
        "PATH": f"{root / 'bin'}:{os.environ['PATH']}",
        "PYTHON": str(root / "python-stub"),
        "STUB_LOG": str(log),
        "POSTURI_LOCKED": "1",
        "POSTURI_RUN_ID": "test-run",
        "HEALTHCHECK_URL": SECRET_URL,
        **extra,
    }
    proc = subprocess.run(["bash", str(root / "ops" / "run-pipeline.sh")], env=env,
                          capture_output=True, text=True, timeout=30)
    calls = log.read_text().splitlines() if log.exists() else []
    return proc.returncode, calls, proc.stdout + proc.stderr


def test_every_healthcheck_ping_is_recorded_and_the_url_is_not(root):
    code, calls, out = _run(root)
    assert code == 0
    recorded = [c for c in calls if c.startswith("record-alert")]
    assert [c.split()[4] for c in recorded] == ["start", "success"]
    assert all("--channel healthcheck" in c and "--http-status 200" in c
               and "--exit-code 0" in c for c in recorded)
    assert not any(SECRET_URL in c for c in recorded)


def test_a_failed_ping_is_recorded_and_does_not_change_the_exit_status(root):
    code, calls, out = _run(root, CURL_EXIT="22", CURL_HTTP="404")
    assert code == 0, out
    recorded = [c for c in calls if c.startswith("record-alert")]
    assert recorded and all("--exit-code 22" in c and "--http-status 404" in c
                            for c in recorded)


def test_a_failed_run_records_the_fail_ping(root):
    code, calls, out = _run(root, STUB_MIGRATE="1")
    assert code == 78, out
    events = [c.split()[4] for c in calls if c.startswith("record-alert")]
    assert events == ["start", "fail"]

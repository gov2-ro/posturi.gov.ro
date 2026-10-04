"""REV-13 — ops/run-pipeline.sh control flow, with every command stubbed.

The wrapper's handled failures used `set +e; cmd; status=$?`, but an ERR trap is
not disabled by `set +e`, so on_error exited at the first failing step: the
ABORT / degraded-deploy / exit-65 branches were unreachable and the opt-in
POSTURI_ALLOW_DEGRADED_DEPLOY could never work. These tests run the real script
in a scratch root where `$PYTHON` is a stub that exits with configured codes and
logs what was invoked.
"""

from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]

STUB_PYTHON = r"""#!/usr/bin/env bash
# Dispatch on the script being "run"; exit with the configured code.
echo "$*" >> "$STUB_LOG"
case "$1" in
  webapp/manage.py) exit "${STUB_MIGRATE:-0}" ;;
  pipeline.py)      exit "${STUB_PIPELINE:-0}" ;;
  ops/check-export.py) exit "${STUB_CHECK:-0}" ;;
  ops/record-deploy.py) exit 0 ;;
esac
exit 0
"""

STUB_DEPLOY = """#!/usr/bin/env bash
echo "deploy-php.sh $*" >> "$STUB_LOG"
"""


@pytest.fixture
def root(tmp_path):
    (tmp_path / "ops").mkdir()
    shutil.copy(REPO_ROOT / "ops" / "run-pipeline.sh", tmp_path / "ops" / "run-pipeline.sh")
    shutil.copy(REPO_ROOT / "ops" / "env.sh", tmp_path / "ops" / "env.sh")
    for name, body in (("python-stub", STUB_PYTHON), ("deploy-php.sh", STUB_DEPLOY)):
        p = tmp_path / name
        p.write_text(body)
        p.chmod(0o755)
    return tmp_path


def run(root: Path, **codes) -> tuple[int, str, list[str]]:
    log = root / "calls.log"
    env = {
        "PATH": os.environ["PATH"],
        "PYTHON": str(root / "python-stub"),
        "STUB_LOG": str(log),
        "POSTURI_LOCKED": "1",          # skip the flock re-exec
        "POSTURI_RUN_ID": "test-run",
        **{k: str(v) for k, v in codes.items()},
    }
    proc = subprocess.run(["bash", str(root / "ops" / "run-pipeline.sh")], env=env,
                          capture_output=True, text=True, timeout=30)
    calls = log.read_text().splitlines() if log.exists() else []
    return proc.returncode, proc.stdout + proc.stderr, calls


def deployed(calls):
    return any(c.startswith("deploy-php.sh") for c in calls)


def test_healthy_run_deploys(root):
    code, out, calls = run(root)
    assert code == 0 and deployed(calls)
    assert "ops/record-deploy.py" in calls
    assert "=== done" in out


def test_failed_step_aborts_with_the_explicit_message(root):
    code, out, calls = run(root, STUB_PIPELINE=1)
    assert code == 1 and not deployed(calls)
    assert "ABORT: pipeline steps failed" in out, "the handled branch is reached"
    assert "FAILED (exit" not in out, "not the crash handler"


def test_degraded_opt_in_is_reachable(root):
    code, out, calls = run(root, STUB_PIPELINE=1, POSTURI_ALLOW_DEGRADED_DEPLOY=1)
    assert deployed(calls), "degraded publication is reachable when opted into"
    assert "ops/record-deploy.py --degraded" in calls
    assert code == 1, "the run still reports its step failure"


def test_failed_export_check_exits_65_without_deploying(root):
    code, out, calls = run(root, STUB_CHECK=1)
    assert code == 65 and not deployed(calls)
    assert "ABORT: the export failed its hard checks" in out


def test_pending_migrations_stop_before_the_pipeline(root):
    code, out, calls = run(root, STUB_MIGRATE=1)
    assert code == 78
    assert not any(c.startswith("pipeline.py") for c in calls)
    assert "unapplied migrations" in out

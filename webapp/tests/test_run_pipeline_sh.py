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
  ops/check-llm-balance.py) exit "${STUB_BALANCE:-0}" ;;
  pipeline.py)      exit "${STUB_PIPELINE:-0}" ;;
  ops/check-export.py) exit "${STUB_CHECK:-0}" ;;
  ops/record-deploy.py) exit 0 ;;
  ops/ntfy.py)      exit "${STUB_NTFY:-0}" ;;
esac
exit 0
"""

STUB_DEPLOY = """#!/usr/bin/env bash
echo "deploy-php.sh $*" >> "$STUB_LOG"
exit "${STUB_DEPLOY_EXIT:-0}"
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


def test_low_balance_aborts_before_the_pipeline_with_exit_69(root):
    # Healthcheck is a curl to a URL; stub curl on PATH to see the /fail ping.
    bin_dir = root / "bin"
    bin_dir.mkdir()
    curl = bin_dir / "curl"
    curl.write_text('#!/usr/bin/env bash\necho "curl $*" >> "$STUB_LOG"\n')
    curl.chmod(0o755)
    code, out, calls = run(root, STUB_BALANCE=69, HEALTHCHECK_URL="http://hc.invalid/x",
                           PATH=f"{bin_dir}:{os.environ['PATH']}")
    assert code == 69
    assert "ABORT: the LLM account balance is too low" in out
    assert "FAILED (exit" not in out, "the handled branch, not the crash handler"
    assert "=== aborted before the pipeline" in out
    assert any(c.endswith("/x/fail") for c in calls if c.startswith("curl")), calls
    assert not any(c.startswith("pipeline.py") for c in calls), "nothing was spent"
    assert not any(c.startswith("ops/check-export.py") for c in calls)
    assert not deployed(calls)


def test_balance_check_runs_after_migrations_and_before_the_pipeline(root):
    code, out, calls = run(root)
    assert code == 0
    order = [c.split()[0] for c in calls]
    assert order.index("webapp/manage.py") < order.index("ops/check-llm-balance.py") \
        < order.index("pipeline.py")


def test_pending_migrations_stop_before_the_balance_check(root):
    code, out, calls = run(root, STUB_MIGRATE=1)
    assert code == 78
    assert not any(c.startswith("ops/check-llm-balance.py") for c in calls)


def test_a_crashing_balance_check_does_not_block_the_run(root):
    # The script exits 0 for warnings and "unavailable"; a crash (any other
    # non-zero status) must be reported and ignored, never mistaken for 69.
    code, out, calls = run(root, STUB_BALANCE=1)
    assert code == 0 and deployed(calls)
    assert "WARNING: the balance check failed (exit 1)" in out
    assert "FAILED (exit" not in out
    assert any(c.startswith("pipeline.py") for c in calls)


def test_balance_exit_0_covers_ok_warn_and_unavailable_and_continues(root):
    code, out, calls = run(root, STUB_BALANCE=0)
    assert code == 0 and deployed(calls)
    assert "ABORT" not in out and "balance check failed" not in out


def test_skip_env_bypasses_the_balance_check(root):
    code, out, calls = run(root, STUB_BALANCE=69, POSTURI_SKIP_BALANCE_CHECK=1)
    assert code == 0 and deployed(calls)
    assert not any(c.startswith("ops/check-llm-balance.py") for c in calls)


# ------------------------------------------------- ntfy: one line per finished run

def ntfy_calls(calls):
    return [c for c in calls if c.startswith("ops/ntfy.py")]


def test_a_healthy_run_sends_its_summary_once_after_the_deploy_is_recorded(root):
    code, out, calls = run(root)
    assert code == 0
    assert ntfy_calls(calls) == ["ops/ntfy.py run --outcome ok --exit 0"]
    assert calls.index("ops/record-deploy.py") < calls.index(ntfy_calls(calls)[0])


def test_a_failed_step_sends_a_failure(root):
    code, out, calls = run(root, STUB_PIPELINE=1)
    assert code == 1
    assert ntfy_calls(calls) == ["ops/ntfy.py run --outcome failed --exit 1"]


def test_a_degraded_deploy_sends_a_degraded_summary_after_recording_it(root):
    code, out, calls = run(root, STUB_PIPELINE=1, POSTURI_ALLOW_DEGRADED_DEPLOY=1)
    assert code == 1
    assert ntfy_calls(calls) == ["ops/ntfy.py run --outcome degraded --exit 1"]
    assert calls.index("ops/record-deploy.py --degraded") < calls.index(ntfy_calls(calls)[0])


def test_a_failed_export_check_sends_a_failure_with_exit_65(root):
    code, out, calls = run(root, STUB_CHECK=1)
    assert code == 65
    assert ntfy_calls(calls) == ["ops/ntfy.py run --outcome failed --exit 65"]


def test_pending_migrations_send_a_failure_with_exit_78(root):
    code, out, calls = run(root, STUB_MIGRATE=1)
    assert code == 78
    assert ntfy_calls(calls) == ["ops/ntfy.py run --outcome failed --exit 78"]


def test_a_crash_after_the_checks_still_reports_through_the_error_handler(root):
    code, out, calls = run(root, STUB_DEPLOY_EXIT=3)
    assert code == 3 and "FAILED (exit 3)" in out
    assert ntfy_calls(calls) == ["ops/ntfy.py run --outcome failed --exit 3"]


def test_a_balance_abort_leaves_the_push_to_the_balance_check(root):
    # check-llm-balance.py has already sent the urgent push; a second one would be noise.
    code, out, calls = run(root, STUB_BALANCE=69)
    assert code == 69
    assert ntfy_calls(calls) == []


def test_a_crashing_notifier_never_changes_the_exit_status(root):
    assert run(root, STUB_NTFY=1)[0] == 0
    assert run(root, STUB_NTFY=1, STUB_CHECK=1)[0] == 65
    assert run(root, STUB_NTFY=1, STUB_PIPELINE=2)[0] == 2
    code, out, calls = run(root, STUB_NTFY=1, STUB_MIGRATE=1)
    assert code == 78 and "FAILED (exit" not in out, "not routed through the crash handler"


def test_export_check_gets_the_soft_schema_floor(root):
    """OPS-FLOORS: the level is gated (as a warn -- check-export keeps floors soft),
    not just the slope. --v3-floor stays unset under the v4 pin."""
    code, out, calls = run(root)
    check = next(c for c in calls if c.startswith("ops/check-export.py"))
    assert "--schema-floor 95" in check
    assert "--v3-floor" not in check
    assert "--strict" not in check


def test_schema_floor_can_be_overridden_from_the_environment(root):
    code, out, calls = run(root, SCHEMA_FLOOR=90)
    check = next(c for c in calls if c.startswith("ops/check-export.py"))
    assert "--schema-floor 90" in check


def test_a_bare_run_is_recorded_as_manual(root):
    """OPS-TRIGGER: only the scheduler may claim to be a scheduled slot."""
    code, out, calls = run(root)
    assert "(manual)" in out


def test_the_scheduler_sets_cron_explicitly(root):
    code, out, calls = run(root, POSTURI_RUN_TRIGGER="cron")
    assert "(cron)" in out


def test_systemd_unit_and_docs_crontab_set_the_trigger():
    unit = (REPO_ROOT / "ops" / "systemd" / "posturi-pipeline.service").read_text()
    assert "Environment=POSTURI_RUN_TRIGGER=cron" in unit
    doc = (REPO_ROOT / "docs" / "deploy-vps.md").read_text()
    lines = [ln for ln in doc.splitlines() if ln.rstrip().endswith("2>&1") and "ops/run-pipeline.sh" in ln]
    assert len(lines) == 2 and all("POSTURI_RUN_TRIGGER=cron" in ln for ln in lines)


def test_the_prompt_version_comes_from_dotenv(root):
    """BETA-V5: LLM_PROMPT_VERSION=v5 in .env switches the run, no crontab edit."""
    (root / ".env").write_text("LLM_PROMPT_VERSION=v5\n")
    code, out, calls = run(root)
    check = next(c for c in calls if c.startswith("ops/check-export.py"))
    assert "--prompt-version v5" in check


def test_the_prompt_version_defaults_to_v4(root):
    code, out, calls = run(root)
    check = next(c for c in calls if c.startswith("ops/check-export.py"))
    assert "--prompt-version v4" in check

"""The `observe` pipeline step is OPTIONAL: it warns on failure and the run carries on (UX-12).

History capture is additive. If it breaks, the cost is one run's observations — it must
never be the reason the site's data does not ship. Every other step stays required.
`pipeline.main()` is driven with `_run_step` replaced by a recorder, so no subprocess
runs and the real control flow (abort / continue-on-error / run log / exit code) is
what is under test.
"""

import importlib.util
import json
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


@pytest.fixture
def pipeline(monkeypatch):
    spec = importlib.util.spec_from_file_location("pipeline_under_test", REPO_ROOT / "pipeline.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["pipeline_under_test"] = mod
    spec.loader.exec_module(mod)
    monkeypatch.setattr(mod, "_load_env", lambda: None)
    monkeypatch.setattr(mod, "VENV_PYTHON", Path(sys.executable))
    monkeypatch.delenv("POSTURI_SKIP_OBSERVE", raising=False)
    return mod


class Recorder:
    """A `_run_step` stand-in: records the steps run, fails the ones it is told to."""

    def __init__(self, failing=()):
        self.failing = set(failing)
        self.ran = []

    def __call__(self, step, cmd):
        self.ran.append(step)
        code = 3 if step in self.failing else 0
        return {"step": step, "ok": code == 0, "exit": code, "started_at": "t", "duration_s": 0.0}


def run_main(pipeline, monkeypatch, tmp_path, recorder, *argv):
    log = tmp_path / "runs.jsonl"
    monkeypatch.setattr(pipeline, "_run_step", recorder)
    monkeypatch.setattr(sys, "argv", ["pipeline.py", "--provider", "deepseek", "--run-log", str(log), *argv])
    code = 0
    try:
        pipeline.main()
    except SystemExit as exc:
        code = exc.code or 0
    records = [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []
    return code, (records[-1] if records else None)


class TestDeclaration:
    def test_observe_runs_after_schema_and_inference_and_before_export(self, pipeline):
        steps = pipeline.ALL_STEPS
        assert steps.index("observe") > max(steps.index(s) for s in ("import", "schema", "infer", "occupations", "salary"))
        assert steps.index("observe") < steps.index("export-sqlite")

    def test_it_is_a_manage_step_for_the_observe_command_and_the_only_optional_one(self, pipeline):
        assert pipeline._MANAGE_STEPS["observe"] == "observe_postings"
        assert pipeline.OPTIONAL_STEPS == frozenset({"observe"})
        cmd = pipeline._build_cmd("observe", force=False, no_llm=False, provider="deepseek", limit=None, since=None)
        assert [str(c) for c in cmd[1:]][-2:] == [str(pipeline.WEBAPP_DIR / "manage.py"), "observe_postings"]

    def test_force_is_not_passed_to_a_command_that_has_no_such_flag(self, pipeline):
        kw = dict(force=True, no_llm=False, provider="deepseek", limit=None, since=None)
        assert "--force" not in pipeline._build_cmd("observe", **kw)
        assert "--force" in pipeline._build_cmd("import", **kw)      # unchanged for the others


class TestNonBlocking:
    def test_a_failing_observe_does_not_stop_the_export_nor_fail_the_run(self, pipeline, monkeypatch, tmp_path, capsys):
        rec = Recorder(failing={"observe"})
        code, record = run_main(pipeline, monkeypatch, tmp_path, rec, "--steps", "schema,observe,export-sqlite")
        assert code == 0
        assert rec.ran == ["schema", "observe", "export-sqlite"]
        assert record["exit"] == 0 and record["failed_steps"] == [] and record["aborted_at"] is None
        assert record["warned_steps"] == ["observe"]
        observe = next(s for s in record["steps"] if s["step"] == "observe")
        assert observe["ok"] is False and observe["optional"] is True      # the failure is still on record
        assert "WARNING: optional step observe failed" in capsys.readouterr().err

    def test_same_without_continue_on_error_and_with_it(self, pipeline, monkeypatch, tmp_path):
        for extra in ([], ["--continue-on-error"]):
            rec = Recorder(failing={"observe"})
            code, record = run_main(pipeline, monkeypatch, tmp_path, rec, "--steps", "observe,export-sqlite", *extra)
            assert (code, rec.ran, record["failed_steps"]) == (0, ["observe", "export-sqlite"], [])

    def test_a_required_step_still_aborts_the_run(self, pipeline, monkeypatch, tmp_path):
        rec = Recorder(failing={"schema"})
        code, record = run_main(pipeline, monkeypatch, tmp_path, rec, "--steps", "schema,observe,export-sqlite")
        assert code == 3 and rec.ran == ["schema"]
        assert record["failed_steps"] == ["schema"] and record["aborted_at"] == "schema"

    def test_a_required_step_still_fails_the_run_under_continue_on_error(self, pipeline, monkeypatch, tmp_path):
        rec = Recorder(failing={"schema", "observe"})
        code, record = run_main(pipeline, monkeypatch, tmp_path, rec,
                                "--steps", "schema,observe,export-sqlite", "--continue-on-error")
        assert code == 1 and rec.ran == ["schema", "observe", "export-sqlite"]
        assert record["failed_steps"] == ["schema"] and record["warned_steps"] == ["observe"]

    def test_a_clean_run_records_no_warning(self, pipeline, monkeypatch, tmp_path):
        code, record = run_main(pipeline, monkeypatch, tmp_path, Recorder(), "--steps", "observe,export-sqlite")
        assert code == 0 and record["warned_steps"] == []
        assert next(s for s in record["steps"] if s["step"] == "observe")["ok"] is True


class TestSkip:
    def test_the_env_var_skips_the_step_in_a_full_run(self, pipeline, monkeypatch, tmp_path, capsys):
        monkeypatch.setenv("POSTURI_SKIP_OBSERVE", "1")
        rec = Recorder()
        code, record = run_main(pipeline, monkeypatch, tmp_path, rec, "--steps", "salary,observe,export-sqlite")
        assert code == 0 and rec.ran == ["salary", "export-sqlite"]
        assert "observe" not in record["steps_selected"]
        assert "Skipping observe: POSTURI_SKIP_OBSERVE=1" in capsys.readouterr().out

    @pytest.mark.parametrize("value", ["0", "", "true"])
    def test_only_the_literal_1_skips(self, pipeline, monkeypatch, tmp_path, value):
        monkeypatch.setenv("POSTURI_SKIP_OBSERVE", value)
        rec = Recorder()
        run_main(pipeline, monkeypatch, tmp_path, rec, "--steps", "observe")
        assert rec.ran == ["observe"]

    def test_skipping_the_only_selected_step_runs_nothing(self, pipeline, monkeypatch, tmp_path):
        monkeypatch.setenv("POSTURI_SKIP_OBSERVE", "1")
        rec = Recorder()
        code, record = run_main(pipeline, monkeypatch, tmp_path, rec, "--steps", "observe")
        assert code == 0 and rec.ran == [] and record is None

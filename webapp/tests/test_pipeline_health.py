"""FIX-04 — pipeline failure status, baselines and quality gates.

Two import-by-path modules under test:
  llm-schema.py  — fatal classification, retry behaviour, per-model summaries
                   and the exit-code rules;
  ops/check-export.py — healthy-baseline selection (deploy-associated, never
                   the most recent failed candidate), the rolling window and
                   the sustained-decline / floor / intake-freshness checks.

No provider is ever called: the retry/summary layers take fake `generate`
callables, and the check layers take metric dicts.
"""

from __future__ import annotations

import importlib.util
import sqlite3
import sys
from datetime import date, datetime
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


@pytest.fixture(scope="module")
def llm():
    spec = importlib.util.spec_from_file_location(
        "llmschema_health_under_test", REPO_ROOT / "llm-schema.py"
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["llmschema_health_under_test"] = module
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def check():
    spec = importlib.util.spec_from_file_location(
        "checkexport_health_under_test", REPO_ROOT / "ops" / "check-export.py"
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["checkexport_health_under_test"] = module
    spec.loader.exec_module(module)
    return module


# ---------------------------------------------------------------- llm-schema


class _Status402(Exception):
    status_code = 402


class _Status403(Exception):
    status_code = 403


class _Status429(Exception):
    status_code = 429


class _Status400(Exception):
    status_code = 400


class PaymentRequiredError(Exception):
    """Named like SDK exceptions that hide the numeric status."""


class TestFatalClassification:
    def test_payment_and_auth_are_fatal(self, llm):
        assert llm._is_fatal(_Status402())
        assert llm._is_fatal(_Status403())

    def test_balance_hint_names_are_fatal(self, llm):
        assert llm._is_fatal(PaymentRequiredError())

    def test_transient_and_client_errors_are_not_fatal(self, llm):
        assert not llm._is_fatal(_Status429())
        assert not llm._is_fatal(_Status400())


class TestRetryOnFatal:
    def test_fatal_is_raised_immediately_without_retries(self, llm):
        attempts = []

        def generate(content):
            attempts.append(content)
            raise _Status402()

        with pytest.raises(llm.FatalError):
            llm.generate_with_retry(generate, "payload", max_attempts=4)
        assert len(attempts) == 1  # no backoff, no repair, no second call

    def test_transient_still_recovers_after_retry(self, llm):
        attempts = []

        def generate(content):
            attempts.append(content)
            if len(attempts) < 3:
                raise _Status429()
            return {"ok": True}

        result = llm.generate_with_retry(generate, "payload", max_attempts=4, base_delay=0.01)
        assert result == {"ok": True} and len(attempts) == 3


class TestEvaluateExit:
    def test_all_success_is_zero(self, llm):
        s = llm.RunSummary("p", "m", "v3", selected=10, attempted=10, ok=10)
        assert llm.evaluate_exit([s], 0.5) == 0

    def test_one_in_ten_failures_is_zero(self, llm):
        s = llm.RunSummary("p", "m", "v3", selected=10, attempted=10, ok=9, failed=1)
        assert llm.evaluate_exit([s], 0.5) == 0

    def test_above_half_failures_is_one(self, llm):
        s = llm.RunSummary("p", "m", "v3", selected=10, attempted=10, ok=4, failed=6)
        assert llm.evaluate_exit([s], 0.5) == 1

    def test_all_failures_is_one(self, llm):
        s = llm.RunSummary("p", "m", "v3", selected=10, attempted=10, ok=0, failed=10)
        assert llm.evaluate_exit([s], 0.5) == 1

    def test_fatal_dominates_everything(self, llm):
        fatal = llm.RunSummary("p", "m", "v3", selected=10, attempted=2, ok=1,
                               failed=1, fatal=True, fatal_reason="402")
        assert llm.evaluate_exit([fatal], 0.5) == 2

    def test_zero_work_is_zero(self, llm):
        s = llm.RunSummary("p", "m", "v3", selected=0, attempted=0)
        assert llm.evaluate_exit([s], 0.5) == 0

    def test_selected_but_unattemptable_is_one(self, llm):
        """Every selected posting had an empty body+attachment: a data problem,
        not a healthy no-op."""
        s = llm.RunSummary("p", "m", "v3", selected=10, attempted=0, skipped=10)
        assert llm.evaluate_exit([s], 0.5) == 1

    def test_comparison_models_aggregate_to_the_worst(self, llm):
        good = llm.RunSummary("p", "a", "v3", selected=10, attempted=10, ok=10)
        bad = llm.RunSummary("p", "b", "v3", selected=10, attempted=10, ok=0, failed=10)
        assert llm.evaluate_exit([good, bad], 0.5) == 1

    def test_the_last_model_cannot_mask_an_earlier_fatal(self, llm):
        fatal = llm.RunSummary("p", "a", "v3", selected=10, attempted=1, fatal=True)
        fine = llm.RunSummary("p", "b", "v3", selected=10, attempted=10, ok=10)
        assert llm.evaluate_exit([fatal, fine], 0.5) == 2


class TestRunSummaryRecord:
    def test_record_carries_the_contract_fields(self, llm):
        s = llm.RunSummary("p", "m", "v3", selected=10, attempted=9, ok=8, failed=1,
                           skipped=1, retried=2, ungrounded=3,
                           input_tokens=100, output_tokens=50, cost_usd=0.004)
        rec = s.to_record("run-1")
        assert rec["kind"] == "llm-schema" and rec["format"] == 1
        assert rec["run_id"] == "run-1" and rec["step"] == "schema"
        assert rec["provider"] == "p" and rec["model"] == "m"
        assert rec["selected"] == 10 and rec["attempted"] == 9
        assert rec["ok"] == 8 and rec["failed"] == 1 and rec["skipped"] == 1
        assert rec["retried"] == 2 and rec["ungrounded"] == 3
        assert rec["failure_classes"] == {}
        assert rec["fatal"] is False and rec["zero_work"] is False
        assert rec["usage"]["input_tokens"] == 100
        assert rec["usage"]["cost_usd"] == 0.004
        # No announcement text or credentials can ever ride along.
        assert "content" not in rec and "api_key" not in rec


# ---------------------------------------------------------------- check-export


def write_log(path: Path, records: list[dict]) -> None:
    import json as _json

    path.write_text(
        "\n".join(_json.dumps(r) for r in records) + "\n", encoding="utf-8"
    )


def export_check(run_id: str, status: str, metrics: dict | None = None) -> dict:
    m = {"job_postings": 100, "schema_coverage_pct": 90.0}
    m.update(metrics or {})
    return {"kind": "export-check", "run_id": run_id, "status": status, "metrics": m}


def deploy(run_id: str, deployed: bool) -> dict:
    return {"kind": "deploy", "run_id": run_id, "deployed": deployed}


class TestLoadBaselines:
    def test_deployed_candidate_is_the_baseline_not_the_latest(self, check, tmp_path):
        log = tmp_path / "runs.jsonl"
        write_log(log, [
            export_check("r1", "ok", {"schema_coverage_pct": 90.0}),
            deploy("r1", True),
            export_check("r2", "fail", {"schema_coverage_pct": 40.0}),
            deploy("r2", False),
            export_check("r3", "ok", {"schema_coverage_pct": 41.0}),  # undeployed
        ])
        b = check.load_baselines(log)
        assert b["previous"] == {"job_postings": 100, "schema_coverage_pct": 90.0}

    def test_failed_candidate_never_lowers_the_next_baseline(self, check, tmp_path):
        log = tmp_path / "runs.jsonl"
        write_log(log, [
            export_check("r1", "ok", {"schema_coverage_pct": 90.0}),
            deploy("r1", True),
            export_check("r2", "fail", {"schema_coverage_pct": 10.0}),
            deploy("r2", False),
        ])
        b = check.load_baselines(log)
        assert b["previous"]["schema_coverage_pct"] == 90.0
        assert len(b["window"]) == 1

    def test_window_holds_the_last_seven_healthy_runs(self, check, tmp_path):
        log = tmp_path / "runs.jsonl"
        records = []
        for i in range(1, 10):
            rid = f"r{i}"
            records.append(export_check(rid, "ok", {"schema_coverage_pct": float(80 + i)}))
            records.append(deploy(rid, True))
        # r3 fails after deploy records exist for it — excluded.
        records.append(export_check("r3b", "fail", {"schema_coverage_pct": 1.0}))
        write_log(log, records)
        b = check.load_baselines(log)
        assert len(b["window"]) == 7
        assert b["window"][-1]["schema_coverage_pct"] == 89.0
        assert b["previous"]["schema_coverage_pct"] == 89.0

    def test_legacy_log_without_deploy_records_falls_back_to_status_ok(self, check, tmp_path):
        log = tmp_path / "runs.jsonl"
        write_log(log, [
            export_check("r1", "ok", {"schema_coverage_pct": 90.0}),
            export_check("r2", "warn", {"schema_coverage_pct": 88.0}),
        ])
        b = check.load_baselines(log)
        assert b["previous"]["schema_coverage_pct"] == 90.0

    def test_absent_and_malformed_logs_yield_empty_baselines(self, check, tmp_path):
        assert check.load_baselines(tmp_path / "none.jsonl") == {"previous": None, "window": []}
        log = tmp_path / "bad.jsonl"
        log.write_text("not json\n{'kind': 'export-check'}\n", encoding="utf-8")
        assert check.load_baselines(log) == {"previous": None, "window": []}


def run_evaluate(check, m, baselines=None, **kw):
    con = sqlite3.connect(":memory:")
    con.execute("CREATE TABLE t (x)")
    try:
        return check.evaluate(con, m, baselines or {"previous": None, "window": []}, **kw)
    finally:
        con.close()


def check_map(checks):
    return {c.name: (c.level, c.ok, c.message) for c in checks}


BASE_METRICS = {
    "job_postings": 100, "employers": 5, "judete": 42, "calendar_events": 10,
    "fts_rows": 100, "file_bytes": 1000, "active": 80, "duplicate_urls": 0,
    "published_recent": 10, "published_recent_no_expiry": 0, "expires_out_of_range": 0,
    "published_in_future": 0, "published_after_expiry": 0, "postings_without_judet": 0,
    "judete_cedilla": 0, "schema_rows": 90, "v3_rows": 70, "schema_coverage_pct": 90.0,
    "v3_coverage_pct": 70.0, "attachment_coverage_pct": 50.0, "short_body_pct": 1.0,
    "family_populated_pct": 90.0, "family_altele_pct": 5.0, "family_top": "sanatate",
    "family_top_pct": 10.0, "family_shares_pct": {}, "anomaly_pct": {},
    "skill_top": "excel", "skill_top_pct": 10.0, "mojibake_rows": 0,
    "literal_newline_rows": 0, "build_meta": {"built_at": "2026-10-03T10:00:00+00:00",
                                              "git_sha": "x", "source_host": "h",
                                              "active_only": True},
    "last_seen_at_max": "2026-10-03 09:00:00",
    "title_fill_pct": 100.0, "employer_fill_pct": 100.0, "published_fill_pct": 100.0,
    "body_fill_pct": 98.0, "newest_published": "2026-10-03",
}


def metrics(**kw):
    m = dict(BASE_METRICS)
    m.update(kw)
    return m


class TestSustainedDecline:
    def test_a_1_5_point_loss_each_run_triggers_the_window_check(self, check):
        """No single run drops 5 points, yet the decline accumulates against
        the best healthy run — exactly the slow-bleed the old single-run
        comparison missed."""
        window = [metrics(schema_coverage_pct=v) for v in (90.0, 88.5, 87.0, 85.5)]
        baselines = {"previous": window[-1], "window": window}
        checks = run_evaluate(check, metrics(schema_coverage_pct=84.0), baselines,
                              prompt_version="v3", max_age_hours=6.0)
        m = check_map(checks)
        level, ok, message = m["schema_sustained"]
        assert level == "soft" and not ok
        assert "84.0%" in message and "90.0%" in message

    def test_current_near_the_best_does_not_trigger(self, check):
        window = [metrics(schema_coverage_pct=v) for v in (90.0, 89.9, 89.8)]
        checks = run_evaluate(check, metrics(schema_coverage_pct=89.9),
                              {"previous": window[-1], "window": window},
                              prompt_version="v3", max_age_hours=6.0)
        assert check_map(checks)["schema_sustained"][1] is True

    def test_no_window_yet_is_reported_not_failed(self, check):
        checks = run_evaluate(check, metrics(), {"previous": None, "window": []},
                              prompt_version="v3", max_age_hours=6.0)
        level, ok, message = check_map(checks)["schema_sustained"]
        assert level == "soft" and ok and "no healthy window" in message


class TestFloors:
    def test_schema_floor_warns_below_and_passes_above(self, check):
        checks = run_evaluate(check, metrics(schema_coverage_pct=80.0),
                              {"previous": None, "window": []},
                              prompt_version="v3", max_age_hours=6.0, schema_floor=85.0)
        assert check_map(checks)["schema_floor"][1] is False
        checks = run_evaluate(check, metrics(schema_coverage_pct=86.0),
                              {"previous": None, "window": []},
                              prompt_version="v3", max_age_hours=6.0, schema_floor=85.0)
        assert check_map(checks)["schema_floor"][1] is True

    def test_v3_floor_is_skipped_under_a_v4_rollout(self, check):
        checks = run_evaluate(check, metrics(v3_coverage_pct=50.0),
                              {"previous": None, "window": []},
                              prompt_version="v4", max_age_hours=6.0, v3_floor=70.0)
        level, ok, message = check_map(checks)["v3_floor"]
        assert ok and "v4" in message  # versioned expectations, not a failure

    def test_v3_floor_enforced_under_v3(self, check):
        checks = run_evaluate(check, metrics(v3_coverage_pct=50.0),
                              {"previous": None, "window": []},
                              prompt_version="v3", max_age_hours=6.0, v3_floor=70.0)
        assert check_map(checks)["v3_floor"][1] is False


class TestIntakeFreshness:
    def test_stale_intake_warns_on_a_weekday(self, check, monkeypatch):
        # Monday 2026-10-05, newest posting from 2026-10-01 — four days old.
        fixed_now = datetime(2026, 10, 5, 12, 0)

        class FakeDateTime(check.datetime):
            @classmethod
            def now(cls, tz=None):
                return fixed_now.replace(tzinfo=tz) if tz is not None else fixed_now

        class FakeDate(check.date):
            @classmethod
            def today(cls):
                return date(2026, 10, 5)

        monkeypatch.setattr(check, "datetime", FakeDateTime)
        monkeypatch.setattr(check, "date", FakeDate)
        checks = run_evaluate(check, metrics(newest_published="2026-10-01"),
                              {"previous": None, "window": []},
                              prompt_version="v3", max_age_hours=6.0)
        assert check_map(checks)["intake_fresh"][1] is False

    def test_stale_intake_does_not_warn_on_a_weekend(self, check, monkeypatch):
        fixed_now = datetime(2026, 10, 4, 12, 0)  # a Sunday

        class FakeDateTime(check.datetime):
            @classmethod
            def now(cls, tz=None):
                return fixed_now.replace(tzinfo=tz) if tz is not None else fixed_now

        class FakeDate(check.date):
            @classmethod
            def today(cls):
                return date(2026, 10, 4)

        monkeypatch.setattr(check, "datetime", FakeDateTime)
        monkeypatch.setattr(check, "date", FakeDate)
        checks = run_evaluate(check, metrics(newest_published="2026-10-01"),
                              {"previous": None, "window": []},
                              prompt_version="v3", max_age_hours=6.0)
        assert check_map(checks)["intake_fresh"][1] is True

    def test_fresh_intake_passes(self, check, monkeypatch):
        fixed_now = datetime(2026, 10, 5, 12, 0)

        class FakeDateTime(check.datetime):
            @classmethod
            def now(cls, tz=None):
                return fixed_now.replace(tzinfo=tz) if tz is not None else fixed_now

        class FakeDate(check.date):
            @classmethod
            def today(cls):
                return date(2026, 10, 5)

        monkeypatch.setattr(check, "datetime", FakeDateTime)
        monkeypatch.setattr(check, "date", FakeDate)
        checks = run_evaluate(check, metrics(newest_published="2026-10-04"),
                              {"previous": None, "window": []},
                              prompt_version="v3", max_age_hours=6.0)
        assert check_map(checks)["intake_fresh"][1] is True


class TestFillRates:
    def test_emptied_bodies_warn_against_the_baseline(self, check):
        prev = metrics(body_fill_pct=98.0)
        checks = run_evaluate(check, metrics(body_fill_pct=80.0),
                              {"previous": prev, "window": []},
                              prompt_version="v3", max_age_hours=6.0)
        assert check_map(checks)["body_fill"][1] is False


# ------------------------------------------------- REV-14: truncation at the cap


class _FakeChoice:
    def __init__(self, text, finish_reason):
        self.message = type("M", (), {"content": text})()
        self.finish_reason = finish_reason


class _FakeUsage:
    prompt_tokens = 8000
    completion_tokens = 2000
    prompt_cache_hit_tokens = 7900


class _FakeOpenAI:
    """Stands in for openai.OpenAI; returns one scripted completion."""
    reply = None

    def __init__(self, *a, **kw):
        create = lambda **kwargs: _FakeOpenAI.reply  # noqa: E731
        self.chat = type("C", (), {"completions": type("X", (), {"create": staticmethod(create)})()})()


class TestOutputTruncation:
    def _deepseek(self, llm, monkeypatch, text, finish_reason):
        import openai
        _FakeOpenAI.reply = type("R", (), {"choices": [_FakeChoice(text, finish_reason)],
                                           "usage": _FakeUsage()})()
        monkeypatch.setattr(openai, "OpenAI", _FakeOpenAI)
        return llm.make_generator("deepseek", "deepseek-v4-flash", "system", "v3")

    def test_length_stop_raises_truncated_with_usage(self, llm, monkeypatch):
        generate = self._deepseek(llm, monkeypatch, '{"responsibilities": "- a', "length")
        with pytest.raises(llm.OutputTruncated) as exc:
            generate("posting")
        assert exc.value.usage == (8000, 2000, 7900), "the billed call is still metered"
        assert "cap" in str(exc.value)

    def test_truncation_is_not_repaired(self, llm):
        calls = []

        def generate(content):
            calls.append(content)
            raise llm.OutputTruncated(8000, 1, 8000)

        with pytest.raises(llm.OutputTruncated):
            llm.generate_with_retry(generate, "posting", max_attempts=4, base_delay=0)
        assert len(calls) == 1, "a repair would regenerate the same long answer"

    def test_v3_budget_is_no_longer_2000(self, llm):
        assert llm.max_output_tokens("v3") >= 8000

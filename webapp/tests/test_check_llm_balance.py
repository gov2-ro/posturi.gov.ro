"""OPS-04 — ops/check-llm-balance.py: the DeepSeek balance pre-flight.

The HTTP call is always replaced (`fetch_balance` or `urllib.request.urlopen`), so
nothing here touches the network. What is pinned:

  * the verdict for each balance / response shape,
  * that anything which is not a verdict on the balance degrades to exit 0,
  * the `kind: "llm-balance"` run-log record, and that the readers of that log
    (check-export.py's baselines) are unaffected by it.
"""

from __future__ import annotations

import importlib.util
import io
import json
import sys
import urllib.error
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module      # @dataclass resolves the module through sys.modules
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def bal():
    return _load("check_llm_balance_under_test", REPO_ROOT / "ops" / "check-llm-balance.py")


@pytest.fixture(scope="module")
def check_export():
    return _load("check_export_balance_under_test", REPO_ROOT / "ops" / "check-export.py")


def body(total: str | None = "12.34", *, available=True, currency="USD", extra=()):
    infos = list(extra)
    if total is not None:
        infos.insert(0, {"currency": currency, "total_balance": total,
                         "granted_balance": "0.00", "topped_up_balance": total})
    return {"is_available": available, "balance_infos": infos}


@pytest.fixture
def env(monkeypatch, tmp_path):
    """A clean environment: DeepSeek, a key, no thresholds, a scratch run log."""
    import llm_config  # noqa: F401 — its load_dotenv() runs now, before the vars are cleared
    for name in ("LLM_BALANCE_MIN", "LLM_BALANCE_WARN", "POSTURI_RUN_ID",
                 "POSTURI_RUN_TRIGGER"):
        monkeypatch.delenv(name, raising=False)
    monkeypatch.setenv("LLM_PROVIDER", "deepseek")
    monkeypatch.setenv("DEEPSEEK_API_KEY", "sk-test-secret")
    return tmp_path / "runs.jsonl"


def run_main(bal, monkeypatch, response, *argv):
    """main() with fetch_balance returning `response` (or raising it if exception)."""
    def fake(api_key, timeout=10.0):
        if isinstance(response, BaseException):
            raise response
        return response
    monkeypatch.setattr(bal, "fetch_balance", fake)
    return bal.main(list(argv))


def records(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()]


# ------------------------------------------------------------------ verdicts

class TestAssess:
    def test_ok(self, bal):
        v = bal.assess(body("12.34"), minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code, v.balance) == ("ok", 0, 12.34)
        assert v.message == "DeepSeek balance $12.34 (ok)"

    def test_warn_below_the_warn_threshold(self, bal):
        v = bal.assess(body("1.40"), minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("warn", 0)
        assert v.message == "WARNING: DeepSeek balance $1.40 is below $3.00 — top up soon"

    def test_abort_below_min(self, bal):
        v = bal.assess(body("0.20"), minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("abort", 69)
        assert v.message.startswith("ABORT: DeepSeek balance $0.20 is below the $0.50 minimum")
        assert "top_up" in v.message

    def test_exactly_at_a_threshold_is_not_below_it(self, bal):
        assert bal.assess(body("0.50"), minimum=0.5, warn=3.0).outcome == "warn"
        assert bal.assess(body("3.00"), minimum=0.5, warn=3.0).outcome == "ok"
        assert bal.assess(body("5.00"), minimum=0.5, warn=3.0, need=5.0).outcome == "ok"

    def test_need_aborts_when_the_balance_cannot_cover_it(self, bal):
        v = bal.assess(body("4.99"), minimum=0.5, warn=3.0, need=5.0)
        assert (v.outcome, v.exit_code) == ("abort", 69)
        assert "$5.00 this run needs" in v.message

    def test_need_met_leaves_the_normal_verdict(self, bal):
        assert bal.assess(body("9.00"), minimum=0.5, warn=3.0, need=5.0).outcome == "ok"

    def test_is_available_false_aborts_whatever_the_balance(self, bal):
        v = bal.assess(body("50.00", available=False), minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("abort", 69)
        assert "unavailable" in v.message and "$50.00" in v.message

    def test_is_available_false_with_nothing_listed_still_aborts(self, bal):
        v = bal.assess(body(None, available=False), minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("abort", 69)

    def test_no_usd_entry_warns_and_continues(self, bal):
        data = body(None, extra=[{"currency": "CNY", "total_balance": "88.00"}])
        v = bal.assess(data, minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code, v.balance) == ("unavailable", 0, None)
        assert v.message.startswith("WARNING: balance check unavailable")
        assert "CNY 88.00" in v.message, "prints what is there"

    def test_usd_is_picked_out_of_several_currencies(self, bal):
        data = body("7.00", currency="USD",
                    extra=[{"currency": "CNY", "total_balance": "0.10"}])
        assert bal.assess(data, minimum=0.5, warn=3.0).balance == 7.0

    @pytest.mark.parametrize("data", [
        None, [], "oops", {}, {"is_available": True},
        {"is_available": True, "balance_infos": "x"},
        {"is_available": True, "balance_infos": ["x"]},
    ])
    def test_malformed_json_is_unavailable_not_a_verdict(self, bal, data):
        v = bal.assess(data, minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("unavailable", 0)
        assert v.message.startswith("WARNING: balance check unavailable:")

    def test_unreadable_balance_figure_is_unavailable(self, bal):
        v = bal.assess(body("n/a"), minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("unavailable", 0)


# ------------------------------------------------------------- the I/O edges

class TestFetch:
    def test_sends_bearer_auth_and_decodes_the_body(self, bal, monkeypatch):
        seen = {}

        class Resp(io.BytesIO):
            def __enter__(self): return self
            def __exit__(self, *a): return False

        def fake_urlopen(request, timeout):
            seen["auth"] = request.get_header("Authorization")
            seen["url"] = request.full_url
            seen["timeout"] = timeout
            return Resp(json.dumps(body("2.00")).encode())

        monkeypatch.setattr(bal.urllib.request, "urlopen", fake_urlopen)
        assert bal.fetch_balance("sk-abc", timeout=7)["balance_infos"][0]["total_balance"] == "2.00"
        assert seen == {"auth": "Bearer sk-abc", "url": bal.BALANCE_URL, "timeout": 7}

    @pytest.mark.parametrize("exc, text", [
        (urllib.error.HTTPError(None, 503, "Service Unavailable", {}, None), "HTTP 503"),
        (urllib.error.URLError("no route to host"), "network error"),
        (TimeoutError("timed out"), "timed out"),
        (json.JSONDecodeError("Expecting value", "<html>", 0), "unparseable"),
    ])
    def test_every_failure_mode_is_unavailable_and_exit_0(self, bal, monkeypatch, exc, text):
        def boom(api_key, timeout=10.0):
            raise exc
        monkeypatch.setattr(bal, "fetch_balance", boom)
        v = bal.check(provider="deepseek", api_key="k", minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("unavailable", 0)
        assert v.message.startswith("WARNING: balance check unavailable:")
        assert text in v.message

    def test_non_deepseek_provider_is_skipped_without_a_call(self, bal, monkeypatch):
        monkeypatch.setattr(bal, "fetch_balance",
                            lambda *a, **k: pytest.fail("must not call the API"))
        v = bal.check(provider="gemini", api_key="k", minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("skipped", 0)
        assert v.message == "skipped: provider gemini has no balance check"

    def test_no_key_is_skipped_without_a_call(self, bal, monkeypatch):
        monkeypatch.setattr(bal, "fetch_balance",
                            lambda *a, **k: pytest.fail("must not call the API"))
        v = bal.check(provider="deepseek", api_key="", minimum=0.5, warn=3.0)
        assert (v.outcome, v.exit_code) == ("skipped", 0)


# --------------------------------------------------------------------- main()

class TestMain:
    def test_ok_exit_0_and_message_printed(self, bal, env, monkeypatch, capsys):
        assert run_main(bal, monkeypatch, body("12.34"), "--run-log", str(env)) == 0
        assert capsys.readouterr().out.strip() == "DeepSeek balance $12.34 (ok)"

    def test_abort_exits_69(self, bal, env, monkeypatch, capsys):
        assert run_main(bal, monkeypatch, body("0.10"), "--run-log", str(env)) == 69
        assert capsys.readouterr().out.startswith("ABORT:")

    def test_need_flag(self, bal, env, monkeypatch):
        assert run_main(bal, monkeypatch, body("4.00"), "--need", "8",
                        "--run-log", str(env)) == 69

    def test_env_thresholds_and_flags_override(self, bal, env, monkeypatch):
        monkeypatch.setenv("LLM_BALANCE_MIN", "5")
        assert run_main(bal, monkeypatch, body("4.00"), "--run-log", str(env)) == 69
        assert run_main(bal, monkeypatch, body("4.00"), "--min", "1",
                        "--run-log", str(env)) == 0

    def test_garbage_threshold_env_falls_back_to_the_default(self, bal, env, monkeypatch):
        monkeypatch.setenv("LLM_BALANCE_MIN", "lots")
        assert run_main(bal, monkeypatch, body("4.00"), "--run-log", str(env)) == 0
        assert records(env)[-1]["min"] == bal.DEFAULT_MIN

    def test_provider_comes_from_llm_config(self, bal, env, monkeypatch, capsys):
        monkeypatch.setenv("LLM_PROVIDER", "openai")
        assert run_main(bal, monkeypatch, RuntimeError("must not be called")) == 0
        assert "skipped: provider openai has no balance check" in capsys.readouterr().out

    def test_unknown_provider_never_blocks(self, bal, env, monkeypatch, capsys):
        monkeypatch.setenv("LLM_PROVIDER", "nonesuch")
        assert run_main(bal, monkeypatch, body("1.00")) == 0
        assert "balance check unavailable" in capsys.readouterr().out

    def test_no_key_prints_skipped(self, bal, env, monkeypatch, capsys):
        monkeypatch.delenv("DEEPSEEK_API_KEY")
        assert run_main(bal, monkeypatch, RuntimeError("never")) == 0
        assert "skipped" in capsys.readouterr().out

    def test_network_error_prints_the_warning_and_exits_0(self, bal, env, monkeypatch, capsys):
        err = urllib.error.URLError("boom")
        assert run_main(bal, monkeypatch, err, "--run-log", str(env)) == 0
        assert "WARNING: balance check unavailable:" in capsys.readouterr().out
        assert records(env)[-1]["outcome"] == "unavailable"

    def test_the_key_is_never_printed_or_recorded(self, bal, env, monkeypatch, capsys):
        run_main(bal, monkeypatch, body("1.00"), "--run-log", str(env))
        out = capsys.readouterr()
        assert "sk-test-secret" not in out.out + out.err + env.read_text()


# ---------------------------------------------------------------- the record

class TestRecord:
    def test_shape(self, bal, env, monkeypatch):
        monkeypatch.setenv("POSTURI_RUN_ID", "2026-10-10T11:45:00Z")
        monkeypatch.setenv("POSTURI_RUN_TRIGGER", "cron")
        run_main(bal, monkeypatch, body("1.40"), "--run-log", str(env))
        (rec,) = records(env)
        assert rec["kind"] == "llm-balance"
        assert rec["run_id"] == "2026-10-10T11:45:00Z"
        assert rec["trigger"] == "cron"
        assert rec["provider"] == "deepseek"
        assert rec["currency"] == "USD"
        assert rec["balance"] == 1.40
        assert rec["is_available"] is True
        assert (rec["min"], rec["warn"], rec["need"]) == (0.5, 3.0, None)
        assert rec["outcome"] == "warn"
        assert rec["checked_at"].endswith("Z") and rec["host"]

    @pytest.mark.parametrize("response, argv, outcome", [
        (body("10"), [], "ok"),
        (body("1"), [], "warn"),
        (body("0.1"), [], "abort"),
        (body("1"), ["--need", "9"], "abort"),
        (urllib.error.URLError("x"), [], "unavailable"),
    ])
    def test_outcomes(self, bal, env, monkeypatch, response, argv, outcome):
        run_main(bal, monkeypatch, response, *argv, "--run-log", str(env))
        assert records(env)[-1]["outcome"] == outcome

    def test_skipped_is_recorded_too(self, bal, env, monkeypatch):
        monkeypatch.setenv("LLM_PROVIDER", "gemini")
        run_main(bal, monkeypatch, RuntimeError("never"), "--run-log", str(env))
        rec = records(env)[-1]
        assert (rec["outcome"], rec["provider"], rec["balance"]) == ("skipped", "gemini", None)

    def test_trigger_defaults_to_manual(self, bal, env, monkeypatch):
        run_main(bal, monkeypatch, body("10"), "--run-log", str(env))
        assert records(env)[-1]["trigger"] == "manual"

    def test_no_record_writes_nothing(self, bal, env, monkeypatch):
        run_main(bal, monkeypatch, body("10"), "--no-record", "--run-log", str(env))
        assert not env.exists()

    def test_an_unwritable_log_is_a_warning_not_a_failure(self, bal, env, monkeypatch, tmp_path, capsys):
        blocker = tmp_path / "file"
        blocker.write_text("x")
        # the parent "directory" is a regular file, so mkdir/open raises OSError
        code = run_main(bal, monkeypatch, body("10"), "--run-log", str(blocker / "runs.jsonl"))
        assert code == 0
        assert "could not write the run log" in capsys.readouterr().err

    def test_an_unwritable_log_does_not_swallow_an_abort(self, bal, env, monkeypatch, tmp_path):
        blocker = tmp_path / "file"
        blocker.write_text("x")
        assert run_main(bal, monkeypatch, body("0.1"),
                        "--run-log", str(blocker / "runs.jsonl")) == 69


class TestReadersIgnoreTheRecord:
    """check-export.py's baselines select on `kind`; the new record must not move them."""

    def write(self, path: Path, recs: list[dict]) -> None:
        path.write_text("".join(json.dumps(r) + "\n" for r in recs))

    def test_baselines_are_identical_with_and_without_balance_records(self, check_export, tmp_path):
        export = {"kind": "export-check", "run_id": "r1", "status": "ok",
                  "metrics": {"job_postings": 100}}
        deploy = {"kind": "deploy", "run_id": "r1", "deployed": True}
        balance = [{"kind": "llm-balance", "run_id": rid, "outcome": o, "balance": 1.0,
                    "metrics": {"job_postings": 1}, "deployed": False}   # worst case: keys that collide
                   for rid, o in (("r1", "warn"), ("r2", "abort"))]

        plain, mixed = tmp_path / "a.jsonl", tmp_path / "b.jsonl"
        self.write(plain, [export, deploy])
        self.write(mixed, [balance[0], export, balance[1], deploy])
        assert check_export.load_baselines(mixed) == check_export.load_baselines(plain)

    def test_a_balance_record_alone_creates_no_baseline(self, check_export, tmp_path):
        log = tmp_path / "a.jsonl"
        self.write(log, [{"kind": "llm-balance", "run_id": "r1", "outcome": "ok"}])
        assert check_export.load_baselines(log) == {"previous": None, "window": []}

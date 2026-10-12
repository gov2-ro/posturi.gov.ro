"""OPS-BALANCE — ops/ntfy.py: push notifications for the unattended pipeline.

Nothing here touches the network: `urllib.request.urlopen` is always replaced. What
is pinned:

  * the transport: one JSON POST to the server root, never an exception out of it;
  * the configuration: no NTFY_TOPIC means off, NTFY_URL overrides the server;
  * the once-a-day rule for the low-balance warning (read from the run log);
  * the per-run summary and failure messages built from the run's own records,
    including the LLM cost and the balance left;
  * the CLI the shell wrapper calls, which must never change a run's exit status.
"""

from __future__ import annotations

import importlib.util
import json
import sys
import urllib.error
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]

RUN = "2026-10-12T10:15:01Z"
TOPIC = "posturi-test-topic-0123456789abcdef"


@pytest.fixture(scope="module")
def ntfy():
    path = REPO_ROOT / "ops" / "ntfy.py"
    spec = importlib.util.spec_from_file_location("ntfy_under_test", path)
    module = importlib.util.module_from_spec(spec)
    sys.modules["ntfy_under_test"] = module      # @dataclass resolves its module here
    spec.loader.exec_module(module)
    return module


@pytest.fixture
def clean_env(monkeypatch):
    for name in ("NTFY_TOPIC", "NTFY_URL", "NTFY_SUMMARY", "LLM_BALANCE_MIN",
                 "LLM_BALANCE_WARN", "POSTURI_RUN_ID", "POSTURI_RUN_TRIGGER"):
        monkeypatch.delenv(name, raising=False)


# ------------------------------------------------------------- record builders

def run_rec(**kw):
    base = {
        "kind": "run", "run_id": RUN, "trigger": "cron", "host": "gov2-1",
        "git_sha": "8a00f44", "started_at": "2026-10-12T10:15:05Z",
        "finished_at": "2026-10-12T11:04:55Z", "duration_s": 2989.9,
        "steps": [{"step": "fetch-index", "ok": True, "exit": 0, "duration_s": 38.0},
                  {"step": "schema", "ok": True, "exit": 0, "duration_s": 386.2}],
        "failed_steps": [], "warned_steps": [], "aborted_at": None, "exit": 0,
    }
    base.update(kw)
    return base


def check(name, ok=True, level="soft", message="fine"):
    return {"name": name, "level": level, "ok": ok, "message": message}


def export_rec(run_id=RUN, status="ok", active=2504, calendar=908, n_checks=31,
               failing=(), **metrics):
    checks = [check(f"c{i}", message="") for i in range(n_checks - len(failing))]
    checks += list(failing)
    m = {"active": active, "calendar_events": calendar, "schema_coverage_pct": 100.0,
         "v3_coverage_pct": 98.1}
    m.update(metrics)
    return {"kind": "export-check", "run_id": run_id, "status": status,
            "metrics": m, "checks": checks}


def schema_rec(run_id=RUN, selected=148, ok=147, failed=1, cost=0.369916,
               classes=None, tokens_in=1_877_120, tokens_out=409_072):
    return {"kind": "llm-schema", "run_id": run_id, "step": "schema",
            "selected": selected, "attempted": selected, "ok": ok, "failed": failed,
            "failure_classes": {"ValueError": 1} if classes is None else classes,
            "usage": {"input_tokens": tokens_in, "output_tokens": tokens_out,
                      "cost_usd": cost}}


def usage_rec(step="schema", run_id=RUN, cost=0.369916, tokens_in=1_877_120,
              tokens_out=409_072, unpriced=0, write_errors=0, calls=148):
    """The `llm-usage` record every LLM step now writes (FIX-10)."""
    return {"kind": "llm-usage", "format": 1, "run_id": run_id, "step": step,
            "provider": "deepseek", "model": "deepseek-v4-flash", "calls": calls,
            "unknown_cost_calls": unpriced, "ledger_write_errors": write_errors,
            "usage": {"input_tokens": tokens_in, "output_tokens": tokens_out,
                      "cached_input_tokens": 0, "reasoning_tokens": 0, "cost_usd": cost}}


def deploy_rec(run_id=RUN, at="2026-10-12T11:05:15Z", degraded=False):
    return {"kind": "deploy", "run_id": run_id, "deployed": True,
            "degraded": degraded, "at": at}


def balance_rec(checked_at, outcome="warn", balance=1.4, notified=None, run_id=None):
    rec = {"kind": "llm-balance", "run_id": run_id or checked_at,
           "checked_at": checked_at, "outcome": outcome, "balance": balance}
    if notified is not None:
        rec["notified"] = notified
    return rec


def summary(ntfy, outcome="ok", exit_code=0, recs=None, prev=None, balance=None,
            minimum=0.5, warn=3.0, run_id=RUN):
    if recs is None:
        recs = {"run": run_rec(), "export": export_rec(), "schema": schema_rec(),
                "deploy": deploy_rec()}
    if prev is None and "export" in recs:
        prev = export_rec(run_id="2026-10-11T15:33:01Z", active=2363, calendar=860)
    return ntfy.build_run_summary(outcome, exit_code, recs, prev, run_id=run_id,
                                  end_balance=balance, minimum=minimum, warn=warn)


# ------------------------------------------------------------------- transport

class TestSend:
    def test_posts_one_json_document_to_the_server_root(self, ntfy, posts):
        msg = ntfy.Message("Titlu", "București: 12 posturi", 4, ("warning",))
        assert ntfy.send(msg, url="https://ntfy.example.org/", topic=TOPIC) is True
        (req,) = posts
        assert req["method"] == "POST"
        assert req["url"].rstrip("/") == "https://ntfy.example.org"
        assert req["headers"]["content-type"].startswith("application/json")
        assert req["body"] == {"topic": TOPIC, "title": "Titlu",
                               "message": "București: 12 posturi",
                               "priority": 4, "tags": ["warning"]}

    def test_has_a_short_timeout_so_it_cannot_hold_a_run(self, ntfy, posts):
        ntfy.send(ntfy.Message("t", "b"), url="https://ntfy.sh", topic=TOPIC)
        assert 0 < posts[0]["timeout"] <= 15

    @pytest.mark.parametrize("exc", [
        urllib.error.URLError("Name or service not known"),
        urllib.error.HTTPError("https://ntfy.sh", 500, "Server Error", {}, None),
        TimeoutError("timed out"),
        ConnectionResetError("reset"),
        ValueError("anything else"),
    ])
    def test_every_failure_is_false_never_an_exception(self, ntfy, monkeypatch, exc):
        def boom(*a, **k):
            raise exc
        monkeypatch.setattr("urllib.request.urlopen", boom)
        assert ntfy.send(ntfy.Message("t", "b"), url="https://ntfy.sh", topic=TOPIC) is False


class TestNotify:
    @pytest.mark.parametrize("env", [{}, {"NTFY_TOPIC": ""}, {"NTFY_TOPIC": "   "}])
    def test_no_topic_means_off_and_no_request(self, ntfy, posts, env):
        assert ntfy.notify(ntfy.Message("t", "b"), env) is False
        assert posts == []

    def test_defaults_to_the_public_server(self, ntfy, posts):
        assert ntfy.notify(ntfy.Message("t", "b"), {"NTFY_TOPIC": f"  {TOPIC}  "}) is True
        assert posts[0]["url"].rstrip("/") == "https://ntfy.sh"
        assert posts[0]["body"]["topic"] == TOPIC

    def test_ntfy_url_overrides_the_server(self, ntfy, posts):
        env = {"NTFY_TOPIC": TOPIC, "NTFY_URL": "https://ntfy.mioritics.ro"}
        ntfy.notify(ntfy.Message("t", "b"), env)
        assert posts[0]["url"].rstrip("/") == "https://ntfy.mioritics.ro"

    def test_a_failed_push_warns_without_printing_the_secret_topic(
            self, ntfy, monkeypatch, capsys):
        def boom(*a, **k):
            raise urllib.error.URLError("Name or service not known")
        monkeypatch.setattr("urllib.request.urlopen", boom)
        assert ntfy.notify(ntfy.Message("t", "b"), {"NTFY_TOPIC": TOPIC}) is False
        out = capsys.readouterr()
        assert "WARNING: ntfy push failed" in out.out + out.err
        assert TOPIC not in out.out + out.err


# --------------------------------------------------------- low-balance alerts

class TestBalanceAlert:
    URL = "https://platform.deepseek.com/top_up"

    def test_abort_is_urgent_and_says_nothing_ran(self, ntfy):
        m = ntfy.build_balance_alert("abort", 0.45, minimum=0.5, warn=3.0, top_up_url=self.URL)
        assert m.priority == 5
        assert "rotating_light" in m.tags
        assert "$0.45" in m.body and "$0.50" in m.body and self.URL in m.body
        assert "previous database" in m.body
        assert "balance" in m.title.lower()

    def test_abort_without_a_readable_balance_still_builds(self, ntfy):
        m = ntfy.build_balance_alert("abort", None, minimum=0.5, warn=3.0, top_up_url=self.URL)
        assert m.priority == 5 and self.URL in m.body

    def test_warn_is_default_priority_and_says_the_run_continues(self, ntfy):
        m = ntfy.build_balance_alert("warn", 1.40, minimum=0.5, warn=3.0, top_up_url=self.URL)
        assert m.priority == 3
        assert "warning" in m.tags
        assert "$1.40" in m.body and "$3.00" in m.body and self.URL in m.body


class TestWarnAlreadySent:
    NOW = datetime(2026, 10, 12, 15, 33, tzinfo=timezone.utc)

    def stamp(self, hours_ago):
        return (self.NOW - timedelta(hours=hours_ago)).strftime("%Y-%m-%dT%H:%M:%SZ")

    def test_a_delivered_warn_inside_the_window_suppresses_the_next(self, ntfy):
        recs = [balance_rec(self.stamp(5), "warn", notified=True)]
        assert ntfy.warn_already_sent(recs, self.NOW) is True

    def test_after_the_window_it_may_fire_again(self, ntfy):
        recs = [balance_rec(self.stamp(24), "warn", notified=True)]
        assert ntfy.warn_already_sent(recs, self.NOW) is False

    def test_a_warn_whose_push_failed_is_retried(self, ntfy):
        recs = [balance_rec(self.stamp(5), "warn", notified=False)]
        assert ntfy.warn_already_sent(recs, self.NOW) is False

    def test_records_from_before_notifications_existed_do_not_count(self, ntfy):
        recs = [balance_rec(self.stamp(5), "warn")]          # no `notified` key
        assert ntfy.warn_already_sent(recs, self.NOW) is False

    def test_only_delivered_warns_count_not_aborts_or_other_kinds(self, ntfy):
        recs = [balance_rec(self.stamp(2), "abort", notified=True),
                {"kind": "run", "checked_at": self.stamp(1), "notified": True}]
        assert ntfy.warn_already_sent(recs, self.NOW) is False

    def test_garbage_timestamps_are_ignored(self, ntfy):
        recs = [balance_rec("not a time", "warn", notified=True), {"kind": "llm-balance"}]
        assert ntfy.warn_already_sent(recs, self.NOW) is False


# ------------------------------------------------------------- reading the log

class TestRecords:
    def test_a_missing_log_is_empty_and_bad_lines_are_skipped(self, ntfy, tmp_path):
        assert ntfy.read_records(tmp_path / "nope.jsonl") == []
        p = tmp_path / "runs.jsonl"
        p.write_text('{"kind":"run","run_id":"a"}\nnot json\n\n[1,2]\n{"kind":"deploy"}\n')
        assert [r["kind"] for r in ntfy.read_records(p)] == ["run", "deploy"]

    def test_for_run_picks_this_runs_records_by_kind(self, ntfy):
        other = "2026-10-11T15:33:01Z"
        recs = [run_rec(run_id=other), export_rec(run_id=other), schema_rec(run_id=other),
                run_rec(), export_rec(), schema_rec(), deploy_rec(),
                balance_rec("2026-10-12T10:15:05Z", run_id=RUN, balance=0.83)]
        got = ntfy.for_run(recs, RUN)
        assert set(got) == {"run", "export", "schema", "deploy", "balance"}
        assert all(r["run_id"] == RUN for r in got.values())

    def test_for_run_keeps_the_last_of_a_kind(self, ntfy):
        recs = [export_rec(active=1), export_rec(active=2)]
        assert ntfy.for_run(recs, RUN)["export"]["metrics"]["active"] == 2

    def test_previous_export_is_the_latest_one_from_another_run(self, ntfy):
        recs = [export_rec(run_id="r1", active=1), export_rec(run_id="r2", active=2),
                export_rec(run_id=RUN, active=3)]
        assert ntfy.previous_export(recs, RUN)["metrics"]["active"] == 2

    def test_no_previous_export_is_none(self, ntfy):
        assert ntfy.previous_export([export_rec(run_id=RUN)], RUN) is None

    def test_previous_means_before_this_run_even_when_later_runs_are_in_the_log(self, ntfy):
        # A re-sent summary for an old run must compare with the run before it, not
        # with whatever ran last.
        recs = [export_rec(run_id="r1", active=1), export_rec(run_id=RUN, active=2),
                export_rec(run_id="r3", active=3)]
        assert ntfy.previous_export(recs, RUN)["metrics"]["active"] == 1

    def test_a_run_with_no_export_of_its_own_compares_with_the_latest_one(self, ntfy):
        recs = [export_rec(run_id="r1", active=1), export_rec(run_id="r2", active=2)]
        assert ntfy.previous_export(recs, RUN)["metrics"]["active"] == 2


# --------------------------------------------------------------- run summaries

class TestRunSummaryOk:
    def test_the_everyday_message_carries_the_numbers_you_want(self, ntfy):
        m = summary(ntfy, balance=4.20)
        assert m.title == "posturi 13:15 ok"                    # 10:15Z is 13:15 in Bucharest
        assert m.priority == 2 and "white_check_mark" in m.tags
        for part in ("50 min",                                  # duration_s 2989.9
                     "deployed 14:05",                          # 11:05:15Z, local
                     "Active 2,504 (+141)",
                     "calendar 908 (+48)",
                     "Schema 147/148 ok",
                     "ValueError",
                     "LLM $0.370",
                     "1.9M in", "0.4M out",
                     "Balance $4.20",
                     "Checks ok 31/31",
                     "schema 100.0%", "v3 98.1%"):
            assert part in m.body, f"{part!r} missing from:\n{m.body}"

    def test_winter_time_is_two_hours_ahead_of_utc(self, ntfy):
        recs = {"run": run_rec(run_id="2026-12-01T10:15:01Z",
                               started_at="2026-12-01T10:15:05Z"),
                "export": export_rec(), "schema": schema_rec()}
        assert summary(ntfy, recs=recs).title == "posturi 12:15 ok"

    def test_deltas_show_a_fall_and_no_change(self, ntfy):
        prev = export_rec(run_id="x", active=2600, calendar=908)
        m = summary(ntfy, prev=prev)
        assert "Active 2,504 (-96)" in m.body
        assert "calendar 908 (±0)" in m.body

    def test_no_previous_export_means_no_delta(self, ntfy):
        recs = {"run": run_rec(), "export": export_rec(), "schema": schema_rec()}
        m = ntfy.build_run_summary("ok", 0, recs, None, run_id=RUN, end_balance=None,
                                   minimum=0.5, warn=3.0)
        assert "Active 2,504" in m.body and "(+" not in m.body and "(-" not in m.body

    def test_a_run_that_extracted_nothing_has_no_llm_line(self, ntfy):
        recs = {"run": run_rec(), "export": export_rec(), "deploy": deploy_rec()}
        m = summary(ntfy, recs=recs)
        assert "LLM" not in m.body and "Schema" not in m.body

    def test_no_balance_means_no_balance_line(self, ntfy):
        assert "Balance" not in summary(ntfy, balance=None).body

    def test_a_healthy_balance_does_not_raise_the_priority(self, ntfy):
        m = summary(ntfy, balance=12.00)
        assert m.priority == 2 and "top up" not in m.body.lower()


class TestRunSummaryBalanceFlag:
    def test_under_the_warn_level_says_to_top_up_and_is_default_priority(self, ntfy):
        m = summary(ntfy, balance=1.40)
        assert "Balance $1.40" in m.body and "top up" in m.body.lower()
        assert m.priority == 3 and "warning" in m.tags

    def test_under_the_minimum_says_the_next_slot_will_abort(self, ntfy):
        m = summary(ntfy, balance=0.45)
        assert "next slot will abort" in m.body
        assert m.priority == 4 and "rotating_light" in m.tags


class TestRunSummarySoftWarnings:
    def test_export_check_warnings_are_named(self, ntfy):
        failing = [check("intake_fresh", ok=False, message="newest posting 3 day(s) ago")]
        recs = {"run": run_rec(), "schema": schema_rec(), "deploy": deploy_rec(),
                "export": export_rec(status="warn", n_checks=31, failing=failing)}
        m = summary(ntfy, recs=recs)
        assert m.priority == 3 and "warning" in m.tags
        assert "intake_fresh" in m.body and "newest posting 3 day(s) ago" in m.body
        assert m.title == "posturi 13:15 ok"        # the run itself succeeded


class TestRunSummaryProblems:
    def test_degraded_names_the_failed_steps_and_is_high_priority(self, ntfy):
        steps = [{"step": "occupations", "ok": False, "exit": 3, "duration_s": 2.0}]
        recs = {"run": run_rec(steps=steps, failed_steps=["occupations"], exit=1),
                "export": export_rec(), "schema": schema_rec(), "deploy": deploy_rec(degraded=True)}
        m = summary(ntfy, outcome="degraded", exit_code=1, recs=recs)
        assert m.title == "posturi 13:15 degraded"
        assert m.priority == 4 and "warning" in m.tags
        assert "occupations" in m.body and "exit 3" in m.body
        assert "deployed" in m.body                 # degraded publication did ship

    def test_failed_step_says_the_site_is_unchanged(self, ntfy):
        steps = [{"step": "fetch-detail", "ok": False, "exit": 2, "duration_s": 700.0}]
        recs = {"run": run_rec(steps=steps, failed_steps=["fetch-detail"],
                               aborted_at="fetch-detail", exit=2, duration_s=720.0)}
        m = summary(ntfy, outcome="failed", exit_code=2, recs=recs)
        assert m.title == "posturi 13:15 FAILED"
        assert m.priority == 4 and "x" in m.tags
        assert "fetch-detail" in m.body and "exit 2" in m.body
        assert "still serving the previous build" in m.body
        assert "deployed" not in m.body.lower()

    def test_exit_65_lists_the_hard_checks_that_failed(self, ntfy):
        failing = [check("judete_count", ok=False, level="hard", message="41 județe, expected 42")]
        recs = {"run": run_rec(), "schema": schema_rec(),
                "export": export_rec(status="fail", failing=failing)}
        m = summary(ntfy, outcome="failed", exit_code=65, recs=recs)
        assert "hard checks" in m.body
        assert "judete_count" in m.body and "41 județe, expected 42" in m.body
        assert "still serving the previous build" in m.body

    def test_exit_78_says_migrations_are_pending(self, ntfy):
        m = summary(ntfy, outcome="failed", exit_code=78, recs={})
        assert "unapplied migrations" in m.body and "migrate" in m.body

    def test_with_no_records_at_all_it_still_says_something_useful(self, ntfy):
        m = summary(ntfy, outcome="failed", exit_code=1, recs={})
        assert m.title == "posturi 13:15 FAILED"    # the run id is the wrapper's start time
        assert "exit 1" in m.body and m.priority == 4

    def test_a_run_id_that_is_not_a_timestamp_leaves_the_time_out(self, ntfy):
        m = summary(ntfy, outcome="failed", exit_code=1, recs={}, run_id="manual-backfill")
        assert m.title == "posturi FAILED"

    def test_a_failure_does_not_mention_balance_unless_it_is_known(self, ntfy):
        m = summary(ntfy, outcome="failed", exit_code=1, recs={}, balance=None)
        assert "Balance" not in m.body


# ---------------------------------------------------------------- the spend ledger

class TestBalanceLedger:
    """The DeepSeek balance is the ACCOUNT's, shared with other projects. Between two
    runs' start-of-run readings it can only fall by what this project logged (the
    schema steps in between) plus what somebody else spent; it rises by a top-up.
    """

    A, B = "2026-10-11T15:33:01Z", "2026-10-12T10:15:01Z"

    def log(self, before, ours, after, *, between=()):
        return [balance_rec("2026-10-11T15:33:04Z", "ok", before, run_id=self.A),
                schema_rec(run_id=self.A, cost=ours), *between,
                balance_rec("2026-10-12T10:15:05Z", "ok", after, run_id=self.B)]

    def test_a_fall_the_log_does_not_account_for_is_spent_elsewhere(self, ntfy):
        got = ntfy.balance_ledger(self.log(1.26, 0.028, 0.83), self.B)
        assert got["elsewhere"] == pytest.approx(0.402)
        assert got["topped_up"] == 0

    def test_a_rise_is_a_top_up(self, ntfy):
        got = ntfy.balance_ledger(self.log(0.45, 0.0, 5.45), self.B)
        assert got["topped_up"] == pytest.approx(5.00)
        assert got["elsewhere"] == 0

    def test_a_top_up_is_what_was_added_on_top_of_our_own_spend(self, ntfy):
        got = ntfy.balance_ledger(self.log(0.45, 0.10, 5.35), self.B)
        assert got["topped_up"] == pytest.approx(5.00)

    def test_our_own_spend_alone_is_nothing_to_report(self, ntfy):
        assert ntfy.balance_ledger(self.log(1.40, 0.04, 1.36), self.B) == \
            {"elsewhere": 0.0, "topped_up": 0.0}

    def test_cents_of_unlogged_occupations_spend_are_not_reported(self, ntfy):
        got = ntfy.balance_ledger(self.log(1.40, 0.04, 1.40 - 0.04 - 0.03), self.B)
        assert got == {"elsewhere": 0.0, "topped_up": 0.0}

    def test_hand_runs_between_the_readings_count_as_ours(self, ntfy):
        manual = [schema_rec(run_id="v4-backfill-resume-2026-10-12T06:00:00Z", cost=0.30)]
        got = ntfy.balance_ledger(self.log(1.40, 0.04, 1.06, between=manual), self.B)
        assert got == {"elsewhere": 0.0, "topped_up": 0.0}

    def test_this_runs_own_spend_is_not_charged_to_the_gap_before_it(self, ntfy):
        recs = self.log(1.26, 0.028, 0.83) + [schema_rec(run_id=self.B, cost=0.37)]
        assert ntfy.balance_ledger(recs, self.B)["elsewhere"] == pytest.approx(0.402)

    def test_readings_without_a_balance_are_skipped(self, ntfy):
        unavailable = [{"kind": "llm-balance", "run_id": "x", "outcome": "unavailable",
                        "balance": None, "checked_at": "2026-10-12T06:00:00Z"}]
        got = ntfy.balance_ledger(self.log(1.26, 0.028, 0.83, between=unavailable), self.B)
        assert got["elsewhere"] == pytest.approx(0.402)

    def test_with_no_earlier_reading_there_is_nothing_to_compare(self, ntfy):
        only = [balance_rec("2026-10-12T10:15:05Z", "ok", 0.83, run_id=self.B)]
        assert ntfy.balance_ledger(only, self.B) is None

    def test_a_run_with_no_reading_of_its_own_has_no_ledger(self, ntfy):
        assert ntfy.balance_ledger(self.log(1.26, 0.028, 0.83), "some-other-run") is None


class TestRunSummaryLedger:
    def test_money_spent_elsewhere_is_named_beside_the_balance(self, ntfy):
        m = ntfy.build_run_summary(
            "ok", 0, {"run": run_rec(), "export": export_rec(), "schema": schema_rec()},
            None, run_id=RUN, end_balance=12.0, minimum=1.0, warn=5.0,
            ledger={"elsewhere": 0.402, "topped_up": 0.0})
        assert "Other spend $0.40 since last run" in m.body
        assert "Top-up" not in m.body

    def test_a_top_up_is_named(self, ntfy):
        m = ntfy.build_run_summary(
            "ok", 0, {"run": run_rec(), "export": export_rec(), "schema": schema_rec()},
            None, run_id=RUN, end_balance=5.45, minimum=1.0, warn=5.0,
            ledger={"elsewhere": 0.0, "topped_up": 5.0})
        assert "Top-up $5.00 since last run" in m.body
        assert "Other spend" not in m.body

    @pytest.mark.parametrize("ledger", [None, {"elsewhere": 0.0, "topped_up": 0.0}])
    def test_nothing_to_report_says_nothing(self, ntfy, ledger):
        m = ntfy.build_run_summary(
            "ok", 0, {"run": run_rec(), "export": export_rec(), "schema": schema_rec()},
            None, run_id=RUN, end_balance=12.0, minimum=1.0, warn=5.0, ledger=ledger)
        assert "Other spend" not in m.body and "Top-up" not in m.body

    def test_it_does_not_change_the_priority(self, ntfy):
        m = ntfy.build_run_summary(
            "ok", 0, {"run": run_rec(), "export": export_rec(), "schema": schema_rec()},
            None, run_id=RUN, end_balance=12.0, minimum=1.0, warn=5.0,
            ledger={"elsewhere": 3.0, "topped_up": 0.0})
        assert m.priority == 2


# ------------------------------------------------- FIX-10: every step's LLM cost

class TestUsageRecords:
    def test_for_run_collects_the_usage_of_each_step(self, ntfy):
        recs = [usage_rec("schema"), usage_rec("occupations", cost=0.007),
                usage_rec("schema", run_id="other"), schema_rec()]
        got = ntfy.for_run(recs, RUN)
        assert set(got["usage"]) == {"schema", "occupations"}
        assert got["usage"]["occupations"]["usage"]["cost_usd"] == 0.007

    def test_a_run_with_no_usage_records_has_no_usage_key(self, ntfy):
        assert "usage" not in ntfy.for_run([schema_rec()], RUN)


class TestRunSummaryLlmCost:
    def body(self, ntfy, usage, **kw):
        recs = {"run": run_rec(), "export": export_rec(), "schema": schema_rec(), "usage": usage}
        return ntfy.build_run_summary("ok", 0, recs, None, run_id=RUN, end_balance=None,
                                      minimum=1.0, warn=5.0, **kw).body

    def test_the_total_covers_every_step_with_the_breakdown(self, ntfy):
        usage = {"schema": usage_rec("schema", cost=0.369916),
                 "occupations": usage_rec("occupations", cost=0.0070, tokens_in=40_000, tokens_out=8_000),
                 "infer": usage_rec("infer", cost=0.0003, tokens_in=900, tokens_out=40)}
        text = self.body(ntfy, usage)
        assert "LLM $0.377 (schema $0.370, occupations $0.007)" in text
        assert "1.9M in / 0.4M out" in text, "tokens are summed across steps too"

    def test_one_step_needs_no_breakdown(self, ntfy):
        text = self.body(ntfy, {"schema": usage_rec("schema", cost=0.369916)})
        assert "LLM $0.370 ·" in text and "(schema" not in text

    def test_older_runs_without_usage_records_fall_back_to_the_schema_record(self, ntfy):
        recs = {"run": run_rec(), "export": export_rec(), "schema": schema_rec()}
        text = ntfy.build_run_summary("ok", 0, recs, None, run_id=RUN, end_balance=None,
                                      minimum=1.0, warn=5.0).body
        assert "LLM $0.370 · 1.9M in / 0.4M out" in text

    def test_calls_with_no_price_are_counted_not_hidden(self, ntfy):
        text = self.body(ntfy, {"schema": usage_rec("schema", unpriced=3)})
        assert "3 calls unpriced" in text

    def test_a_ledger_that_missed_writes_says_so(self, ntfy):
        text = self.body(ntfy, {"schema": usage_rec("schema", write_errors=2)})
        assert "2 calls not in the ledger" in text

    def test_a_clean_ledger_adds_nothing(self, ntfy):
        text = self.body(ntfy, {"schema": usage_rec("schema")})
        assert "unpriced" not in text and "ledger" not in text


class TestLedgerCountsEveryStep:
    A, B = "2026-10-11T15:33:01Z", "2026-10-12T10:15:01Z"

    def log(self, before, after, *between):
        return [balance_rec("2026-10-11T15:33:04Z", "ok", before, run_id=self.A), *between,
                balance_rec("2026-10-12T10:15:05Z", "ok", after, run_id=self.B)]

    def test_occupations_and_infer_spend_is_ours_not_elsewhere(self, ntfy):
        between = [usage_rec("schema", run_id=self.A, cost=0.37),
                   usage_rec("occupations", run_id=self.A, cost=0.20),
                   usage_rec("infer", run_id=self.A, cost=0.10)]
        got = ntfy.balance_ledger(self.log(1.40, 0.73, *between), self.B)
        assert got == {"elsewhere": 0.0, "topped_up": 0.0}

    def test_a_step_with_both_records_is_counted_once(self, ntfy):
        # llm-schema (old measure) and llm-usage describe the same spend.
        between = [schema_rec(run_id=self.A, cost=0.35), usage_rec("schema", run_id=self.A, cost=0.37)]
        got = ntfy.balance_ledger(self.log(1.40, 1.03, *between), self.B)
        assert got == {"elsewhere": 0.0, "topped_up": 0.0}

    def test_a_run_from_before_usage_records_still_counts_through_llm_schema(self, ntfy):
        got = ntfy.balance_ledger(self.log(1.26, 0.83, schema_rec(run_id=self.A, cost=0.028)), self.B)
        assert got["elsewhere"] == pytest.approx(0.402)

    def test_hand_run_usage_between_the_readings_counts_as_ours(self, ntfy):
        manual = [usage_rec("schema", run_id="v4-backfill-resume-2026-10-12T06:00:00Z", cost=0.30)]
        got = ntfy.balance_ledger(self.log(1.40, 1.10, *manual), self.B)
        assert got == {"elsewhere": 0.0, "topped_up": 0.0}


# ------------------------------------------------------------------------- CLI

@pytest.fixture
def cli(ntfy, clean_env, monkeypatch, tmp_path, posts):
    """main() with a scratch run log, a topic, and no real balance lookup."""
    log = tmp_path / "runs.jsonl"
    prev = "2026-10-11T15:33:01Z"
    log.write_text("\n".join(json.dumps(r) for r in (
        balance_rec("2026-10-11T15:33:04Z", "warn", 1.26, run_id=prev),
        export_rec(run_id=prev, active=2363, calendar=860),
        schema_rec(run_id=prev, cost=0.028),
        balance_rec("2026-10-12T10:15:05Z", "warn", 0.83, run_id=RUN),
        run_rec(), export_rec(), schema_rec(), deploy_rec())) + "\n")
    monkeypatch.setenv("NTFY_TOPIC", TOPIC)
    monkeypatch.setenv("POSTURI_RUN_ID", RUN)
    monkeypatch.setattr(ntfy, "fetch_end_balance", lambda: 12.00)

    def call(*argv):
        return ntfy.main(["run", "--run-log", str(log), *argv])
    call.log = log
    return call


class TestCli:
    def test_run_sends_one_summary_built_from_the_log(self, cli, posts):
        assert cli("--outcome", "ok", "--exit", "0") == 0
        (req,) = posts
        assert req["body"]["title"] == "posturi 13:15 ok"
        assert "Active 2,504 (+141)" in req["body"]["message"]
        assert "Balance $12.00" in req["body"]["message"]

    def test_run_tells_you_what_the_account_lost_that_this_project_did_not_spend(
            self, cli, posts):
        cli("--outcome", "ok", "--exit", "0")
        assert "Other spend $0.40 since last run" in posts[0]["body"]["message"]

    def test_the_exit_status_is_0_even_when_the_push_fails(self, cli, monkeypatch):
        def boom(*a, **k):
            raise urllib.error.URLError("down")
        monkeypatch.setattr("urllib.request.urlopen", boom)
        assert cli("--outcome", "failed", "--exit", "65") == 0

    def test_the_exit_status_is_0_when_the_log_is_missing(self, cli, posts):
        cli.log.unlink()
        assert cli("--outcome", "failed", "--exit", "1") == 0
        assert posts and posts[0]["body"]["title"] == "posturi 13:15 FAILED"

    def test_a_balance_lookup_that_raises_is_swallowed(self, cli, ntfy, monkeypatch, posts):
        def boom():
            raise RuntimeError("endpoint down")
        monkeypatch.setattr(ntfy, "fetch_end_balance", boom)
        assert cli("--outcome", "ok", "--exit", "0") == 0
        assert "Balance" not in posts[0]["body"]["message"]

    def test_no_topic_sends_nothing(self, cli, monkeypatch, posts):
        monkeypatch.delenv("NTFY_TOPIC")
        assert cli("--outcome", "ok", "--exit", "0") == 0
        assert posts == []

    def test_summary_off_silences_successes_but_not_problems(self, cli, monkeypatch, posts):
        monkeypatch.setenv("NTFY_SUMMARY", "off")
        cli("--outcome", "ok", "--exit", "0")
        assert posts == []
        cli("--outcome", "degraded", "--exit", "1")
        cli("--outcome", "failed", "--exit", "65")
        assert [p["body"]["title"].split()[-1] for p in posts] == ["degraded", "FAILED"]

    def test_summary_off_still_alerts_when_the_balance_ran_out(self, cli, ntfy, monkeypatch, posts):
        monkeypatch.setenv("NTFY_SUMMARY", "off")
        monkeypatch.setattr(ntfy, "fetch_end_balance", lambda: 0.30)
        cli("--outcome", "ok", "--exit", "0")
        assert len(posts) == 1 and "next slot will abort" in posts[0]["body"]["message"]

    def test_the_run_id_can_be_passed_explicitly(self, cli, monkeypatch, posts):
        monkeypatch.delenv("POSTURI_RUN_ID")
        cli("--outcome", "ok", "--exit", "0", "--run-id", RUN)
        assert posts[0]["body"]["title"] == "posturi 13:15 ok"


class TestCliTest:
    def test_test_sends_a_marker_message_and_reports_success(self, ntfy, clean_env,
                                                             monkeypatch, posts, capsys):
        monkeypatch.setenv("NTFY_TOPIC", TOPIC)
        assert ntfy.main(["test"]) == 0
        assert posts[0]["body"]["title"] == "posturi ntfy test"
        assert TOPIC not in capsys.readouterr().out

    def test_test_exits_1_when_nothing_is_configured(self, ntfy, clean_env, posts, capsys):
        assert ntfy.main(["test"]) == 1
        assert posts == []
        assert "NTFY_TOPIC" in capsys.readouterr().out


class TestSuiteSafety:
    def test_no_test_can_inherit_the_real_topic_from_the_environment_or_dotenv(self):
        """With the topic in .env, a test of the abort path would otherwise buzz a phone.

        webapp/conftest.py loads llm_config (and so .env) first, then removes the NTFY_*
        variables for every test; tests that want a topic set their own.
        """
        import os
        assert not os.environ.get("NTFY_TOPIC")
        assert not os.environ.get("NTFY_URL")

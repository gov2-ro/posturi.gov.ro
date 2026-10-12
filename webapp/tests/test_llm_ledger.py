"""FIX-10 — llm_ledger: the shared, thread-safe record of every provider API attempt.

Nothing here calls a provider or needs a database except `TestPostgresSql`, which
runs the real INSERT against the test Postgres. What is pinned:

  * a row per attempt, with null (never zero) where usage is unknown;
  * the rates and cost at call time, from an injected pricer;
  * call ids that are unique under threads and make re-delivery a no-op;
  * a broken sink or pricer never reaches the caller, and totals do not depend on it.
"""

import importlib.util
import sys
import threading
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import llm_ledger as L  # noqa: E402

T0 = datetime(2026, 10, 12, 10, 20, 0, tzinfo=timezone.utc)
T1 = T0 + timedelta(seconds=3, milliseconds=250)


def fake_pricer(provider, model, usage, ended_at):
    if usage is None or usage.input_tokens is None or usage.output_tokens is None:
        return None, None
    cost = (usage.input_tokens * 1.0 + usage.output_tokens * 2.0) / 1_000_000
    return cost, {"input_per_million": 1.0, "output_per_million": 2.0, "peak": False}


def make(sink=None, **kw):
    sink = sink if sink is not None else L.MemorySink()
    base = dict(step="schema", run_id="2026-10-12T10:15:01Z", provider="deepseek",
                model="deepseek-v4-flash", prompt_version="v4", pricer=fake_pricer)
    base.update(kw)
    return L.Ledger(sink, **base), sink


def usage(i=1000, o=200, cache=400, **kw):
    return L.Usage(input_tokens=i, output_tokens=o, cache_read_tokens=cache, **kw)


# ------------------------------------------------------------------ the rows

class TestRows:
    def test_a_successful_attempt_is_one_complete_row(self):
        ledger, sink = make()
        row = ledger.request("medic-specialist", posting_id=7, source_revision="abc123") \
            .record_attempt(attempt=1, is_repair=False, started_at=T0, ended_at=T1,
                            outcome="ok", usage=usage(request_id="req-9"))
        assert sink.rows == [row]
        assert row["step"] == "schema" and row["run_id"] == "2026-10-12T10:15:01Z"
        assert (row["provider"], row["model"], row["prompt_version"]) == \
            ("deepseek", "deepseek-v4-flash", "v4")
        assert (row["subject"], row["posting_id"], row["source_revision"]) == \
            ("medic-specialist", 7, "abc123")
        assert (row["attempt"], row["is_repair"], row["api_ok"], row["outcome"]) == \
            (1, False, True, "ok")
        assert row["error_class"] == "" and row["request_id"] == "req-9"
        assert (row["input_tokens"], row["output_tokens"], row["cache_read_tokens"]) == (1000, 200, 400)
        assert row["cost_usd"] == pytest.approx(0.0014)
        assert row["pricing"]["peak"] is False
        assert row["latency_ms"] == 3250
        assert row["started_at"] == T0 and row["ended_at"] == T1

    def test_timestamps_are_timezone_aware_utc(self):
        # The Bucharest day is derived from these at report time; a naive value would
        # silently land on the wrong day around midnight.
        ledger, _ = make()
        late = datetime(2026, 10, 12, 21, 30, tzinfo=timezone.utc)      # 00:30 on the 13th in Bucharest
        row = ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=late,
                                                 ended_at=late, outcome="ok", usage=usage())
        assert row["ended_at"].utcoffset() == timedelta(0)

    def test_a_call_that_failed_before_any_usage_has_nulls_not_zeros(self):
        ledger, _ = make()
        row = ledger.request("x").record_attempt(
            attempt=1, is_repair=False, started_at=T0, ended_at=T1,
            outcome="transient_error", error=TimeoutError("took too long"), usage=None)
        assert row["api_ok"] is False
        assert row["error_class"] == "TimeoutError"
        for f in ("input_tokens", "output_tokens", "cache_read_tokens", "cache_write_tokens",
                  "reasoning_tokens", "cost_usd", "pricing", "request_id"):
            assert row[f] in (None, ""), f
        assert row["input_tokens"] is None and row["cost_usd"] is None

    def test_the_error_message_is_never_stored(self):
        ledger, _ = make()
        row = ledger.request("x").record_attempt(
            attempt=1, is_repair=False, started_at=T0, ended_at=T1, outcome="error",
            error=RuntimeError("Bearer sk-live-secret leaked in a message"), usage=None)
        assert "sk-live" not in repr(row)

    def test_a_validation_failure_after_a_response_is_billed_and_api_ok(self):
        ledger, _ = make()
        row = ledger.request("x").record_attempt(
            attempt=1, is_repair=False, started_at=T0, ended_at=T1,
            outcome="validation_failed", error=ValueError("bad stage"), usage=usage())
        assert row["api_ok"] is True and row["outcome"] == "validation_failed"
        assert row["cost_usd"] == pytest.approx(0.0014), "a rejected answer is still paid for"

    def test_a_repair_is_a_second_row_flagged_as_one(self):
        ledger, sink = make()
        req = ledger.request("x")
        req.record_attempt(attempt=1, is_repair=False, started_at=T0, ended_at=T1,
                           outcome="validation_failed", error=ValueError("x"), usage=usage())
        req.record_attempt(attempt=2, is_repair=True, started_at=T1, ended_at=T1,
                           outcome="ok", usage=usage())
        assert [(r["attempt"], r["is_repair"], r["outcome"]) for r in sink.rows] == \
            [(1, False, "validation_failed"), (2, True, "ok")]

    def test_reasoning_tokens_are_recorded_but_do_not_change_the_cost(self):
        ledger, _ = make()
        plain = ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                                   ended_at=T1, outcome="ok", usage=usage())
        thinking = ledger.request("x").record_attempt(
            attempt=1, is_repair=False, started_at=T0, ended_at=T1, outcome="ok",
            usage=usage(reasoning_tokens=150))
        assert thinking["reasoning_tokens"] == 150
        assert thinking["cost_usd"] == plain["cost_usd"], "reasoning is inside output_tokens"

    def test_the_pricer_is_asked_at_the_time_the_call_ended(self):
        seen = []

        def pricer(provider, model, u, ended_at):
            seen.append(ended_at)
            return 0.0, {}
        ledger, _ = make(pricer=pricer)
        ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                           ended_at=T1, outcome="ok", usage=usage())
        assert seen == [T1]

    def test_a_broken_pricer_leaves_the_cost_unknown_not_the_row_missing(self):
        def broken(*a, **k):
            raise KeyError("model vanished from the config")
        ledger, sink = make(pricer=broken)
        row = ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                                 ended_at=T1, outcome="ok", usage=usage())
        assert sink.rows == [row] and row["cost_usd"] is None and row["pricing"] is None


# ------------------------------------------------------------------- identity

class TestCallIds:
    def test_every_attempt_gets_its_own_id(self):
        ledger, sink = make()
        req = ledger.request("x")
        for n in (1, 2, 3):
            req.record_attempt(attempt=n, is_repair=False, started_at=T0, ended_at=T1,
                               outcome="ok", usage=usage())
        assert len({r["call_id"] for r in sink.rows}) == 3

    def test_the_same_posting_twice_is_two_calls(self):
        # Two force re-extractions are two real payments; neither may replace the other.
        ledger, sink = make()
        for _ in range(2):
            ledger.request("same-posting").record_attempt(
                attempt=1, is_repair=False, started_at=T0, ended_at=T1, outcome="ok", usage=usage())
        assert len(sink.rows) == 2
        assert sink.rows[0]["call_id"] != sink.rows[1]["call_id"]

    def test_ids_are_unique_across_threads(self):
        ledger, sink = make()

        def work():
            for _ in range(50):
                ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                                   ended_at=T1, outcome="ok", usage=usage())
        threads = [threading.Thread(target=work) for _ in range(8)]
        [t.start() for t in threads]
        [t.join() for t in threads]
        assert len(sink.rows) == 400
        assert len({r["call_id"] for r in sink.rows}) == 400

    def test_two_processes_cannot_collide(self):
        a, sa = make()
        b, sb = make()
        for ledger in (a, b):
            ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                               ended_at=T1, outcome="ok", usage=usage())
        assert sa.rows[0]["call_id"] != sb.rows[0]["call_id"]

    def test_a_redelivered_row_is_stored_once(self):
        sink = L.MemorySink()
        row = {"call_id": "r/schema/n-1", "cost_usd": 0.01}
        assert sink.write(row) is True
        assert sink.write(dict(row)) is True
        assert len(sink.rows) == 1


# --------------------------------------------------------------- the usage stash

class TestUsageHandOff:
    def test_usage_noted_by_the_provider_code_is_taken_once(self):
        L.discard_usage()
        L.note_usage(input_tokens=10, output_tokens=2, cache_read_tokens=4, request_id="r")
        got = L.take_usage()
        assert (got.input_tokens, got.output_tokens, got.cache_read_tokens, got.request_id) == (10, 2, 4, "r")
        assert L.take_usage() is None

    def test_one_threads_usage_is_invisible_to_another(self):
        L.discard_usage()
        seen = []
        t = threading.Thread(target=lambda: seen.append(L.take_usage()))
        L.note_usage(input_tokens=1, output_tokens=1)
        t.start()
        t.join()
        assert seen == [None]
        assert L.take_usage().input_tokens == 1

    def test_unknown_fields_stay_none(self):
        L.discard_usage()
        L.note_usage(input_tokens=5, output_tokens=None)
        got = L.take_usage()
        assert got.output_tokens is None and got.cache_read_tokens is None


# ---------------------------------------------------------------------- totals

class TestTotals:
    def test_totals_sum_what_is_known_and_count_what_is_not(self):
        ledger, _ = make()
        req = ledger.request("x")
        req.record_attempt(attempt=1, is_repair=False, started_at=T0, ended_at=T1,
                           outcome="transient_error", error=TimeoutError(), usage=None)
        req.record_attempt(attempt=2, is_repair=False, started_at=T0, ended_at=T1,
                           outcome="validation_failed", error=ValueError(), usage=usage())
        req.record_attempt(attempt=3, is_repair=True, started_at=T0, ended_at=T1,
                           outcome="ok", usage=usage(reasoning_tokens=50))
        t = ledger.totals()
        assert (t["calls"], t["api_ok_calls"], t["error_calls"], t["repair_calls"]) == (3, 2, 1, 1)
        assert t["unknown_cost_calls"] == 1
        assert (t["input_tokens"], t["output_tokens"], t["cache_read_tokens"],
                t["reasoning_tokens"]) == (2000, 400, 800, 50)
        assert t["cost_usd"] == pytest.approx(0.0028)

    def test_the_usage_record_is_the_run_log_shape_the_summary_reads(self):
        ledger, _ = make()
        ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                           ended_at=T1, outcome="ok", usage=usage())
        rec = ledger.usage_record()
        assert rec["kind"] == "llm-usage" and rec["step"] == "schema"
        assert rec["run_id"] == "2026-10-12T10:15:01Z" and rec["provider"] == "deepseek"
        assert rec["calls"] == 1 and rec["ledger_write_errors"] == 0
        assert rec["usage"]["cost_usd"] == pytest.approx(0.0014)
        assert rec["usage"]["input_tokens"] == 1000 and rec["usage"]["cached_input_tokens"] == 400

    def test_a_step_that_made_no_calls_writes_no_record(self):
        ledger, _ = make()
        assert ledger.usage_record() is None

    def test_a_dead_sink_never_reaches_the_caller_and_totals_survive_it(self):
        class Dead:
            def write(self, row):
                raise ConnectionError("database is down")
        ledger, _ = make(sink=Dead())
        ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                           ended_at=T1, outcome="ok", usage=usage())
        t = ledger.totals()
        assert t["calls"] == 1 and t["cost_usd"] == pytest.approx(0.0014)
        assert t["write_errors"] == 1
        assert ledger.usage_record()["ledger_write_errors"] == 1

    def test_a_sink_that_reports_failure_is_counted_the_same_way(self):
        class Refuses:
            def write(self, row):
                return False
        ledger, _ = make(sink=Refuses())
        ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                           ended_at=T1, outcome="ok", usage=usage())
        assert ledger.totals()["write_errors"] == 1

    def test_totals_are_exact_under_threads(self):
        ledger, _ = make()

        def work():
            for _ in range(25):
                ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                                   ended_at=T1, outcome="ok", usage=usage())
        threads = [threading.Thread(target=work) for _ in range(8)]
        [t.start() for t in threads]
        [t.join() for t in threads]
        assert ledger.totals()["calls"] == 200
        assert ledger.totals()["cost_usd"] == pytest.approx(200 * 0.0014)


# --------------------------------------------------------------------- factory

class TestFactory:
    def test_off_switch_keeps_totals_but_writes_no_rows(self, monkeypatch):
        monkeypatch.setenv("POSTURI_LEDGER", "off")
        ledger = L.make_ledger("schema", "deepseek", "deepseek-v4-flash", "v4",
                               run_id="r", pricer=fake_pricer)
        ledger.request("x").record_attempt(attempt=1, is_repair=False, started_at=T0,
                                           ended_at=T1, outcome="ok", usage=usage())
        assert ledger.totals()["calls"] == 1 and ledger.totals()["write_errors"] == 0
        assert isinstance(ledger.sink, L.NullSink)

    def test_by_default_it_writes_to_postgres(self, monkeypatch):
        monkeypatch.delenv("POSTURI_LEDGER", raising=False)
        ledger = L.make_ledger("occupations", "deepseek", "deepseek-v4-flash", "",
                               run_id="r", pricer=fake_pricer, dsn="postgres://nowhere/none")
        assert isinstance(ledger.sink, L.PostgresSink)

    def test_the_run_id_comes_from_the_environment_when_not_given(self, monkeypatch):
        monkeypatch.setenv("POSTURI_LEDGER", "off")
        monkeypatch.setenv("POSTURI_RUN_ID", "2026-10-13T10:15:01Z")
        ledger = L.make_ledger("infer", "deepseek", "deepseek-v4-flash", "", pricer=fake_pricer)
        assert ledger.run_id == "2026-10-13T10:15:01Z"


# ------------------------------------------------------------------- Postgres

class FakeConn:
    def __init__(self, fail_first=0):
        self.calls, self.fail_first, self.closed = [], fail_first, False
        self.inside = 0
        self.overlap = False

    def execute(self, sql, params=None):
        self.inside += 1
        if self.inside > 1:
            self.overlap = True
        try:
            if self.fail_first:
                self.fail_first -= 1
                raise ConnectionError("server closed the connection")
            self.calls.append((sql, params))
            return self
        finally:
            self.inside -= 1

    def close(self):
        self.closed = True


ROW = {"call_id": "r/schema/n-1", "run_id": "r", "step": "schema", "subject": "x",
       "posting_id": 7, "provider": "deepseek", "model": "m", "prompt_version": "v4",
       "source_revision": "", "started_at": T0, "ended_at": T1, "latency_ms": 3250,
       "attempt": 1, "is_repair": False, "api_ok": True, "outcome": "ok", "error_class": "",
       "request_id": "", "input_tokens": 1000, "output_tokens": 200, "cache_read_tokens": 400,
       "cache_write_tokens": None, "reasoning_tokens": None,
       "pricing": {"peak": False}, "cost_usd": 0.0014}


class TestPostgresSink:
    def test_one_insert_per_row_that_ignores_a_repeat(self):
        conn = FakeConn()
        sink = L.PostgresSink("dsn", connect=lambda dsn: conn)
        assert sink.write(ROW) is True
        (sql, params), = conn.calls
        assert "ON CONFLICT (call_id) DO NOTHING" in sql and "jobs_llmcall" in sql
        assert params["call_id"] == "r/schema/n-1"

    def test_a_dropped_connection_is_reopened_once(self):
        conns = [FakeConn(fail_first=1), FakeConn()]
        sink = L.PostgresSink("dsn", connect=lambda dsn: conns.pop(0))
        assert sink.write(ROW) is True

    def test_a_database_that_stays_down_is_false_not_an_exception(self):
        sink = L.PostgresSink("dsn", connect=lambda dsn: FakeConn(fail_first=99))
        assert sink.write(ROW) is False
        assert sink.errors == 1

    def test_connect_failure_is_false(self):
        def refuse(dsn):
            raise ConnectionError("no route")
        assert L.PostgresSink("dsn", connect=refuse).write(ROW) is False

    def test_writers_do_not_overlap_on_the_shared_connection(self):
        conn = FakeConn()
        sink = L.PostgresSink("dsn", connect=lambda dsn: conn)
        threads = [threading.Thread(target=lambda: [sink.write(dict(ROW, call_id=f"c{n}-{i}"))
                                                    for i in range(30)]) for n in range(6)]
        [t.start() for t in threads]
        [t.join() for t in threads]
        assert len(conn.calls) == 180 and conn.overlap is False

    def test_the_connection_string_is_never_reported_in_a_warning(self, capsys):
        def refuse(dsn):
            raise ConnectionError(f"could not connect to {dsn}")
        L.PostgresSink("postgres://user:hunter2@host/db", connect=refuse).write(ROW)
        out = capsys.readouterr()
        assert "hunter2" not in out.out + out.err


@pytest.mark.django_db
class TestPostgresSql:
    """The INSERT, run for real against the test database."""

    def run_insert(self, row):
        from django.db import connection
        with connection.cursor() as cur:
            cur.execute(L.INSERT_SQL, L.row_params(row))

    def test_a_row_round_trips(self):
        from apps.jobs.models import LlmCall
        self.run_insert(ROW)
        got = LlmCall.objects.get(call_id="r/schema/n-1")
        assert (got.step, got.attempt, got.outcome, got.input_tokens) == ("schema", 1, "ok", 1000)
        assert got.pricing == {"peak": False} and float(got.cost_usd) == pytest.approx(0.0014)
        assert got.ended_at == T1 and got.created_at is not None

    def test_a_repeat_is_ignored_and_a_null_stays_null(self):
        from apps.jobs.models import LlmCall
        self.run_insert(dict(ROW, input_tokens=None, output_tokens=None, cost_usd=None, pricing=None))
        self.run_insert(ROW)
        got = LlmCall.objects.get()
        assert got.input_tokens is None and got.cost_usd is None and got.pricing is None


# --------------------------------------------------- the default pricer (real rates)

class TestDefaultPricer:
    OFF = datetime(2026, 10, 5, 5, tzinfo=timezone.utc)      # a Monday, before the peak window
    PEAK = datetime(2026, 10, 5, 7, tzinfo=timezone.utc)

    def test_it_prices_with_the_runners_rates_and_attaches_the_card(self):
        price = L.default_pricer()
        u = L.Usage(input_tokens=1_000_000, output_tokens=1_000_000)
        cost, card = price("deepseek", "deepseek-v4-flash", u, self.OFF)
        assert cost == pytest.approx(0.75) and card["peak"] is False
        cost, card = price("deepseek", "deepseek-v4-flash", u, self.PEAK)
        assert cost == pytest.approx(1.5) and card["peak"] is True

    def test_cache_reads_are_billed_at_the_cache_rate(self):
        price = L.default_pricer()
        u = L.Usage(input_tokens=1_000_000, output_tokens=0, cache_read_tokens=1_000_000)
        assert price("deepseek", "deepseek-v4-flash", u, self.OFF)[0] == pytest.approx(0.003)

    def test_reasoning_tokens_do_not_change_the_amount(self):
        price = L.default_pricer()
        plain = L.Usage(input_tokens=10_000, output_tokens=5_000)
        thinking = L.Usage(input_tokens=10_000, output_tokens=5_000, reasoning_tokens=4_000)
        assert price("deepseek", "deepseek-v4-flash", thinking, self.OFF) == \
            price("deepseek", "deepseek-v4-flash", plain, self.OFF)

    def test_no_usage_and_no_such_model_are_unpriced(self):
        price = L.default_pricer()
        assert price("deepseek", "deepseek-v4-flash", None, self.OFF) == (None, None)
        u = L.Usage(input_tokens=10, output_tokens=5)
        assert price("deepseek", "no-such-model", u, self.OFF) == (None, None)


class TestSuiteSafety:
    def test_no_test_can_write_to_the_real_ledger_table(self):
        """webapp/conftest.py sets POSTURI_LEDGER=off for every test, so a test that
        reaches a step's main() (or make_ledger) cannot insert into the real
        `jobs_llmcall`; the tests that need a sink pass their own."""
        import os
        assert os.environ.get("POSTURI_LEDGER") == "off"

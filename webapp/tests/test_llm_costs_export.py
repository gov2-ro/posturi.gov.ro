"""`llm_costs` — daily LLM spend, aggregated for the PHP /statistici page.

The SQLite file the live site serves has no access to Postgres, so the per-day
totals are computed at export time from `jobs_jobpostingschemavariant`. These tests
run the real aggregation against Postgres: the two things that go wrong quietly are
which *day* a call lands on (the connection may be UTC, the cron runs in Bucharest)
and rows with no recorded cost diluting the per-posting average.
"""

import importlib.util
import sqlite3
import sys
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path

import pytest
from django.db import connection
from psycopg.rows import dict_row

from apps.jobs.models import Employer, JobPosting, JobPostingSchemaVariant, LlmCall

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location(
        "llm_costs_under_test", REPO_ROOT / "export-to-sqlite.py"
    )
    mod = importlib.util.module_from_spec(spec)
    sys.modules["llm_costs_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


@pytest.fixture
def sqlite_con(exp):
    con = sqlite3.connect(":memory:")
    exp.create_schema(con)
    return con


@pytest.fixture
def employer(db):
    return Employer.objects.create(name="Test Employer LC", slug="test-employer-lc")


def make_variant(employer, n, *, created_at, cost, model="deepseek-v4-flash",
                 prompt_version="v3", input_tokens=9000, output_tokens=1300):
    posting = JobPosting.objects.create(
        url=f"https://posturi.gov.ro/joburi/llm-costs-{n}/", title=f"Post {n}",
        employer=employer,
    )
    variant = JobPostingSchemaVariant.objects.create(
        posting=posting, provider="deepseek", model=model, prompt_version=prompt_version,
        schema_json={}, input_tokens=input_tokens, output_tokens=output_tokens,
        cost_usd=cost,
    )
    # created_at is auto_now_add; pin it after the fact.
    JobPostingSchemaVariant.objects.filter(pk=variant.pk).update(created_at=created_at)
    return variant


def run_export(exp, con):
    with connection.connection.cursor(row_factory=dict_row) as cur:
        exp.export_llm_costs(cur, con)
    return con.execute(
        "SELECT day, provider, model, prompt_version, calls, input_tokens, "
        "output_tokens, cost_usd FROM llm_costs ORDER BY day, model, prompt_version"
    ).fetchall()


@pytest.mark.django_db
def test_calls_are_summed_per_day_and_model(exp, sqlite_con, employer):
    d1 = datetime(2026, 10, 2, 9, 0, tzinfo=timezone.utc)
    for i in range(3):
        make_variant(employer, i, created_at=d1, cost=Decimal("0.00100000"))
    make_variant(employer, 10, created_at=datetime(2026, 10, 3, 9, 0, tzinfo=timezone.utc),
                 cost=Decimal("0.00200000"))

    rows = run_export(exp, sqlite_con)

    assert rows == [
        ("2026-10-02", "deepseek", "deepseek-v4-flash", "v3", 3, 27000, 3900, pytest.approx(0.003)),
        ("2026-10-03", "deepseek", "deepseek-v4-flash", "v3", 1, 9000, 1300, pytest.approx(0.002)),
    ]


@pytest.mark.django_db
def test_day_is_the_bucharest_day_not_the_utc_day(exp, sqlite_con, employer):
    """22:30 UTC on the 2nd is 01:30 on the 3rd in Bucharest (UTC+3 in summer) — the
    evening cron run belongs to the 3rd. Django's own connection is UTC, so this
    only passes if the query converts explicitly instead of trusting the session."""
    make_variant(employer, 1, created_at=datetime(2026, 10, 2, 22, 30, tzinfo=timezone.utc),
                 cost=Decimal("0.001"))

    rows = run_export(exp, sqlite_con)

    assert [r[0] for r in rows] == ["2026-10-03"]


@pytest.mark.django_db
def test_rows_without_a_cost_are_left_out(exp, sqlite_con, employer):
    """A NULL cost means the provider returned no usage, not that the call was free.
    Counting it would drag the per-posting average toward zero."""
    when = datetime(2026, 10, 2, 9, 0, tzinfo=timezone.utc)
    make_variant(employer, 1, created_at=when, cost=Decimal("0.001"))
    make_variant(employer, 2, created_at=when, cost=None, input_tokens=None, output_tokens=None)

    rows = run_export(exp, sqlite_con)

    assert len(rows) == 1
    assert rows[0][4] == 1  # calls


@pytest.mark.django_db
def test_prompt_versions_and_models_stay_separate(exp, sqlite_con, employer):
    when = datetime(2026, 9, 15, 9, 0, tzinfo=timezone.utc)
    make_variant(employer, 1, created_at=when, cost=Decimal("0.001"), prompt_version="v3")
    make_variant(employer, 2, created_at=when, cost=Decimal("0.002"), prompt_version="v4")
    make_variant(employer, 3, created_at=when, cost=Decimal("0.003"), model="gemini-2.5-flash")

    rows = run_export(exp, sqlite_con)

    assert {(r[2], r[3]) for r in rows} == {
        ("deepseek-v4-flash", "v3"), ("deepseek-v4-flash", "v4"), ("gemini-2.5-flash", "v3"),
    }


@pytest.mark.django_db
def test_no_variants_leaves_the_table_empty(exp, sqlite_con):
    assert run_export(exp, sqlite_con) == []


def test_schema_declares_llm_costs(exp, sqlite_con):
    cols = [r[1] for r in sqlite_con.execute("PRAGMA table_info(llm_costs)")]
    # The first eight are what the deployed /statistici page reads by name; the rest are
    # additive (FIX-10) so an older page keeps working on a newer export.
    assert cols == ["day", "provider", "model", "prompt_version", "calls",
                    "input_tokens", "output_tokens", "cost_usd",
                    "step", "source", "unpriced_calls", "postings"]
    pk = [r[1] for r in sorted(sqlite_con.execute("PRAGMA table_info(llm_costs)"), key=lambda r: r[5]) if r[5]]
    assert pk == ["day", "provider", "model", "prompt_version", "step", "source"]



# ---------------------------------------------------------------- FIX-10: the ledger

def utc(*args):
    return datetime(*args, tzinfo=timezone.utc)


_call_seq = iter(range(1, 10_000))


def make_call(*, ended_at, cost, step="schema", outcome="ok", posting_id=None,
              model="deepseek-v4-flash", prompt_version="v4", input_tokens=9000,
              output_tokens=1300, started_at=None, provider="deepseek"):
    n = next(_call_seq)
    return LlmCall.objects.create(
        call_id=f"t/{step}/{n}", run_id="R", step=step, subject=f"s{n}", posting_id=posting_id,
        provider=provider, model=model, prompt_version=prompt_version,
        started_at=started_at or ended_at - timedelta(seconds=3), ended_at=ended_at,
        attempt=1, api_ok=cost is not None or outcome != "transient_error", outcome=outcome,
        input_tokens=input_tokens if cost is not None else None,
        output_tokens=output_tokens if cost is not None else None, cost_usd=cost)


def ledger_rows(exp, con):
    with connection.connection.cursor(row_factory=dict_row) as cur:
        exp.export_llm_costs(cur, con)
    return con.execute(
        "SELECT day, step, source, provider, model, prompt_version, calls, input_tokens, "
        "output_tokens, cost_usd, unpriced_calls, postings FROM llm_costs "
        "ORDER BY day, source, step, model, prompt_version").fetchall()


@pytest.mark.django_db
class TestLedgerExport:
    def test_calls_are_summed_per_bucharest_day_step_and_model(self, exp, sqlite_con):
        make_call(ended_at=utc(2026, 10, 12, 9, 0), cost=Decimal("0.001"), posting_id=1)
        make_call(ended_at=utc(2026, 10, 12, 9, 5), cost=Decimal("0.002"), posting_id=2)
        make_call(ended_at=utc(2026, 10, 12, 9, 6), cost=Decimal("0.0007"), step="occupations")
        make_call(ended_at=utc(2026, 10, 13, 9, 0), cost=Decimal("0.004"), posting_id=1)

        assert ledger_rows(exp, sqlite_con) == [
            ("2026-10-12", "occupations", "ledger", "deepseek", "deepseek-v4-flash", "v4",
             1, 9000, 1300, pytest.approx(0.0007), 0, 0),
            ("2026-10-12", "schema", "ledger", "deepseek", "deepseek-v4-flash", "v4",
             2, 18000, 2600, pytest.approx(0.003), 0, 2),
            ("2026-10-13", "schema", "ledger", "deepseek", "deepseek-v4-flash", "v4",
             1, 9000, 1300, pytest.approx(0.004), 0, 1),
        ]

    @pytest.mark.parametrize("ended_at, day", [
        # Summer time (EEST, UTC+3) until 04:00 on 2026-10-25.
        (utc(2026, 10, 12, 20, 59, 59), "2026-10-12"),
        (utc(2026, 10, 12, 21, 0, 0), "2026-10-13"),
        (utc(2026, 10, 24, 21, 0, 0), "2026-10-25"),
        # Winter time (EET, UTC+2) after it: midnight moves to 22:00 UTC.
        (utc(2026, 10, 25, 21, 59, 59), "2026-10-25"),
        (utc(2026, 10, 25, 22, 0, 0), "2026-10-26"),
    ])
    def test_the_day_is_the_bucharest_day_across_midnight_and_the_dst_change(
            self, exp, sqlite_con, ended_at, day):
        make_call(ended_at=ended_at, cost=Decimal("0.001"))
        assert [r[0] for r in ledger_rows(exp, sqlite_con)] == [day]

    def test_a_call_that_ended_after_midnight_belongs_to_the_day_it_ended(self, exp, sqlite_con):
        # Started 23:59:58 Bucharest, ended 00:00:02: one day, the day it was paid on.
        make_call(started_at=utc(2026, 10, 12, 20, 59, 58), ended_at=utc(2026, 10, 12, 21, 0, 2),
                  cost=Decimal("0.001"))
        assert [r[0] for r in ledger_rows(exp, sqlite_con)] == ["2026-10-13"]

    def test_calls_without_a_cost_are_counted_but_never_summed(self, exp, sqlite_con):
        when = utc(2026, 10, 12, 9, 0)
        make_call(ended_at=when, cost=Decimal("0.001"))
        make_call(ended_at=when, cost=Decimal("0.002"))
        make_call(ended_at=when, cost=None, outcome="transient_error")
        (row,) = ledger_rows(exp, sqlite_con)
        assert row[6] == 3, "calls"
        assert row[9] == pytest.approx(0.003), "cost_usd is the sum of the known costs"
        assert row[10] == 1, "unpriced_calls says how many are missing from it"
        assert (row[7], row[8]) == (18000, 2600), "unknown tokens are not zeros in the sum"

    def test_a_rejected_answer_is_a_paid_call_in_the_totals(self, exp, sqlite_con):
        when = utc(2026, 10, 12, 9, 0)
        make_call(ended_at=when, cost=Decimal("0.0030"), outcome="validation_failed", posting_id=5)
        make_call(ended_at=when, cost=Decimal("0.0030"), outcome="ok", posting_id=5)
        (row,) = ledger_rows(exp, sqlite_con)
        assert row[6] == 2 and row[9] == pytest.approx(0.006)
        assert row[11] == 1, "two attempts, one posting"

    def test_postings_counts_distinct_postings_with_a_valid_extraction(self, exp, sqlite_con):
        when = utc(2026, 10, 12, 9, 0)
        make_call(ended_at=when, cost=Decimal("0.001"), posting_id=1)
        make_call(ended_at=when, cost=Decimal("0.001"), posting_id=1)                     # force re-extraction
        make_call(ended_at=when, cost=Decimal("0.001"), posting_id=2, outcome="validation_failed")
        make_call(ended_at=when, cost=Decimal("0.001"), posting_id=2)                     # repaired
        make_call(ended_at=when, cost=Decimal("0.001"), posting_id=3, outcome="truncated")  # never stored
        (row,) = ledger_rows(exp, sqlite_con)
        assert row[11] == 2

    def test_the_amount_is_the_one_stored_at_call_time_never_repriced(self, exp, sqlite_con):
        # Same tokens either side of the 10:00 UTC end of the peak window: different stored
        # costs. The export sums what was stored; today's config cannot move them.
        make_call(ended_at=utc(2026, 10, 5, 9, 59), cost=Decimal("0.003000"))    # peak, 2x
        make_call(ended_at=utc(2026, 10, 5, 10, 0), cost=Decimal("0.001500"))    # off-peak
        (row,) = ledger_rows(exp, sqlite_con)
        assert row[9] == pytest.approx(0.0045)

    def test_providers_models_and_prompt_versions_stay_separate(self, exp, sqlite_con):
        when = utc(2026, 10, 12, 9, 0)
        make_call(ended_at=when, cost=Decimal("0.001"), prompt_version="v3")
        make_call(ended_at=when, cost=Decimal("0.001"), prompt_version="v4")
        make_call(ended_at=when, cost=Decimal("0.001"), model="gemini-2.5-flash", provider="gemini")
        assert {(r[3], r[4], r[5]) for r in ledger_rows(exp, sqlite_con)} == {
            ("deepseek", "deepseek-v4-flash", "v3"), ("deepseek", "deepseek-v4-flash", "v4"),
            ("gemini", "gemini-2.5-flash", "v4")}


@pytest.mark.django_db
class TestLegacyAndLedgerTogether:
    """Until 2026-10-12 spend is only in the variant table (an estimate that forgets
    every extraction a later one overwrote); after it, in the ledger. The export joins
    them at the first ledgered call and must count neither side twice."""

    def test_without_a_ledger_the_series_is_the_legacy_one_marked_as_such(
            self, exp, sqlite_con, employer):
        make_variant(employer, 1, created_at=utc(2026, 10, 2, 9, 0), cost=Decimal("0.003"))
        (row,) = ledger_rows(exp, sqlite_con)
        assert (row[1], row[2], row[6], row[10], row[11]) == ("schema", "legacy", 1, 0, 1)

    def test_variants_after_the_first_ledgered_call_are_the_ledgers_not_legacy(
            self, exp, sqlite_con, employer):
        first_call = utc(2026, 10, 12, 17, 43, 49)
        make_variant(employer, 1, created_at=utc(2026, 10, 12, 10, 55), cost=Decimal("0.370"))
        make_call(started_at=first_call, ended_at=first_call + timedelta(seconds=10),
                  cost=Decimal("0.001524"), posting_id=9)
        # The variant the ledgered call itself wrote a moment later: the same spend.
        make_variant(employer, 2, created_at=first_call + timedelta(seconds=10, milliseconds=300),
                     cost=Decimal("0.001524"))

        rows = ledger_rows(exp, sqlite_con)
        assert [(r[0], r[2], r[6], r[9]) for r in rows] == [
            ("2026-10-12", "ledger", 1, pytest.approx(0.001524)),
            ("2026-10-12", "legacy", 1, pytest.approx(0.370)),
        ]
        assert sum(r[9] for r in rows) == pytest.approx(0.371524), "each payment counted once"

    def test_legacy_days_before_the_ledger_are_kept(self, exp, sqlite_con, employer):
        make_variant(employer, 1, created_at=utc(2026, 10, 2, 9, 0), cost=Decimal("0.003"))
        make_variant(employer, 2, created_at=utc(2026, 10, 3, 9, 0), cost=Decimal("0.004"))
        make_call(ended_at=utc(2026, 10, 13, 9, 0), cost=Decimal("0.005"))
        by_source = {(r[0], r[2]) for r in ledger_rows(exp, sqlite_con)}
        assert by_source == {("2026-10-02", "legacy"), ("2026-10-03", "legacy"),
                             ("2026-10-13", "ledger")}

    def test_legacy_postings_equal_calls_since_a_variant_is_one_posting(
            self, exp, sqlite_con, employer):
        for i in range(3):
            make_variant(employer, i, created_at=utc(2026, 10, 2, 9, 0), cost=Decimal("0.001"))
        (row,) = ledger_rows(exp, sqlite_con)
        assert row[6] == 3 and row[11] == 3

    def test_the_previous_stats_queries_still_run_and_total_correctly(
            self, exp, sqlite_con, employer):
        """The page already deployed reads llm_costs by column name and sums every row.
        On a newer export it must keep working, and its total must stay the true total."""
        make_variant(employer, 1, created_at=utc(2026, 10, 2, 9, 0), cost=Decimal("0.003"))
        make_call(ended_at=utc(2026, 10, 13, 9, 0), cost=Decimal("0.005"))
        ledger_rows(exp, sqlite_con)
        usd, calls, since = sqlite_con.execute(
            "SELECT COALESCE(SUM(cost_usd), 0), COALESCE(SUM(calls), 0), MIN(day) FROM llm_costs"
        ).fetchone()
        assert (usd, calls, since) == (pytest.approx(0.008), 2, "2026-10-02")
        days = sqlite_con.execute(
            "SELECT day, SUM(calls), SUM(cost_usd) FROM llm_costs GROUP BY day ORDER BY day DESC"
        ).fetchall()
        assert [d[0] for d in days] == ["2026-10-13", "2026-10-02"]

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
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path

import pytest
from django.db import connection
from psycopg.rows import dict_row

from apps.jobs.models import Employer, JobPosting, JobPostingSchemaVariant

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
    assert cols == ["day", "provider", "model", "prompt_version", "calls",
                    "input_tokens", "output_tokens", "cost_usd"]

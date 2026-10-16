"""`publication_monthly` — historical publication intake, aggregated for /statistici (FIX-09).

The PHP host has no Postgres and, in production, only the announcements still open, so the
history is computed at export time over EVERY `jobs_jobposting` row. These run the real
export function against Postgres and reconcile it with direct queries. What goes wrong
quietly: expired rows dropping out, cancelled or undated rows being lost instead of
counted explicitly, and a month assigned by the session time zone instead of the date.
"""

import importlib.util
import sqlite3
import sys
from datetime import date
from pathlib import Path

import pytest
from django.db import connection
from psycopg.rows import dict_row

from apps.jobs.models import Employer, JobPosting, Judet

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location(
        "publication_monthly_under_test", REPO_ROOT / "export-to-sqlite.py"
    )
    mod = importlib.util.module_from_spec(spec)
    sys.modules["publication_monthly_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


@pytest.fixture
def sqlite_con(exp):
    con = sqlite3.connect(":memory:")
    exp.create_schema(con)
    return con


@pytest.fixture
def employer(db):
    return Employer.objects.create(name="Test Employer PM", slug="test-employer-pm")


@pytest.fixture
def counties(db):
    return (Judet.objects.create(name="Cluj PM", slug="cluj-pm"),
            Judet.objects.create(name="Bacău PM", slug="bacau-pm"))


_n = 0


def post(employer, published, *, judet=None, cancelled=False, expires=None):
    global _n
    _n += 1
    return JobPosting.objects.create(
        url=f"https://posturi.gov.ro/joburi/pm-{_n}/", title=f"PM {_n}", employer=employer,
        published_at=published, expires_at=expires, judet=judet, cancelled=cancelled,
    )


def run_export(exp, con, session_zone=None):
    with connection.connection.cursor(row_factory=dict_row) as cur:
        if session_zone:
            cur.execute(f"SET TIME ZONE '{session_zone}'")
        exp.export_publication_monthly(cur, con)
    return con.execute(
        "SELECT agg_version, month, judet_id, postings, cancelled FROM publication_monthly "
        "ORDER BY month, judet_id"
    ).fetchall()


@pytest.mark.django_db
def test_every_row_is_counted_and_reconciles_with_postgres(exp, sqlite_con, employer, counties):
    cluj, bacau = counties
    post(employer, date(2025, 11, 20), judet=cluj, expires=date(2025, 12, 1))     # long expired
    post(employer, date(2026, 9, 3), judet=cluj)
    post(employer, date(2026, 9, 30), judet=bacau, cancelled=True)
    post(employer, date(2026, 9, 12))                                              # no county
    post(employer, None, judet=cluj)                                               # no date
    post(employer, None, cancelled=True)                                           # no date, no county

    rows = run_export(exp, sqlite_con)

    v = exp.PUBLICATION_AGG_VERSION
    assert rows == [
        (v, "2025-11", cluj.id, 1, 0),
        (v, "2026-09", 0, 1, 0),
        (v, "2026-09", cluj.id, 1, 0),
        (v, "2026-09", bacau.id, 1, 1),
        (v, "unknown", 0, 1, 1),
        (v, "unknown", cluj.id, 1, 0),
    ]
    # Reconciliation against direct Postgres queries (the query shipped in the docs).
    assert sum(r[3] for r in rows) == JobPosting.objects.count() == 6
    assert sum(r[4] for r in rows) == JobPosting.objects.filter(cancelled=True).count() == 2
    with connection.cursor() as cur:
        cur.execute("SELECT COALESCE(to_char(published_at, 'YYYY-MM'), 'unknown'), COUNT(*) "
                    "FROM jobs_jobposting GROUP BY 1 ORDER BY 1")
        direct = cur.fetchall()
    per_month = {}
    for _, month, _, n, _ in rows:
        per_month[month] = per_month.get(month, 0) + n
    assert sorted(per_month.items()) == sorted(direct)


@pytest.mark.django_db
def test_expired_rows_are_in_the_history_even_though_active_only_drops_them(exp, sqlite_con, employer):
    """The slice filter of --active-only (expires_at >= today AND NOT cancelled) must not touch
    the aggregate: deleting expired rows from the SQLite file cannot erase intake."""
    post(employer, date(2026, 1, 10), expires=date(2026, 2, 1))
    post(employer, date(2026, 1, 11), expires=date(2026, 2, 1), cancelled=True)
    post(employer, date(2026, 10, 1), expires=date(2099, 1, 1))

    rows = run_export(exp, sqlite_con)
    slice_rows = JobPosting.objects.filter(expires_at__gte=date(2026, 10, 3), cancelled=False).count()

    assert slice_rows == 1
    assert [(r[1], r[3], r[4]) for r in rows] == [("2026-01", 2, 1), ("2026-10", 1, 0)]


@pytest.mark.django_db
@pytest.mark.parametrize("zone", ["UTC", "Europe/Bucharest", "America/Los_Angeles", "Pacific/Auckland"])
def test_month_is_the_published_date_in_any_session_time_zone(exp, sqlite_con, employer, zone):
    """published_at is the calendar DATE the source prints. 30 Sep and 1 Oct sit either side of
    a month boundary and must land there whatever zone the connection runs in (the export pins
    Bucharest, the VPS default is UTC, Django's own connection is UTC)."""
    post(employer, date(2026, 9, 30))
    post(employer, date(2026, 10, 1))
    post(employer, date(2026, 3, 31))     # the DST change month

    rows = run_export(exp, sqlite_con, session_zone=zone)

    assert [(r[1], r[3]) for r in rows] == [("2026-03", 1), ("2026-09", 1), ("2026-10", 1)]


@pytest.mark.django_db
def test_later_export_keeps_earlier_months_fixed(exp, employer):
    """Two export dates: new intake adds to its own month and leaves history unchanged."""
    post(employer, date(2026, 8, 5))
    post(employer, date(2026, 8, 6))
    first = run_export(exp, _fresh(exp))

    post(employer, date(2026, 9, 1))
    second = run_export(exp, _fresh(exp))

    assert [r for r in second if r[1] == "2026-08"] == [r for r in first if r[1] == "2026-08"]
    assert [r[3] for r in second if r[1] == "2026-09"] == [1]


def _fresh(exp):
    con = sqlite3.connect(":memory:")
    exp.create_schema(con)
    return con


@pytest.mark.django_db
def test_table_is_versioned_with_a_stable_key_and_no_rows_for_an_empty_database(exp, sqlite_con):
    rows = run_export(exp, sqlite_con)
    assert rows == []
    cols = [(c[1], c[2], c[5]) for c in sqlite_con.execute("PRAGMA table_info(publication_monthly)")]
    assert cols == [("agg_version", "INTEGER", 0), ("month", "TEXT", 1), ("judet_id", "INTEGER", 2),
                    ("postings", "INTEGER", 0), ("cancelled", "INTEGER", 0)]
    assert exp.PUBLICATION_AGG_VERSION == 1

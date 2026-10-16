"""`archived_postings` — the slim records behind UX-03's expired/cancelled pages (D14).

The complement of the live slice, bounded to ARCHIVE_DAYS: expired in the last 90 days,
or cancelled with an expiry not older than that. Run against Postgres with the real
export function, as the publication_monthly tests do.
"""

import importlib.util
import sqlite3
import sys
from datetime import date, timedelta
from pathlib import Path

import pytest
from django.db import connection
from psycopg.rows import dict_row

from apps.jobs.models import Employer, JobPosting, Judet

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("archive_export_under_test", REPO_ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["archive_export_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


_n = 0


def post(employer, judet, *, expires, cancelled=False, body=""):
    global _n
    _n += 1
    return JobPosting.objects.create(
        url=f"https://posturi.gov.ro/joburi/ar-{_n}/", title=f"AR {_n}", employer=employer, judet=judet,
        published_at=expires - timedelta(days=30), expires_at=expires, cancelled=cancelled,
        body_markdown=body,
    )


@pytest.mark.django_db
def test_the_archive_is_the_last_90_days_outside_the_live_slice(exp):
    employer = Employer.objects.create(name="Archive Employer", slug="archive-employer")
    judet = Judet.objects.create(name="Cluj AR", slug="cluj-ar")
    today = date.today()
    live = post(employer, judet, expires=today + timedelta(days=10))
    expired = post(employer, judet, expires=today - timedelta(days=5),
                   body="Primul paragraf.\n\nAl doilea paragraf.")
    too_old = post(employer, judet, expires=today - timedelta(days=exp.ARCHIVE_DAYS + 5))
    cancelled = post(employer, judet, expires=today + timedelta(days=20), cancelled=True)

    con = sqlite3.connect(":memory:")
    exp.create_schema(con)
    with connection.connection.cursor(row_factory=dict_row) as cur:
        exp.export_archive(cur, con)
    rows = {r[0]: r for r in con.execute("SELECT id, cancelled, summary, judet_slug FROM archived_postings")}

    assert expired.id in rows and cancelled.id in rows
    assert live.id not in rows, "a live posting stays in job_postings only"
    assert too_old.id not in rows, f"older than {exp.ARCHIVE_DAYS} days is dropped"
    assert rows[cancelled.id][1] == 1 and rows[expired.id][1] == 0
    assert rows[expired.id][2] == "Primul paragraf.\n\nAl doilea paragraf."
    assert rows[expired.id][3] == "cluj-ar"


def test_summary_keeps_whole_paragraphs(exp):
    body = "A" * 400 + "\n\n" + "B" * 400 + "\n\n" + "C" * 50
    assert exp._summary(body) == "A" * 400, "a paragraph that would pass the limit is left out"
    assert exp._summary("") == ""
    assert exp._summary("x" * 2000).startswith("x") and len(exp._summary("x" * 2000)) <= 800

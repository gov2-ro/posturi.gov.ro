"""`export-to-sqlite.py` promotion guards.

The live site once served three active postings for five weeks because a bad export
was deployed and nobody read the output. Now the export builds beside the target and
only replaces it if it opens, is intact, and has not collapsed against the file it is
about to replace — so an unattended run cannot quietly publish an empty site.
"""

import importlib.util
import sqlite3
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location(
        "export_guards_under_test", REPO_ROOT / "export-to-sqlite.py"
    )
    mod = importlib.util.module_from_spec(spec)
    sys.modules["export_guards_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


def make_db(path, rows, *, active_only=1, with_meta=True, fts_rows=None):
    con = sqlite3.connect(path)
    con.executescript("""
        CREATE TABLE job_postings (id INTEGER PRIMARY KEY, title TEXT);
        CREATE VIRTUAL TABLE job_postings_fts USING fts5(title);
    """)
    con.executemany("INSERT INTO job_postings(id, title) VALUES (?, 'x')",
                    [(i,) for i in range(1, rows + 1)])
    for i in range(1, (rows if fts_rows is None else fts_rows) + 1):
        con.execute("INSERT INTO job_postings_fts(rowid, title) VALUES (?, 'x')", (i,))
    if with_meta:
        con.executescript("""
            CREATE TABLE build_meta (
                id INTEGER PRIMARY KEY, built_at TEXT, git_sha TEXT, source_host TEXT,
                active_only INTEGER, job_postings INTEGER, employers INTEGER,
                calendar_events INTEGER);
        """)
        con.execute(
            "INSERT INTO build_meta VALUES (1,'2026-09-08T00:00:00+00:00','abc','h',?,?,0,0)",
            (active_only, rows),
        )
    con.commit()
    con.close()
    return str(path)


def test_healthy_export_passes(exp, tmp_path):
    db = make_db(tmp_path / "new.sqlite", 1500)
    assert exp.verify(db, min_rows=100, previous=None, active_only=True) == 1500


def test_below_min_rows_refuses(exp, tmp_path):
    db = make_db(tmp_path / "new.sqlite", 12)
    with pytest.raises(SystemExit, match="below the --min-rows floor"):
        exp.verify(db, min_rows=100, previous=None, active_only=True)


def test_collapse_against_previous_refuses(exp, tmp_path):
    db = make_db(tmp_path / "new.sqlite", 3)
    previous = {"job_postings": 1656, "active_only": 1}
    with pytest.raises(SystemExit, match="down from 1656"):
        exp.verify(db, min_rows=0, previous=previous, active_only=True)


def test_ordinary_churn_passes(exp, tmp_path):
    db = make_db(tmp_path / "new.sqlite", 1600)
    previous = {"job_postings": 1656, "active_only": 1}
    assert exp.verify(db, min_rows=100, previous=previous, active_only=True) == 1600


def test_full_export_not_compared_against_active_only_baseline(exp, tmp_path):
    # An --active-only file is legitimately a fifth of a full one; comparing the two
    # would refuse every switch between them.
    db = make_db(tmp_path / "new.sqlite", 1700, active_only=0)
    previous = {"job_postings": 9600, "active_only": 0}
    with pytest.raises(SystemExit):
        exp.verify(db, min_rows=0, previous=previous, active_only=False)
    # ...but a baseline built the other way is not a comparable measurement.
    previous_active = {"job_postings": 9600, "active_only": 1}
    assert exp.verify(db, min_rows=0, previous=previous_active, active_only=False) == 1700


def test_corrupt_file_refuses(exp, tmp_path):
    db = tmp_path / "broken.sqlite"
    make_db(db, 500)
    with open(db, "r+b") as fh:      # scribble over the middle of the file
        fh.seek(4096)
        fh.write(b"\x00" * 8192)
    with pytest.raises(SystemExit):
        exp.verify(str(db), min_rows=0, previous=None, active_only=True)


def test_fts_shortfall_warns_but_promotes(exp, tmp_path, capsys):
    db = make_db(tmp_path / "new.sqlite", 1000, fts_rows=400)
    assert exp.verify(db, min_rows=100, previous=None, active_only=True) == 1000
    assert "FTS index has 400 rows" in capsys.readouterr().err


def test_previous_build_reads_counts(exp, tmp_path):
    db = make_db(tmp_path / "old.sqlite", 1656)
    assert exp.previous_build(db) == {"job_postings": 1656, "active_only": 1}


def test_previous_build_tolerates_a_missing_baseline(exp, tmp_path):
    assert exp.previous_build(str(tmp_path / "absent.sqlite")) is None


def test_previous_build_tolerates_a_pre_build_meta_export(exp, tmp_path):
    db = make_db(tmp_path / "old.sqlite", 1656, with_meta=False)
    assert exp.previous_build(db) == {"job_postings": 1656, "active_only": None}

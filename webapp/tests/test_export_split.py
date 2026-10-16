"""PERF-SPLIT: the export moves the long texts out of `job_postings`.

Every facet count on the PHP list page is a full scan of `job_postings`; with the
body, schema_json and sal_json in the row, ~75% of what each scan read was text no
count looks at. `split_posting_text()` moves those columns to `posting_text` after
everything that reads them ran, and `has_schema` stands in for the one test PHP made
on them (`schema_json IS NOT NULL`).
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
        "export_split_under_test", REPO_ROOT / "export-to-sqlite.py"
    )
    mod = importlib.util.module_from_spec(spec)
    sys.modules["export_split_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


@pytest.fixture()
def con(exp):
    c = sqlite3.connect(":memory:")
    exp.create_schema(c)
    cols = [r[1] for r in c.execute("PRAGMA table_info(job_postings)")]
    for pid, body, schema in [(1, "Concurs **medic**", '{"x": 1}'), (2, "Referent", None)]:
        row = {"id": pid, "url": f"https://posturi.gov.ro/joburi/p-{pid}/", "title": f"Post {pid}",
               "body_markdown": body, "schema_json": schema, "sal_json": '{"c": 2}' if schema else None,
               "attachment_meta": '[{"name": "a.pdf"}]', "other_links": "[]", "detalii_raw": "d",
               "v3_bibliography": '["Legea 53/2003"]'}
        keys = [k for k in row if k in cols]
        c.execute(f"INSERT INTO job_postings({', '.join(keys)}) VALUES ({', '.join('?' * len(keys))})",
                  [row[k] for k in keys])
    return c


def test_texts_move_to_posting_text_and_leave_job_postings(exp, con):
    exp.split_posting_text(con)
    left = {r[1] for r in con.execute("PRAGMA table_info(job_postings)")}
    assert not left & set(exp.TEXT_COLUMNS), "no long text stays in job_postings"
    assert "has_schema" in left
    rows = con.execute("SELECT posting_id, body_markdown, schema_json, sal_json, attachment_meta, "
                       "v3_bibliography, detalii_raw FROM posting_text ORDER BY posting_id").fetchall()
    assert rows == [
        (1, "Concurs **medic**", '{"x": 1}', '{"c": 2}', '[{"name": "a.pdf"}]', '["Legea 53/2003"]', "d"),
        (2, "Referent", None, None, '[{"name": "a.pdf"}]', '["Legea 53/2003"]', "d"),
    ]


def test_has_schema_is_the_old_null_test(exp, con):
    exp.split_posting_text(con)
    assert con.execute("SELECT id, has_schema FROM job_postings ORDER BY id").fetchall() == [(1, 1), (2, 0)]


def test_fts_is_contentless_and_still_ranks(exp, con):
    con.execute("INSERT INTO job_postings_fts(rowid, title, employer_name, judet_name, body_text) "
                "VALUES (1, 'Post 1', '', '', 'medic primar')")
    hit = con.execute("SELECT rowid, bm25(job_postings_fts) FROM job_postings_fts "
                      "WHERE job_postings_fts MATCH 'medic'").fetchall()
    assert [r[0] for r in hit] == [1]
    assert con.execute("SELECT body_text FROM job_postings_fts").fetchone()[0] is None, \
        "contentless: the body is not stored a second time"

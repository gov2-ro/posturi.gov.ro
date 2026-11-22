"""SALARY-LABELS-LIMITS — the export side: the baseline columns and the numbers of /despre/#ce-nu-stim."""

from __future__ import annotations

import importlib.util
import json
import sqlite3
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("export_salary_limits_under_test", REPO_ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def _est(baza, lo, draft=None):
    d = {"baza": baza, "lei_min": lo, "lei_max": lo, "varianta": "x"}
    if draft is not None:
        d["proiect"] = {"baza": "proiect", "lei_min": draft, "lei_max": draft}
    return json.dumps(d)


def test_columns_for_a_law_figure_with_a_draft_note(exp):
    c = exp._salary_columns(_est("lege_in_vigoare", 5000, 5700))
    assert (c["baza"], c["min"], c["draft_min"], c["draft_max"]) == ("lege_in_vigoare", 5000, 5700, 5700)


def test_columns_for_a_draft_only_figure(exp):
    c = exp._salary_columns(_est("proiect", 4000))
    assert (c["baza"], c["draft_min"]) == ("proiect", 4000)


def test_columns_for_a_declared_figure(exp):
    c = exp._salary_columns(_est("declaratii", 9000, 9500))
    assert (c["baza"], c["draft_min"]) == ("declaratii", 9500)


def test_an_old_row_has_no_baza_and_its_top_level_is_the_draft(exp):
    c = exp._salary_columns(json.dumps({"lei_min": 4000, "lei_max": 4100}))
    assert (c["baza"], c["draft_min"], c["draft_max"]) == ("", 4000, 4100)


def test_no_estimate_gives_empty_columns(exp):
    c = exp._salary_columns(None)
    assert (c["baza"], c["draft_min"]) == ("", None)


def test_limits_groups_do_not_overlap_and_the_gap_is_the_median(exp):
    rows = [  # (baza, sal_min, sal_draft_min, stated, project_hire)
        ("lege_in_vigoare", 1000, 1100, 0, 0),   # +10 %
        ("lege_in_vigoare", 1000, 1200, 0, 0),   # +20 %
        ("lege_in_vigoare", 1000, 1500, 0, 0),   # +50 %
        ("lege_in_vigoare", 1000, None, 0, 0),   # law, no draft: not in the gap
        ("proiect", 800, 800, 0, 0),
        ("", 700, 700, 0, 0),                    # old row: the draft
        ("declaratii", 9000, 9500, 0, 0),
        ("lege_in_vigoare", 1000, 1100, 1, 0),   # states its pay: group A; still has both figures (+10 %)
        ("lege_in_vigoare", 1000, 1100, 0, 1),   # project hire without pay: none, not in the gap
        ("", None, None, 0, 0),                  # none
    ]
    out = exp.salary_limits(rows)
    assert (out["N"], out["A"], out["Dc"], out["L"], out["D"], out["F"]) == (10, 1, 1, 4, 2, 2)
    assert out["A"] + out["Dc"] + out["L"] + out["D"] + out["F"] == out["N"]
    assert (out["gap_pct"], out["gap_n"]) == (15, 4)   # 10, 10, 20, 50 -> median 15


def test_limits_with_no_pair_has_no_gap(exp):
    out = exp.salary_limits([("proiect", 800, 800, 0, 0)])
    assert out["gap_pct"] is None and out["gap_n"] == 0


def test_the_table_is_written(exp):
    con = sqlite3.connect(":memory:")
    con.execute("""CREATE TABLE job_postings (sal_baza TEXT, sal_min REAL, sal_draft_min REAL, inf_salary_min REAL,
                   project_hire INTEGER, proj_pay_amount REAL)""")
    con.executemany("INSERT INTO job_postings VALUES (?,?,?,?,?,?)",
                    [("lege_in_vigoare", 1000, 1140, None, 0, None), ("proiect", 500, 500, None, 0, None)])
    exp.export_salary_limits(con)
    got = {k: json.loads(v) for k, v in con.execute("SELECT key, value FROM salary_limits")}
    assert got["N"] == 2 and got["L"] == 1 and got["D"] == 1 and got["gap_pct"] == 14

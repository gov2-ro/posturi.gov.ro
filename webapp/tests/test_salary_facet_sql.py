"""The salary facet's SQL, and the type-affinity trap under it.

The facet filters on `COALESCE(inf_salary_min, sal_min)` so a genuinely
announced salary outranks the estimate from the draft grid. That COALESCE is
what makes the comparison dangerous: PDO's `execute($array)` binds every value
as text, and an expression — unlike a column — carries no type affinity, so
SQLite compares a REAL against a TEXT. In SQLite every number sorts before every
string, which makes `>=` unconditionally false. The facet returned zero rows
while the underlying data was fine, and nothing errored.

These tests pin the behaviour in SQLite directly, then check the PHP call sites
still carry the CAST that works around it.
"""

import re
import sqlite3
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
PHP_DIR = REPO_ROOT / "webapp-php"


@pytest.fixture
def db():
    con = sqlite3.connect(":memory:")
    con.execute("CREATE TABLE job_postings (id INTEGER PRIMARY KEY, "
                "inf_salary_min REAL, sal_min REAL)")
    con.executemany("INSERT INTO job_postings(inf_salary_min, sal_min) VALUES (?,?)",
                    [(None, 4500.0), (None, 5541.0), (None, 7579.0), (6000.0, 4000.0)])
    return con


def test_a_text_bind_against_a_coalesce_silently_matches_nothing(db):
    """The bug, pinned. This is not a hypothetical: PDO binds text by default."""
    rows = db.execute(
        "SELECT COUNT(*) FROM job_postings "
        "WHERE COALESCE(inf_salary_min, sal_min) >= ?", ("5000",)).fetchone()[0]
    assert rows == 0


def test_a_bare_column_converts_the_bind_because_it_has_affinity(db):
    """Why the old `inf_salary_min >= ?` looked fine — and why wrapping it broke.

    Two rows, not three: the fourth row's `sal_min` is 4000, and only the
    COALESCE sees its announced 6000.
    """
    rows = db.execute(
        "SELECT COUNT(*) FROM job_postings WHERE sal_min >= ?", ("5000",)).fetchone()[0]
    assert rows == 2


def test_casting_the_bind_restores_the_numeric_comparison(db):
    rows = db.execute(
        "SELECT COUNT(*) FROM job_postings "
        "WHERE COALESCE(inf_salary_min, sal_min) >= CAST(? AS REAL)", ("5000",)).fetchone()[0]
    assert rows == 3


def test_an_announced_salary_outranks_the_estimate(db):
    """The last row announces 6000 and is estimated at 4000; 6000 is the truth."""
    value = db.execute(
        "SELECT COALESCE(inf_salary_min, sal_min) FROM job_postings WHERE id = 4").fetchone()[0]
    assert value == 6000.0


class TestPhpCallSites:
    """Three places have to agree on the expression and keep the CAST."""

    @pytest.fixture(scope="class")
    def sources(self):
        return {name: (PHP_DIR / name).read_text(encoding="utf-8")
                for name in ("helpers.php", "pages/list.php")}

    def test_the_expression_is_defined_once(self, sources):
        assert "const SALARY_EXPR = 'COALESCE(j.inf_salary_min, j.sal_min)'" in sources["helpers.php"]

    @pytest.mark.parametrize("name", ["helpers.php", "pages/list.php"])
    def test_every_salary_comparison_casts_its_bind(self, sources, name):
        src = sources[name]
        comparisons = re.findall(r"SALARY_EXPR \. \"\s*[<>]=?\s*([^\"]*)\"", src)
        assert comparisons, f"no salary comparison found in {name}"
        for rhs in comparisons:
            assert "CAST(? AS REAL)" in rhs, f"uncast bind in {name}: {rhs!r}"

    def test_the_facet_gate_counts_what_the_filter_matches(self, sources):
        """The old gate counted `inf_salary_min`, which 44 of 9,757 postings
        have, so the `>= 100` threshold never opened and the facet never
        rendered. It must count the same expression the filter uses."""
        src = sources["pages/list.php"]
        gate = re.search(r"\$sal_total = .*?;", src, re.S)
        assert gate and "SALARY_EXPR" in gate.group(0)

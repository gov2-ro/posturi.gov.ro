"""SALARY-UNMATCHED fix (a): `normalize-titles.py --fill-selectors` and its pipeline step.

The fill is rule-based (no model). It gives an occupation with no grid function the
grid row of its canonical name, and never touches one that already has a function.
"""
import importlib.util
import json
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import salary_grid as sg  # noqa: E402

pytestmark = pytest.mark.skipif(
    not (sg.DATA_DIR / f"grila-{sg.LAW_VERSION}.csv").exists()
    or not (sg.DATA_DIR / f"grila-{sg.DEFAULT_VERSION}.csv").exists(),
    reason="the grid registries are not built",
)


@pytest.fixture(scope="module")
def nt():
    spec = importlib.util.spec_from_file_location("normalize_titles_fill_test",
                                                  REPO_ROOT / "normalize-titles.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def grids():
    return sg.load_grid(), sg.load_grid(sg.LAW_VERSION)


EMPTY = {"cod": None, "anexa": None, "regim": "personal_contractual",
         "tip_post": "executie", "functie_grila": None, "nivel_administrativ": None}


def fill(nt, grids, canonical, selector=None):
    draft, law = grids
    return nt.selector_fill(canonical, dict(selector if selector is not None else EMPTY), draft, law)


def test_fill_uses_the_canonical_name(nt, grids):
    out = fill(nt, grids, "Medic specialist")
    assert out["functie_grila"] == "Medic specialist"
    assert out["anexa"] is None                         # the annex comes from the employer, per posting
    assert out["regim"] == "personal_contractual"      # the rest stays


def test_fill_finds_a_name_that_only_the_law_grid_has(nt, grids):
    draft, law = grids
    assert not draft.by_name.get(sg.norm("Brancardier")) and law.by_name.get(sg.norm("Brancardier"))
    assert fill(nt, grids, "Brancardier")["functie_grila"] == "Brancardier"


def test_cleaner_is_an_alias_of_ingrijitor(nt, grids):
    assert fill(nt, grids, "Femeie de serviciu")["functie_grila"] == "Îngrijitor"


def test_infirmier_takes_the_feminine_row(nt, grids):
    assert fill(nt, grids, "Infirmier")["functie_grila"] == "Infirmieră"


@pytest.mark.parametrize("trade", ["Electrician", "Fochist", "Electromecanic"])
def test_trade_without_a_row_takes_the_skilled_worker_row(nt, grids, trade):
    out = fill(nt, grids, trade)
    assert out["functie_grila"] == "Muncitor calificat"


def test_a_non_trade_without_a_row_stays_empty(nt, grids):
    # COR group 2 ("expert medic") and a name that is nowhere: no alias.
    assert fill(nt, grids, "Medic fictiv de proba") is None
    assert fill(nt, grids, "Consilier școlar") is None


def test_never_changes_a_filled_function(nt, grids):
    for filled in ({"functie_grila": "Referent"}, {"functie": "Referent"}, {"cod": "21.00304001.01.1"}):
        assert fill(nt, grids, "Medic specialist", dict(EMPTY, **filled)) is None


def test_keeps_an_existing_anexa(nt, grids):
    out = fill(nt, grids, "Medic specialist", dict(EMPTY, anexa="VIII"))
    assert out["anexa"] == "VIII" and out["functie_grila"] == "Medic specialist"


def test_idempotent(nt, grids):
    once = fill(nt, grids, "Brancardier")
    assert fill(nt, grids, "Brancardier", once) is None


def test_the_filled_selector_gives_a_figure(nt, grids):
    draft, law = grids
    out = fill(nt, grids, "Brancardier")
    sel = sg.selector_to_grid(out)
    assert law.estimate(sg.selector_for_law(sel, draft)).lei_min is not None


class _Cursor:
    def __init__(self, db):
        self.db = db

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def execute(self, sql, params=None):
        self.sql = sql

    def fetchall(self):
        return self.db.rows

    def executemany(self, sql, seq):
        self.db.updates.extend(list(seq))
        self.db.sql = sql


class _Conn:
    def __init__(self, rows):
        self.rows, self.updates, self.commits = rows, [], 0

    def cursor(self, row_factory=None):
        return _Cursor(self)

    def commit(self):
        self.commits += 1


def test_fill_selectors_dry_run_writes_nothing_and_run_is_idempotent(nt, grids):
    draft, law = grids
    rows = [{"id": 1, "canonical": "Brancardier", "grid_selector": dict(EMPTY)},
            {"id": 2, "canonical": "Referent", "grid_selector": dict(EMPTY, functie_grila="Consilier")}]
    conn = _Conn(rows)
    assert nt.fill_selectors(conn, draft, law, dry_run=True) == 1 and conn.updates == []
    assert nt.fill_selectors(conn, draft, law, dry_run=False) == 1
    (payload, row_id), = conn.updates
    assert row_id == 1 and json.loads(payload)["functie_grila"] == "Brancardier"
    assert "rule-name-lookup" in conn.sql
    rows[0]["grid_selector"] = json.loads(payload)          # what the next run reads
    conn.updates.clear()
    assert nt.fill_selectors(conn, draft, law, dry_run=False) == 0 and conn.updates == []


def test_pipeline_runs_selectors_between_occupations_and_salary():
    import pipeline
    steps = pipeline.ALL_STEPS
    assert steps.index("occupations") < steps.index("selectors") < steps.index("salary")
    cmd = pipeline._build_cmd("selectors", force=False, no_llm=True, provider="", limit=None, since=None)
    assert Path(cmd[1]).name == "normalize-titles.py" and cmd[-1] == "--fill-selectors"

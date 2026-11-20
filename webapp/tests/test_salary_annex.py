"""SALARY-UNMATCHED: the annex of a function that exists in several annexes comes from the employer.

`estimate-salaries.py` decides it per posting; the occupation dictionary never holds it.
"""
import importlib.util
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
def est():
    spec = importlib.util.spec_from_file_location("estimate_salaries_annex_test",
                                                  REPO_ROOT / "estimate-salaries.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def grids():
    return sg.load_grid(), sg.load_grid(sg.LAW_VERSION)


def posting(function, employer, category="Instituții locale", studii="licenta", **extra):
    selector = {"functie_grila": function, "regim": "personal_contractual", "tip_post": "executie"}
    selector.update(extra.pop("selector", {}))
    return dict({"title": function, "employer_name": employer, "employer_category": category,
                 "judet_name": "", "job_level": "Funcții de execuție", "schema_json": {},
                 "grid_selector": selector, "provider": "deepseek", "study_level": studii,
                 "canonical": function}, **extra)


def run_law(est, grids, p):
    draft, law = grids
    sel, _ = est.build_selector(p)
    e, used, why = est.estimate_in_annex(law, sel, p, lambda s: est.law_selector(s, p, draft))
    return e, used, why


@pytest.mark.parametrize("function,employer,category,annex", [
    ("Psiholog", "Spitalul Clinic Județean de Urgență Cluj", "Funcție contractuală", "II"),
    ("Psiholog", "Direcția Generală de Asistență Socială și Protecția Copilului Iași", "Consilii județene", "II"),
    ("Psiholog", "Muzeul Național de Istorie", "Instituții naționale", "III"),
    ("Infirmieră", "Spitalul Municipal Blaj", "Funcție contractuală", "II"),
    ("Referent", "Primăria Comunei Giroc", "Primării", "VIII"),
])
def test_the_employer_chooses_the_annex(est, grids, function, employer, category, annex):
    e, used, why = run_law(est, grids, posting(function, employer, category))
    assert e.lei_min is not None
    assert why == {"anexa": "din tipul angajatorului"}
    assert used["anexa"] == annex
    assert f"Anexa {annex}" in e.motiv


def test_psiholog_at_a_hospital_is_the_health_pay(est, grids):
    e, _used, _why = run_law(est, grids, posting("Psiholog", "Spitalul Municipal Blaj", "Funcție contractuală"))
    assert e.lei_min == 5679                      # Psiholog stagiar, unit a.1 (OUG 19/2024)
    assert e.lei_max >= 6988


def test_an_unknown_employer_gets_the_range_over_the_annexes(est, grids):
    draft, law = grids
    p = posting("Psiholog", "Unitatea Militară 01853", "Unități militare")
    e, used, why = run_law(est, grids, p)
    annexes = law.function_annexes({"functie": "Psiholog"})
    assert len(annexes) > 1 and why["anexa"].startswith("interval peste anexele")
    assert e.incredere == "interval" and used.get("anexa") is None
    assert any("mai multe anexe" in w for w in e.avertismente)
    per_annex = [law.estimate(dict(sg.selector_for_law(
        {**est.build_selector(p)[0], "anexa": a, "nume_cu_grad": True}, draft))) for a in sorted(annexes)]
    per_annex = [x for x in per_annex if x.lei_min is not None]
    assert e.lei_min == min(x.lei_min for x in per_annex) and e.lei_max >= max(x.lei_min for x in per_annex)


def test_a_selector_with_an_annex_does_not_change(est, grids):
    p = posting("Psiholog", "Spitalul Municipal Blaj", "Funcție contractuală", selector={"anexa": "III"})
    e, used, why = run_law(est, grids, p)
    assert why == {} and used["anexa"] == "III" and "Anexa III" in e.motiv


def test_an_annex_written_by_the_fill_gives_way_to_a_clear_employer(est, grids):
    p = posting("Psiholog", "Spitalul Municipal Blaj", "Funcție contractuală",
                selector={"anexa": "III"}, provider="rule-name-lookup")
    e, used, why = run_law(est, grids, p)
    assert used["anexa"] == "II" and why == {"anexa": "din tipul angajatorului"}
    # An unclear employer keeps the stored annex.
    q = posting("Psiholog", "Unitatea Militară 01853", "Unități militare",
                selector={"anexa": "III"}, provider="rule-name-lookup")
    assert run_law(est, grids, q)[1]["anexa"] == "III"


def test_a_function_in_one_annex_is_paid_under_it(est, grids):
    e, used, why = run_law(est, grids, posting("Medic specialist", "Spitalul Municipal Blaj", "Funcție contractuală"))
    assert used["anexa"] == "II" and why == {"anexa": "singura anexă a funcției"}
    assert used["tip_unitate"] in ("a.1", "a.4") and e.lei_min is not None


def test_no_figure_is_lost(est, grids):
    # A function the employer's annex does not have falls back to the unchanged estimate.
    p = posting("Brancardier", "Muzeul Național de Istorie", "Instituții naționale", studii="liceala")
    e, _used, _why = run_law(est, grids, p)
    assert e.lei_min is not None


@pytest.mark.parametrize("name,annex", [
    ("Spitalul Județean de Urgență Tulcea", "II"),
    ("Direcția de Asistență Socială Sector 2", "II"),
    ("Școala Gimnazială Nr. 1", "I"),
    ("Universitatea de Vest", "I"),
    ("Teatrul Național", "III"),
    ("Biblioteca Județeană", "III"),
    ("Consiliul Județean Arad", "VIII"),
    ("Unitatea Militară 01853", ""),
    ("", ""),
])
def test_employer_annex(est, name, annex):
    category = "Consilii județene" if "Consiliul" in name else "Instituții locale"
    assert est.employer_annex({"employer_name": name, "employer_category": category}) == annex

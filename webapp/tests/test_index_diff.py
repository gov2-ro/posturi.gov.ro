"""`fetch-index.py` change detection — the relative-countdown exception.

The redesigned listing renders "6 zile rămase" where the old one rendered a date,
so a verbatim diff reported an `expira_in` change on every posting on every run:
~9,600 bogus entries appended to the `updates` column per pass, a full CSV rewrite
on every page, and the unchanged-page early stop permanently defeated. Twice-daily
scraping only works if the clock ticking is not mistaken for news.
"""

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def fi():
    spec = importlib.util.spec_from_file_location(
        "fetch_index_under_test", REPO_ROOT / "fetch-index.py"
    )
    mod = importlib.util.module_from_spec(spec)
    sys.modules["fetch_index_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


@pytest.mark.parametrize("value", [
    "6 zile rămase", "1 zi rămasă", "Ultima zi", "ultima zi",
    "10 zile ramase", "2 zi ramasa", "  3 zile rămase  ",
])
def test_countdown_shapes_recognised(fi, value):
    assert fi.is_countdown(value)


@pytest.mark.parametrize("value", [
    "Anunț anulat", "Expiră in  15/09/2026", "", None, "3 zile", "rămase",
])
def test_non_countdowns_rejected(fi, value):
    assert not fi.is_countdown(value)


def test_ticking_clock_is_not_a_change(fi):
    assert not fi.values_differ("expira_in", "7 zile rămase", "6 zile rămase")
    assert not fi.values_differ("expira_in", "1 zi rămasă", "Ultima zi")


def test_leaving_the_countdown_family_is_a_change(fi):
    # A cancelled competition is exactly the event this must not swallow.
    assert fi.values_differ("expira_in", "6 zile rămase", "Anunț anulat")
    assert fi.values_differ("expira_in", "Anunț anulat", "6 zile rămase")
    assert fi.values_differ("expira_in", "Expiră in  15/09/2026", "Expiră in  16/09/2026")


def test_other_fields_compare_verbatim(fi):
    # The exemption is scoped to expira_in; nothing else gets fuzzy matching.
    assert fi.values_differ("pozitie", "6 zile rămase", "Ultima zi")
    assert fi.values_differ("angajator", "A", "B")
    assert not fi.values_differ("angajator", "A", "A")


def _row(**over):
    row = {
        "pozitie": "Inspector", "url": "https://x/joburi/a/", "angajator": "Primăria",
        "detalii": "", "publicat_in": "01.09.2026", "expira_in": "7 zile rămase",
        "judet": "Cluj", "url_judet": "", "tip": "Permanent", "updates": "",
    }
    row.update(over)
    return row


def test_countdown_only_delta_reports_no_change(fi):
    existing = _row()
    updated, changed = fi.compare_and_update(existing, _row(expira_in="6 zile rămase"))
    assert changed is False
    assert updated["updates"] == ""          # nothing appended to the change log


def test_real_delta_still_recorded(fi):
    existing = _row()
    updated, changed = fi.compare_and_update(
        existing, _row(pozitie="Consilier", expira_in="6 zile rămase")
    )
    assert changed is True
    assert updated["pozitie"] == "Consilier"
    assert "pozitie" in updated["updates"]
    assert "expira_in" not in updated["updates"]

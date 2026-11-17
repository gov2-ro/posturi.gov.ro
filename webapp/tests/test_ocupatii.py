"""ocupatii.parse_title() and trade_label(): the trade survives a dash after a generic head.

Real titles from the 2026-09 corpus (brief 2026-11-17-pipeline-domain-trade).
The salary grid keys on the grade and the study level, so each case that adds a
trade also pins the grade.
"""
import doctest
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
import ocupatii  # noqa: E402


def test_the_doctests_of_the_module_pass():
    assert doctest.testmod(ocupatii).failed == 0


@pytest.mark.parametrize("raw, base, grade", [
    ("Muncitor calificat IV – fochist", "Muncitor calificat fochist", "gradul IV"),
    ("MUNCITOR CALIFICAT III – ELECTRICIAN", "MUNCITOR CALIFICAT electrician", "gradul III"),
    ("Muncitor calificat – Bucătar", "Muncitor calificat bucătar", ""),
    ("Inginer – construcții", "Inginer construcții", ""),
])
def test_a_dash_with_a_trade_keeps_the_trade(raw, base, grade):
    p = ocupatii.parse_title(raw)
    assert p.base.lower() == base.lower()
    assert p.grade_token == grade


@pytest.mark.parametrize("raw, base", [
    ("Referent – Serviciul Buget", "Referent"),
    ("Inginer – Serviciul Tehnic", "Inginer"),
    ("Muncitor calificat – Compartiment Administrativ", "Muncitor calificat"),
    ("Muncitor calificat – Biroul Achiziții", "Muncitor calificat"),
])
def test_a_dash_with_a_department_still_drops_the_department(raw, base):
    assert ocupatii.parse_title(raw).base == base


def test_a_dash_with_a_grade_still_keeps_the_grade():
    p = ocupatii.parse_title("Asistent medical comunitar – debutant")
    assert (p.base, p.grade_token) == ("Asistent medical comunitar", "debutant")
    p = ocupatii.parse_title("Muncitor calificat – treapta II")
    assert (p.base, p.grade_token) == ("Muncitor calificat", "treapta II")


def test_a_title_with_several_posts_keeps_the_first():
    p = ocupatii.parse_title("Muncitor calificat IV – liftier, Muncitor necalificat II, Șofer II")
    assert p.base_norm == "muncitor calificat liftier"


def test_the_trade_replaces_only_a_generic_label():
    base = "Muncitor calificat electrician"
    assert ocupatii.trade_label(base, "Muncitor calificat") == "Electrician"
    assert ocupatii.trade_label(base, "Electrician") == "", "a specific label stays"
    assert ocupatii.trade_label("Muncitor calificat", "Muncitor calificat") == ""
    assert ocupatii.trade_label("Muncitor calificat IV-I instalator", "Muncitor") == "Instalator"
    assert ocupatii.trade_label("Muncitor calificat bucătărie", "Muncitor calificat") == "", \
        "words that do not start a COR name are not a trade"
    assert ocupatii.trade_label("Muncitor calificat muncitor necalificat", "Muncitor calificat") == ""


def test_two_spellings_of_one_occupation_give_one_label():
    counts = {"Îngrijitor copii": 22, "Îngrijitor de copii": 17, "Infirmier": 240, "Infirmieră": 14,
              "Contabil-șef": 37, "Contabil șef": 8}
    winners = ocupatii.spelling_winners(counts)
    assert len({winners[ocupatii.spelling_key(lbl)] for lbl in ("Îngrijitor copii", "Îngrijitor de copii")}) == 1
    assert winners[ocupatii.spelling_key("Infirmieră")] == "Infirmier", "the most frequent spelling wins"
    assert winners[ocupatii.spelling_key("Contabil șef")] == "Contabil-șef"


def test_a_tie_goes_to_the_longer_label_then_to_the_alphabet():
    winners = ocupatii.spelling_winners({"Asistent medical laborator": 5, "Asistent medical de laborator": 5})
    assert list(winners.values()) == ["Asistent medical de laborator"]


def test_labels_that_differ_by_a_word_stay_two_labels():
    for a, b in [("Asistent medical", "Asistent medical generalist"), ("Îngrijitor", "Îngrijitor clădiri"),
                 ("Administrator", "Administrator patrimoniu")]:
        assert ocupatii.spelling_key(a) != ocupatii.spelling_key(b)
        assert len(ocupatii.spelling_winners({a: 3, b: 2})) == 2

"""declaratii.py: match a posting to a group of the asset declarations (ADR 0008 rule 9)."""
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import declaratii as d  # noqa: E402
import salary_net  # noqa: E402


def _g(inst_id, functie, n=10, p25=5000, med=6000, p75=7000, familie="admin-local"):
    return {"institutie_id": inst_id, "institutie": inst_id.split("|")[0].title(), "judet": inst_id.split("|")[1],
            "functie": functie, "familie": familie, "n": str(n), "p25_net_2023": str(p25),
            "mediana_net_2023": str(med), "p75_net_2023": str(p75), "an_venit": "2023", "factor": "1.150",
            "factor_sursa": "INS test", "eroare_tipica": "5.2"}


GROUPS = d.Groups([
    _g("MUNICIPIUL IASI|IASI", "Consilier superior"),
    _g("MUNICIPIUL IASI|IASI", "Șef serviciu"),
    _g("COMUNA TOMESTI|IASI", "Referent superior"),
    _g("COMUNA TOMESTI|BACAU", "Referent superior"),       # same name, other county
    _g("MUNICIPIUL DEVA|HUNEDOARA", "Consilier"),
    _g("MUNICIPIUL DEVA|HUNEDOARA", "consilier"),          # two groups, same key: ambiguous
])


def test_employer_key_primaria():
    assert d.employer_key("PRIMĂRIA MUNICIPIULUI IAŞI") == "municipiul iasi"
    assert d.employer_key("Primăria Comunei Tomești, județul Iași") == "comuna tomesti"
    assert d.employer_key("Primăria Orașului Pașcani") == "orasul pascani"
    assert d.employer_key("Primăria Pașcani") == "primaria pascani"      # no guess


def test_title_key():
    assert d.title_key("Consilier, clasa I, grad profesional superior") == "consilier superior"
    assert d.title_key("Șef serviciu") == "sef serviciu"
    assert d.title_key("Consilier superior - Serviciul Buget") == "consilier superior"


def test_match_exact():
    g = GROUPS.match("Primăria Municipiului Iași", "Iași", "Consilier, clasa I, grad profesional superior")
    assert g and g["functie"] == "Consilier superior"
    assert GROUPS.match("Municipiul Iași", "Iași", "ȘEF SERVICIU")["functie"] == "Șef serviciu"


def test_county_must_match():
    assert GROUPS.match("Comuna Tomești", "Iași", "Referent superior")["institutie_id"] == "COMUNA TOMESTI|IASI"
    assert GROUPS.match("Comuna Tomești", "Vaslui", "Referent superior") is None


def test_no_fuzzy_no_guess():
    assert GROUPS.match("Municipiul Iași", "Iași", "Consilier") is None            # grade absent, group has one
    assert GROUPS.match("Municipiul Iași", "Iași", "Consilier IA") is None
    assert GROUPS.match("Municipiul Iași", "Iași", "Consilier debutant") is None
    assert GROUPS.match("Municipiul Deva", "Hunedoara", "Consilier") is None       # two groups fit
    assert GROUPS.match("Municipiul Iași Nord", "Iași", "Consilier superior") is None


def test_estimate_dict():
    g = GROUPS.match("Municipiul Iași", "Iași", "Consilier superior")
    est = d.estimate_dict(g)
    assert est["baza"] == "declaratii" and est["incredere"] == "interval"
    assert est["lei_min"] == salary_net.gross_for_net(5000 * 1.15)
    assert est["lei_max"] == salary_net.gross_for_net(7000 * 1.15)
    x = est["declaratii"]
    assert (x["p25_net"], x["mediana_net"], x["p75_net"]) == (5750, 6900, 8050)
    assert x["n"] == 10 and x["an_venit"] == 2023 and x["factor"] == 1.15
    assert set(x) == {"n", "an_venit", "factor", "factor_sursa", "mediana_net", "p25_net", "p75_net",
                      "institutie_id", "functie", "familie", "eroare_tipica"}


def test_round50():
    assert d.round50(6849.9) == 6850 and d.round50(6824.9) == 6800 and d.round50(6825) == 6850

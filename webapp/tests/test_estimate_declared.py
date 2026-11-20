"""estimate-salaries.py, step (a2) of ADR 0008 rule 4: the declared figure takes the top level."""
import importlib.util
import sys
from pathlib import Path
from types import SimpleNamespace

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))
spec = importlib.util.spec_from_file_location("estimate_salaries", REPO_ROOT / "estimate-salaries.py")
es = importlib.util.module_from_spec(spec)
spec.loader.exec_module(es)


def _fig(lo, hi, label):
    d = {"lei_min": lo, "lei_max": hi, "coef_min": 1.0, "coef_max": 1.0, "gradatie": 0, "varianta": label,
         "valoare_referinta": 4100, "incredere": "exact", "motiv": "", "randuri": ["r1"], "avertismente": [],
         "disclaimer": "d"}
    return SimpleNamespace(lei_min=lo, lei_max=hi, as_dict=lambda d=d: dict(d))


GROUP = {"institutie_id": "MUNICIPIUL IASI|IASI", "institutie": "Municipiul Iasi", "judet": "IASI",
         "functie": "Consilier superior", "familie": "admin-local", "n": "37", "p25_net_2023": "5200",
         "mediana_net_2023": "5950", "p75_net_2023": "6600", "an_venit": "2023", "factor": "1.150",
         "factor_sursa": "INS", "eroare_tipica": "5.2"}


def test_group_takes_the_top_level_and_keeps_law_and_draft():
    law, draft = _fig(8000, 9000, "lege"), _fig(9000, 10000, "proiect")
    out = es.assemble(law, draft, {"anexa": "VIII"}, {"anexa": "VIII"}, GROUP)
    assert out["baza"] == "declaratii" and out["incredere"] == "interval"
    assert out["declaratii"]["mediana_net"] == 6850          # 5950 x 1.15 = 6842.5, to 50
    assert out["lege_in_vigoare"]["baza"] == "lege_in_vigoare" and out["lege_in_vigoare"]["lei_min"] == 8000
    assert out["proiect"]["baza"] == "proiect"
    assert out["lei_min"] < out["lei_max"]


def test_no_group_is_unchanged():
    law, draft = _fig(8000, 9000, "lege"), _fig(9000, 10000, "proiect")
    out = es.assemble(law, draft, {"anexa": "VIII"}, {"anexa": "VIII"})
    assert out["baza"] == "lege_in_vigoare" and out["lei_min"] == 8000 and "declaratii" not in out
    assert out["proiect"]["lei_min"] == 9000 and "lege_in_vigoare" not in out
    out = es.assemble(_fig(None, None, "x"), draft, {}, {"anexa": "VIII"})
    assert out["baza"] == "proiect" and "proiect" not in out


def test_group_without_law_row():
    out = es.assemble(_fig(None, None, "x"), _fig(9000, 10000, "p"), {}, {}, GROUP)
    assert out["baza"] == "declaratii" and "lege_in_vigoare" not in out and out["proiect"]["lei_min"] == 9000


def test_salary_bucket_matches_site_buckets():
    assert es.salary_bucket(None) == "fara-estimare" and es.salary_bucket(0) == "fara-estimare"
    assert es.salary_bucket(4325) == "net-sub-3000" and es.salary_bucket(6500) == "net-3000-4000"
    assert es.salary_bucket(12000) == "net-peste-7000"

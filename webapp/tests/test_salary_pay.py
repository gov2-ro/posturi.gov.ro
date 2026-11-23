"""SALARY-GRAD-FOOD — salary_pay.py: the gradation range and the food allowance (ADR 0008 rule 11).

Pinned: posting 21359 (stated 7,870 lei, 3 years: 8,808 to 10,147 gross), unknown experience, 20+ years,
the 6,000 lei net boundary on each side, an excluded employer, a management row, a local range, declared
pay (no range), the data file, and the agreement with `Grid.row_pay()` for the rows that name a law figure.
"""

from __future__ import annotations

import importlib.util
import json
import math
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import salary_grid  # noqa: E402
import salary_net  # noqa: E402
import salary_pay as sp  # noqa: E402


@pytest.fixture(scope="module")
def grid():
    return salary_grid.load_grid(salary_grid.LAW_VERSION)


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("export_pay_under_test", REPO_ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


def ends(r):
    return [(r[k]["gradation"], r[k]["base"], r[k]["food"], r[k]["total"]) for k in ("low", "high")]


def test_posting_21359(grid):
    r = sp.pay_range("stated", 7870, 7870, years=3, employer="Teatrul Național", grid=grid)
    assert ends(r) == [(1, 8461, 347, 8808), (5, 9800, 347, 10147)]
    assert salary_net.net_from_gross(9800) == 5733 <= 6000          # the food test uses the base, not the total
    assert sp.columns(r) == (8808, 10147, 1, 5, 347, 347)


def test_unknown_experience_is_gradation_0(grid):
    r = sp.pay_range("stated", 7870, 7870, years=None, employer="X", grid=grid)
    assert ends(r)[0] == (0, 7870, 347, 8217)
    assert ends(r)[1][0] == 5


@pytest.mark.parametrize("years,floor", [(0, 0), (2.9, 0), (3, 1), (4.9, 1), (5, 2), (10, 3), (15, 4), (20, 5), (35, 5)])
def test_floor_gradation_from_years(grid, years, floor):
    assert sp.pay_range("stated", 6000, years=years, employer="X", grid=grid)["low"]["gradation"] == floor


def test_20_years_has_one_gradation(grid):
    r = sp.pay_range("stated", 7870, 7870, years=25, employer="X", grid=grid)
    assert r["low"]["gradation"] == r["high"]["gradation"] == 5
    assert r["low"]["base"] == r["high"]["base"] == 9800


def test_net_cap_boundary_on_each_side(grid):
    # net_from_gross(10258) = 6000 (food), net_from_gross(10259) = 6001 (no food).
    assert salary_net.net_from_gross(10258) == 6000 and salary_net.net_from_gross(10259) == 6001
    at = sp.pay_range("stated", 10258, years=None, employer="X", grid=grid)
    over = sp.pay_range("stated", 10259, years=None, employer="X", grid=grid)
    assert at["low"]["food"] == 347 and at["low"]["total"] == 10258 + 347
    assert over["low"]["food"] == 0 and over["low"]["total"] == 10259
    # The high end: food only below the cap, whatever the low end has.
    mid = sp.pay_range("stated", 7870, 7870, years=None, employer="X", grid=grid)
    assert mid["high"]["food"] == 347                                  # 9,800 gross = 5,733 net
    big = sp.pay_range("stated", 8300, 8300, years=None, employer="X", grid=grid)
    assert big["high"]["base"] == 10336 and big["high"]["food"] == 0 and big["low"]["food"] == 347


def test_excluded_employer_gets_gradations_but_no_food(grid):
    for name in ("Unitatea Militară 02490 București", "Inspectoratul pentru Situații de Urgență Dolj",
                 "Penitenciarul Rahova", "Serviciul de Telecomunicații Speciale", "Serviciul Român de Informații"):
        assert sp.excluded_employer(name), name
        r = sp.pay_range("stated", 5000, years=3, employer=name, grid=grid)
        assert r["low"]["food"] == r["high"]["food"] == 0
        assert r["low"]["gradation"] == 1 and r["high"]["gradation"] == 5
    for name in ("Serviciul de Ambulanță Județean Sibiu", "Primăria Cluj-Napoca", "", None):
        assert sp.excluded_employer(name) is None, name


def test_stated_span_keeps_its_maximum_and_adds_no_gradation(grid):
    # Owner, 2026-11-23: 20202 states 4,544 (gradation 0) to 5,855.
    r = sp.pay_range("stated", 4544, 5855, years=0, employer="X", grid=grid)
    assert ends(r) == [(0, 4544, 347, 4891), (None, 5855, 347, 6202)]      # ratio 1.289: no gradation number for the high end
    assert r["capped"] is False
    r5 = sp.pay_range("stated", 4544, 5855, years=5, employer="X", grid=grid)
    assert r5["low"]["base"] == min(math.ceil(round(4544 * grid.multiplier(2), 6)), 5855)
    assert abs(r5["low"]["base"] - math.ceil(4544 * 1.075 * 1.05)) <= 1 and r5["low"]["gradation"] == 2
    assert r5["high"]["base"] == 5855


def test_stated_span_that_matches_the_table_reports_gradation_5(grid):
    r = sp.pay_range("stated", 5126, 6375, years=None, employer="X", grid=grid)     # ratio 1.244
    assert ends(r)[1] == (5, 6375, 347, 6722)


def test_stated_span_ratio_1_5_is_not_a_gradation_table(grid):
    r = sp.pay_range("stated", 4000, 6000, years=0, employer="X", grid=grid)
    assert r["high"]["gradation"] is None and r["high"]["base"] == 6000


def test_stated_span_low_end_is_capped_at_the_maximum(grid):
    r = sp.pay_range("stated", 6000, 6100, years=20, employer="X", grid=grid)      # 6,000 x 1.2452 > 6,100
    assert r["capped"] is True
    assert ends(r)[0] == (None, 6100, 347, 6447)


def test_stated_span_food_is_tested_at_each_end(grid):
    r = sp.pay_range("stated", 9000, 11000, years=0, employer="X", grid=grid)
    assert (r["low"]["food"], r["high"]["food"]) == (347, 0)
    r = sp.pay_range("stated", 9000, 11000, years=0, employer="Unitatea Militară 01", grid=grid)
    assert (r["low"]["food"], r["high"]["food"]) == (0, 0)


def test_single_stated_figure_is_unchanged(grid):
    for hi in (7870, None):
        r = sp.pay_range("stated", 7870, hi, years=3, employer="X", grid=grid)
        assert ends(r) == [(1, 8461, 347, 8808), (5, 9800, 347, 10147)]


def test_no_range_outside_the_scope(grid):
    assert sp.pay_range("declared", 5000, grid=grid) is None
    assert sp.pay_range("draft", 5000, grid=grid) is None
    assert sp.pay_range("stated", None, grid=grid) is None
    assert sp.pay_range("stated", 0, grid=grid) is None
    law = {"baza": "lege_in_vigoare", "lei_min": 5000, "lei_max": 5000, "selector": {"anexa": "VIII", "tip_post": "execuție"}}
    assert sp.estimate_source(law) == "law"
    assert sp.estimate_source(dict(law, selector={"anexa": "VIII", "tip_post": "conducere"})) is None     # management row
    assert sp.estimate_source(dict(law, selector={"anexa": "VI"})) is None                                # Anexa VI
    assert sp.estimate_source(dict(law, lei_functie_min=4000)) is None
    assert sp.estimate_source(dict(law, baza="declaratii")) is None                                        # declared pay
    assert sp.estimate_source(dict(law, baza="proiect")) is None                                           # the draft
    assert sp.estimate_source(None) is None


def test_local_range_caps_the_high_end_with_no_gradation(grid):
    est = {"baza": "lege_in_vigoare", "lei_min": 4543, "lei_max": 7970, "selector": {"anexa": "VIII", "nivel_administrativ": "local"}}
    assert sp.is_local_range(est)
    r = sp.pay_range("law", 4543, 7970, years=5, employer="Primăria X", local=True, grid=grid)
    assert ends(r)[0] == (2, math.ceil(round(4543 * grid.multiplier(2), 6)), 347, math.ceil(round(4543 * grid.multiplier(2), 6)) + 347)
    assert ends(r)[1] == (None, 7970, 347, 8317)           # the cap: no gradation; 7,970 gross is 4,6xx net, under the cap
    capped = sp.pay_range("law", 4543, 11000, years=None, employer="Primăria X", local=True, grid=grid)
    assert ends(capped)[1] == (None, 11000, 0, 11000)      # over the net cap: no food
    assert sp.columns(capped)[3] is None


def test_law_figure_comes_from_the_rows_and_matches_row_pay(grid):
    row = next(r for r in grid.rows if r.tip_post != "conducere" and r.anexa != "VI" and r.lei_min and r.lei_min < 6000
               and grid.row_pay(r, 0)[0] > grid.minimum_wage())
    est = {"baza": "lege_in_vigoare", "lei_min": grid.row_pay(row, 0)[0], "lei_max": grid.row_pay(row, 0)[1],
           "randuri": [row.ref], "selector": {"anexa": row.anexa}}
    at = sp.law_figure_at(est, grid)
    assert at is not None
    for g in range(6):
        assert at(g) == tuple(grid.row_pay(row, g))
    r = sp.pay_range("law", est["lei_min"], est["lei_max"], years=10, employer="X", figure_at=at, grid=grid)
    assert r["method"] == "rows"
    assert r["low"]["base"] == grid.row_pay(row, 3)[0] and r["high"]["base"] == grid.row_pay(row, 5)[1]
    # A figure that the refs do not reproduce, or refs cut at 12, fall back to scaling.
    assert sp.law_figure_at(dict(est, lei_min=est["lei_min"] + 1), grid) is None
    assert sp.law_figure_at(dict(est, randuri=[row.ref] * 12), grid) is None
    assert sp.pay_range("law", 5000, 5000, years=3, employer="X", grid=grid)["method"] == "scaled"


def test_a_row_at_the_minimum_wage_does_not_scale_from_the_floor(grid):
    floor = grid.minimum_wage()
    row = next(r for r in grid.rows if r.tip_post != "conducere" and r.anexa != "VI" and grid.row_pay(r, 0)[0] == floor
               and grid.row_pay(r, 5)[0] == floor)
    est = {"baza": "lege_in_vigoare", "lei_min": floor, "lei_max": floor, "randuri": [row.ref], "selector": {"anexa": row.anexa}}
    r = sp.pay_range("law", floor, floor, years=20, employer="X", figure_at=sp.law_figure_at(est, grid), grid=grid)
    assert r["low"]["base"] == floor                      # scaling would give 5,385
    assert math.ceil(floor * grid.multiplier(5)) > floor


def test_data_file_has_act_and_date_for_each_value():
    data = json.loads(sp.RIGHTS_PATH.read_text(encoding="utf-8"))
    food = data["indemnizatie_hrana"]
    assert (food["lei"], food["plafon_net_lei"], food["de_la"]) == (347, 6000, "2026-01-01")
    assert "art. 18" in food["act"]
    assert {r["id"] for r in data["angajatori_exclusi_hrana"]["reguli"]} == {
        "mapn", "mai", "anp", "sri", "sie", "spp", "sts", "anrsps"}
    assert "act" in data["gradatii"] and "de_la" in data["gradatii"]


def test_gradation_values_are_the_audit_values(grid):
    # docs/audits/2026-11-23-salary-law-research.md: 7.5 / 5 / 5 / 2.5 / 2.5 %, compounded; 3/5/10/15/20 years.
    levels = grid.gradations
    assert [g.majorare_pct for g in levels] == [0, 7.5, 5, 5, 2.5, 2.5]
    assert [g.vechime_min_ani for g in levels] == [0, 3, 5, 10, 15, 20]
    assert grid.multiplier(5) == pytest.approx(1.2451876171875)


def test_export_columns(exp):
    stated = {"salary_min": 7870, "salary_max": 7870, "experience_years": 3}
    assert exp._pay_range_columns(stated, None, "X") == (8808, 10147, 1, 5, 347, 347)
    assert exp._pay_range_columns({"salary_min": 60, "experience_years": 3}, None, "X") == (None,) * 6   # not monthly pay
    law = {"baza": "lege_in_vigoare", "lei_min": 5000, "lei_max": 5000, "selector": {"anexa": "VIII"}}
    cols = exp._pay_range_columns({}, json.dumps(law), "X")
    assert cols[2:4] == (0, 5) and cols[0] == 5347
    for est in ({"baza": "declaratii", "lei_min": 5000}, {"baza": "proiect", "lei_min": 5000},
                dict(law, selector={"anexa": "VI"})):
        assert exp._pay_range_columns({}, json.dumps(est), "X") == (None,) * 6
    assert exp._pay_range_columns({}, None, "X") == (None,) * 6
    assert exp._pay_range_columns({}, "not json", "X") == (None,) * 6

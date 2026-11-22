"""The 2026 draft salary grid: registry integrity and the gross-pay calculator.

The grid is the only way this site can show pay at all — 44 of 9,757 postings
state a salary in the text — so the arithmetic and the row selection have to be
reproducible from `(selector, variant)` alone.
"""

import csv
import json
import math
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import salary_grid as sg  # noqa: E402

GRID_CSV = sg.DATA_DIR / f"grila-{sg.DEFAULT_VERSION}.csv"
pytestmark = pytest.mark.skipif(
    not GRID_CSV.exists(),
    reason="run `python build-salary-grid.py` to materialise the grid registry",
)


@pytest.fixture(scope="module")
def grid():
    return sg.load_grid()


class TestParameters:
    def test_gradation_increments_and_cumulative_multipliers_agree(self, grid):
        """Two sources describe gradations and they must not disagree.

        `gradatii_standard.csv` gives per-step increments (+7.5%, +5%, ...);
        the `notebook lm` workbook gives cumulative multipliers (1.075,
        1.12875, ...). The calculator uses the cumulative form, so the
        increments are what cross-checks it.
        """
        running = 1.0
        for level in grid.gradations:
            running *= 1 + level.majorare_pct / 100
            assert running == pytest.approx(level.multiplicator_cumulat, abs=1e-12)

    def test_gradation_bands_are_contiguous_and_cover_every_seniority(self, grid):
        levels = grid.gradations
        assert levels[0].vechime_min_ani == 0
        assert levels[-1].vechime_max_exclusiv_ani is None
        for lower, upper in zip(levels, levels[1:]):
            assert lower.vechime_max_exclusiv_ani == upper.vechime_min_ani

    @pytest.mark.parametrize(
        "years,expected",
        [(None, 0), (0, 0), (2.9, 0), (3, 1), (4.9, 1), (5, 2), (9.9, 2),
         (10, 3), (15, 4), (20, 5), (41, 5)],
    )
    def test_seniority_maps_to_the_right_gradation(self, grid, years, expected):
        assert grid.gradation_for_years(years) == expected

    def test_the_august_variant_is_not_silently_costed_with_july_coefficients(self):
        """VR changed 4100 -> 4000 between variants, but so did the coefficients.

        Only the July variant has a materialised grid. Loading August must fail
        loudly rather than reuse July's coefficients at a different VR.
        """
        params = json.loads((sg.DATA_DIR / "parametri.json").read_text(encoding="utf-8"))
        assert params["variante"]["2026-08-20"]["are_grila"] is False
        with pytest.raises(FileNotFoundError):
            sg.load_grid("2026-08-20")


class TestRegistry:
    def test_every_row_has_a_usable_coefficient_within_the_legal_scale(self, grid):
        """Art. 5 caps the scale at 1:8; the workbook holds a little headroom."""
        assert len(grid.rows) > 2000
        for row in grid.rows:
            assert 0.4 < row.coef_min <= row.coef_max < 30

    def test_all_nine_annexes_are_represented(self, grid):
        annexes = {r.anexa for r in grid.rows}
        assert {"I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX"} <= annexes

    def test_anexa_viii_rows_all_carry_a_regim(self, grid):
        """Public servant vs contractual decides which half of Anexa VIII applies."""
        viii = [r for r in grid.rows if r.anexa == "VIII"]
        assert viii and all(r.regim for r in viii)

    def test_known_good_coefficients_match_the_workbook(self, grid):
        """Spot-checks read straight out of the source sheets."""
        for cod_or_ref, expected in [
            ("VIII CII A 3_local4!23", 1.351269253125),   # Consilier gradul II, UAT < 10k
            ("VIII CII A 3_local1!21", 1.8485004999999997),  # ... same post, UAT > 200k
            ("VIII_CI_A_1!9", 5.4),                        # Secretar general, nivel I
        ]:
            sheet, source_row = cod_or_ref.split("!")
            row = next(r for r in grid.rows
                       if r.sheet == sheet and r.source_row == int(source_row))
            assert row.coef_min == pytest.approx(expected)

    def test_nr_crt_is_never_read_as_a_coefficient(self, grid):
        """The pre-existing bundle CSV had this bug on 21 rows.

        It captured the `Nr. crt` column (1, 2, 5, 6...) as the coefficient, so
        `Secretar general` came out at 1.0 instead of 5.4. Small integers that
        equal a row ordinal are the signature.
        """
        suspicious = [r for r in grid.rows
                      if r.coef_min == r.coef_max and float(r.coef_min).is_integer()
                      and r.coef_min <= 12 and not r.grad_treapta and not r.studii]
        assert len(suspicious) < 20, f"possible Nr. crt contamination: {suspicious[:5]}"


class TestSelection:
    def test_a_blank_field_does_not_satisfy_a_filter(self, grid):
        """Rows from unbanded sheets must not answer a banded query.

        This was the bug that made every local estimate collapse onto the
        central coefficient: a row with `banda_populatie=''` is not a match for
        "sub 10.000", it is a row from a sheet that is not banded at all.
        """
        rows = grid.select({
            "functie": "Consilier", "grad_treapta": "gradul II", "anexa": "VIII",
            "regim": "personal contractual", "nivel_administrativ": "local",
            "banda_populatie": "sub 10.000",
        })
        assert [r.ref for r in rows] == ["VIII CII A 3_local4!23"]

    def test_population_band_changes_the_answer_substantially(self, grid):
        """37% spread — which is why an unknown band must widen, not guess."""
        base = {"functie": "Consilier", "grad_treapta": "gradul II", "anexa": "VIII",
                "regim": "personal contractual", "nivel_administrativ": "local"}
        small = grid.estimate(dict(base, banda_populatie="sub 10.000"))
        large = grid.estimate(dict(base, banda_populatie="peste 200.000"))
        assert small.lei_min == 5541
        assert large.lei_min == 7579
        assert large.lei_min / small.lei_min > 1.3

    def test_an_unresolved_band_widens_the_range_and_says_so(self, grid):
        est = grid.estimate({
            "functie": "Consilier", "grad_treapta": "gradul II", "anexa": "VIII",
            "regim": "personal contractual", "nivel_administrativ": "local",
        })
        assert est.lei_min <= 5541 and est.lei_max >= 7579
        assert any("Banda de populație" in w for w in est.avertismente)

    def test_the_postings_grade_overrides_the_code_the_dictionary_pinned(self, grid):
        """A code names the function; the grade belongs to the posting.

        The occupation dictionary is keyed on the grade-stripped title, so one
        entry serves "Consilier gradul I" and "Consilier debutant" alike. Its
        stored code is necessarily one specific grade's row (a code's last
        segment IS the grade index), so selection has to widen along the grade
        axis and let the posting decide.
        """
        pinned_at_grade_i_a = "82.60128002.07.1"
        base = {"cod": pinned_at_grade_i_a, "functie": "Consilier", "anexa": "VIII",
                "regim": "personal contractual", "nivel_administrativ": "local",
                "banda_populatie": "sub 10.000"}
        by_grade = {g: grid.estimate(dict(base, grad_treapta=g)).lei_min
                    for g in ("gradul I A", "gradul I", "gradul II", "debutant")}
        assert by_grade == {"gradul I A": 6309, "gradul I": 6095,
                            "gradul II": 5541, "debutant": 5337}

    def test_widening_along_grades_does_not_drag_in_neighbouring_functions(self, grid):
        """`Consilier` and `arhitect` share a grid line but are not one job."""
        rows = grid.select({
            "cod": "82.60128002.07.1", "functie": "Consilier", "grad_treapta": "gradul II",
            "anexa": "VIII", "regim": "personal contractual",
            "nivel_administrativ": "local", "banda_populatie": "sub 10.000",
        })
        assert [r.ref for r in rows] == ["VIII CII A 3_local4!23"]

    def test_an_unmatched_selector_returns_no_number_rather_than_a_guess(self, grid):
        est = grid.estimate({"functie": "Vrăjitor de curte"})
        assert est.lei_min is None and est.incredere == "necunoscut"


class TestCalculator:
    def test_salary_is_coefficient_times_reference_value_rounded_up(self, grid):
        est = grid.estimate({
            "functie": "Consilier", "grad_treapta": "gradul II", "anexa": "VIII",
            "regim": "personal contractual", "nivel_administrativ": "local",
            "banda_populatie": "sub 10.000",
        })
        assert est.lei_min == math.ceil(est.coef_min * 4100 * 1.0)

    def test_gradation_compounds_sequentially(self, grid):
        """calculator_pseudocod.md: compound each step, ceil once at the end."""
        sel = {"functie": "Consilier", "grad_treapta": "gradul II", "anexa": "VIII",
               "regim": "personal contractual", "nivel_administrativ": "local",
               "banda_populatie": "sub 10.000"}
        g0 = grid.estimate(sel, gradation=0)
        g5 = grid.estimate(sel, gradation=5)
        assert g5.lei_min == math.ceil(g0.coef_min * 4100 * 1.2451876171875)
        assert g0.lei_min < g5.lei_min

    def test_every_estimate_carries_the_draft_law_disclaimer_and_its_provenance(self, grid):
        est = grid.estimate({
            "functie": "Consilier", "grad_treapta": "gradul II", "anexa": "VIII",
            "regim": "personal contractual", "nivel_administrativ": "local",
            "banda_populatie": "sub 10.000",
        })
        assert "proiect de lege neadoptat" in est.disclaimer
        assert est.varianta == "2026-07-17" and est.valoare_referinta == 4100
        assert est.randuri == ["VIII CII A 3_local4!23"]


class TestRetrieval:
    """Retrieval feeds the LLM a shortlist of real rows so it cannot invent one."""

    @pytest.mark.parametrize(
        "title,expected",
        [("Îngrijitor", "ingrijitor"), ("ȘOFER", "sofer"), ("Infirmieră", "infirmiera"),
         ("Muncitor calificat", "muncitor calificat"), ("Consilier juridic", "consilier juridic")],
    )
    def test_an_obvious_title_retrieves_its_grid_function_first(self, grid, title, expected):
        top = grid.candidates(title, k=5)
        assert top, f"no candidates for {title!r}"
        assert any(sg.norm(name) == expected for _, _row, name in top[:3])

    def test_the_matched_synonym_comes_back_in_the_workbook_spelling(self, grid):
        """A row can carry 27 synonyms; the caller needs the one that matched.

        Anexa II pays two dozen health professions off a single line, so pasting
        whole rows into a prompt would be mostly alternatives. And the spelling
        must be the workbook's, not the normalised key.
        """
        top = grid.candidates("Îngrijitor", k=3)
        assert any(name.startswith(("Î", "î")) for _, _row, name in top)

    def test_context_lifts_the_matching_annex(self, grid):
        """`Consilier` exists in Anexa IV and VIII; context should break the tie."""
        top = grid.candidates("Consilier", anexa="VIII", nivel_administrativ="local", k=5)
        assert top[0][1].anexa == "VIII"

    def test_gibberish_retrieves_nothing(self, grid):
        assert grid.candidates("qwertyuiop asdfghjkl") == []


# ---------------------------------------------------------------------------
# The grid of the law in force (Legea 153/2017, ADR 0008)
# ---------------------------------------------------------------------------
import importlib.util  # noqa: E402
from datetime import date  # noqa: E402

LAW_CSV = sg.DATA_DIR / f"grila-{sg.LAW_VERSION}.csv"
law_only = pytest.mark.skipif(
    not LAW_CSV.exists(),
    reason="run `python build-salary-grid.py --source lege-153` to build the law grid",
)

#: The 14 functions of S1 section 4.2: (selector, 2022 annex value or the later table
#: value, factor, which end of the range the S1 value is). The factor is 1.15 for Anexa VIII
#: (OUG 115/2023 + OUG 53/2024), 1 for the OUG 19/2024 and OUG 128/2023 tables.
S1_FUNCTIONS = {
    "F1": (dict(functie="Consilier", anexa="VIII", regim="funcționar public",
                nivel_administrativ="teritorial", grad_treapta="superior", studii="licenta"), 4905, 1.15, "min"),
    "F2": (dict(functie="Consilier", anexa="VIII", regim="funcționar public",
                nivel_administrativ="teritorial", grad_treapta="principal", studii="licenta"), 4561, 1.15, "min"),
    "F3": (dict(functie="Referent", anexa="VIII", regim="funcționar public",
                nivel_administrativ="teritorial", grad_treapta="superior", studii="liceala"), 4087, 1.15, "min"),
    "F4": (dict(functie="Consilier", anexa="VIII", regim="personal contractual",
                nivel_administrativ="teritorial", grad_treapta="gradul I A", studii="licenta"), 4733, 1.15, "min"),
    "F5": (dict(functie="Muncitor calificat", anexa="VIII", regim="personal contractual",
                grad_treapta="treapta I"), 3850, 1.15, "max"),
    "F6": (dict(functie="Muncitor necalificat", anexa="VIII", regim="personal contractual",
                grad_treapta="treapta I"), 2535, 1.15, "min"),
    "F7": (dict(functie="Îngrijitor", anexa="VIII", regim="personal contractual"), 2950, 1.15, "min"),
    "F8": (dict(functie="Șofer", anexa="VIII", regim="personal contractual",
                grad_treapta="treapta I"), 3850, 1.15, "min"),
    "F9": (dict(functie="Asistent medical", anexa="II", tip_unitate="a.4",
                grad_treapta="principal", studii="PL"), 5460, 1.0, "min"),
    "F10": (dict(functie="Medic specialist", anexa="II", tip_unitate="a.4"), 10963, 1.0, "min"),
    "F11": (dict(functie="Infirmieră", anexa="II", tip_unitate="a.4", studii="generala"), 4615, 1.0, "max"),
    "F12": (dict(functie="Asistent social principal", anexa="II", studii="licenta"), 5473, 1.0, "min"),
    "F13": (dict(functie="Administrator financiar", anexa="I", grad_treapta="gradul I"), 6530, 1.0, "max"),
    "F14": (dict(functie="Mediator școlar", anexa="I", grad_treapta="treapta I"), 5083, 1.0, "min"),
}
#: A date after 1 July 2026: the floor is 4,325 lei.
AFTER_JULY = date(2026, 9, 30)


@pytest.fixture(scope="module")
def law():
    return sg.load_grid(sg.LAW_VERSION)


@pytest.fixture(scope="module")
def est():
    """`estimate-salaries.py` has a hyphen in its name; import it by path."""
    spec = importlib.util.spec_from_file_location(
        "estimate_salaries_under_test", REPO_ROOT / "estimate-salaries.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["estimate_salaries_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


@law_only
class TestLawGolden:
    """The 14 functions of S1 section 4.2, at gradation 0 and 5, with the factor of the brief."""

    @pytest.mark.parametrize("gradation", [0, 5])
    @pytest.mark.parametrize("fn", sorted(S1_FUNCTIONS, key=lambda k: int(k[1:])))
    def test_value_is_annex_x_factor_x_gradation_never_below_the_minimum_wage(self, law, fn, gradation):
        selector, base, factor, end = S1_FUNCTIONS[fn]
        result = law.estimate(selector, gradation=gradation, as_of=AFTER_JULY)
        expected = max(math.ceil(round(base * factor * law.multiplier(gradation), 6)), 4325)
        got = result.lei_max if end == "max" else result.lei_min
        assert got == expected, f"{fn}: {result.motiv} {result.randuri}"

    def test_the_law_baseline_is_in_force_and_names_itself(self, law):
        result = law.estimate(S1_FUNCTIONS["F10"][0], as_of=AFTER_JULY)
        assert law.variant.status_juridic == "în vigoare"
        assert result.varianta == sg.LAW_VERSION
        assert result.disclaimer == sg.DISCLAIMER_LAW
        assert result.coef_min is None

    def test_every_row_records_its_source_table_and_row(self, law):
        assert len(law.rows) > 1500
        for row in law.rows:
            assert row.lei_min is not None and row.lei_max >= row.lei_min > 1000
            assert row.act_sursa and row.sheet.startswith(("L153-t", "OUG19-t")) and row.source_row > 0

    def test_no_row_is_flagged_after_the_review(self, law):
        """The 552 rows of Anexele III, IV, V were reviewed on 2026-11-18 (tmp/261118/grid153/review.csv)."""
        assert not [r.ref for r in law.rows if r.needs_review]

    def test_the_rows_of_the_review_fixes(self, law):
        rows = {r.ref: r for r in law.rows}
        # "- gradul II" under a head row keeps the name of the head row (was "-").
        assert rows["L153-t75!13"].functie.startswith("Muzeograf") and rows["L153-t75!13"].grad_treapta == "gradul II"
        # "gradul I (10 ani vechime)" gives the grade (was part of the name).
        assert rows["L153-t91!6"].functie == "Expert criminalist" and rows["L153-t91!6"].grad_treapta == "gradul I"
        # "curier diplomatic I, referent cabinet" gives gradul I; "Secretar I, consul" too.
        assert rows["L153-t82!7"].grad_treapta == "gradul I"
        assert (rows["L153-t81!7"].functie, rows["L153-t81!7"].grad_treapta) == ("Secretar, consul", "gradul I")

    def test_the_social_assistance_table_superseded_by_oug_19_is_not_in_the_grid(self, law):
        assert not [r for r in law.rows if r.sheet == "L153-t45"]
        assert any(r.sheet == "OUG19-t18" and r.functie == "Infirmieră" and r.lei_min == 4260 for r in law.rows)

    def test_the_health_type_of_unit_changes_the_value(self, law):
        a1 = law.estimate(dict(functie="Medic specialist", anexa="II", tip_unitate="a.1"), as_of=AFTER_JULY)
        a4 = law.estimate(dict(functie="Medic specialist", anexa="II", tip_unitate="a.4"), as_of=AFTER_JULY)
        assert (a1.lei_min, a4.lei_min) == (11187, 10963)

    def test_the_draft_variant_is_unchanged(self, grid):
        """Rule 3 of the brief: the draft result does not move. One value, read before the change."""
        result = grid.estimate(dict(functie="Îngrijitor", anexa="VIII"), gradation=0)
        assert result.varianta == "2026-07-17" and result.disclaimer == sg.DISCLAIMER


@law_only
class TestLawLocalRange:
    LOCAL = dict(functie="Consilier", anexa="VIII", regim="funcționar public",
                 grad_treapta="superior", studii="licenta", nivel_administrativ="local")

    @pytest.mark.parametrize("unit,cap", [
        (dict(tip="comuna", populatie=2500), math.ceil(1.10 * math.ceil(3.0 * 2415))),   # poz. 28, OUG 53/2024
        (dict(tip="comuna", populatie=3000), math.ceil(1.10 * math.ceil(3.0 * 2415))),
        (dict(tip="comuna", populatie=3001), math.ceil(1.10 * math.ceil(3.5 * 2415))),   # poz. 27
        (dict(tip="comuna", populatie=7000), math.ceil(1.10 * math.ceil(4.0 * 2415))),   # poz. 26
        (dict(tip="comuna", populatie=25000), math.ceil(5.0 * 2415)),                    # poz. 22: no 10%
        (dict(tip="oras", populatie=8000), math.ceil(1.10 * math.ceil(4.5 * 2415))),     # poz. 25
        (dict(tip="oras", populatie=15000), math.ceil(1.10 * math.ceil(5.0 * 2415))),    # poz. 23
        (dict(tip="oras", populatie=30000), math.ceil(5.5 * 2415)),                      # poz. 21
        (dict(tip="oras", populatie=100000), math.ceil(6.0 * 2415)),                     # poz. 20
        (dict(tip="oras", populatie=100001), math.ceil(6.5 * 2415)),                     # poz. 19
        (dict(tip="resedinta", populatie=60000), 8 * 2415),
        (dict(tip="sector", populatie=None), 8 * 2415),
        (dict(tip="capitala", populatie=None), 9 * 2415),
        (dict(tip="judet", populatie=None), 8 * 2415),
    ])
    def test_the_cap_follows_the_type_and_the_population_of_the_unit(self, law, unit, cap):
        result = law.estimate(dict(self.LOCAL, uat_unitati=[unit]), as_of=AFTER_JULY)
        assert result.lei_min == 5641            # the territorial table: 4,905 x 1.15
        assert result.lei_max == cap
        assert result.incredere == "interval"
        assert result.motiv.startswith("Consiliul local stabilește salariul")
        assert any("tabelul unităților teritoriale" in w for w in result.avertismente)

    def test_a_commune_without_a_population_takes_the_widest_commune_cap(self, law):
        result = law.estimate(dict(self.LOCAL, uat_unitati=[dict(tip="comuna", populatie=None)]),
                              as_of=AFTER_JULY)
        assert result.lei_max == math.ceil(5.0 * 2415) == 12075

    def test_an_ambiguous_name_takes_the_widest_of_its_units(self, law):
        units = [dict(tip="comuna", populatie=2500), dict(tip="oras", populatie=30000)]
        result = law.estimate(dict(self.LOCAL, uat_unitati=units), as_of=AFTER_JULY)
        assert result.lei_max == math.ceil(5.5 * 2415)

    def test_an_unknown_unit_takes_the_widest_cap_and_says_so(self, law):
        result = law.estimate(dict(self.LOCAL), as_of=AFTER_JULY)
        assert result.lei_max == 19320 and "necunoscut" in result.motiv
        assert any("nu a putut fi determinată" in w for w in result.avertismente)

    def test_a_non_local_post_is_not_a_range(self, law):
        result = law.estimate(dict(self.LOCAL, nivel_administrativ="teritorial"), as_of=AFTER_JULY)
        assert result.lei_min == result.lei_max == 5641 and result.incredere == "exact"

    def test_the_plafon_table_has_one_row_per_unit_type_with_its_source(self):
        rows = list(csv.DictReader((sg.DATA_DIR / "plafon-local.csv").open(encoding="utf-8")))
        assert len(rows) == 14
        for r in rows:
            assert int(r["plafon_lei"]) == math.ceil(float(r["coeficient"]) * 2415)
            assert "Anexa IX" in r["sursa"] and "rând" in r["sursa"]
            raised = "OUG 53/2024 art. II" in r["sursa"]
            assert float(r["majorare"]) == (1.10 if raised else 1.0)
            assert int(r["plafon_maxim_lei"]) == math.ceil(round(int(r["plafon_lei"]) * float(r["majorare"]), 6))
        assert sum("OUG 53/2024 art. II" in r["sursa"] for r in rows) == 6      # poz. 23-28

    def test_the_population_ranges_of_a_unit_type_do_not_overlap_or_leave_a_gap(self):
        rows = list(csv.DictReader((sg.DATA_DIR / "plafon-local.csv").open(encoding="utf-8")))
        for kinds in ({"comună"}, {"oraș", "oraș/municipiu"}):
            for pop in range(0, 300_000, 500):
                hits = [r for r in rows if r["tip_unitate"] in kinds
                        and int(r["populatie_min"] or 0) <= pop
                        and (not r["populatie_max"] or pop <= int(r["populatie_max"]))]
                assert len(hits) <= 1, (kinds, pop)
                if pop <= 50_000 or kinds != {"comună"}:
                    assert hits, (kinds, pop)


@law_only
class TestLawMinimumWage:
    def test_the_minimum_wage_by_date(self, law):
        assert law.minimum_wage(date(2025, 1, 1)) == 4050
        assert law.minimum_wage(date(2026, 6, 30)) == 4050
        assert law.minimum_wage(date(2026, 7, 1)) == 4325
        assert law.minimum_wage(date(2024, 12, 31)) == 0       # before the table: no floor

    def test_a_pay_below_the_minimum_is_raised_to_it(self, law):
        selector = S1_FUNCTIONS["F6"][0]              # muncitor necalificat: 2,535 x 1.15 = 2,916
        assert law.estimate(selector, as_of=date(2026, 3, 31)).lei_min == 4050
        assert law.estimate(selector, as_of=date(2026, 9, 30)).lei_min == 4325

    def test_the_floor_does_not_touch_a_pay_above_it(self, law):
        selector = S1_FUNCTIONS["F10"][0]
        assert law.estimate(selector, as_of=date(2026, 3, 31)).lei_min == 10963


class TestHospitalClassifier:
    @pytest.mark.parametrize("name,expected", [
        ("Spitalul Clinic Județean de Urgență Oradea", "a.1"),
        ("Spitalul Universitar de Urgență București", "a.1"),
        ("Institutul Național de Boli Infecțioase „Prof.dr.Matei Balș”", "a.1"),
        ("INSTITUL CLINIC DE URGENȚE OFTALMOLOGICE", "a.1"),
        ("Spitalul Județean de Urgență Slatina", "a.4"),
        ("Spitalul Municipal Sighișoara", "a.4"),
        ("Școala Gimnazială Nr. 1 Tuzla", "a.4"),
        ("", "a.4"),
    ])
    def test_clinical_by_name_other_when_unsure(self, est, name, expected):
        assert est.hospital_type(name) == expected


@law_only
class TestPrecedenceAndFormat:
    """ADR 0008 rule 4 and the JSON of the brief: the top level keeps the old keys."""

    def _pair(self, law, grid):
        selector = dict(functie="Îngrijitor", anexa="VIII", regim="personal contractual")
        return law.estimate(selector, as_of=AFTER_JULY), grid.estimate(selector), selector

    def test_the_law_figure_takes_the_top_level_and_the_draft_goes_under_proiect(self, est, law, grid):
        lw, dr, sel = self._pair(law, grid)
        assert lw.lei_min is not None and dr.lei_min is not None
        payload = est.assemble(lw, dr, sel, sel)
        assert payload["baza"] == "lege_in_vigoare"
        assert payload["lei_min"] == lw.lei_min and payload["varianta"] == sg.LAW_VERSION
        assert payload["proiect"]["baza"] == "proiect" and payload["proiect"]["lei_min"] == dr.lei_min

    def test_the_draft_figure_takes_the_top_level_when_the_law_has_no_row(self, est, law, grid):
        _, dr, sel = self._pair(law, grid)
        none = law.estimate(dict(functie="qwertyuiop"), as_of=AFTER_JULY)
        assert none.lei_min is None
        payload = est.assemble(none, dr, sel, sel)
        assert payload["baza"] == "proiect" and payload["lei_min"] == dr.lei_min
        assert "proiect" not in payload

    def test_the_export_reads_the_top_level_and_an_old_row_has_no_baza(self, est, law, grid):
        spec = importlib.util.spec_from_file_location(
            "export_under_test_salary", REPO_ROOT / "export-to-sqlite.py")
        exp = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(exp)
        lw, dr, sel = self._pair(law, grid)
        new = exp._salary_columns(json.dumps(est.assemble(lw, dr, sel, sel)))
        assert (new["min"], new["max"], new["variant"]) == (lw.lei_min, lw.lei_max, sg.LAW_VERSION)
        old_row = {k: v for k, v in dr.as_dict().items()}          # the format before this change
        assert "baza" not in old_row
        old = exp._salary_columns(json.dumps(old_row))
        assert (old["min"], old["max"]) == (dr.lei_min, dr.lei_max)


@law_only
class TestCouncilRange:
    """GRID153-CAP-SELECTOR: Art. 11 covers all Anexa VIII staff of a council employer,
    also when the draft selector names no level (workers, drivers, asistent social)."""

    COUNCIL = {"employer_name": "Primăria Comunei Mircea Vodă", "employer_category": "Primării",
               "judet_name": "Constanța"}
    SCHOOL = {"employer_name": "Liceul Teoretic Ion Creangă", "employer_category": "Instituții locale",
              "judet_name": "Constanța"}

    def test_a_council_worker_with_no_level_gets_the_local_range(self, est, law):
        sel = est.law_selector({"anexa": "VIII", "functie": "Muncitor calificat"}, self.COUNCIL, sg.load_grid())
        assert sel["nivel_administrativ"] == "local" and "uat_unitati" in sel
        result = law.estimate(sel, as_of=AFTER_JULY)
        assert result.incredere == "interval" and result.lei_max > result.lei_min
        assert "Consiliul local" in result.motiv

    def test_a_local_level_outside_a_council_is_a_fixed_annex_value(self, est):
        sel = est.law_selector({"anexa": "VIII", "functie": "Consilier", "nivel_administrativ": "local"},
                               self.SCHOOL, sg.load_grid())
        assert sel["nivel_administrativ"] == "teritorial" and "uat_unitati" not in sel

    def test_another_annex_at_a_council_is_not_a_range(self, est):
        sel = est.law_selector({"anexa": "II", "functie": "Asistent medical"}, self.COUNCIL, sg.load_grid())
        assert sel.get("nivel_administrativ") != "local"


class TestStatedSalaryIsMonthly:
    """DATA-SALARY-NOT-MONTHLY: a stated salary under 1,000 lei is not monthly pay."""

    @pytest.fixture(scope="class")
    def exp(self):
        spec = importlib.util.spec_from_file_location(
            "export_under_test_stated", REPO_ROOT / "export-to-sqlite.py")
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        return mod

    @pytest.mark.parametrize("inferred, expected", [
        ({"salary_min": 60, "salary_max": 60}, (None, None)),          # posting 16966: an hourly rate
        ({"salary_min": 999.9, "salary_max": 5000}, (None, None)),
        ({"salary_min": 1000, "salary_max": None}, (1000, None)),      # a quarter-time post
        ({"salary_min": 5250, "salary_max": 6100}, (5250, 6100)),
        ({}, (None, None)),
        ({"salary_min": "n/a"}, (None, None)),
    ])
    def test_only_a_monthly_figure_is_kept(self, exp, inferred, expected):
        assert exp._stated_salary(inferred) == expected


@law_only
class TestRowPay:
    """`Grid.row_pay()` is the one pay formula: `estimate()` is the min and max of it over the rows."""

    @staticmethod
    def _from_rows(grid, selector, gradation=0, as_of=AFTER_JULY):
        rows = grid.select(selector)
        pays = [grid.row_pay(r, gradation, as_of) for r in rows]
        return rows, min(p[0] for p in pays), max(p[1] for p in pays)

    def _management_row(self, law):
        return next(r for r in law.rows if r.tip_post == "conducere")

    @pytest.mark.parametrize("name,selector", [
        ("central exact", S1_FUNCTIONS["F10"][0]),
        ("central range", dict(functie="Consilier", anexa="VIII", regim="funcționar public",
                               nivel_administrativ="teritorial", grad_treapta="superior")),
        ("hospital tip_unitate", dict(functie="Medic specialist", anexa="II", tip_unitate="a.1")),
    ])
    @pytest.mark.parametrize("gradation", [0, 3])
    def test_the_law_estimate_is_the_min_and_max_of_row_pay(self, law, name, selector, gradation):
        rows, lo, hi = self._from_rows(law, selector, gradation)
        assert rows, name
        est = law.estimate(selector, gradation=gradation, as_of=AFTER_JULY)
        assert (est.lei_min, est.lei_max) == (lo, hi), name

    def test_a_local_post_takes_its_lower_end_from_row_pay(self, law):
        selector = dict(TestLawLocalRange.LOCAL)
        rows, lo, hi = self._from_rows(law, dict(selector, nivel_administrativ="teritorial"))
        est = law.estimate(dict(selector, uat_unitati=[dict(tip="comuna", populatie=3000)]), as_of=AFTER_JULY)
        assert est.lei_min == lo == 5641
        assert est.lei_max >= hi

    def test_a_management_row_ignores_the_gradation(self, law):
        row = self._management_row(law)
        assert law.row_pay(row, 0, AFTER_JULY) == law.row_pay(row, 5, AFTER_JULY)

    def test_the_draft_estimate_is_the_min_and_max_of_row_pay(self, grid):
        selector = dict(functie="Consilier", anexa="VIII", regim="funcționar public", grad_treapta="superior")
        for gradation in (0, 3):
            rows, lo, hi = self._from_rows(grid, selector, gradation)
            est = grid.estimate(selector, gradation=gradation)
            assert (est.lei_min, est.lei_max) == (lo, hi)
        row = rows[0]
        assert grid.row_pay(row, 0) == (math.ceil(row.coef_min * 4100), math.ceil(row.coef_max * 4100))

    def test_the_gradation_raises_a_non_management_row(self, law):
        row = next(r for r in law.rows if r.tip_post != "conducere" and r.lei_min > 6000)
        assert law.row_pay(row, 3, AFTER_JULY)[0] > law.row_pay(row, 0, AFTER_JULY)[0]


@law_only
class TestEstimatorExport:
    """The tables of the estimator page: Python computes, PHP looks up."""

    @pytest.fixture(scope="class")
    def tables(self):
        import sqlite3
        spec = importlib.util.spec_from_file_location(
            "export_under_test_estimator", REPO_ROOT / "export-to-sqlite.py")
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        con = sqlite3.connect(":memory:")
        coverage = mod.export_salary_estimator(con, today=AFTER_JULY)
        yield con, coverage
        con.close()

    def test_every_grid_row_is_exported_with_six_gradations(self, tables, law):
        con, _ = tables
        draft = sg.load_grid()
        assert con.execute("SELECT COUNT(*) FROM salary_grid_rows").fetchone()[0] == len(law.rows) + len(draft.rows)
        assert con.execute("SELECT COUNT(*) FROM salary_grid_pay").fetchone()[0] == 6 * (len(law.rows) + len(draft.rows))

    def test_the_pay_table_is_row_pay(self, tables, law):
        con, _ = tables
        draft = sg.load_grid()
        for grid in (law, draft):
            first = {}
            for r in grid.rows:
                first.setdefault(r.ref, r)           # the plain ref belongs to the first row
            for ref, row in list(first.items())[::37]:
                for g in range(6):
                    got = con.execute("SELECT lei_min, lei_max FROM salary_grid_pay "
                                      "WHERE version=? AND ref=? AND gradatie=?", (grid.version, ref, g)).fetchone()
                    assert got == grid.row_pay(row, g, AFTER_JULY), (grid.version, ref, g)

    def test_a_repeated_ref_gets_a_suffix_so_that_the_key_is_unique(self, tables, law):
        con, _ = tables
        assert len({r.ref for r in law.rows}) < len(law.rows)
        assert con.execute("SELECT COUNT(*) FROM salary_grid_rows WHERE ref LIKE '%#2'").fetchone()[0] > 0

    def test_the_link_pairs_rows_of_the_same_annex_and_reports_its_coverage(self, tables):
        con, cov = tables
        bad = con.execute("""SELECT COUNT(*) FROM salary_grid_link k
            JOIN salary_grid_rows l ON l.version = ? AND l.ref = k.law_ref
            JOIN salary_grid_rows d ON d.version = ? AND d.ref = k.draft_ref
            WHERE l.anexa <> d.anexa""", (sg.LAW_VERSION, sg.DEFAULT_VERSION)).fetchone()[0]
        assert bad == 0
        assert cov["law_with_draft"] > 1000 and cov["draft_with_law"] > 1500
        # A draft-only annex (military, dignitaries) has no law row.
        assert con.execute("""SELECT COUNT(*) FROM salary_grid_link k
            JOIN salary_grid_rows d ON d.version = ? AND d.ref = k.draft_ref
            WHERE d.anexa IN ('VI', 'IX')""", (sg.DEFAULT_VERSION,)).fetchone()[0] == 0

    def test_the_caps_and_the_parameters(self, tables, law):
        con, _ = tables
        assert con.execute("SELECT COUNT(*) FROM salary_cap").fetchone()[0] == len(law.plafon) == 14
        params = {k: json.loads(v) for k, v in con.execute("SELECT key, value FROM salary_param")}
        assert params["law"]["status_juridic"] == "în vigoare"
        assert params["draft"]["valoare_referinta_lei"] == 4100
        assert params["salariu_minim"]["lei"] == 4325 and params["salariu_minim"]["de_la"] == "2026-07-01"
        assert [g["nivel"] for g in params["gradatii"]] == [0, 1, 2, 3, 4, 5]


class TestAnexaVILabel:
    """Anexa VI writes "agent de poliţie/penitenciare debutant" in one cell.

    The synonym split on the slash leaves no synonym that equals the full label, so the
    label found no row (SALARY-ANEXA-VI). `select()` now falls back to the full label.
    """

    LABEL = "Funcţii corespunzătoare gradului de agent de poliţie/penitenciare debutant"

    def test_the_full_label_with_debutant_finds_its_row(self, grid):
        rows = grid.select({"functie": self.LABEL, "studii": "postliceala", "anexa": "VI"})
        assert [(r.sheet, r.source_row, r.studii) for r in rows] == [("VI_Armata", 28, "PL")]
        assert rows[0].coef_min == rows[0].coef_max == 1.49

    def test_the_full_label_without_studies_finds_both_study_levels(self, grid):
        rows = grid.select({"functie": self.LABEL})
        assert sorted(r.studii for r in rows) == ["M", "PL"]

    def test_the_post_pay_of_the_row(self, grid):
        est = grid.estimate({"functie": self.LABEL, "studii": "postliceala", "anexa": "VI"})
        assert est.lei_min == est.lei_max == math.ceil(1.49 * 4100)

    def test_the_rank_pay_file_has_the_rank_table(self):
        path = sg.DATA_DIR / f"solda-grad-{sg.DEFAULT_VERSION}.csv"
        rows = list(csv.DictReader(path.open(encoding="utf-8")))
        assert len(rows) == 22
        assert {r["coeficient"] for r in rows if "Agent de poliţie" in r["grad"]} == {"0.52"}

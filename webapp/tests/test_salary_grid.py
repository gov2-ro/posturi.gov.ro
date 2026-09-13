"""The 2026 draft salary grid: registry integrity and the gross-pay calculator.

The grid is the only way this site can show pay at all — 44 of 9,757 postings
state a salary in the text — so the arithmetic and the row selection have to be
reproducible from `(selector, variant)` alone.
"""

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
        assert any(sg.norm(n) == expected for _, row in top[:3] for n in row.sinonime)

    def test_context_lifts_the_matching_annex(self, grid):
        """`Consilier` exists in Anexa IV and VIII; context should break the tie."""
        top = grid.candidates("Consilier", anexa="VIII", nivel_administrativ="local", k=5)
        assert top[0][1].anexa == "VIII"

    def test_gibberish_retrieves_nothing(self, grid):
        assert grid.candidates("qwertyuiop asdfghjkl") == []

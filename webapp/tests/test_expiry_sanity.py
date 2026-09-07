"""Guard against a silent repeat of the 2026-07 expiry-parsing outage.

The redesign replaced absolute dates on index cards with a relative countdown,
`expires_at` went NULL for everything scraped afterwards, and the active-only
export quietly shipped 3 rows for five weeks. These tests pin the detector.
"""
from datetime import date

from apps.jobs.management.commands.import_csvs import (
    expiry_sanity_warnings,
    parse_date,
    plausible_expiry,
)


class TestExpirySanityWarnings:
    def test_healthy_dataset_is_silent(self):
        assert expiry_sanity_warnings(active=1656, recent_total=2295, recent_without_expiry=28) == []

    def test_detects_the_actual_outage(self):
        # The real numbers on 2026-09-06, before the fix.
        warnings = expiry_sanity_warnings(active=3, recent_total=2295, recent_without_expiry=2295)
        assert len(warnings) == 1
        assert "100%" in warnings[0]
        assert "expiry parsing is probably broken" in warnings[0]

    def test_flags_an_empty_active_export(self):
        warnings = expiry_sanity_warnings(active=0, recent_total=500, recent_without_expiry=500)
        assert len(warnings) == 2
        assert any("empty site" in w for w in warnings)

    def test_ratio_below_threshold_is_tolerated(self):
        # Half missing is bad but within the documented threshold; don't cry wolf.
        assert expiry_sanity_warnings(active=100, recent_total=100, recent_without_expiry=50) == []

    def test_empty_dataset_does_not_warn(self):
        # A fresh DB has nothing to judge; warning here would just be noise.
        assert expiry_sanity_warnings(active=0, recent_total=0, recent_without_expiry=0) == []


class TestParseDate:
    def test_iso_format(self):
        # parse-anunturi.py writes Data Expirare as ISO; parse_date had no branch for it.
        assert parse_date("2026-09-15") == date(2026, 9, 15)

    def test_legacy_index_formats_still_work(self):
        assert parse_date("Expiră in  16/09/2024") == date(2024, 9, 16)
        assert parse_date("15.09.2026") == date(2026, 9, 15)
        assert parse_date("Publicat în: 23 decembrie,2025") == date(2025, 12, 23)

    def test_countdown_strings_are_not_dates(self):
        for s in ("1 zi rămasă", "6 zile rămase", "Ultima zi", "Anunț anulat"):
            assert parse_date(s) is None


class TestPlausibleExpiry:
    def test_rejects_upstream_typo_year(self):
        # Real row: "Expiră in 14/01/2046" on a posting published 2025-12-23.
        assert plausible_expiry(date(2046, 1, 14), max_year=2028) is None

    def test_accepts_normal_dates(self):
        assert plausible_expiry(date(2026, 12, 22), max_year=2028) == date(2026, 12, 22)

    def test_passes_through_none(self):
        assert plausible_expiry(None, max_year=2028) is None


# ---------------------------------------------------------------- județ sanity

from apps.jobs.management.commands.import_csvs import (  # noqa: E402
    MAX_EXPECTED_COUNTIES,
    judet_sanity_warnings,
)


class TestJudetSanityWarnings:
    def test_clean_state_is_silent(self):
        assert judet_sanity_warnings(MAX_EXPECTED_COUNTIES, 0, 9514) == []

    def test_fewer_counties_than_the_maximum_is_fine(self):
        """A small or regional dataset legitimately covers fewer counties."""
        assert judet_sanity_warnings(12, 0, 300) == []

    def test_too_many_counties_warns(self):
        """The 261-row fragmentation this check exists to catch."""
        warnings = judet_sanity_warnings(261, 0, 9514)
        assert len(warnings) == 1
        assert "261" in warnings[0]
        assert "normalize_judete" in warnings[0]

    def test_unresolved_postings_warn_with_a_percentage(self):
        warnings = judet_sanity_warnings(MAX_EXPECTED_COUNTIES, 50, 1000)
        assert len(warnings) == 1
        assert "50 posting" in warnings[0]
        assert "5.0%" in warnings[0]

    def test_both_problems_report_separately(self):
        assert len(judet_sanity_warnings(300, 7, 1000)) == 2

    def test_zero_total_does_not_divide_by_zero(self):
        assert judet_sanity_warnings(MAX_EXPECTED_COUNTIES, 0, 0) == []

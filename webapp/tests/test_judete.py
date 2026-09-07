"""Tests for canonical județ normalisation (apps.jobs.judete)."""

import pytest

from apps.jobs.judete import (
    COUNTIES,
    fold,
    normalize_judet,
    repair_diacritics,
    titlecase_locality,
)


def test_there_are_42_counties():
    """41 counties plus București, no duplicates."""
    assert len(COUNTIES) == 42
    assert len(set(COUNTIES)) == 42


def test_counties_use_comma_below_diacritics():
    """Canonical spelling must never contain the Turkish cedilla forms."""
    joined = "".join(COUNTIES)
    assert "ş" not in joined and "ţ" not in joined
    assert "Iași" in COUNTIES and "Timiș" in COUNTIES


class TestRepairDiacritics:
    def test_cedilla_becomes_comma_below(self):
        assert repair_diacritics("Timiş") == "Timiș"
        assert repair_diacritics("Galaţi") == "Galați"
        assert repair_diacritics("BRAŞOV") == "BRAȘOV"

    def test_correct_diacritics_are_untouched(self):
        assert repair_diacritics("Iași") == "Iași"


class TestFold:
    def test_spelling_variants_share_a_key(self):
        assert fold("Bistriţa-Năsăud") == fold("Bistrița-Năsăud") == fold("BISTRITA NASAUD")

    def test_punctuation_and_case_are_ignored(self):
        assert fold("Caraş-Severin") == fold("caras severin")

    def test_distinct_counties_do_not_collide(self):
        assert len({fold(c) for c in COUNTIES}) == len(COUNTIES)


class TestTitlecaseLocality:
    def test_shouted_names_are_title_cased(self):
        assert titlecase_locality("CLUJ-NAPOCA") == "Cluj-Napoca"
        assert titlecase_locality("ALBA IULIA") == "Alba Iulia"

    def test_particles_stay_lowercase(self):
        assert titlecase_locality("BAIA DE ARAMĂ") == "Baia de Aramă"

    def test_diacritics_are_repaired(self):
        assert titlecase_locality("TÂRGU MUREŞ") == "Târgu Mureș"

    def test_mixed_case_input_is_left_alone(self):
        """The source occasionally supplies proper case; re-casing only loses information."""
        assert titlecase_locality("Amara") == "Amara"


class TestNormalizeJudet:
    @pytest.mark.parametrize(
        "raw,county",
        [
            ("Timiş", "Timiș"),                 # pre-redesign, cedilla
            ("Timiș", "Timiș"),                 # already canonical
            ("Bucureşti", "București"),
            ("Caraş-Severin", "Caraș-Severin"),
            ("Satu Mare", "Satu Mare"),         # two-word county, no comma
            ("Bistriţa-Năsăud", "Bistrița-Năsăud"),
        ],
    )
    def test_bare_county(self, raw, county):
        parsed = normalize_judet(raw)
        assert parsed.judet == county
        assert parsed.locality is None
        assert parsed.resolved

    @pytest.mark.parametrize(
        "raw,county,locality",
        [
            ("CLUJ-NAPOCA, Cluj", "Cluj", "Cluj-Napoca"),
            ("TÂRGU MUREŞ, Mureș", "Mureș", "Târgu Mureș"),
            ("BAIA DE ARAMĂ, Mehedinți", "Mehedinți", "Baia de Aramă"),
            ("Amara, Ialomița", "Ialomița", "Amara"),
            ("Călăraşi ,Ialomiţa", "Ialomița", "Călărași"),  # stray space before comma
        ],
    )
    def test_locality_comma_county(self, raw, county, locality):
        parsed = normalize_judet(raw)
        assert (parsed.judet, parsed.locality) == (county, locality)

    def test_county_capital_keeps_its_locality(self):
        """"ARAD, Arad" is the *city* of Arad — dropping it would lose precision."""
        parsed = normalize_judet("ARAD, Arad")
        assert (parsed.judet, parsed.locality) == ("Arad", "Arad")

    @pytest.mark.parametrize("raw", ["", "   ", None])
    def test_empty_is_unresolved(self, raw):
        parsed = normalize_judet(raw)
        assert parsed.judet is None and parsed.locality is None
        assert not parsed.resolved

    def test_unknown_value_is_unresolved_and_keeps_the_raw(self):
        parsed = normalize_judet("Freedonia")
        assert not parsed.resolved
        assert parsed.judet is None
        assert parsed.raw == "Freedonia"

    def test_unknown_locality_with_known_county_still_resolves(self):
        parsed = normalize_judet("SOME NEW COMMUNE, Vaslui")
        assert parsed.judet == "Vaslui"
        assert parsed.locality == "Some New Commune"

    def test_every_canonical_name_round_trips(self):
        for county in COUNTIES:
            assert normalize_judet(county).judet == county

    def test_bucharest_sector_alias(self):
        assert normalize_judet("Municipiul Bucuresti").judet == "București"

    def test_is_idempotent(self):
        """Feeding the output back in must not change it — the backfill relies on this."""
        for raw in ["Timiş", "CLUJ-NAPOCA, Cluj", "Satu Mare"]:
            once = normalize_judet(raw).judet
            assert normalize_judet(once).judet == once


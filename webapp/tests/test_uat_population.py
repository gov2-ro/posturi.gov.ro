"""Population bands for local-government employers (DATA-06).

Anexa VIII pays a `Consilier gradul II` 5.541 lei under 10.000 inhabitants and
7.579 over 200.000, so the band decides the answer. `data/uat-populatie.csv`
holds every town and every commune of the 2021 census; a name alone is not a
key (Slatina is a municipiu in Olt and a commune in Suceava), so rows are
matched on unit type and, when known, county.
"""

import csv
import sys
from collections import Counter
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import uat  # noqa: E402

pytestmark = pytest.mark.skipif(
    not uat.POPULATION_CSV.exists(),
    reason="run `python -I ops/build-uat-population.py` to materialise the table",
)

UNDER_10K = frozenset({uat.BAND_UNDER_10K})
FROM_10K = frozenset({uat.BAND_10_50K})
FROM_50K = frozenset({uat.BAND_50_200K})
OVER_200K = frozenset({uat.BAND_OVER_200K})


def bands(employer, county=None):
    return uat.bands_for(employer, county)[0]


def basis(employer, county=None):
    return uat.bands_for(employer, county)[1]


class TestCommunes:
    def test_a_big_commune_gets_the_band_its_population_says(self):
        # Florești (Cluj): 52.735 at the 2021 census, the largest commune.
        assert bands("Primăria Comunei Florești, județul Cluj") == FROM_50K
        assert basis("Primăria Comunei Florești, județul Cluj") == "populație"

    def test_a_commune_between_10k_and_50k(self):
        assert bands("Comuna Chiajna, județul Ilfov") == FROM_10K        # 43.584
        assert bands("Primăria Comunei Giroc, județul Timiș") == FROM_10K  # 22.270

    def test_a_small_commune_stays_under_10k(self):
        assert bands("Primăria Comunei Mărișel, județul Cluj") == UNDER_10K
        assert basis("Primăria Comunei Mărișel, județul Cluj") == "populație"
        assert bands("Primăria Comunei Ciugud") == UNDER_10K

    def test_a_commune_not_in_the_table_still_falls_back_to_under_10k(self):
        employer = "Primăria Comunei Inexistenta, județul Cluj"
        assert bands(employer) == UNDER_10K
        assert basis(employer) == "tip UAT: comună"

    def test_without_the_table_every_commune_is_under_10k(self, monkeypatch):
        monkeypatch.setattr(uat, "load_populations", lambda: {})
        assert uat.bands_for("Comuna Florești, județul Cluj") == (UNDER_10K, "tip UAT: comună")

    def test_the_county_survives_spelling_noise(self):
        # Truncated by the source ("Timi;"), SHOUTED, no diacritics, cedilla.
        assert bands("Comuna Dumbrăvița, județul Timi;") == FROM_10K
        assert bands("COMUNA DUMBRAVITA,JUDETUL TIMIS") == FROM_10K
        assert bands("COMUNA FLORESTI ,  FLORESTI, judetul CLUJ") == FROM_50K
        assert bands("Comuna  VALU LUI TRAIAN, JUDEȚUL CONSTANTA") == FROM_10K


class TestSameNameDifferentUnit:
    def test_slatina_commune_in_suceava_is_not_the_municipiu_in_olt(self):
        assert bands("Primăria Comunei Slatina, județul Suceava") == UNDER_10K   # 4.837
        assert bands("Primăria Municipiului Slatina, județul Olt") == FROM_50K    # 63.487

    def test_type_alone_separates_slatina_when_no_county_is_given(self):
        assert bands("Primăria Comunei Slatina") == UNDER_10K
        assert bands("Primăria Municipiului Slatina") == FROM_50K

    def test_satu_mare_commune_in_harghita_is_not_the_city(self):
        assert bands("Comuna Satu Mare, județul Harghita") == UNDER_10K
        assert bands("Comuna Satu Mare") == UNDER_10K
        assert bands("Primăria Municipiului Satu Mare") == FROM_50K

    def test_same_name_communes_in_different_bands_need_a_county(self):
        # Florești: Cluj 52.735, Prahova 6.891, Mehedinți 2.311, ...
        assert bands("Comuna Florești, județul Prahova") == UNDER_10K
        assert bands("Comuna Florești, județul Mehedinți") == UNDER_10K
        # No county anywhere: ambiguous, so the type rule, never a guess.
        assert uat.bands_for("Comuna Florești") == (UNDER_10K, "tip UAT: comună")

    def test_the_postings_county_resolves_what_the_employer_string_does_not(self):
        assert bands("Primăria Comunei Florești", "Cluj") == FROM_50K
        assert bands("Primăria Comunei Florești", "Prahova") == UNDER_10K
        assert bands("Primăria Comunei Florești", "CLUJ-NAPOCA, Cluj") == FROM_50K
        assert bands("Primăria Comunei Florești", "Cluj") == bands("Primăria Comunei Florești, județul Cluj")

    def test_the_employer_county_beats_the_postings_county(self):
        assert bands("Primăria Comunei Florești, județul Prahova", "Cluj") == UNDER_10K


class TestStefanesti:
    """Two towns share the name, in different bands: Argeș 15.931, Botoșani 5.032."""

    def test_without_a_county_it_is_ambiguous_and_takes_the_type_fallback(self):
        assert uat.bands_for("Primăria Orașului Ștefănești") == (
            frozenset({uat.BAND_10_50K, uat.BAND_UNDER_10K}), "tip UAT: oraș")
        assert bands("Primăria Orașului Stefanesti") == {uat.BAND_10_50K, uat.BAND_UNDER_10K}

    def test_with_a_county_it_resolves(self):
        assert bands("Primăria Orașului Ștefănești, județul Argeș") == FROM_10K
        assert bands("Primăria Orașului Ștefănești, județul Botoșani") == UNDER_10K
        assert bands("Primăria Orașului Ștefănești", "Botoșani") == UNDER_10K

    def test_a_commune_of_that_name_does_not_disturb_the_towns(self):
        assert bands("Primăria Comunei Ștefănești, județul Vâlcea") == UNDER_10K


class TestTowns:
    def test_a_town_keeps_its_band(self):
        assert bands("Primăria Municipiului Alba Iulia") == FROM_50K          # 64.227
        assert bands("Primăria Municipiului Brașov") == OVER_200K
        assert bands("Primăria Orașului Abrud") == UNDER_10K                  # 4.360
        assert bands("Primăria Orașului Aiud") == FROM_10K                    # 21.307
        assert basis("Primăria Orașului Aiud") == "populație"

    def test_a_bare_town_name_without_a_type_is_still_read_as_the_town(self):
        assert bands("Primăria Câmpia Turzii") == FROM_10K
        assert bands("Primăria Slatina") == FROM_50K

    def test_a_town_named_like_a_commune_elsewhere_stays_the_town(self):
        # Bragadiru: oraș in Ilfov (40.080), commune in Teleorman (3.327).
        assert bands("Primăria Orașului Bragadiru") == FROM_10K
        assert bands("Primăria Orașului Bragadiru, județul Ilfov") == FROM_10K
        assert bands("Primăria Comunei Bragadiru, județul Teleorman") == UNDER_10K

    def test_a_wrong_county_on_a_town_does_not_change_the_band(self):
        assert bands("Primăria Municipiului Alba Iulia, județul Cluj") == FROM_50K

    def test_the_six_sectors_and_the_big_cities_are_over_200k(self):
        assert bands("Primăria Sectorului 3 București") == OVER_200K
        assert bands("Primăria Municipiului Cluj-Napoca") == OVER_200K

    def test_county_level_and_unknown_employers_are_not_banded(self):
        assert bands("Serviciul de Telecomunicații Speciale") == frozenset()


class TestTable:
    @pytest.fixture(scope="class")
    def rows(self):
        with uat.POPULATION_CSV.open(encoding="utf-8") as fh:
            return list(csv.DictReader(fh))

    def test_every_town_and_commune_of_the_2021_census_is_there(self, rows):
        assert Counter(r["tip"] for r in rows) == {"oras": 319, "comuna": 2862}

    def test_a_row_is_identified_by_name_county_and_tip(self, rows):
        keys = Counter((uat.norm(r["nume"]), uat.norm(r["judet"]), r["tip"]) for r in rows)
        assert [k for k, n in keys.items() if n > 1] == []

    def test_counties_are_canonical(self, rows):
        counties = {r["judet"] for r in rows}
        assert counties == {uat.canonical_county(c) for c in counties}
        assert len(counties) == 42

    def test_communes_carry_a_siruta_code(self, rows):
        assert all(r["siruta"].isdigit() for r in rows if r["tip"] == "comuna")

    def test_communes_over_10k_are_a_small_minority(self, rows):
        big = [r for r in rows if r["tip"] == "comuna" and int(r["populatie"]) >= 10_000]
        assert 30 <= len(big) <= 100
        assert max(int(r["populatie"]) for r in big) < 60_000


class TestEmployerCounty:
    @pytest.mark.parametrize("employer,expected", [
        ("Comuna Matca, județul Galați", "Galați"),
        ("PRIMARIA COMUNA CEPTURA,JUDETUL PRAHOVA", "Prahova"),
        ("Comuna Dumbrăvița, județul Timi;", "Timiș"),
        ("Primăria Cârlibaba ,  com. Cârlibaba,  jud. Suceava", "Suceava"),
        ("PRIMARIA TEACA, BISTRITA NASAUD", "Bistrița-Năsăud"),
        ("Comuna Matca", ""),
        ("Spitalul Județean Alba", ""),
        ("", ""),
    ])
    def test_county_named_in_the_employer_string(self, employer, expected):
        assert uat.employer_county(employer) == expected

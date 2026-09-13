"""`_v3_columns` — flattening prompt-v3 schema_json into queryable SQLite columns.

The browse facets read these columns, so a v2 row (or no schema at all) must
produce empty defaults rather than blow up: the filters then stay invisible
until a v3 extraction has actually run.
"""

import importlib.util
import json
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]


def _export_module():
    spec = importlib.util.spec_from_file_location("exp_under_test", REPO_ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["exp_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


@pytest.fixture(scope="module")
def v3cols():
    return _export_module()._v3_columns


V3_PAYLOAD = {
    "education": {
        "minimum_level": "licenta", "eqf_level": 6,
        "fields_of_study": [
            {"label_ro": "geografie", "isced_field": "05_stiinte_naturale_matematica"},
            {"label_ro": "silvicultură", "isced_field": "08_agricultura_silvicultura_veterinara"},
        ],
    },
    "skill_list": [{"label": "GIS", "type": "knowledge"}, {"label": "analiză spațială"}],
    "language_list": [{"language": "engleză", "iso_code": "en", "cefr": "B2"}],
    "credentials": [{"label": "permis categoria B", "kind": "permis_conducere"}],
    "policy_domains": ["mediu", "tehnologia_informatiei"],
    "exam_stages": ["selectie_dosare", "proba_scrisa"],
    "positions": [{"title": "A"}, {"title": "B"}],
}


class TestEmptyDefaults:
    @pytest.mark.parametrize("raw", [None, "", "not json", "[]", "null"])
    def test_junk_yields_empty_columns(self, v3cols, raw):
        out = v3cols(raw)
        assert out["eqf_level"] is None
        assert out["isced_fields"] == "[]"
        assert out["skills"] == "[]"

    def test_a_v2_payload_yields_empty_columns(self, v3cols):
        """v2 rows must not half-populate the v3 facets."""
        v2 = {"educationRequirements": "Studii superioare.", "skills": "- Excel"}
        out = v3cols(json.dumps(v2))
        assert out["eqf_level"] is None
        assert out["isced_fields"] == "[]" and out["skills"] == "[]"
        assert out["positions"] is None


class TestV3Flattening:
    def test_scalars(self, v3cols):
        out = v3cols(json.dumps(V3_PAYLOAD))
        assert out["eqf_level"] == 6
        assert out["study_level"] == "licenta"
        assert out["positions"] == 2

    def test_lists_are_json_arrays_of_codes(self, v3cols):
        out = v3cols(json.dumps(V3_PAYLOAD))
        assert json.loads(out["isced_fields"]) == [
            "05_stiinte_naturale_matematica", "08_agricultura_silvicultura_veterinara",
        ]
        assert set(json.loads(out["skills"])) == {"GIS", "analiză spațială"}
        assert json.loads(out["credentials"]) == ["permis_conducere"]
        assert set(json.loads(out["policy_domains"])) == {"mediu", "tehnologia_informatiei"}

    def test_language_and_level_travel_together(self, v3cols):
        """One LIKE probe has to filter on both, so they share a token."""
        assert json.loads(v3cols(json.dumps(V3_PAYLOAD))["languages"]) == ["en:B2"]

    def test_language_without_a_level_has_no_trailing_colon(self, v3cols):
        payload = {**V3_PAYLOAD, "language_list": [{"language": "engleză", "iso_code": "en"}]}
        assert json.loads(v3cols(json.dumps(payload))["languages"]) == ["en"]

    def test_values_are_deduplicated_and_sorted(self, v3cols):
        """Two fields of study in the same ISCED bucket must not count twice."""
        payload = {"education": {"fields_of_study": [
            {"label_ro": "geografie", "isced_field": "05_stiinte_naturale_matematica"},
            {"label_ro": "biologie", "isced_field": "05_stiinte_naturale_matematica"},
        ]}}
        assert json.loads(v3cols(json.dumps(payload))["isced_fields"]) == [
            "05_stiinte_naturale_matematica"
        ]

    def test_nulls_inside_lists_are_dropped(self, v3cols):
        payload = {"education": {"fields_of_study": [
            {"label_ro": "geografie", "isced_field": None},
            {"label_ro": "biologie", "isced_field": "05_stiinte_naturale_matematica"},
        ]}}
        assert json.loads(v3cols(json.dumps(payload))["isced_fields"]) == [
            "05_stiinte_naturale_matematica"
        ]

    def test_no_positions_means_null_not_zero(self, v3cols):
        """NULL keeps the column out of "multi-role" counts entirely."""
        payload = {"education": {}, "positions": []}
        assert v3cols(json.dumps(payload))["positions"] is None


class TestLikeProbeCompatibility:
    """The facets query these columns with LIKE '%"value"%' ESCAPE '\\'."""

    def test_values_are_quoted_so_a_probe_cannot_match_a_prefix(self, v3cols):
        out = v3cols(json.dumps({"education": {}, "policy_domains": ["mediu", "mediu_urban"]}))
        assert '"mediu"' in out["policy_domains"]
        # a probe for "mediu" must not also match "mediu_urban"
        assert out["policy_domains"].count('"mediu"') == 1

    def test_underscored_codes_survive_json_encoding(self, v3cols):
        """Underscores are LIKE wildcards — the filter escapes them, and this
        asserts the stored form is the plain code."""
        out = v3cols(json.dumps(V3_PAYLOAD))
        assert "05_stiinte_naturale_matematica" in out["isced_fields"]

    def test_diacritics_are_not_escaped(self, v3cols):
        out = v3cols(json.dumps(V3_PAYLOAD))
        assert "analiză spațială" in out["skills"]


class TestContractAndBibliography:
    """Extracted by v3 from the start; exported and browsable only from
    2026-09-13. `contract` was populated on 1,470 of 1,549 structured payloads
    and `bibliography_topics` on 1,102, none of it reachable from the site."""

    def test_contract_terms_are_flattened(self, v3cols):
        got = v3cols(json.dumps({"education": {}, "contract": {
            "duration": "determinata", "duration_months": 12,
            "schedule": "norma_partiala", "hours_per_week": 20.0,
            "remote_mode": "hibrid", "shift_work": True,
        }}))
        assert got["contract_duration"] == "determinata"
        assert got["schedule"] == "norma_partiala"
        assert got["hours_per_week"] == 20.0
        assert got["remote_mode"] == "hibrid"
        assert got["shift_work"] == 1

    def test_shift_work_is_tri_state(self, v3cols):
        """NULL ("the posting was never read for it") is not 0 ("it says no").

        A facet that treats them the same silently claims thousands of postings
        involve no shift work when nothing was ever extracted about them.
        """
        no_contract = v3cols(json.dumps({"education": {}}))
        assert no_contract["shift_work"] is None

        says_no = v3cols(json.dumps({"education": {}, "contract": {"shift_work": False}}))
        assert says_no["shift_work"] == 0

    def test_bibliography_topics_are_a_json_array(self, v3cols):
        got = v3cols(json.dumps({"education": {},
                                 "bibliography_topics": ["Codul administrativ", "achiziții publice"]}))
        assert json.loads(got["bibliography"]) == ["Codul administrativ", "achiziții publice"]

    def test_seniority_hint_is_carried(self, v3cols):
        got = v3cols(json.dumps({"education": {}, "seniority_hint": "principal"}))
        assert got["seniority_hint"] == "principal"

    def test_a_v2_payload_leaves_all_of_them_empty(self, v3cols):
        got = v3cols(json.dumps({"responsibilities": "x", "baseSalary": None}))
        assert got["contract_duration"] == "" and got["schedule"] == ""
        assert got["hours_per_week"] is None and got["shift_work"] is None
        assert got["bibliography"] == "[]" and got["seniority_hint"] == ""


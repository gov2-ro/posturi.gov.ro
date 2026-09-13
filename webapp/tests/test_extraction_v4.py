"""Prompt v4: the four fields v3 could not answer, and the guards around them.

v4 exists for things the site needs but could not ask: when the application
actually closes, who pays for the post, which ministry the employer answers to,
and whatever the schema dropped. It is a strict superset of v3 — the rest of
the payload must survive untouched, because the export detects a structured
payload by looking for a top-level `education` key.
"""

import sys
from pathlib import Path

import pytest
from pydantic import ValidationError

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from schema_models import (  # noqa: E402
    EXTRACTION_MODELS,
    CompetitionCalendar,
    JobPostingExtraction,
    JobPostingExtractionV3,
    JobPostingExtractionV4,
    OccupationMapping,
    json_schema_for,
    model_for_version,
)


class TestVersionCompatibility:
    def test_v4_is_registered(self):
        assert model_for_version("v4") is JobPostingExtractionV4
        assert "v4" in EXTRACTION_MODELS

    def test_v4_is_a_superset_of_v3(self):
        assert set(JobPostingExtractionV3.model_fields) <= set(JobPostingExtractionV4.model_fields)
        assert set(JobPostingExtraction.model_fields) <= set(JobPostingExtractionV4.model_fields)

    def test_v4_keeps_education_at_the_top_level(self):
        """`export-to-sqlite.py::_v3_columns` detects a structured payload with
        `"education" in s`. Nesting or renaming it would empty every v3 facet on
        the live site without erroring anywhere."""
        assert "education" in JobPostingExtractionV4.model_fields

    def test_a_bare_v3_payload_validates_as_v4(self):
        payload = JobPostingExtractionV3(occupation_title="Referent").model_dump(mode="json")
        upgraded = JobPostingExtractionV4.model_validate(payload)
        assert upgraded.occupation_title == "Referent"
        assert upgraded.competition_calendar is None

    def test_the_v4_prompt_exists_and_extends_v3_verbatim(self):
        """v3's wording is the tested part; v4 appends rather than rewrites."""
        import json
        prompts = json.loads((REPO_ROOT / "models_config.json").read_text(encoding="utf-8"))["prompts"]
        v3, v4 = prompts["v3"], prompts["v4"]
        tail = "Now extract from the posting below and return ONLY the JSON object."
        assert v4.startswith(v3.rstrip()[: -len(tail)].rstrip())
        assert "Part D" in v4 and v4.rstrip().endswith(tail)

    def test_the_v4_output_budget_is_raised_above_v3(self):
        """v4 truncated mid-JSON at 2,000 output tokens on long calendars —
        which surfaces as "Expected dict, got str", not as a clean error."""
        import importlib.util
        spec = importlib.util.spec_from_file_location("llm_schema", REPO_ROOT / "llm-schema.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        assert module.max_output_tokens("v4") > module.max_output_tokens("v3")


class TestCompetitionCalendar:
    def test_the_deadline_is_derived_from_the_submission_event_when_omitted(self):
        """Never the last row: that is the final-results date, weeks later."""
        calendar = CompetitionCalendar.model_validate({"events": [
            {"stage": "publicare", "date": "2024-08-28"},
            {"stage": "depunere_dosare", "date": "2024-09-04"},
            {"stage": "depunere_dosare", "date": "2024-09-11", "time": "16:00"},
            {"stage": "rezultate_finale", "date": "2024-09-30"},
        ]})
        assert calendar.application_deadline == "2024-09-11"     # not 2024-09-30
        assert calendar.application_deadline_time == "16:00"

    def test_a_stated_deadline_is_not_overwritten_by_the_derivation(self):
        calendar = CompetitionCalendar.model_validate({
            "application_deadline": "2024-09-03",
            "events": [{"stage": "depunere_dosare", "date": "2024-09-11"}],
        })
        assert calendar.application_deadline == "2024-09-03"

    def test_a_runaway_calendar_is_capped(self):
        """19,335 rows of `data/calendar.csv` include bullets and empty strings
        as event names; a 40-row calendar is table layout, not a competition."""
        calendar = CompetitionCalendar.model_validate(
            {"events": [{"stage": "altele", "date": "2024-01-01"}] * 40})
        assert len(calendar.events) == CompetitionCalendar.MAX_EVENTS

    def test_an_unknown_stage_is_rejected_rather_than_stored_as_free_text(self):
        with pytest.raises(ValidationError):
            CompetitionCalendar.model_validate(
                {"events": [{"stage": "afisare rezultate", "date": "2024-01-01"}]})


class TestOccupationMapping:
    def test_isco_group_and_eqf_are_derived_not_trusted(self):
        """Same rule as v3's `_derive_eqf_level` / `_derive_iso_code`: anything
        computable is computed, and the model's value is discarded."""
        mapping = OccupationMapping.model_validate({
            "occupation_canonical": "Îngrijitor", "cor_code": "911201",
            "isco_group": "0000", "cor_label": "not this", "study_level": "generala",
            "eqf_level": 99, "match_confidence": "exact",
        })
        assert mapping.isco_group == "9112"
        assert mapping.eqf_level == 2
        assert mapping.cor_label is None   # only the COR table may set it

    def test_a_malformed_cor_code_fails_loudly(self):
        """Better a repair round-trip than a plausible six digits nobody checked."""
        with pytest.raises(ValidationError):
            OccupationMapping.model_validate(
                {"occupation_canonical": "X", "cor_code": "12345"})

    def test_no_cor_code_means_no_isco_group(self):
        mapping = OccupationMapping.model_validate({"occupation_canonical": "X"})
        assert mapping.cor_code is None and mapping.isco_group is None

    def test_the_selector_carries_no_salary(self):
        """The draft law has more than one public variant. A number stored here
        would have to be re-extracted every time it moves; a selector is
        re-costed by a script."""
        from schema_models import GridSelector
        fields = set(GridSelector.model_fields)
        assert not {f for f in fields if "salar" in f or "lei" in f or "coef" in f}

    def test_the_occupation_prompt_exists(self):
        import json
        prompts = json.loads((REPO_ROOT / "models_config.json").read_text(encoding="utf-8"))["prompts"]
        assert "occupation_v1" in prompts and len(prompts["occupation_v1"]) > 500


class TestProviderSchemas:
    @pytest.mark.parametrize("version", ["v2", "v3", "v4"])
    def test_openai_strict_schema_builds(self, version):
        schema = json_schema_for(version)
        assert schema["strict"] is True and schema["schema"]["type"] == "object"

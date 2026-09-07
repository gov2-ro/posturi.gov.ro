"""Prompt-v3 extraction schema: controlled vocabularies and v2 compatibility.

v3's whole purpose is that a filter — and later a Europass CV — can be matched
against a posting. That only works if the match keys are controlled values, so
these tests are mostly about the vocabularies holding.
"""

import json
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from schema_models import (  # noqa: E402
    EXTRACTION_MODELS,
    STUDY_LEVEL_TO_EQF,
    JobPostingExtraction,
    JobPostingExtractionV3,
    json_schema_for,
    model_for_version,
)


class TestVersionRegistry:
    def test_v2_and_v3_are_registered(self):
        assert model_for_version("v2") is JobPostingExtraction
        assert model_for_version("v3") is JobPostingExtractionV3

    def test_unknown_version_is_free_form(self):
        """v1 predates structured output and must keep the loose JSON path."""
        assert model_for_version("v1") is None

    def test_every_registered_version_has_a_prompt(self):
        cfg = json.loads((REPO_ROOT / "models_config.json").read_text())
        for version in EXTRACTION_MODELS:
            assert cfg["prompts"].get(version), f"prompt {version} missing from models_config.json"

    @pytest.mark.parametrize("version", ["v2", "v3"])
    def test_openai_strict_schema_builds(self, version):
        schema = json_schema_for(version)
        assert schema["strict"] is True
        assert schema["schema"]["additionalProperties"] is False
        # strict mode requires every property to be listed as required
        props = schema["schema"]["properties"]
        assert set(schema["schema"]["required"]) == set(props)


class TestBackwardCompatibility:
    """A v3 row must render on pages written for v2, untouched."""

    V2_KEYS = [
        "responsibilities", "educationRequirements", "experienceRequirements",
        "qualifications", "skills", "baseSalary", "jobBenefits", "workHours",
        "jobLocation", "application_docs", "application_fee", "application_contact",
    ]

    def test_v3_is_a_superset_of_v2(self):
        assert set(JobPostingExtraction.model_fields) <= set(JobPostingExtractionV3.model_fields)

    def test_every_v2_key_survives_a_v3_dump(self):
        dumped = JobPostingExtractionV3().model_dump()
        for key in self.V2_KEYS:
            assert key in dumped

    def test_a_bare_v2_payload_validates_as_v3(self):
        payload = {"educationRequirements": "Studii superioare.", "skills": "- Excel"}
        obj = JobPostingExtractionV3.model_validate(payload)
        assert obj.educationRequirements == "Studii superioare."
        assert obj.skill_list == []          # structured layer simply stays empty


class TestEducation:
    def test_eqf_mapping_covers_every_study_level(self):
        levels = JobPostingExtractionV3.model_fields  # touch model to ensure import
        assert set(STUDY_LEVEL_TO_EQF) == {
            "generala", "profesionala", "liceala", "postliceala",
            "licenta", "master", "doctorat",
        }
        assert sorted(STUDY_LEVEL_TO_EQF.values()) == [2, 3, 4, 5, 6, 7, 8]
        assert levels  # silence linters

    def test_alternative_fields_split_into_separate_entries(self):
        """The point of the exercise: "geografie/silvicultură/geologie" is three
        matchable fields, not one unmatchable string."""
        obj = JobPostingExtractionV3.model_validate({
            "education": {
                "minimum_level": "licenta", "eqf_level": 6,
                "fields_of_study": [
                    {"label_ro": "geografie", "isced_field": "05_stiinte_naturale_matematica"},
                    {"label_ro": "silvicultură", "isced_field": "08_agricultura_silvicultura_veterinara"},
                    {"label_ro": "inginerie geodezică", "isced_field": "07_inginerie_constructii"},
                ],
            }
        })
        assert len(obj.education.fields_of_study) == 3
        assert obj.education.fields_of_study[1].isced_field.startswith("08_")

    def test_invalid_study_level_is_rejected(self):
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"education": {"minimum_level": "phd"}})

    def test_eqf_level_is_bounded(self):
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"education": {"eqf_level": 9}})

    def test_no_field_constraint_means_empty_list(self):
        obj = JobPostingExtractionV3.model_validate({"education": {"minimum_level": "liceala"}})
        assert obj.education.fields_of_study == []


class TestSkills:
    def test_a_sentence_becomes_a_tag_with_proficiency(self):
        obj = JobPostingExtractionV3.model_validate({
            "skill_list": [{
                "label": "GIS", "type": "knowledge", "proficiency": "avansat",
                "required": True, "evidence": "Cunoștințe avansate de operare sisteme GIS",
            }]
        })
        skill = obj.skill_list[0]
        assert (skill.label, skill.type, skill.proficiency) == ("GIS", "knowledge", "avansat")

    def test_skill_type_uses_the_esco_pillars(self):
        for pillar in ("knowledge", "skill", "transversal", "language"):
            JobPostingExtractionV3.model_validate({"skill_list": [{"label": "x", "type": pillar}]})
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"skill_list": [{"label": "x", "type": "hard"}]})

    def test_advantage_is_not_a_requirement(self):
        obj = JobPostingExtractionV3.model_validate(
            {"skill_list": [{"label": "AutoCAD", "required": False}]}
        )
        assert obj.skill_list[0].required is False

    def test_proficiency_vocabulary(self):
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate(
                {"skill_list": [{"label": "x", "proficiency": "expert"}]}
            )


class TestLanguagesAndCredentials:
    def test_cefr_levels(self):
        obj = JobPostingExtractionV3.model_validate({
            "language_list": [{"language": "engleză", "iso_code": "en", "cefr": "B2"}]
        })
        assert obj.language_list[0].cefr == "B2"

    def test_invalid_cefr_is_rejected(self):
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"language_list": [{"language": "x", "cefr": "B3"}]})

    def test_credentials_are_separate_from_skills(self):
        """A licence is a document you hold, not an ability — the hardest filter."""
        obj = JobPostingExtractionV3.model_validate({
            "credentials": [{"label": "permis categoria B", "kind": "permis_conducere"}]
        })
        assert obj.credentials[0].kind == "permis_conducere"
        assert obj.skill_list == []


class TestExperience:
    def test_months_expressed_as_a_fraction(self):
        obj = JobPostingExtractionV3.model_validate({"experience": {"years_minimum": 0.5}})
        assert obj.experience.years_minimum == 0.5

    def test_specialty_versus_general_tenure(self):
        obj = JobPostingExtractionV3.model_validate(
            {"experience": {"years_minimum": 3, "in_specialty": True}}
        )
        assert obj.experience.in_specialty is True

    def test_explicit_none_required(self):
        obj = JobPostingExtractionV3.model_validate({"experience": {"none_required": True}})
        assert obj.experience.none_required is True

    def test_absurd_tenure_is_rejected(self):
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"experience": {"years_minimum": 99}})


class TestClassification:
    def test_policy_domain_vocabulary(self):
        obj = JobPostingExtractionV3.model_validate(
            {"policy_domains": ["mediu", "tehnologia_informatiei"]}
        )
        assert obj.policy_domains == ["mediu", "tehnologia_informatiei"]
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"policy_domains": ["cooking"]})

    def test_exam_stages_vocabulary(self):
        obj = JobPostingExtractionV3.model_validate(
            {"exam_stages": ["selectie_dosare", "proba_scrisa", "interviu"]}
        )
        assert len(obj.exam_stages) == 3


# ------------------------------------------------ provider branch integrity

class TestProviderBranches:
    """`make_generator` builds a closure per provider; a name error inside one
    only surfaces when that provider is actually called.

    This is not hypothetical: renaming `_validate_v2` → `_validate_extraction`
    left three call sites behind (OpenAI, Anthropic, DeepSeek) and the failure
    only appeared as `name '_validate_v2' is not defined` mid-run, after the
    Gemini path had already been exercised and looked fine.
    """

    @staticmethod
    def _module():
        import importlib.util

        spec = importlib.util.spec_from_file_location(
            "llmschema_under_test", REPO_ROOT / "llm-schema.py"
        )
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        return mod

    def test_no_stale_helper_names_remain(self):
        source = (REPO_ROOT / "llm-schema.py").read_text()
        assert "_validate_v2" not in source, "stale reference to the pre-rename helper"

    def test_every_name_used_in_a_provider_branch_resolves(self):
        """Compile-time check that each generate() closure has no free variable
        that is neither a module global nor a local of make_generator."""
        import ast

        source = (REPO_ROOT / "llm-schema.py").read_text()
        tree = ast.parse(source)
        module_names = {
            n.name for node in ast.walk(tree)
            if isinstance(node, (ast.Import, ast.ImportFrom)) for n in node.names
        }
        module_names |= {
            node.name for node in tree.body
            if isinstance(node, (ast.FunctionDef, ast.ClassDef))
        }
        module_names |= {
            t.id for node in tree.body if isinstance(node, ast.Assign)
            for t in node.targets if isinstance(t, ast.Name)
        }

        gen = next(n for n in ast.walk(tree)
                   if isinstance(n, ast.FunctionDef) and n.name == "make_generator")
        # Names bound anywhere inside make_generator (params, assignments, imports).
        bound = {a.arg for a in gen.args.args}
        for node in ast.walk(gen):
            if isinstance(node, ast.Name) and isinstance(node.ctx, ast.Store):
                bound.add(node.id)
            elif isinstance(node, (ast.Import, ast.ImportFrom)):
                bound |= {a.asname or a.name.split(".")[0] for a in node.names}
            elif isinstance(node, ast.FunctionDef):
                bound.add(node.name)
                bound |= {a.arg for a in node.args.args}
            elif isinstance(node, ast.ExceptHandler) and node.name:
                bound.add(node.name)

        import builtins

        known = bound | module_names | set(dir(builtins))
        unresolved = {
            n.id for n in ast.walk(gen)
            if isinstance(n, ast.Name) and isinstance(n.ctx, ast.Load) and n.id not in known
        }
        assert not unresolved, f"undefined name(s) in a provider branch: {sorted(unresolved)}"

    def test_validator_dispatches_on_version(self):
        mod = self._module()
        payload = {"educationRequirements": "Studii superioare.",
                   "skill_list": [{"label": "GIS", "type": "knowledge"}]}
        # v2 has no skill_list — the extra key is dropped, not an error.
        assert "skill_list" not in mod._validate_extraction(dict(payload), "v2")
        # v3 keeps it.
        assert mod._validate_extraction(dict(payload), "v3")["skill_list"][0]["label"] == "GIS"


class TestMultiRolePostings:
    """8.4% of active postings advertise several roles with different
    requirements. The first v3 run returned all-nulls for those — the model
    would not pick between "minim 3 ani" and "minim 7 ani", so education,
    skills, occupation and seniority all came back empty.
    """

    def test_positions_is_empty_for_an_ordinary_posting(self):
        obj = JobPostingExtractionV3.model_validate({"occupation_title": "Referent"})
        assert obj.positions == []

    def test_two_roles_keep_their_own_requirements(self):
        obj = JobPostingExtractionV3.model_validate({
            "experience": {"years_minimum": 3},          # flat = first role
            "positions": [
                {"title": "expert IT senior", "count": 1,
                 "experience": {"years_minimum": 3, "in_specialty": True},
                 "education": {"minimum_level": "licenta", "eqf_level": 6,
                               "fields_of_study": [{"label_ro": "informatică", "isced_field": "06_tic"}]}},
                {"title": "expert administrație publică I", "count": 2,
                 "experience": {"years_minimum": 7, "in_specialty": True}},
            ],
        })
        assert [p.count for p in obj.positions] == [1, 2]
        assert [p.experience.years_minimum for p in obj.positions] == [3, 7]
        assert obj.positions[0].education.fields_of_study[0].isced_field == "06_tic"

    def test_flat_fields_still_describe_the_first_role(self):
        """A consumer that ignores `positions` must still get usable data."""
        obj = JobPostingExtractionV3.model_validate({
            "experience": {"years_minimum": 3},
            "positions": [{"title": "A", "experience": {"years_minimum": 3}},
                          {"title": "B", "experience": {"years_minimum": 7}}],
        })
        assert obj.experience.years_minimum == obj.positions[0].experience.years_minimum

    def test_a_position_requires_a_title(self):
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"positions": [{"count": 2}]})

    def test_seat_count_must_be_positive(self):
        with pytest.raises(Exception):
            JobPostingExtractionV3.model_validate({"positions": [{"title": "A", "count": 0}]})


class TestPromptGuardrails:
    """The prompt fixes that came out of the first real run. Asserted against
    the prompt text so they cannot be silently dropped in a later edit."""

    @staticmethod
    def _v3_prompt():
        return json.loads((REPO_ROOT / "models_config.json").read_text())["prompts"]["v3"]

    def test_field_labels_must_be_normalised(self):
        """The run returned 'juridic', 'medical', 'specialitate' as fields of study."""
        prompt = self._v3_prompt()
        assert "Normalise each label" in prompt
        assert "specialitate" in prompt

    def test_bibliography_is_capped(self):
        """One posting produced 37 topics, several in genitive fragments."""
        assert "At most 12" in self._v3_prompt()

    def test_multi_role_instruction_present(self):
        prompt = self._v3_prompt()
        assert "MORE THAN ONE distinct role" in prompt
        assert "positions" in prompt

    def test_prompt_has_a_multi_role_example(self):
        assert "Example 3" in self._v3_prompt()

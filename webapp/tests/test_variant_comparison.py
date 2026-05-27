"""Tests for the rewritten variant_comparison view and _section_status helper."""
import pytest
from django.test import Client

from apps.jobs.models import Employer, JobPosting, JobPostingSchemaVariant
from apps.jobs.views import _section_status


# ---------------------------------------------------------------------------
# Unit tests for _section_status
# ---------------------------------------------------------------------------

class TestSectionStatus:

    def test_all_none_is_all_null(self):
        assert _section_status([None, None, None], "responsibilities") == "all_null"

    def test_empty_string_treated_as_null(self):
        assert _section_status(["", None, ""], "responsibilities") == "all_null"

    def test_identical_strings_is_agree(self):
        assert _section_status(["Studii superioare", "Studii superioare"], "educationRequirements") == "agree"

    def test_case_whitespace_normalization_agree(self):
        # Differ only in case and extra whitespace
        assert _section_status(["Studii  SUPERIOARE", "studii superioare"], "educationRequirements") == "agree"

    def test_bullet_stripping_agree(self):
        # One variant has bullet list, other is prose — normalize to same
        assert _section_status(["- item one\n- item two", "item one item two"], "responsibilities") == "agree"

    def test_partial_some_null(self):
        assert _section_status(["Studii superioare", None, "Studii superioare"], "educationRequirements") == "partial"

    def test_diverge_two_distinct_non_null(self):
        assert _section_status(["Studii superioare", "Studii medii"], "educationRequirements") == "diverge"

    def test_diverge_with_some_null(self):
        # ≥2 distinct non-null → diverge regardless of nulls
        assert _section_status(["Studii superioare", None, "Studii medii"], "educationRequirements") == "diverge"

    def test_dict_equality_agree(self):
        d1 = {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"}
        d2 = {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"}
        assert _section_status([d1, d2], "baseSalary") == "agree"

    def test_dict_key_order_independent(self):
        d1 = {"minValue": 4500, "currency": "RON", "maxValue": 6000, "unitText": "MONTH"}
        d2 = {"unitText": "MONTH", "maxValue": 6000, "minValue": 4500, "currency": "RON"}
        assert _section_status([d1, d2], "baseSalary") == "agree"

    def test_dict_diverge(self):
        d1 = {"minValue": 4500, "currency": "RON"}
        d2 = {"minValue": 5000, "currency": "RON"}
        assert _section_status([d1, d2], "baseSalary") == "diverge"

    def test_single_value_agree(self):
        assert _section_status(["some value"], "responsibilities") == "agree"

    def test_single_value_with_null_partial(self):
        assert _section_status(["some value", None], "responsibilities") == "partial"


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture
def posting_with_4_v2_variants(db):
    """4 variants (gemini, anthropic, openai, deepseek) all prompt_version=v2.

    Row statuses in the fixture data:
      - educationRequirements: agree (all "Studii superioare")
      - qualifications:        partial (2 have value, 2 are None)
      - responsibilities:      diverge (variant 2 has a different value)
      - workHours:             all_null (all None)
    """
    employer, _ = Employer.objects.get_or_create(
        name="Test Employer VC", defaults={"slug": "test-employer-vc"}
    )
    posting = JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-4variants/",
        title="Inspector cu 4 variante",
        employer=employer,
    )
    variants_data = [
        ("gemini",    "gemini-1.5-pro",      "Elaborează documente",  "Aviz CSAT"),
        ("anthropic", "claude-3-5-sonnet",   "Verifică dosare",       None),
        ("openai",    "gpt-4o",              "Elaborează documente",  None),
        ("deepseek",  "deepseek-chat",       "Elaborează documente",  "Aviz CSAT"),
    ]
    for provider, model, responsibilities, qualifications in variants_data:
        JobPostingSchemaVariant.objects.create(
            posting=posting,
            provider=provider,
            model=model,
            prompt_version="v2",
            schema_json={
                "responsibilities": responsibilities,
                "educationRequirements": "Studii superioare",
                "qualifications": qualifications,
                "workHours": None,
            },
        )
    return posting


@pytest.fixture
def posting_with_v1_and_v2_variants(db):
    """1 v2 variant + 1 v1 variant with legacy keys."""
    employer, _ = Employer.objects.get_or_create(
        name="Test Employer BC", defaults={"slug": "test-employer-bc"}
    )
    posting = JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-backcompat/",
        title="Referent backcompat",
        employer=employer,
    )
    # v2 variant — has educationRequirements and baseSalary
    JobPostingSchemaVariant.objects.create(
        posting=posting,
        provider="gemini",
        model="gemini-1.5-pro",
        prompt_version="v2",
        schema_json={
            "responsibilities": "Elaborează proiecte",
            "educationRequirements": "Studii superioare",
            "baseSalary": {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"},
        },
    )
    # v1 variant — flat strings, legacy keys, no educationRequirements
    JobPostingSchemaVariant.objects.create(
        posting=posting,
        provider="openai",
        model="gpt-3.5-turbo",
        prompt_version="v1",
        schema_json={
            "responsibilities": "Elaborează proiecte",
            "qualifications": "Studii superioare juridice",
            "salary": "4500–6000 RON/lună",
            "work_conditions": "Sediu, 8h/zi",
            "educationRequirements": None,
        },
    )
    return posting


# ---------------------------------------------------------------------------
# Integration tests — view with full page response
# ---------------------------------------------------------------------------

@pytest.mark.django_db
def test_rendered_view_returns_200_with_all_non_null_statuses(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=rendered"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    assert "agree" in content
    assert "partial" in content
    assert "diverge" in content


@pytest.mark.django_db
def test_all_null_row_absent_from_rendered_view(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=rendered"
    )
    assert resp.status_code == 200
    # "Program de lucru" is the label for workHours — all_null row must be omitted
    assert "Program de lucru" not in resp.content.decode()


@pytest.mark.django_db
def test_diff_only_collapses_agree_rows(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=diff-only"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # agree row is collapsed: shows "variants agree" merged cell
    assert "variants agree" in content
    # diverge row is still expanded
    assert "diverge" in content


@pytest.mark.django_db
def test_diff_only_diverge_row_has_multiple_cells(posting_with_4_v2_variants):
    """In diff-only mode, diverge rows must not be collapsed."""
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=diff-only"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # Both distinct responsibility values must appear
    assert "Elaborează documente" in content
    assert "Verifică dosare" in content


@pytest.mark.django_db
def test_empty_state_when_no_matching_prompt(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v1"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # Empty-state yellow box with the CLI hint
    assert "llm-schema.py" in content
    # No matrix rows
    assert "agree" not in content
    assert "diverge" not in content


@pytest.mark.django_db
def test_json_view_shows_raw_json(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=json"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # JSON view should expose the raw string values
    assert "Elaborează documente" in content
    assert "Studii superioare" in content


# ---------------------------------------------------------------------------
# Back-compat test: v1 + v2 variants mixed
# ---------------------------------------------------------------------------

@pytest.mark.django_db
def test_renderer_backcompat_v1_v2_mixed(posting_with_v1_and_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_v1_and_v2_variants.pk}/variants/"
        "?prompt=v1&prompt=v2&view=rendered"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # v1-only "salary" key renders under "Salarizare" label
    assert "Salarizare" in content
    # v1-only "work_conditions" key renders under "Condiții de muncă" label
    assert "Condiții de muncă" in content
    # v2-only "educationRequirements" → "Studii" label appears (partial: v2 has it, v1 null)
    assert "Studii" in content
    # partial status because v1 variant has null for educationRequirements
    assert "partial" in content

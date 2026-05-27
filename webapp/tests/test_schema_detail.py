"""Tests for schema_json display section rendering in job_detail view.

Covers both prompt-v2 (Schema.org-aligned) and prompt-v1 (legacy) shapes —
the renderer must handle both so postings extracted before the v1→v2 cutover
keep displaying correctly during the transition.
"""
import pytest
from apps.jobs.models import Employer, JobPosting
from django.test import Client


# ---------------------------------------------------------------------------
# Unit tests for _render_schema_sections — v2 shape
# ---------------------------------------------------------------------------

def test_render_schema_sections_v2_all_present():
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": "- Elaborează proiecte\n- Verifică documente",
        "educationRequirements": "Studii superioare juridice.",
        "experienceRequirements": "Vechime minim 2 ani.",
        "qualifications": "- Aviz CSAT pentru acces clasificat Secret.",
        "skills": "- Cunoştinţe PC\n- Limbă engleză",
        "baseSalary": {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"},
        "jobBenefits": "- Tichete de masă",
        "workHours": "8h/zi.",
        "jobLocation": "Sediul Primăriei sector 1, București.",
        "application_docs": "- Formular înscriere\n- Copia CI",
        "application_fee": {"amount": 50, "currency": "RON", "account": "RO12TREZ000", "details": None},
        "application_contact": {"name": "Biroul RU", "phone": "0212345678", "email": "ru@x.ro", "address": "Sediu"},
    }
    sections = _render_schema_sections(schema)
    assert sections is not None
    labels = [s["label"] for s in sections]
    assert "Atribuții principale" in labels
    assert "Studii" in labels
    assert "Experiență" in labels
    assert "Condiții specifice" in labels
    assert "Competențe" in labels
    assert "Dosar de candidatură" in labels
    assert "Salarizare" in labels
    assert "Taxă de participare" in labels
    assert "Contact pentru depunere" in labels
    assert "Beneficii" in labels
    assert "Program de lucru" in labels
    assert "Locație" in labels


def test_render_base_salary_range():
    from apps.jobs.views import _render_base_salary
    out = _render_base_salary({"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"})
    assert out == "4500–6000 RON/lună"


def test_render_base_salary_single_value():
    from apps.jobs.views import _render_base_salary
    out = _render_base_salary({"minValue": 4500, "maxValue": 4500, "currency": "RON", "unitText": "MONTH"})
    assert out == "4500 RON/lună"


def test_render_base_salary_null_returns_empty():
    from apps.jobs.views import _render_base_salary
    assert _render_base_salary({"minValue": None, "maxValue": None, "currency": "RON", "unitText": "MONTH"}) == ""
    assert _render_base_salary(None) == ""


def test_render_application_fee_full():
    from apps.jobs.views import _render_application_fee
    out = _render_application_fee({"amount": 50, "currency": "RON", "account": "RO12TREZ000", "details": "achitat în avans"})
    assert "50 RON" in out
    assert "RO12TREZ000" in out
    assert "achitat în avans" in out


def test_render_application_fee_amount_only():
    from apps.jobs.views import _render_application_fee
    out = _render_application_fee({"amount": 50, "currency": "RON", "account": None, "details": None})
    assert out == "50 RON"


def test_render_application_contact_partial():
    from apps.jobs.views import _render_application_contact
    out = _render_application_contact({"name": "Biroul RU", "phone": None, "email": "ru@x.ro", "address": None})
    assert "Biroul RU" in out
    assert "ru@x.ro" in out
    assert "Telefon" not in out
    assert "Adresă" not in out


# ---------------------------------------------------------------------------
# Unit tests for _render_schema_sections — v1 back-compat
# ---------------------------------------------------------------------------

def test_render_schema_sections_v1_all_present():
    """v1 used flat string for qualifications + salary + application_fee +
    work_conditions. These shouldn't break the renderer even though some keys
    have been renamed in the section order."""
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": "- Elaborează proiecte\n- Verifică documente",
        "qualifications": "Studii superioare juridice",
        "skills": "- Cunoştinţe PC\n- Limbă engleză",
        "application_docs": "- CV\n- Copii studii",
        "salary": None,
        "application_fee": "150 lei",
        "work_conditions": "Sediu, 8h/zi",
    }
    sections = _render_schema_sections(schema)
    assert sections is not None
    labels = [s["label"] for s in sections]
    assert "Atribuții principale" in labels
    assert "Condiții specifice" in labels  # qualifications under v2 label
    assert "Competențe" in labels
    assert "Dosar de candidatură" in labels
    assert "Taxă de participare" in labels  # v1 string fee renders unchanged
    assert "Condiții de muncă" in labels    # v1-only key still labelled
    assert "Salarizare" not in labels  # salary is null


def test_render_schema_sections_v1_string_application_fee_back_compat():
    """v1 stored application_fee as a markdown string, not a dict. The
    renderer should fall through to the markdown path."""
    from apps.jobs.views import _render_schema_sections
    schema = {"application_fee": "150 lei, IBAN RO12TREZ000"}
    sections = _render_schema_sections(schema)
    assert sections is not None
    assert sections[0]["label"] == "Taxă de participare"
    assert "150 lei" in sections[0]["html"]


def test_render_schema_sections_null_returns_none():
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": None,
        "qualifications": None,
        "skills": None,
        "application_docs": None,
        "salary": None,
        "application_fee": None,
        "work_conditions": None,
    }
    assert _render_schema_sections(schema) is None


def test_render_schema_sections_empty_dict_returns_none():
    from apps.jobs.views import _render_schema_sections
    assert _render_schema_sections({}) is None


def test_render_schema_sections_html_in_output():
    from apps.jobs.views import _render_schema_sections
    schema = {"responsibilities": "- Elaborează proiecte"}
    sections = _render_schema_sections(schema)
    assert sections is not None
    resp_section = sections[0]
    assert resp_section["label"] == "Atribuții principale"
    assert "<ul>" in resp_section["html"] or "<li>" in resp_section["html"]


# ---------------------------------------------------------------------------
# Integration tests for job_detail view
# ---------------------------------------------------------------------------

@pytest.fixture
def posting_with_schema(db):
    employer, _ = Employer.objects.get_or_create(name="Test Employer", defaults={"slug": "test-employer"})
    return JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-schema-job/",
        title="Inspector principal",
        employer=employer,
        body_markdown="Angajam inspector.",
        schema_json={
            "responsibilities": "- Verifică documente",
            "educationRequirements": "Studii superioare",
            "experienceRequirements": None,
            "qualifications": None,
            "skills": None,
            "application_docs": "- CV",
            "baseSalary": None,
            "application_fee": None,
            "application_contact": None,
            "jobBenefits": None,
            "workHours": None,
            "jobLocation": None,
        },
    )


@pytest.fixture
def posting_without_schema(db):
    employer, _ = Employer.objects.get_or_create(name="Test Employer 2", defaults={"slug": "test-employer-2"})
    return JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-no-schema-job/",
        title="Referent",
        employer=employer,
        body_markdown="Angajam referent pentru departamentul administrativ.",
        schema_json=None,
    )


@pytest.mark.django_db
def test_detail_with_schema_json_passes_sections(posting_with_schema):
    client = Client()
    resp = client.get(f"/job/{posting_with_schema.pk}/")
    assert resp.status_code == 200
    assert resp.context["schema_sections"] is not None
    assert len(resp.context["schema_sections"]) >= 2
    assert "schema_sections" in resp.context


@pytest.mark.django_db
def test_detail_without_schema_json_passes_body_html(posting_without_schema):
    client = Client()
    resp = client.get(f"/job/{posting_without_schema.pk}/")
    assert resp.status_code == 200
    assert resp.context["schema_sections"] is None
    assert resp.context["body_html"] != ""

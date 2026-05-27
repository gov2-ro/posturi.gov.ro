"""Tests for schema_json display section rendering in job_detail view."""
import pytest
from apps.jobs.models import Employer, JobPosting
from django.test import Client


# ---------------------------------------------------------------------------
# Unit tests for _render_schema_sections
# ---------------------------------------------------------------------------

def test_render_schema_sections_all_present():
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": "- Elaborează proiecte\n- Verifică documente",
        "qualifications": "Studii superioare juridice",
        "skills": "- Cunoştinţe PC\n- Limbă engleză",
        "application_docs": "- CV\n- Copii studii",
        "salary": None,
        "application_fee": "150 lei",
        "work_conditions": None,
    }
    sections = _render_schema_sections(schema)
    assert sections is not None
    labels = [s["label"] for s in sections]
    assert "Atribuții principale" in labels
    assert "Condiții de participare" in labels
    assert "Competențe" in labels
    assert "Dosar de candidatură" in labels
    assert "Taxă de participare" in labels
    # null fields must NOT appear
    assert "Salarizare" not in labels
    assert "Condiții de muncă" not in labels


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


def test_render_schema_sections_html_in_output():
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": "- Elaborează proiecte",
        "qualifications": None,
        "skills": None,
        "application_docs": None,
        "salary": None,
        "application_fee": None,
        "work_conditions": None,
    }
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
            "qualifications": "Studii superioare",
            "skills": None,
            "application_docs": "- CV",
            "salary": None,
            "application_fee": None,
            "work_conditions": None,
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


def test_detail_with_schema_json_passes_sections(posting_with_schema):
    client = Client()
    resp = client.get(f"/job/{posting_with_schema.pk}/")
    assert resp.status_code == 200
    assert resp.context["schema_sections"] is not None
    assert len(resp.context["schema_sections"]) >= 2
    assert "schema_sections" in resp.context


def test_detail_without_schema_json_passes_body_html(posting_without_schema):
    client = Client()
    resp = client.get(f"/job/{posting_without_schema.pk}/")
    assert resp.status_code == 200
    assert resp.context["schema_sections"] is None
    assert resp.context["body_html"] != ""

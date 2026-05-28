"""Tests for work-condition inference helpers and new view filter params."""
import pytest
from apps.jobs.management.commands.infer_postings import (
    _extract_salary_range,
    _infer_computer,
    _infer_experience,
    _infer_remote,
    _infer_work_type,
)


# ---------------------------------------------------------------------------
# _infer_work_type
# ---------------------------------------------------------------------------

class TestInferWorkType:
    def test_norma_intreaga_explicit(self):
        wt, conf = _infer_work_type("Norma de lucru: normă întreagă 8h/zi.", None)
        assert wt == "norma_intreaga"
        assert conf >= 0.95  # 2 matches

    def test_norma_intreaga_single(self):
        wt, conf = _infer_work_type("Program: norma intreaga.", None)
        assert wt == "norma_intreaga"
        assert conf == 0.75

    def test_norma_partiala(self):
        wt, conf = _infer_work_type("Post cu normă parțială, 4h/zi.", None)
        assert wt == "norma_partiala"
        assert conf >= 0.95

    def test_norma_partiala_part_time(self):
        wt, conf = _infer_work_type("Angajare part-time disponibilă.", None)
        assert wt == "norma_partiala"
        assert conf == 0.75

    def test_schimburi(self):
        wt, conf = _infer_work_type("Postul presupune lucrul în ture și tură de noapte.", None)
        assert wt == "schimburi"
        assert conf >= 0.95

    def test_work_hours_fallback(self):
        wt, conf = _infer_work_type("", "Program 40h/săptămână")
        assert wt == "norma_intreaga"
        assert conf == 0.70

    def test_no_match_short_body(self):
        wt, conf = _infer_work_type("Scurt.", None)
        assert wt is None
        assert conf == 0.0

    def test_no_match_long_body(self):
        body = "A" * 300
        wt, conf = _infer_work_type(body, None)
        assert wt is None
        assert conf == 0.0

    def test_diacritic_tolerant(self):
        # normalized form: "norma partiala"
        wt, conf = _infer_work_type("Norma partiala de 0,5 norma", None)
        assert wt == "norma_partiala"


# ---------------------------------------------------------------------------
# _infer_remote
# ---------------------------------------------------------------------------

class TestInferRemote:
    def test_telemunca(self):
        assert _infer_remote("Postul permite telemuncă.") is True

    def test_remote(self):
        assert _infer_remote("Lucru remote posibil.") is True

    def test_hibrid(self):
        assert _infer_remote("Program hibrid.") is True

    def test_munca_la_distanta(self):
        assert _infer_remote("Muncă la distanță acceptată.") is True

    def test_not_mentioned(self):
        assert _infer_remote("Postul este la sediul instituției.") is None

    def test_empty(self):
        assert _infer_remote("") is None


# ---------------------------------------------------------------------------
# _infer_computer
# ---------------------------------------------------------------------------

class TestInferComputer:
    def test_ms_office(self):
        req, level = _infer_computer("Cunoștințe Microsoft Office necesare.", [])
        assert req is True
        assert level == "basic"

    def test_excel_skill(self):
        req, level = _infer_computer("Competențe Excel.", [])
        assert req is True

    def test_seap(self):
        req, level = _infer_computer("Experiență în lucrul cu SEAP.", [])
        assert req is True

    def test_advanced_level(self):
        req, level = _infer_computer("Cunoștințe avansate de calculator.", [])
        assert req is True
        assert level == "advanced"

    def test_explicit_not_required(self):
        req, level = _infer_computer(
            "Nu se solicită cunoștințe de calculator pentru acest post.", []
        )
        assert req is False
        assert level is None

    def test_not_mentioned(self):
        req, level = _infer_computer("Post de paznic la intrare.", [])
        assert req is None
        assert level is None

    def test_existing_skills_excel(self):
        req, level = _infer_computer("Alte cerințe.", ["Excel", "Word"])
        assert req is True


# ---------------------------------------------------------------------------
# _extract_salary_range
# ---------------------------------------------------------------------------

class TestExtractSalaryRange:
    def test_min_max(self):
        schema = {"baseSalary": {"minValue": 4500, "maxValue": 6000, "currency": "RON"}}
        mn, mx = _extract_salary_range(schema)
        assert mn == 4500
        assert mx == 6000

    def test_equal(self):
        schema = {"baseSalary": {"minValue": 5000, "maxValue": 5000}}
        mn, mx = _extract_salary_range(schema)
        assert mn == 5000
        assert mx == 5000

    def test_no_salary(self):
        mn, mx = _extract_salary_range({"responsibilities": "foo"})
        assert mn is None
        assert mx is None

    def test_null_schema(self):
        mn, mx = _extract_salary_range(None)
        assert mn is None
        assert mx is None

    def test_float_values(self):
        schema = {"baseSalary": {"minValue": 3750.0, "maxValue": 4200.5}}
        mn, mx = _extract_salary_range(schema)
        assert mn == 3750
        assert mx == 4200


# ---------------------------------------------------------------------------
# _infer_experience (nu este cazul)
# ---------------------------------------------------------------------------

class TestInferExperience:
    def test_numeric_years(self):
        yrs, flag = _infer_experience("Minim 3 ani experienta in specialitate.")
        assert yrs == 3
        assert flag is False

    def test_nu_este_cazul(self):
        yrs, flag = _infer_experience("Experiență: nu este cazul.")
        assert yrs == 0
        assert flag is True

    def test_nu_se_solicita_experienta(self):
        yrs, flag = _infer_experience("Nu se solicită experiență în muncă.")
        assert yrs == 0
        assert flag is True

    def test_fara_experienta_munca(self):
        yrs, flag = _infer_experience("Fara experienta in munca.")
        assert yrs == 0
        assert flag is True

    def test_no_match_returns_none(self):
        yrs, flag = _infer_experience("Candidatul trebuie să cunoască legislația.")
        assert yrs is None
        assert flag is False

    def test_numeric_overrides_no_experience_phrase(self):
        # If a body somehow contains both, the numeric match wins
        yrs, flag = _infer_experience("Minim 2 ani experienta; nu este cazul pentru formare.")
        assert yrs == 2
        assert flag is False

    def test_diacritic_variants(self):
        # Diacritic-free variant should still match
        yrs, flag = _infer_experience("Experienta: nu este cazul.")
        assert yrs == 0
        assert flag is True


# ---------------------------------------------------------------------------
# View filter params (integration tests)
# ---------------------------------------------------------------------------

@pytest.mark.django_db
class TestConditionFilters:
    def _make_posting(self, inferred=None, **kwargs):
        from apps.jobs.models import Employer, JobPosting
        employer, _ = Employer.objects.get_or_create(name="Test Angajator")
        defaults = dict(
            title="Test Post",
            url=f"https://example.com/{id(inferred)}",
            employer=employer,
            inferred=inferred or {},
        )
        defaults.update(kwargs)
        return JobPosting.objects.create(**defaults)

    def test_work_type_filter(self, client):
        p1 = self._make_posting(
            inferred={"work_type": "norma_partiala"},
            url="https://example.com/p1",
        )
        p2 = self._make_posting(
            inferred={"work_type": "norma_intreaga"},
            url="https://example.com/p2",
        )
        resp = client.get("/?work_type=norma_partiala", HTTP_HX_REQUEST="true")
        assert resp.status_code == 200
        content = resp.content.decode()
        assert "norma_partiala" not in content or str(p1.pk) in content or "Normă parțială" in content

    def test_remote_filter(self, client):
        self._make_posting(
            inferred={"remote_eligible": True},
            url="https://example.com/remote1",
        )
        self._make_posting(
            inferred={"remote_eligible": None},
            url="https://example.com/remote2",
        )
        resp = client.get("/?remote=1", HTTP_HX_REQUEST="true")
        assert resp.status_code == 200

    def test_computer_solicitat_filter(self, client):
        self._make_posting(
            inferred={"requires_computer": True},
            url="https://example.com/comp1",
        )
        resp = client.get("/?computer=solicitat", HTTP_HX_REQUEST="true")
        assert resp.status_code == 200

    def test_computer_nesolicitat_filter(self, client):
        self._make_posting(
            inferred={"requires_computer": False},
            url="https://example.com/comp2",
        )
        resp = client.get("/?computer=nesolicitat", HTTP_HX_REQUEST="true")
        assert resp.status_code == 200

    def test_studies_level_filter(self, client):
        self._make_posting(
            inferred={"studies_required": "licenta"},
            url="https://example.com/stud1",
        )
        resp = client.get("/?studies_level=licenta", HTTP_HX_REQUEST="true")
        assert resp.status_code == 200

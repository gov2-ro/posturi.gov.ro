"""BETA-V5: the v5 extraction model, its prompt and the --resume accept-list.

docs/specs/2026-10-16-v5-extraction/README.md is the design. What is pinned here:
  * the keys v5 drops are never stored, whatever the model emits;
  * an unknown value in one of the NEW closed lists degrades, it never voids the
    extraction (the calendar stage list keeps its strict rule);
  * the prompt's examples are valid v5 answers and mention no dropped key;
  * with an accept-list, --resume leaves v4 rows alone until their source changes.
"""

import importlib.util
import json
import re
import sys
from pathlib import Path

import pytest
from pydantic import ValidationError

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import schema_models as sm  # noqa: E402

V5 = sm.JobPostingExtractionV5


@pytest.fixture(scope="module")
def llm():
    spec = importlib.util.spec_from_file_location("llmschema_v5_under_test", REPO_ROOT / "llm-schema.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["llmschema_v5_under_test"] = module
    spec.loader.exec_module(module)
    return module


def prompt() -> str:
    return json.loads((REPO_ROOT / "models_config.json").read_text(encoding="utf-8"))["prompts"]["v5"]


# ------------------------------------------------------------------ the model


def test_v5_is_registered():
    assert sm.model_for_version("v5") is V5
    assert "v5" in sm.STRUCTURED_VERSIONS
    assert sm.json_schema_for("v5")["name"] == "JobPostingExtractionV5"


def test_dropped_keys_are_never_stored():
    d = V5.model_validate({
        "educationRequirements": "x", "experienceRequirements": "y", "skills": "z", "application_docs": "- a",
        "contract": {"remote_mode": "la_sediu", "duration": "determinata"},
        "employer_context": {"parent_institution": "Ministerul Sănătății", "sector": "sanatate"},
    }).model_dump()
    for k in ("educationRequirements", "experienceRequirements", "skills", "application_docs"):
        assert d[k] is None, k
    assert d["contract"]["remote_mode"] is None
    assert d["employer_context"]["parent_institution"] is None


def test_new_closed_lists_degrade_instead_of_failing():
    d = V5.model_validate({
        "recruitment": {"kind": "concurs_intern"},
        "application": {"channels": ["Poșta", "e-mail", "fax", "email"]},
        "contract": {"post_status": "ocupat", "fte": 1.0, "end_date": "30.06.2027"},
        "documents": {"standard": ["CV", "adeverinta_somaj"], "specific": []},
        "vacancies_total": "PROJ-2024-118",
        "age_limit": {"min_years": "18", "max_years": 300},
    }).model_dump()
    assert d["recruitment"]["kind"] == "altele"
    assert d["application"]["channels"] == ["posta", "email"]
    assert d["contract"]["post_status"] is None
    assert d["contract"]["fte"] is None, "a full norm is not a fraction"
    assert d["contract"]["end_date"] == "2027-06-30"
    assert d["documents"]["standard"] == ["cv"]
    assert d["documents"]["specific"] == [{"label": "adeverinta somaj", "evidence": None, "roles": []}], \
        "an unknown dosar code is kept as a specific item, not lost"
    assert d["vacancies_total"] is None, "a project code is not a seat count"
    assert (d["age_limit"]["min_years"], d["age_limit"]["max_years"]) == (18, None)


def test_calendar_stage_list_stays_strict():
    with pytest.raises(ValidationError):
        V5.model_validate({"competition_calendar": {"events": [{"stage": "proba_de_dans"}]}})


def test_derived_facts():
    d = V5.model_validate({"contract": {"hours_per_week": 19.5},
                           "recruitment": {"kind": "promovare"}}).model_dump()
    assert d["contract"]["fte"] == 0.49, "fte from stated hours"
    assert d["contract"]["shift_work"] is None, "silent means null in v5, not false"
    assert d["recruitment"]["internal_only"] is True, "a promotion exam is internal"


def test_credentials_and_roles_carry_evidence():
    d = V5.model_validate({
        "credentials": [{"label": "permis B", "kind": "permis_conducere", "evidence": "Permis de conducere categoria B.",
                         "licence_category": "B"}],
        "positions": [{"title": "șofer", "credentials": [{"label": "permis C", "kind": "permis_conducere",
                                                          "evidence": "permis de conducere categoria C", "licence_category": "C"}]}],
    }).model_dump()
    assert d["credentials"][0]["evidence"] == "Permis de conducere categoria B."
    assert d["positions"][0]["credentials"][0]["licence_category"] == "C"


def test_v4_answers_still_validate_as_v4():
    """v5 is additive for consumers: nothing about the v4 model changed."""
    d = sm.JobPostingExtractionV4.model_validate({"contract": {"remote_mode": "la_sediu"},
                                                   "employer_context": {"parent_institution": "X"}}).model_dump()
    assert d["contract"]["remote_mode"] == "la_sediu"
    assert d["employer_context"]["parent_institution"] == "X"


# ------------------------------------------------------------------ the prompt


def test_prompt_examples_are_valid_v5_answers():
    examples = re.findall(r"^(\{.*\})$", prompt(), flags=re.M)
    assert len(examples) >= 4
    for ex in examples:
        V5.model_validate(json.loads(ex))


def test_prompt_mentions_dropped_keys_only_to_forbid_them():
    body = prompt().split("Now extract from the posting below")[0]
    for k in ("educationRequirements", "experienceRequirements", "remote_mode", "parent_institution", '"skills"'):
        assert k not in body, k
    assert "at least 5 words" in body, "evidence rule (README §3.4)"
    assert "never `nedeterminata` by assumption" in body, "manager contest rule (README §3.5)"


def test_prompt_lists_every_document_code():
    p = prompt()
    for code in sm.DOCUMENT_CODES:
        assert f"`{code}`" in p, code


# ------------------------------------------------------------------ --resume accept-list


def test_accept_list(llm, monkeypatch):
    monkeypatch.delenv("LLM_ACCEPT_PROMPT_VERSIONS", raising=False)
    assert llm.accepted_prompt_versions("v5") == ["v5"]
    monkeypatch.setenv("LLM_ACCEPT_PROMPT_VERSIONS", "v4, v5")
    assert llm.accepted_prompt_versions("v5") == ["v4", "v5"]
    assert llm.accepted_prompt_versions("v5", "v3") == ["v3", "v5"], "the flag overrides the environment"


KEY = ("deepseek", "deepseek-v4-flash")


@pytest.mark.django_db
@pytest.mark.parametrize("accepted, want", [
    (["v5"], {"v4 same source", "v4 source changed", "no schema"}),
    (["v4", "v5"], {"v4 source changed", "no schema"}),
])
def test_resume_with_accept_list_keeps_v4_rows_until_their_source_changes(llm, accepted, want):
    from django.db import connection

    from apps.jobs.models import Employer, JobPosting
    employer, _ = Employer.objects.get_or_create(name="Primăria V5", defaults={"slug": "primaria-v5"})
    rows = {
        "v4 same source": dict(schema_prompt_version="v4", schema_source_revision="a", detail_content_hash="a"),
        "v4 source changed": dict(schema_prompt_version="v4", schema_source_revision="a", detail_content_hash="b"),
        "v5 current": dict(schema_prompt_version="v5", schema_source_revision="a", detail_content_hash="a"),
        "no schema": dict(schema_json=None),
    }
    made = {}
    for i, (name, f) in enumerate(rows.items()):
        fields = dict(url=f"https://posturi.gov.ro/joburi/v5-{i}/", title="Referent", employer=employer,
                      schema_json={"x": 1}, schema_provider=KEY[0], schema_model=KEY[1])
        fields.update(f)
        made[JobPosting.objects.create(**fields).url] = name
    where, params = llm._selection_where(resume_key=(*KEY, accepted))
    with connection.cursor() as cur:
        cur.execute("SELECT url FROM jobs_jobposting" + where, params)
        got = {made[r[0]] for r in cur.fetchall() if r[0] in made}
    assert got == want


# ------------------------------------------------------------------ input dedup (v5 only)


def test_dedup_drops_only_paragraphs_the_attachment_carries_with_the_same_figures(llm):
    att = ("Calendarul concursului: proba scrisă are loc în data de 30.09.2026 la sediul instituției, ora 10. "
           "Dosarele se depun la registratura instituției până la data de 22.09.2026 inclusiv.")
    body = "\n\n".join([
        "Dosarele se depun la registratura instituției până la data de 22.09.2026 inclusiv.",       # same: dropped
        "Calendarul concursului: proba scrisă are loc în data de 25.09.2026 la sediul instituției, ora 10.",  # date differs: kept
        "Relații suplimentare la telefon 0259 123 456.",                                              # not in attachment: kept
    ])
    out = llm.dedup_body(body, att)
    assert "22.09.2026" not in out
    assert "25.09.2026" in out, "a paragraph whose date differs is never dropped"
    assert "0259 123 456" in out


def test_dedup_without_attachment_text_changes_nothing(llm):
    assert llm.dedup_body("Un paragraf.\n\nAlt paragraf.", "") == "Un paragraf.\n\nAlt paragraf."

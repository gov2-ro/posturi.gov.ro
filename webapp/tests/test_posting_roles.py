"""`posting_roles` — the role-scoped read model (DATA-04, first slice).

`role_rows` is a pure function, so most of this runs without Postgres or Django.
The last block drives the real `export()` against a fake Postgres cursor to prove
the rows reach the SQLite file with the rest of the export, and that `verify()`
refuses a build in which a posting has no role row (the PHP filters match through
that table, so a missing row would silently drop the posting from every search).
"""

import importlib.util
import json
import sqlite3
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import posting_roles as pr  # noqa: E402

REV = "ab12cd34ef56" + "0" * 52          # a 64-char source revision (detail_content_hash)


def flat(**over):
    """The posting-level columns `role_rows` reads, as the export computes them."""
    base = {
        "schema_source_revision": REV,
        "inf_studies_required": None, "v3_eqf_level": None, "v3_isced_fields": "[]",
        "inf_experience_years": None, "v3_skills": "[]", "v3_credentials": "[]",
        "v3_seniority_hint": "",
    }
    base.update(over)
    return base


ROLE_A = {
    "title": "Expert contabil", "count": 1,
    "education": {"minimum_level": "licenta", "eqf_level": 6,
                  "fields_of_study": [{"label_ro": "contabilitate", "isced_field": "04_afaceri_administratie_drept"}]},
    "experience": {"years_minimum": 5, "in_specialty": True, "none_required": False},
    "skill_list": [{"label": "Contabilitate"}, {"label": "Excel"}],
    "credentials": [{"label": "CECCAR", "kind": "autorizatie"}],
    "seniority_hint": "principal",
}
ROLE_B = {
    "title": "Referent", "count": 2,
    "education": {"minimum_level": "liceala", "eqf_level": 4, "fields_of_study": []},
    "experience": {"years_minimum": 0, "in_specialty": False, "none_required": True},
    "skill_list": [{"label": "Word"}],
    "credentials": [{"label": "Permis B", "kind": "permis_conducere"}],
}


def schema(*positions, **extra):
    return {"education": {"minimum_level": "master", "eqf_level": 7}, **extra,
            **({"positions": list(positions)} if positions else {})}


class TestOneRole:
    def test_a_single_positions_entry_is_one_decomposed_row(self):
        rows = pr.role_rows(flat(), schema(ROLE_A))
        assert len(rows) == 1
        r = rows[0]
        assert (r["ordinal"], r["decomposed"], r["title"], r["vacancy_count"]) == (1, 1, "Expert contabil", 1)
        assert (r["study_level"], r["eqf_level"]) == ("licenta", 6)
        assert r["isced_fields"] == '["04_afaceri_administratie_drept"]'
        assert (r["exp_years"], r["exp_in_specialty"], r["exp_none_required"]) == (5.0, 1, 0)
        assert r["skills"] == '["Contabilitate", "Excel"]'
        assert r["credentials"] == '["autorizatie"]'
        assert r["seniority_hint"] == "principal"
        assert r["role_format"] == pr.ROLE_FORMAT == 1
        assert r["source_revision"] == REV

    def test_every_row_has_exactly_the_documented_columns(self):
        for row in pr.role_rows(flat(), schema(ROLE_A, ROLE_B)) + pr.role_rows(flat(), None):
            assert tuple(row) == pr.ROLE_COLUMNS


class TestTwoContrastingRoles:
    def test_each_role_carries_only_its_own_requirements(self):
        a, b = pr.role_rows(flat(), schema(ROLE_A, ROLE_B))
        assert [a["ordinal"], b["ordinal"]] == [1, 2]
        assert (a["study_level"], a["eqf_level"], a["exp_years"]) == ("licenta", 6, 5.0)
        assert (b["study_level"], b["eqf_level"], b["exp_years"]) == ("liceala", 4, 0.0)
        assert a["skills"] == '["Contabilitate", "Excel"]' and b["skills"] == '["Word"]'
        assert a["credentials"] == '["autorizatie"]' and b["credentials"] == '["permis_conducere"]'
        assert b["vacancy_count"] == 2
        # An empty field list is a known "any field qualifies", not "unknown".
        assert b["isced_fields"] == "[]"

    def test_no_row_is_the_union_of_the_roles(self):
        posting = flat(v3_skills='["Contabilitate","Excel","Word"]', v3_eqf_level=6)
        a, b = pr.role_rows(posting, schema(ROLE_A, ROLE_B))
        assert "Word" not in a["skills"] and "Contabilitate" not in b["skills"]
        assert (a["eqf_level"], b["eqf_level"]) == (6, 4)    # not the posting's single level, twice


class TestDuplicateTitles:
    def test_identical_titles_are_two_rows_with_two_keys(self):
        twin = dict(ROLE_A, title="Inspector")
        other = dict(ROLE_B, title="Inspector")
        rows = pr.role_rows(flat(), schema(twin, other))
        assert [r["title"] for r in rows] == ["Inspector", "Inspector"]
        assert len({r["role_key"] for r in rows}) == 2
        assert rows[0]["eqf_level"] != rows[1]["eqf_level"]


class TestMissingPositions:
    @pytest.mark.parametrize("sch", [
        None, {}, {"education": {"minimum_level": "licenta"}},           # nothing / v3 without positions
        {"educationRequirements": "Studii superioare."},                 # a v2 payload
        {"positions": []}, {"positions": None}, {"positions": ["junk", 3, None]},
    ])
    def test_one_implicit_row_copying_the_flat_columns(self, sch):
        posting = flat(inf_studies_required="master", v3_eqf_level=7, v3_isced_fields='["x"]',
                       inf_experience_years=4.0, v3_skills='["A"]', v3_credentials='["autorizatie"]',
                       v3_seniority_hint="superior")
        rows = pr.role_rows(posting, sch)
        assert len(rows) == 1
        r = rows[0]
        assert (r["ordinal"], r["decomposed"], r["title"], r["vacancy_count"]) == (0, 0, None, None)
        assert (r["study_level"], r["eqf_level"], r["isced_fields"]) == ("master", 7, '["x"]')
        assert (r["exp_years"], r["skills"], r["credentials"]) == (4.0, '["A"]', '["autorizatie"]')
        assert r["seniority_hint"] == "superior"

    def test_an_empty_posting_gets_unknowns_not_inventions(self):
        (r,) = pr.role_rows({}, None)
        assert (r["study_level"], r["eqf_level"], r["exp_years"]) == (None, None, None)
        assert (r["isced_fields"], r["skills"], r["credentials"]) == ("[]", "[]", "[]")
        assert r["exp_in_specialty"] is None and r["exp_none_required"] is None
        assert r["source_revision"] is None and r["role_key"] == "none:-:0"

    def test_blank_studies_become_unknown(self):
        (r,) = pr.role_rows(flat(inf_studies_required=""), None)
        assert r["study_level"] is None

    def test_the_implicit_row_reads_the_posting_level_experience_flags(self):
        sch = {"experience": {"years_minimum": 2, "in_specialty": True, "none_required": False}}
        (r,) = pr.role_rows(flat(inf_experience_years=2.0), sch)
        assert (r["exp_in_specialty"], r["exp_none_required"]) == (1, 0)


class TestSharedVersusRoleSpecific:
    def test_a_role_never_inherits_posting_level_requirements(self):
        posting_level = {
            "education": {"minimum_level": "licenta", "eqf_level": 6,
                          "fields_of_study": [{"label_ro": "drept", "isced_field": "04_afaceri_administratie_drept"}]},
            "skill_list": [{"label": "Excel"}],
            "credentials": [{"label": "Permis B", "kind": "permis_conducere"}],
            "experience": {"years_minimum": 3},
            "positions": [{"title": "Muncitor", "count": 4}],      # states nothing but a title and a count
        }
        (r,) = pr.role_rows(flat(inf_studies_required="licenta", v3_eqf_level=6,
                                 v3_skills='["Excel"]', inf_experience_years=3.0), posting_level)
        assert (r["title"], r["vacancy_count"], r["decomposed"]) == ("Muncitor", 4, 1)
        for column in ("study_level", "eqf_level", "isced_fields", "exp_years", "skills", "credentials",
                       "exp_in_specialty", "exp_none_required", "seniority_hint"):
            assert r[column] is None, f"{column} must stay unknown, not borrow the posting's value"

    def test_a_role_with_no_education_block_has_no_education(self):
        a, b = pr.role_rows(flat(), schema(ROLE_A, {"title": "Muncitor", "education": None}))
        assert a["eqf_level"] == 6
        assert (b["study_level"], b["eqf_level"], b["isced_fields"]) == (None, None, None)

    def test_an_explicitly_empty_list_is_known_empty(self):
        (r,) = pr.role_rows(flat(), schema({"title": "X", "skill_list": [], "credentials": []}))
        assert (r["skills"], r["credentials"]) == ("[]", "[]")


class TestExperience:
    def exp(self, **block):
        (r,) = pr.role_rows(flat(), schema({"title": "X", "experience": block}))
        return r

    def test_none_required_means_zero_years(self):
        r = self.exp(years_minimum=None, none_required=True)
        assert (r["exp_years"], r["exp_none_required"]) == (0.0, 1)

    def test_unstated_experience_is_unknown_not_zero(self):
        r = self.exp(years_minimum=None, none_required=False)
        assert r["exp_years"] is None and r["exp_none_required"] == 0

    def test_a_stated_minimum_wins_over_a_stray_flag(self):
        assert self.exp(years_minimum=3, none_required=True)["exp_years"] == 3.0

    def test_fractional_years_survive(self):
        assert self.exp(years_minimum=0.5)["exp_years"] == 0.5

    def test_no_experience_block_is_unknown(self):
        (r,) = pr.role_rows(flat(), schema({"title": "X"}))
        assert (r["exp_years"], r["exp_in_specialty"], r["exp_none_required"]) == (None, None, None)

    def test_a_bad_value_is_unknown(self):
        assert self.exp(years_minimum="trei")["exp_years"] is None


class TestRoleKeys:
    def test_key_scheme_with_a_source_revision(self):
        rows = pr.role_rows(flat(), schema(ROLE_A, ROLE_B))
        assert [r["role_key"] for r in rows] == [f"src:{REV[:12]}:1", f"src:{REV[:12]}:2"]
        (implicit,) = pr.role_rows(flat(), None)
        assert implicit["role_key"] == f"src:{REV[:12]}:0"

    def test_revision_is_normalised(self):
        rows = pr.role_rows(flat(schema_source_revision="  ABCDEF0123456789  "), schema(ROLE_A))
        assert rows[0]["role_key"] == "src:abcdef012345:1"
        assert rows[0]["source_revision"] == "ABCDEF0123456789"

    def test_without_a_revision_the_key_hashes_the_payload(self):
        sch = schema(ROLE_A, ROLE_B)
        a = pr.role_rows(flat(schema_source_revision=""), sch)
        assert all(r["role_key"].startswith("pay:") for r in a)
        assert all(r["source_revision"] is None for r in a)
        # Stable for the same payload however its keys were ordered...
        shuffled = json.loads(json.dumps(sch, sort_keys=True))
        assert [r["role_key"] for r in pr.role_rows(flat(schema_source_revision=""), shuffled)] == \
               [r["role_key"] for r in a]
        # ...and a different payload gives different keys.
        changed = schema(dict(ROLE_A, count=9), ROLE_B)
        assert not {r["role_key"] for r in pr.role_rows(flat(schema_source_revision=""), changed)} & \
                   {r["role_key"] for r in a}

    def test_a_reorder_under_one_revision_keeps_keys_and_swaps_the_roles(self):
        """Documented instability: a key names a *position within one revision*.

        Re-running the extraction on the same page can list the roles in another
        order. The keys stay put and the roles under them swap, which is exactly why
        a key must never carry progress from one extraction to another.
        """
        before = pr.role_rows(flat(), schema(ROLE_A, ROLE_B))
        after = pr.role_rows(flat(), schema(ROLE_B, ROLE_A))
        assert [r["role_key"] for r in before] == [r["role_key"] for r in after]
        assert before[0]["title"] == "Expert contabil" and after[0]["title"] == "Referent"

    def test_a_reorder_without_a_revision_changes_every_key(self):
        no_rev = flat(schema_source_revision="")
        before = pr.role_rows(no_rev, schema(ROLE_A, ROLE_B))
        after = pr.role_rows(no_rev, schema(ROLE_B, ROLE_A))
        assert not {r["role_key"] for r in before} & {r["role_key"] for r in after}

    def test_keys_differ_only_by_ordinal_within_a_revision(self):
        keys = [r["role_key"] for r in pr.role_rows(flat(), schema(ROLE_A, ROLE_B, ROLE_A))]
        assert [k.rsplit(":", 1)[0] for k in keys] == [f"src:{REV[:12]}"] * 3
        assert [k.rsplit(":", 1)[1] for k in keys] == ["1", "2", "3"]

    def test_ordinal_is_the_array_position_even_when_an_entry_is_skipped(self):
        rows = pr.role_rows(flat(), schema(ROLE_A, "junk", ROLE_B))
        assert [r["ordinal"] for r in rows] == [1, 3]

    def test_no_schema_no_revision(self):
        (r,) = pr.role_rows({"schema_source_revision": ""}, None)
        assert r["role_key"] == "none:-:0"


class TestVacancyAndTitle:
    @pytest.mark.parametrize("count, want", [(2, 2), (1, 1), (0, None), (-3, None), (None, None),
                                             ("2", None), (True, None), (2.0, 2), (2.5, None)])
    def test_vacancy_count(self, count, want):
        (r,) = pr.role_rows(flat(), schema({"title": "X", "count": count}))
        assert r["vacancy_count"] == want

    def test_title_is_trimmed_or_unknown(self):
        rows = pr.role_rows(flat(), schema({"title": "  Referent  "}, {"title": "   "}, {"title": None}, {}))
        assert [r["title"] for r in rows] == ["Referent", None, None, None]


# ---- the real export(), against a fake Postgres cursor ---------------------------------------

@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("roles_export_under_test", REPO_ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["roles_export_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


def posting_row(pid, *, schema_json=None, inferred=None, revision="", prompt=""):
    return {
        "id": pid, "url": f"https://posturi.gov.ro/joburi/p{pid}/", "title": f"Post {pid}",
        "employer_id": 1, "employer_name": "Primăria Test", "judet_id": 1, "judet_name": "Cluj",
        "judet_slug": "cluj", "locality": "Cluj-Napoca", "detalii_raw": "", "published_at": None,
        "expires_at": None, "tip": "", "job_level": "", "job_type": "", "employer_category": "",
        "categorie": "", "announcement_url": "", "body_markdown": "", "nr_posturi": None,
        "contact_phone": "", "contact_email": "", "contact_person": "", "data_limita_depunere": None,
        "data_proba_scrisa": None, "data_interviu": None, "data_rezultate_finale": None,
        "created_at": None, "updated_at": None, "last_seen_at": None, "detail_fetched_at": None,
        "detail_content_hash": "", "other_links": "[]", "attachment_meta": "[]",
        "inferred": json.dumps(inferred or {}), "schema_json": schema_json,
        "schema_source_revision": revision, "schema_prompt_version": prompt,
        "salary_estimate": None, "occ_canonical": "", "occ_cor_code": "", "occ_isco_group": "",
        "occ_confidence": "",
    }


class FakeCursor:
    """Answers the handful of queries export() issues, from canned rows."""

    def __init__(self, postings):
        self.postings = postings
        self.rows = []

    def execute(self, sql, params=None):
        if "jp.id, jp.url" in sql:
            self.rows = list(self.postings)
        elif "FROM jobs_judet" in sql:
            self.rows = [{"id": 1, "name": "Cluj", "slug": "cluj"}]
        elif "FROM jobs_employer" in sql:
            self.rows = [{"id": 1, "name": "Primăria Test", "slug": "primaria-test"}]
        elif "MAX(jp.detail_fetched_at)" in sql:
            self.rows = [{"max_fetched": None, "fetched_rows": 0}]
        else:                                   # calendar events, llm costs
            self.rows = []

    def fetchall(self):
        return self.rows

    def fetchone(self):
        return self.rows[0] if self.rows else None

    def __iter__(self):
        return iter(self.rows)


class FakePg:
    def __init__(self, postings):
        self.postings = postings

    def cursor(self):
        return FakeCursor(self.postings)


def run_export(exp, tmp_path, postings):
    path = tmp_path / "posturi.sqlite"
    con = sqlite3.connect(path)
    exp.create_schema(con)
    with con:
        exp.export(FakePg(postings), con, active_only=False)
    return path, con


PAYLOAD_TWO_ROLES = json.dumps({
    "education": {"minimum_level": "licenta", "eqf_level": 6},
    "skill_list": [{"label": "Excel"}],
    "positions": [ROLE_A, ROLE_B],
})
PAYLOAD_FLAT_V3 = json.dumps({
    "education": {"minimum_level": "master", "eqf_level": 7,
                  "fields_of_study": [{"label_ro": "drept", "isced_field": "04_afaceri_administratie_drept"}]},
    "skill_list": [{"label": "Excel"}], "credentials": [{"label": "A", "kind": "autorizatie"}],
})


@pytest.fixture
def exported(exp, tmp_path):
    postings = [
        posting_row(1, schema_json=PAYLOAD_TWO_ROLES, revision=REV, prompt="v4",
                    inferred={"studies_required": "licenta", "experience_years": 0}),
        posting_row(2, schema_json=PAYLOAD_FLAT_V3, revision="", prompt="v3",
                    inferred={"studies_required": "master", "experience_years": 4}),
        posting_row(3),
    ]
    path, con = run_export(exp, tmp_path, postings)
    yield path, con
    con.close()


def test_export_writes_one_row_per_role(exported):
    _, con = exported
    rows = con.execute("SELECT posting_id, ordinal, decomposed, title, study_level, eqf_level, exp_years "
                       "FROM posting_roles ORDER BY posting_id, ordinal").fetchall()
    assert rows == [
        (1, 1, 1, "Expert contabil", "licenta", 6, 5.0),
        (1, 2, 1, "Referent", "liceala", 4, 0.0),
        (2, 0, 0, None, "master", 7, 4.0),
        (3, 0, 0, None, None, None, None),
    ]


def test_the_implicit_row_copies_the_columns_the_export_wrote(exported):
    _, con = exported
    posting = con.execute("SELECT inf_studies_required, v3_eqf_level, v3_isced_fields, inf_experience_years, "
                          "v3_skills, v3_credentials FROM job_postings WHERE id = 2").fetchone()
    role = con.execute("SELECT study_level, eqf_level, isced_fields, exp_years, skills, credentials "
                       "FROM posting_roles WHERE posting_id = 2").fetchone()
    assert role == posting


def test_provenance_columns_and_keys_reach_the_file(exported):
    _, con = exported
    assert con.execute("SELECT schema_source_revision, schema_prompt_version FROM job_postings ORDER BY id").fetchall() \
        == [(REV, "v4"), ("", "v3"), ("", "")]
    keys = con.execute("SELECT role_key FROM posting_roles WHERE posting_id = 1 ORDER BY ordinal").fetchall()
    assert keys == [(f"src:{REV[:12]}:1",), (f"src:{REV[:12]}:2",)]
    pay = con.execute("SELECT role_key, source_revision FROM posting_roles WHERE posting_id = 2").fetchone()
    assert pay[0].startswith("pay:") and pay[1] is None
    assert con.execute("SELECT role_key FROM posting_roles WHERE posting_id = 3").fetchone() == ("none:-:0",)
    assert {r[0] for r in con.execute("SELECT role_format FROM posting_roles")} == {1}


def test_every_posting_has_a_role_and_verify_passes(exp, exported):
    path, con = exported
    con.commit()
    assert con.execute("SELECT COUNT(*) FROM job_postings j WHERE NOT EXISTS "
                       "(SELECT 1 FROM posting_roles r WHERE r.posting_id = j.id)").fetchone()[0] == 0
    assert exp.verify(str(path), min_rows=0, previous=None, active_only=False) == 3


def test_verify_refuses_a_posting_without_a_role_row(exp, exported):
    path, con = exported
    con.execute("DELETE FROM posting_roles WHERE posting_id = 3")
    con.commit()
    with pytest.raises(SystemExit, match="no posting_roles row"):
        exp.verify(str(path), min_rows=0, previous=None, active_only=False)


def test_the_table_has_the_indexes_the_filters_use(exported):
    _, con = exported
    names = {r[1] for r in con.execute("PRAGMA index_list('posting_roles')")}
    # posting_id is the leading column of the primary key, so it needs no index of its own.
    assert {"idx_pr_eqf", "idx_pr_study"} <= names
    assert "idx_pr_posting" not in names
    pk = [r[2] for r in con.execute("PRAGMA index_info('sqlite_autoindex_posting_roles_1')")]
    assert pk == ["posting_id", "ordinal"]

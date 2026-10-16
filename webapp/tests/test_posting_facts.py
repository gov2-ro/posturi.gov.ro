"""`posting_facts` — what an observation records, and how two observations relate (UX-12).

Pure module, so most of this runs without a database. The parity block loads the real
`export-to-sqlite.py` (like `test_apply_deadline.py`) and pins the duplicated deadline
and `_v3_columns` logic to it: the site must not tell a reader a deadline that the
observation history disagrees with.
"""

import copy
import importlib.util
import itertools
import json
import sys
from datetime import date, datetime, timezone
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import posting_facts as pf  # noqa: E402

REV = "ab12cd34ef56" + "0" * 52
REV2 = "99ff88ee77dd" + "1" * 52


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("export_sqlite_facts", REPO_ROOT / "export-to-sqlite.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["export_sqlite_facts"] = module
    spec.loader.exec_module(module)
    return module


def posting(**over):
    base = {
        "data_limita_depunere": None, "expires_at": date(2026, 10, 30), "cancelled": False,
        "detail_content_hash": REV, "schema_source_revision": REV, "inferred": {},
    }
    base.update(over)
    return base


ROLE_A = {
    "title": "Expert contabil", "count": 1,
    "education": {"minimum_level": "licenta", "eqf_level": 6, "verbatim": "Studii superioare economice.",
                  "fields_of_study": [{"label_ro": "contabilitate", "isced_field": "04_afaceri_administratie_drept"}]},
    "experience": {"years_minimum": 5, "in_specialty": True, "none_required": False,
                   "verbatim": "5 ani vechime în specialitate."},
    "skill_list": [{"label": "Excel"}, {"label": "Contabilitate"}],
    "credentials": [{"label": "CECCAR", "kind": "autorizatie"}],
    "seniority_hint": "principal",
}
ROLE_B = {
    "title": "Referent", "count": 2,
    "education": {"minimum_level": "liceala", "eqf_level": 4, "fields_of_study": []},
    "experience": {"years_minimum": 0, "in_specialty": False, "none_required": True,
                   "verbatim": "Fără experiență."},
    "skill_list": [{"label": "Word"}],
    "credentials": [],
}

CALENDAR = {
    "application_deadline": "2026-10-16", "application_deadline_time": "14:00",
    "events": [
        {"stage": "depunere_dosare", "date": "2026-10-16", "time": "14:00",
         "verbatim": "Data limită de depunere a dosarelor: 16.10.2026, ora 14:00"},
        {"stage": "interviu", "date": "2026-11-02", "verbatim": "Interviu: 02.11.2026"},
    ],
}


def v4(*positions, calendar=CALENDAR, **extra):
    schema = {"education": {"minimum_level": "licenta", "eqf_level": 6,
                            "verbatim": "Studii superioare."},
              "experience": {"years_minimum": 3, "verbatim": "3 ani vechime."},
              "skill_list": [{"label": "GIS"}], "credentials": [], **extra}
    if calendar is not None:
        schema["competition_calendar"] = calendar
    if positions:
        schema["positions"] = list(positions)
    return schema


class TestDeadline:
    def test_the_v4_calendar_wins_with_time_and_evidence(self):
        f = pf.tracked_facts(posting(data_limita_depunere=datetime(2026, 10, 20, 21, 0, tzinfo=timezone.utc)), v4())
        assert (f["deadline"], f["deadline_source"], f["deadline_time"]) == ("2026-10-16", "concurs", "14:00")
        assert f["evidence"]["deadline"] == "Data limită de depunere a dosarelor: 16.10.2026, ora 14:00"

    def test_a_malformed_deadline_time_is_dropped_not_stored(self):
        cal = dict(CALENDAR, application_deadline_time="la prânz")
        assert pf.tracked_facts(posting(), v4(calendar=cal))["deadline_time"] is None

    def test_the_scraped_card_deadline_is_next_in_bucharest_time(self):
        stored = datetime(2026, 10, 20, 21, 0, tzinfo=timezone.utc)      # local midnight, summer
        f = pf.tracked_facts(posting(data_limita_depunere=stored), v4(calendar=None))
        assert (f["deadline"], f["deadline_source"], f["deadline_time"]) == ("2026-10-21", "anunt", None)

    def test_expiry_is_the_fallback(self):
        f = pf.tracked_facts(posting(), v4(calendar=None))
        assert (f["deadline"], f["deadline_source"]) == ("2026-10-30", "expirare")
        assert f["evidence"]["deadline"] is None

    def test_nothing_known(self):
        f = pf.tracked_facts(posting(expires_at=None), None)
        assert (f["deadline"], f["deadline_source"], f["expires_at"]) == (None, "", None)

    def test_a_calendar_that_is_not_a_dict_does_not_raise(self):
        f = pf.tracked_facts(posting(), v4(calendar=["junk"]))
        assert f["deadline_source"] == "expirare"


class TestExportParity:
    """The duplicated rule must give the export's answer for every combination."""

    V4 = [None, "", "2026-09-16", "2026-09-16T10:00:00"]
    CARD = [None, datetime(2026, 9, 23, 21, 0, tzinfo=timezone.utc),
            datetime(2026, 1, 15, 22, 0, tzinfo=timezone.utc), "2026-09-20 14:00:00+03", date(2026, 9, 20)]
    EXPIRY = [None, date(2026, 9, 28), date(2026, 10, 9)]

    def test_apply_deadline_matches_the_export_on_every_combination(self, exp):
        for args in itertools.product(self.V4, self.CARD, self.EXPIRY):
            assert pf.apply_deadline(*args) == exp._apply_deadline(*args), args

    def test_local_date_matches_the_export(self, exp):
        for value in self.CARD + self.EXPIRY:
            assert pf.local_date(value) == exp._local_date(value)

    def test_tracked_facts_deadline_matches_apply_deadline_fed_by_v4_columns(self, exp):
        schemas = [v4(), v4(calendar=None), v4(calendar={"application_deadline": None, "events": []}), None, {}]
        for schema, card, expiry in itertools.product(schemas, self.CARD, self.EXPIRY):
            p = posting(data_limita_depunere=card, expires_at=expiry)
            v4col = exp._v4_columns(json.dumps(schema) if schema is not None else None)
            want = exp._apply_deadline(v4col["application_deadline"], card, expiry)
            got = pf.tracked_facts(p, schema)
            # A schema that is empty is not an extraction: the v4 date is unavailable
            # (the export would have used it only if it existed, and {} has none).
            assert (got["deadline"], got["deadline_source"]) == (want["date"], want["source"]), (schema, card, expiry)

    @pytest.mark.parametrize("schema", [
        None, {}, {"educationRequirements": "Studii superioare."},
        v4(), v4(ROLE_A, ROLE_B),
        {"education": {"eqf_level": 7, "fields_of_study": [{"isced_field": "x"}, {"isced_field": "x"}]}},
        {"education": None, "skill_list": [{"label": "B"}, {"label": "A"}], "seniority_hint": "superior"},
    ])
    def test_posting_level_columns_match_the_export_v3_columns(self, exp, schema):
        want = exp._v3_columns(json.dumps(schema) if schema is not None else None)
        got = pf.posting_level_columns(schema)
        for key in ("eqf_level", "isced_fields", "skills", "credentials", "seniority_hint"):
            assert got[key] == want[key], key

    def test_the_stale_marker_is_the_importers(self):
        from apps.jobs.management.commands.import_csvs import STALE_MARKER
        assert pf.STALE_MARKER == STALE_MARKER


class TestValidity:
    def test_a_current_extraction_is_valid(self):
        f = pf.tracked_facts(posting(), v4())
        assert f["requirements_available"] is True and f["roles"]

    @pytest.mark.parametrize("schema", [None, {}, [], "text"])
    def test_no_usable_extraction_is_unavailable_not_empty(self, schema):
        f = pf.tracked_facts(posting(), schema)
        assert f["requirements_available"] is False
        assert f["roles"] is None and f["evidence"]["roles"] is None

    def test_an_extraction_of_an_older_revision_is_unavailable_and_cannot_set_the_deadline(self):
        stale = posting(schema_source_revision=REV2, detail_content_hash=REV,
                        data_limita_depunere=datetime(2026, 10, 20, 21, 0, tzinfo=timezone.utc))
        f = pf.tracked_facts(stale, v4())
        assert f["requirements_available"] is False and f["roles"] is None
        # The stale calendar says 16 Oct; it describes the previous page, so it is not used.
        assert (f["deadline"], f["deadline_source"]) == ("2026-10-21", "anunt")
        assert f["evidence"]["deadline"] is None

    def test_stale_inference_makes_the_requirement_part_unavailable(self):
        f = pf.tracked_facts(posting(inferred={"stale_revision": REV, "studies_required": "master"}), v4())
        assert f["requirements_available"] is False and f["roles"] is None

    def test_an_extraction_with_no_recorded_revision_is_not_provably_stale(self):
        f = pf.tracked_facts(posting(schema_source_revision=""), v4())
        assert f["requirements_available"] is True

    def test_cancellation_and_expiry_do_not_depend_on_the_extraction(self):
        f = pf.tracked_facts(posting(cancelled=True, expires_at=date(2026, 8, 1)), None)
        assert f["cancelled"] is True and f["expires_at"] == "2026-08-01"


class TestRoles:
    def test_two_roles_carry_only_requirement_fields_and_their_own_evidence(self):
        f = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        a, b = f["roles"]
        assert tuple(a) == pf.ROLE_FACT_COLUMNS
        for dropped in ("seniority_hint", "source_revision", "role_format"):
            assert dropped not in a
        assert (a["ordinal"], a["decomposed"], a["title"], a["vacancy_count"]) == (1, True, "Expert contabil", 1)
        assert (a["study_level"], a["eqf_level"], a["exp_years"]) == ("licenta", 6, 5.0)
        assert a["skills"] == ["Contabilitate", "Microsoft Excel"]                # sorted, parsed
        assert a["isced_fields"] == ["04_afaceri_administratie_drept"]
        assert (a["exp_in_specialty"], a["exp_none_required"]) == (True, False)
        assert b["exp_years"] == 0.0 and b["exp_none_required"] is True and b["isced_fields"] == []
        assert f["evidence"]["roles"] == [
            {"ordinal": 1, "education": "Studii superioare economice.", "experience": "5 ani vechime în specialitate."},
            {"ordinal": 2, "education": None, "experience": "Fără experiență."},
        ]

    def test_roles_are_sorted_by_ordinal(self):
        f = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        assert [r["ordinal"] for r in f["roles"]] == [1, 2]

    def test_an_undecomposed_posting_has_one_implicit_role_from_the_flat_columns(self):
        f = pf.tracked_facts(posting(inferred={"studies_required": "master", "experience_years": 4.0}), v4())
        (role,) = f["roles"]
        assert (role["ordinal"], role["decomposed"], role["title"]) == (0, False, None)
        assert (role["study_level"], role["eqf_level"], role["exp_years"]) == ("master", 6, 4.0)
        assert role["skills"] == ["GIS"]
        assert f["evidence"]["roles"] == [
            {"ordinal": 0, "education": "Studii superioare.", "experience": "3 ani vechime."}]

    def test_a_v2_payload_falls_back_to_its_verbatim_strings(self):
        v2 = {"educationRequirements": "Studii medii.", "experienceRequirements": "1 an."}
        f = pf.tracked_facts(posting(), v2)
        assert f["evidence"]["roles"] == [{"ordinal": 0, "education": "Studii medii.", "experience": "1 an."}]

    def test_the_facts_are_json_serializable_and_round_trip(self):
        f = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        assert json.loads(json.dumps(f)) == f

    def test_role_keys_are_those_of_posting_roles(self):
        f = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        assert [r["role_key"] for r in f["roles"]] == [f"src:{REV[:12]}:1", f"src:{REV[:12]}:2"]


class TestRoleContinuityIsNotGuessed:
    """Role keys are revision-scoped; the facts offer no mapping between revisions."""

    def keys(self, *a, **kw):
        return [r["role_key"] for r in pf.tracked_facts(*a, **kw)["roles"]]

    def test_a_new_source_revision_changes_every_key(self):
        before = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        after = pf.tracked_facts(posting(detail_content_hash=REV2, schema_source_revision=REV2), v4(ROLE_A, ROLE_B))
        assert not set(self.keys(posting(), v4(ROLE_A, ROLE_B))) & set(self.keys(
            posting(detail_content_hash=REV2, schema_source_revision=REV2), v4(ROLE_A, ROLE_B)))
        # ...yet the requirements are identical, so the hash (blind to keys) says no tracked fact moved.
        assert pf.facts_hash(before) == pf.facts_hash(after)

    def test_a_reordered_payload_without_a_recorded_revision_changes_every_key(self):
        p = posting(schema_source_revision="")
        a = self.keys(p, v4(ROLE_A, ROLE_B))
        b = self.keys(p, v4(ROLE_B, ROLE_A))
        assert a != b and not set(a) & set(b)

    def test_a_reorder_under_one_revision_reuses_the_key_for_a_different_role(self):
        """Same `src:<rev>:1`, now a different role. The hash moves; no field claims
        that role A became role B, or that either stayed itself."""
        a = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        b = pf.tracked_facts(posting(), v4(ROLE_B, ROLE_A))
        assert a["roles"][0]["role_key"] == b["roles"][0]["role_key"]
        assert a["roles"][0]["title"] != b["roles"][0]["title"]
        assert pf.facts_hash(a) != pf.facts_hash(b)
        for facts in (a, b):
            assert not any("previous" in k or "continues" in k for r in facts["roles"] for k in r)


class TestFactsHash:
    def test_stable_across_key_order_and_blind_to_evidence_and_role_keys(self):
        base = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        h = pf.facts_hash(base)
        shuffled = json.loads(json.dumps(base, sort_keys=True))
        assert pf.facts_hash(shuffled) == h
        reworded = copy.deepcopy(base)
        reworded["evidence"]["roles"][0]["education"] = "Altă formulare."
        reworded["roles"][0]["role_key"] = "src:other:1"
        assert pf.facts_hash(reworded) == h

    @pytest.mark.parametrize("mutate", [
        lambda f: f.update(deadline="2026-10-23"),
        lambda f: f.update(deadline_source="anunt"),
        lambda f: f.update(deadline_time="09:00"),
        lambda f: f.update(cancelled=True),
        lambda f: f.update(expires_at="2026-12-01"),
        lambda f: f["roles"][0].update(exp_years=7.0),
        lambda f: f["roles"][1].update(skills=["Word", "Excel"]),
        lambda f: f.update(roles=None, requirements_available=False),
    ])
    def test_any_tracked_fact_changes_the_hash(self, mutate):
        base = pf.tracked_facts(posting(), v4(ROLE_A, ROLE_B))
        changed = copy.deepcopy(base)
        mutate(changed)
        assert pf.facts_hash(changed) != pf.facts_hash(base)

    def test_unavailable_requirements_never_hash_like_an_empty_role_list(self):
        unavailable = pf.tracked_facts(posting(), None)
        empty = copy.deepcopy(unavailable)
        empty.update(requirements_available=True, roles=[])
        assert pf.facts_hash(unavailable) != pf.facts_hash(empty)


def obs(**over):
    base = {"source_revision": REV, "extraction_provider": "deepseek", "extraction_model": "deepseek-chat",
            "extraction_prompt_version": "v4", "extraction_source_revision": REV,
            "extraction_extracted_at": datetime(2026, 10, 5, 12, 0, tzinfo=timezone.utc),
            "facts_hash": "h1"}
    base.update(over)
    return base


class TestClassify:
    def test_the_first_observation_is_a_baseline_not_a_change(self):
        assert pf.classify(None, obs()) == "baseline"

    def test_identical_is_unchanged(self):
        assert pf.classify(obs(), obs()) == "unchanged"

    def test_a_new_source_revision_is_a_source_change_even_if_everything_else_moved_too(self):
        cur = obs(source_revision=REV2, extraction_source_revision=REV2, facts_hash="h2",
                  extraction_extracted_at=datetime(2026, 10, 9, tzinfo=timezone.utc))
        assert pf.classify(obs(), cur) == "source_change"

    def test_source_bytes_changed_with_the_same_facts_is_still_recorded_as_a_source_change(self):
        assert pf.classify(obs(), obs(source_revision=REV2)) == "source_change"

    @pytest.mark.parametrize("field,value", [
        ("extraction_model", "deepseek-reasoner"),
        ("extraction_prompt_version", "v5"),
        ("extraction_provider", "anthropic"),
        ("extraction_extracted_at", datetime(2026, 10, 8, tzinfo=timezone.utc)),
        ("extraction_source_revision", REV2),
    ])
    def test_a_model_only_correction_is_extraction_only(self, field, value):
        """Same source bytes, a different extraction — even when the facts moved too."""
        assert pf.classify(obs(), obs(**{field: value})) == "extraction_only"
        assert pf.classify(obs(), obs(**{field: value, "facts_hash": "h2"})) == "extraction_only"

    def test_same_source_and_extraction_with_new_facts_is_facts_only(self):
        assert pf.classify(obs(), obs(facts_hash="h2")) == "facts_only"

    def test_unknown_and_empty_extraction_values_compare_equal(self):
        a = obs(extraction_provider="", extraction_extracted_at=None)
        b = obs(extraction_provider=None, extraction_extracted_at=None)
        assert pf.classify(a, b) == "unchanged"

    def test_a_source_revision_that_becomes_known_is_reported_for_the_derivation_to_judge(self):
        assert pf.classify(obs(source_revision=""), obs()) == "source_change"

    def test_the_five_outcomes_are_the_documented_set(self):
        assert pf.CLASSES == ("baseline", "source_change", "extraction_only", "facts_only", "unchanged")

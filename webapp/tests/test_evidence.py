"""Source evidence for extracted requirements (UX-13, first slice).

`evidence.py` is pure, so nearly all of this runs without Postgres or Django. The last
block drives the real `export()` against the fake Postgres cursor of test_posting_roles.py
to prove the rows reach the SQLite file, that `attachment_text` is read but never
exported, and that `verify()` refuses an inconsistent evidence table.

What is pinned here, in the words of the spec: a quote is `verified` only when it can be
located in the exact current text under a documented normalization; anything else is shown
as unverified; a missing quote is `absent` and proves nothing; changed source text
invalidates the check; a role reads only its own entry; no page number is ever invented.
"""

import importlib.util
import json
import random
import sys
import unicodedata
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import evidence as ev  # noqa: E402
import grounding  # noqa: E402
import posting_roles  # noqa: E402

from tests.test_posting_roles import posting_row, run_export  # noqa: E402

REV_A = "a" * 64
REV_B = "b" * 64

BODY = (
    "# Concurs de ocupare\n\n"
    "Instituția organizează concurs pentru postul de referent.\n\n"
    "**Condiții de participare**\n\n"
    "- studii superioare de lungă durată, absolvite cu diplomă de licență;\n"
    "- vechime în specialitatea studiilor: minimum 3 ani;\n"
    "- cunoștințe de operare Microsoft Excel.\n\n"
    "## Dosarul de concurs\n\n"
    "Candidații depun copie după actul de identitate și curriculum vitae.\n"
)
ATTACH = (
    "ANEXA 1 — Bibliografie\n\n"
    "Legea nr. 53/2003 – Codul muncii, republicată; Ordonanța de urgență nr. 57/2019.\n\n"
    "Candidații trebuie să dețină permis de conducere categoria B.\n"
)
PARTS = [("anunt", BODY), ("anexe", ATTACH)]


def loc(quote, parts=PARTS):
    return ev.locate(quote, parts)


# ---- locating a quote ------------------------------------------------------------------------

class TestLocate:
    def test_markdown_quote_is_verified_with_its_section(self):
        r = loc("studii superioare de lungă durată, absolvite cu diplomă de licență")
        assert (r.status, r.part, r.reason, r.occurrences) == ("verified", "anunt", None, 1)
        assert r.section == "Condiții de participare"

    def test_section_follows_the_nearest_heading_above(self):
        assert loc("copie după actul de identitate și curriculum vitae").section == "Dosarul de concurs"
        assert loc("Instituția organizează concurs pentru postul de referent").section == "Concurs de ocupare"

    def test_atx_and_bold_only_headings_both_count(self):
        body = "## Atribuții\n\ntext de atribuții aici.\n\n**Cerințe**\n\nsă aibă experiență de trei ani în domeniu\n"
        assert ev.headings(body)[0][1] == "Atribuții"
        assert [t for _, t in ev.headings(body)] == ["Atribuții", "Cerințe"]
        assert ev.locate("să aibă experiență de trei ani în domeniu", [("anunt", body)]).section == "Cerințe"

    def test_a_bold_sentence_is_not_a_heading(self):
        body = "**Atenție** la termen și **la dosar** complet\n\nrestul textului de aici\n"
        assert ev.headings(body) == []

    def test_no_heading_above_means_no_section(self):
        r = ev.locate("textul anunțului fără titluri deloc", [("anunt", "textul anunțului fără titluri deloc, nimic altceva")])
        assert (r.status, r.section) == ("verified", None)

    def test_section_is_trimmed_to_120_chars(self):
        body = "# " + "titlu " * 40 + "\n\nconținut care contine citatul exact cautat\n"
        r = ev.locate("conținut care contine citatul exact cautat", [("anunt", body)])
        assert r.section is not None and len(r.section) <= ev.SECTION_MAX

    def test_attachment_quote_is_verified_as_anexe_without_a_section_or_page(self):
        r = loc("Candidații trebuie să dețină permis de conducere categoria B")
        assert (r.status, r.part, r.section, r.occurrences) == ("verified", "anexe", None, 1)
        assert not hasattr(r, "page")

    def test_announcement_wins_when_both_parts_contain_the_quote(self):
        both = [("anunt", "Dețineți permis de conducere categoria B."), ("anexe", "Dețineți permis de conducere categoria B.")]
        assert ev.locate("permis de conducere categoria B", both).part == "anunt"

    @pytest.mark.parametrize("variant", [
        "diplomă de licență",          # comma-below / breve forms as stored
        "diploma de licenta",          # no diacritics at all
        "DIPLOMĂ DE LICENȚĂ",          # upper case, comma below
        "diplomă de licenţă",          # cedilla ţ (the legacy code point)
        "diplomă de licență",   # decomposed: o, a + breve, t + comma below
    ])
    def test_romanian_diacritics_and_cedilla_variants_match(self, variant):
        src = [("anunt", "Se cere absolvirea unui program și diplomă de licență în domeniu.")]
        assert ev.locate(variant, src).status == "verified"

    @pytest.mark.parametrize("src_form,quote_form", [
        ("ş", "ș"), ("ș", "ş"), ("ţ", "ț"), ("ț", "ţ"),
    ])
    def test_cedilla_and_comma_below_are_interchangeable_both_ways(self, src_form, quote_form):
        text = f"Candidatul susține proba {src_form}i interviul pentru post"
        quote = f"proba {quote_form}i interviul pentru post"
        assert ev.locate(quote, [("anunt", text)]).status == "verified"

    def test_whitespace_markdown_and_punctuation_do_not_decide(self):
        src = [("anunt", "- **vechime**   în\n\n  specialitatea   studiilor: *minimum*   3 ani!")]
        assert ev.locate("Vechime în specialitatea studiilor — minimum 3 ani", src).status == "verified"
        src2 = [("anunt", "| studii | superioare | de lungă durată |")]
        assert ev.locate("studii superioare de lungă durată", src2).status == "verified"

    def test_matching_is_on_whole_words(self):
        src = [("anunt", "Se cer abilități de comunicare și bani de experiență practică în domeniu.")]
        assert ev.locate("ani de experiență practică în domeniu", src).reason == "not_found"
        assert ev.locate("bani de experiență practică în domeniu", src).status == "verified"

    def test_a_changed_number_is_not_found(self):
        r = loc("vechime în specialitatea studiilor: minimum 5 ani")
        assert (r.status, r.reason) == ("unverified", "not_found")

    def test_not_found(self):
        r = loc("cunoștințe avansate de limba engleză scris și vorbit")
        assert (r.status, r.reason, r.part, r.section, r.occurrences) == ("unverified", "not_found", None, None, None)

    def test_a_quote_cannot_straddle_two_parts(self):
        straddle = "actul de identitate și curriculum vitae ANEXA 1 Bibliografie Legea nr 53 2003"
        assert loc(straddle).reason == "not_found"

    @pytest.mark.parametrize("quote", [None, "", "   ", "\n\t"])
    def test_missing_or_blank_quote_is_absent(self, quote):
        r = loc(quote)
        assert (r.status, r.reason, r.part) == ("absent", None, None)

    def test_non_string_quote_is_absent_not_a_crash(self):
        assert loc(123).status == "absent"
        assert loc(["a", "b"]).status == "absent"

    @pytest.mark.parametrize("quote", [
        "licență",                       # one word
        "Excel avansat",                 # two words
        "a b c d e f",                   # 3+ words but under 12 characters once folded
        "...",                           # nothing left after the ellipsis
        "—",                             # punctuation only
    ])
    def test_too_short_quotes_cannot_verify(self, quote):
        r = loc(quote)
        assert (r.status, r.reason) == ("unverified", "too_short")

    def test_boundary_of_the_too_short_rule(self):
        assert ev.locate("ab cd efghijk", [("anunt", "ab cd efghijk")]).status == "verified"      # 13 chars, 3 words
        assert ev.locate("ab cd efghij", [("anunt", "ab cd efghij")]).status == "verified"        # exactly 12
        assert ev.locate("ab cd efghi", [("anunt", "ab cd efghi")]).reason == "too_short"          # 11
        assert ev.locate("abcdefghij klmnop", [("anunt", "abcdefghij klmnop")]).reason == "too_short"   # 2 words

    def test_every_segment_of_an_elided_quote_must_be_long_enough(self):
        r = loc("licență … vechime în specialitatea studiilor: minimum 3 ani")
        assert (r.status, r.reason) == ("unverified", "too_short")

    def test_no_source_text_cannot_verify(self):
        r = ev.locate("studii superioare de lungă durată", [("anunt", ""), ("anexe", None)])
        assert (r.status, r.reason) == ("unverified", "no_source_text")
        r = ev.locate("studii superioare de lungă durată", [("anunt", "   \n"), ("anexe", "")])
        assert r.reason == "no_source_text"

    # ---- ellipsis ----

    @pytest.mark.parametrize("sep", [" … ", "…", " ... ", "...", " [...] ", " (…) "])
    def test_segments_in_order_verify(self, sep):
        q = f"studii superioare de lungă durată{sep}vechime în specialitatea studiilor"
        r = loc(q)
        assert (r.status, r.part) == ("verified", "anunt"), sep

    def test_segments_out_of_order_do_not_verify(self):
        q = "vechime în specialitatea studiilor … studii superioare de lungă durată"
        assert loc(q).reason == "not_found"

    def test_every_segment_must_exist_in_the_same_part(self):
        q = "studii superioare de lungă durată … permis de conducere categoria B"
        assert loc(q).reason == "not_found"        # first segment is in the page, second only in the attachments

    def test_elided_quote_counts_as_one_occurrence(self):
        q = "studii superioare de lungă durată … diplomă de licență"
        assert loc(q).occurrences == 1

    # ---- duplicates ----

    def test_duplicate_sentence_reports_occurrences(self):
        body = ("# A\n\nCandidatul trebuie să dețină permis de conducere categoria B.\n\n"
                "# B\n\nCandidatul trebuie să dețină permis de conducere categoria B.\n")
        r = ev.locate("Candidatul trebuie să dețină permis de conducere categoria B", [("anunt", body)])
        assert (r.status, r.occurrences) == ("verified", 2)

    def test_duplicates_under_different_headings_name_no_section(self):
        body = ("# A\n\nCandidatul trebuie să dețină permis de conducere categoria B.\n\n"
                "# B\n\nCandidatul trebuie să dețină permis de conducere categoria B.\n")
        r = ev.locate("Candidatul trebuie să dețină permis de conducere categoria B", [("anunt", body)])
        assert r.section is None            # naming either heading would be a guess

    def test_duplicates_under_one_heading_keep_it(self):
        body = ("# A\n\nCandidatul trebuie să dețină permis de conducere categoria B.\n\n"
                "Candidatul trebuie să dețină permis de conducere categoria B.\n")
        r = ev.locate("Candidatul trebuie să dețină permis de conducere categoria B", [("anunt", body)])
        assert (r.occurrences, r.section) == (2, "A")

    def test_hostile_text_is_kept_raw_escaping_is_the_renderers_job(self):
        hostile = '<script>alert(1)</script> "><img src=x onerror=alert(2)> și încă ceva text'
        body = f"# Titlu\n\nText: {hostile}\n"
        r = ev.locate(hostile, [("anunt", body)])
        assert r.status == "verified"           # folded: script alert 1 script img src x onerror alert 2 ...
        row = ev.evidence_rows(
            {"id": 1, "detail_content_hash": REV_A, "schema_source_revision": REV_A},
            {"education": {"minimum_level": "licenta", "verbatim": hostile}},
            [{"ordinal": 0, "role_key": "k", "decomposed": 0}], body, "")[0]
        assert row["quote"] == hostile          # no escaping, no stripping on the way into the table

    def test_locate_accepts_a_prebuilt_source(self):
        src = ev.Source.build(PARTS)
        assert ev.locate("actul de identitate și curriculum vitae", src).status == "verified"
        assert ev.locate("actul de identitate și curriculum vitae", PARTS).status == "verified"


# ---- the normaliser ---------------------------------------------------------------------------

SAMPLES = [
    "", " ", "abc", "ABC def", "Ș ș Ț ț Ş ş Ţ ţ Ă ă Â â Î î", "diplomă de licență",
    "naïve café – Œuvre ﬁnal ① ² ½ ﬃ", "áb", "a\ńb", "x̦y", "́́abc",
    "**bold** __also__ # head\n- item\t1.\r\n| a | b |", "tab\tand nbsp em​zero",
    "ΑΒΓ Σίσυφος ς", "İstanbul ıi I", "日本語 text 123", "a" * 50 + " " * 50 + "b",
    "...…[…](…)", "ß straße", "Ǆ ǆ ǅ", "\x00null\x00byte",
]


class TestNormaliser:
    @pytest.mark.parametrize("text", SAMPLES)
    def test_fold_with_map_equals_grounding_normalise(self, text):
        folded, offsets = ev.fold_with_map(text)
        assert folded == grounding.normalise(text)
        assert ev.fold(text) == folded
        assert len(offsets) == len(folded)

    def test_parity_on_random_unicode(self):
        rng = random.Random(20261010)
        pool = [chr(c) for c in list(range(0x20, 0x180)) + list(range(0x300, 0x370)) + [0x2026, 0x2013, 0x00a0, 0x0219, 0x021b, 0x015f, 0x0163]]
        for _ in range(400):
            text = "".join(rng.choice(pool) for _ in range(rng.randint(0, 60)))
            assert ev.fold_with_map(text)[0] == grounding.normalise(text), repr(text)

    def test_offsets_point_at_the_raw_character(self):
        raw = "  Șef — de  birou "
        folded, offsets = ev.fold_with_map(raw)
        assert folded == "sef de birou"
        assert [raw[o] for o in offsets if raw[o] != " "][0] == "Ș"
        # every offset is inside the raw text and offsets never go backwards
        assert all(0 <= o < len(raw) for o in offsets)
        assert offsets == sorted(offsets)

    def test_the_rule_is_named(self):
        assert ev.NORMALIZATION == "fold-alnum-v1"
        assert "fold-alnum-v1" in (ev.__doc__ or "")


# ---- the revision hash --------------------------------------------------------------------------

class TestSourceHash:
    def test_hash_is_sha256_of_raw_texts_joined_with_nul(self):
        import hashlib
        want = hashlib.sha256("page\x00attachments".encode()).hexdigest()
        assert ev.source_hash([("anunt", "page"), ("anexe", "attachments")]) == want
        assert ev.Source.build([("anunt", "page"), ("anexe", "attachments")]).text_hash == want

    def test_none_counts_as_empty(self):
        assert ev.source_hash([("anunt", None), ("anexe", None)]) == ev.source_hash([("anunt", ""), ("anexe", "")])

    def test_one_changed_byte_changes_the_hash_and_the_verdict(self):
        quote = "vechime în specialitatea studiilor: minimum 3 ani"
        before = ev.Source.build(PARTS)
        assert ev.locate(quote, before).status == "verified"
        edited = BODY.replace("minimum 3 ani", "minimum 4 ani")
        after = ev.Source.build([("anunt", edited), ("anexe", ATTACH)])
        assert after.text_hash != before.text_hash
        assert ev.locate(quote, after).reason == "not_found"       # a previously found quote is no longer found

    def test_whitespace_only_change_changes_the_hash_but_not_the_match(self):
        edited = BODY.replace("\n\n", "\n \n")
        a, b = ev.Source.build(PARTS), ev.Source.build([("anunt", edited), ("anexe", ATTACH)])
        assert a.text_hash != b.text_hash          # the hash is over exact bytes, not the folded text
        assert ev.locate("actul de identitate și curriculum vitae", b).status == "verified"

    def test_attachment_change_changes_the_hash(self):
        assert ev.source_hash([("anunt", "p"), ("anexe", "a")]) != ev.source_hash([("anunt", "p"), ("anexe", "a ")])
        assert ev.source_hash([("anunt", "pa"), ("anexe", "")]) != ev.source_hash([("anunt", "p"), ("anexe", "a")])


# ---- rows ----------------------------------------------------------------------------------------

def posting(rev=REV_A, extraction=REV_A, pid=7):
    return {"id": pid, "detail_content_hash": rev, "schema_source_revision": extraction}


def roles_for(schema, p=None):
    p = p or posting()
    flat = {"schema_source_revision": p["schema_source_revision"], "inf_studies_required": None, "v3_eqf_level": None,
            "v3_isced_fields": "[]", "inf_experience_years": None, "v3_skills": "[]", "v3_credentials": "[]",
            "v3_seniority_hint": ""}
    return posting_roles.role_rows(flat, schema)


FLAT_SCHEMA = {
    "education": {"minimum_level": "licenta", "eqf_level": 6,
                  "fields_of_study": [{"label_ro": "economie", "isced_field": "04_afaceri_administratie_drept"}],
                  "verbatim": "studii superioare de lungă durată, absolvite cu diplomă de licență"},
    "experience": {"years_minimum": 3, "in_specialty": True, "none_required": False,
                   "verbatim": "vechime în specialitatea studiilor: minimum 3 ani"},
    "skill_list": [
        {"label": "Excel", "evidence": "cunoștințe de operare Microsoft Excel"},
        {"label": "Word", "evidence": None},
        {"label": "Engleză", "evidence": "limba engleză la nivel avansat, scris și vorbit"},
        {"label": "GIS", "evidence": "GIS"},
    ],
    "credentials": [{"label": "Permis categoria B", "kind": "permis_conducere"},
                    {"label": "Autorizație CECCAR", "kind": "autorizatie"}],
    "language_list": [{"language": "engleză", "cefr": "B2"}],
}


def rows_of(schema, body=BODY, att=ATTACH, p=None):
    p = p or posting()
    return ev.evidence_rows(p, schema, roles_for(schema, p), body, att)


def by(rows, kind, index=0, ordinal=0):
    (row,) = [r for r in rows if (r["fact_kind"], r["fact_index"], r["ordinal"]) == (kind, index, ordinal)]
    return row


class TestEvidenceRows:
    def test_flat_posting_rows_cover_every_fact(self):
        rows = rows_of(FLAT_SCHEMA)
        assert [(r["fact_kind"], r["fact_index"]) for r in rows] == [
            ("education", 0), ("experience", 0),
            ("skill", 0), ("skill", 1), ("skill", 2), ("skill", 3),
            ("credential", 0), ("credential", 1)]
        assert {r["ordinal"] for r in rows} == {0}
        assert {r["role_key"] for r in rows} == {f"src:{REV_A[:12]}:0"}
        assert {r["basis"] for r in rows} == {"extracted"}
        assert {r["normalization"] for r in rows} == {"fold-alnum-v1"}
        assert {r["posting_id"] for r in rows} == {7}
        assert set(rows[0]) == set(ev.ROW_COLUMNS)

    def test_statuses(self):
        rows = rows_of(FLAT_SCHEMA)
        edu = by(rows, "education")
        assert (edu["status"], edu["part"], edu["section"], edu["occurrences"]) == ("verified", "anunt", "Condiții de participare", 1)
        assert edu["quote"] == FLAT_SCHEMA["education"]["verbatim"]
        assert by(rows, "experience")["status"] == "verified"
        assert by(rows, "skill", 0)["status"] == "verified"
        assert (by(rows, "skill", 1)["status"], by(rows, "skill", 1)["reason"], by(rows, "skill", 1)["quote"]) == ("absent", None, None)
        assert (by(rows, "skill", 2)["status"], by(rows, "skill", 2)["reason"]) == ("unverified", "not_found")
        assert (by(rows, "skill", 3)["status"], by(rows, "skill", 3)["reason"]) == ("unverified", "too_short")
        assert by(rows, "skill", 2)["quote"] == "limba engleză la nivel avansat, scris și vorbit"   # the model's text, shown as such
        assert by(rows, "skill", 2)["part"] is None

    def test_labels(self):
        rows = rows_of(FLAT_SCHEMA)
        assert by(rows, "education")["fact_label"] == "licenta"
        assert by(rows, "experience")["fact_label"] == "3 ani în specialitate"
        assert by(rows, "skill", 0)["fact_label"] == "Excel"
        assert by(rows, "credential", 1)["fact_label"] == "Autorizație CECCAR"

    @pytest.mark.parametrize("exp,label", [
        ({"years_minimum": 0.5}, "6 luni"), ({"years_minimum": 1}, "1 an"), ({"years_minimum": 1.5}, "1,5 ani"),
        ({"years_minimum": 25}, "25 de ani"),
        ({"years_minimum": 2, "in_specialty": True}, "2 ani în specialitate"),
        ({"none_required": True}, "fără experiență"), ({"years_minimum": 0}, "fără experiență"),
        ({"years_minimum": None, "verbatim": "ceva vechime"}, "experiență"),
    ])
    def test_experience_labels(self, exp, label):
        rows = rows_of({"experience": exp})
        assert by(rows, "experience")["fact_label"] == label

    def test_blocks_that_state_nothing_get_no_row(self):
        schema = {"education": {"minimum_level": None, "fields_of_study": [], "eqf_level": None, "verbatim": None},
                  "experience": {"years_minimum": None, "in_specialty": False, "none_required": False, "verbatim": None},
                  "skill_list": [], "credentials": []}
        assert rows_of(schema) == []

    def test_a_block_with_a_fact_but_no_quote_is_absent_not_missing(self):
        rows = rows_of({"education": {"minimum_level": "liceala", "verbatim": None}})
        (row,) = rows
        assert (row["status"], row["reason"], row["quote"]) == ("absent", None, None)

    def test_credentials_are_always_absent_for_want_of_an_evidence_field(self):
        rows = [r for r in rows_of(FLAT_SCHEMA) if r["fact_kind"] == "credential"]
        assert len(rows) == 2
        for r in rows:
            assert (r["status"], r["reason"], r["quote"], r["part"]) == ("absent", "no_evidence_field", None, None)
        # even a (hypothetical) stray evidence key is not trusted: the schema has no such field
        stray = rows_of({"credentials": [{"label": "X", "kind": "altele", "evidence": "permis de conducere categoria B"}]})
        assert stray[0]["status"] == "absent"

    def test_languages_are_not_requirement_evidence(self):
        assert "language" not in {r["fact_kind"] for r in rows_of(FLAT_SCHEMA)}

    def test_no_schema_or_v2_payload_has_no_rows(self):
        assert ev.evidence_rows(posting(), None, roles_for(None), BODY, ATTACH) == []
        assert rows_of({"title": "x", "description": "y"}) == []
        assert ev.evidence_rows(posting(), "not a dict", roles_for(None), BODY, ATTACH) == []

    def test_quote_is_clipped_for_storage_but_verified_in_full(self):
        long_quote = "studii superioare de lungă durată " + "absolvite cu diplomă " * 60
        body = "# T\n\n" + long_quote + "\n"
        row = rows_of({"education": {"minimum_level": "licenta", "verbatim": long_quote}}, body=body)[0]
        assert row["status"] == "verified"
        assert len(row["quote"]) <= ev.QUOTE_MAX and row["quote"].endswith("…")

    def test_blank_labels_are_skipped(self):
        rows = rows_of({"skill_list": [{"label": "  ", "evidence": "x y z w v u t s"}, "junk", None, {"label": "Excel", "evidence": None}],
                        "credentials": [{"label": "", "kind": ""}, {"label": "", "kind": "autorizatie"}]})
        assert [(r["fact_kind"], r["fact_index"], r["fact_label"]) for r in rows] == [("skill", 3, "Excel"), ("credential", 1, "autorizatie")]

    # ---- revisions ----

    def test_revisions_are_recorded_and_unknown_is_null(self):
        row = rows_of(FLAT_SCHEMA)[0]
        assert (row["source_revision"], row["extraction_revision"]) == (REV_A, REV_A)
        assert row["text_hash"] == ev.source_hash([("anunt", BODY), ("anexe", ATTACH)])
        legacy = rows_of(FLAT_SCHEMA, p=posting(rev="", extraction=""))[0]
        assert (legacy["source_revision"], legacy["extraction_revision"]) == (None, None)

    def test_stale_extraction_still_verifies_a_quote_that_is_found(self):
        stale = posting(rev=REV_B, extraction=REV_A)
        rows = rows_of(FLAT_SCHEMA, p=stale)
        assert by(rows, "education")["status"] == "verified"
        assert by(rows, "education")["reason"] is None
        assert (by(rows, "education")["source_revision"], by(rows, "education")["extraction_revision"]) == (REV_B, REV_A)

    def test_stale_extraction_marks_unfound_quotes_stale_revision(self):
        rows = rows_of(FLAT_SCHEMA, p=posting(rev=REV_B, extraction=REV_A))
        r = by(rows, "skill", 2)
        assert (r["status"], r["reason"]) == ("unverified", "stale_revision")
        # too short stays too short, absent stays absent, credentials stay no_evidence_field
        assert by(rows, "skill", 3)["reason"] == "too_short"
        assert by(rows, "skill", 1)["status"] == "absent"
        assert by(rows, "credential")["reason"] == "no_evidence_field"

    def test_same_revision_or_an_unknown_side_is_not_stale(self):
        for p in (posting(REV_A, REV_A), posting("", REV_A), posting(REV_A, "")):
            assert by(rows_of(FLAT_SCHEMA, p=p), "skill", 2)["reason"] == "not_found", p

    def test_changed_text_turns_verified_into_unverified_and_changes_text_hash(self):
        before = rows_of(FLAT_SCHEMA)
        edited = BODY.replace("minimum 3 ani", "minimum 4 ani")
        after = rows_of(FLAT_SCHEMA, body=edited)
        assert by(before, "experience")["status"] == "verified"
        assert (by(after, "experience")["status"], by(after, "experience")["reason"]) == ("unverified", "not_found")
        assert by(before, "experience")["text_hash"] != by(after, "experience")["text_hash"]
        assert by(after, "education")["status"] == "verified"      # untouched quote still verifies, against the new hash

    def test_no_source_text(self):
        rows = rows_of(FLAT_SCHEMA, body="", att="")
        assert by(rows, "education")["reason"] == "no_source_text"

    # ---- roles ----

    ROLE_1 = {"title": "Expert contabil", "education": {"minimum_level": "licenta",
                  "verbatim": "diplomă de licență în domeniul economic, ASF"},
              "experience": {"years_minimum": 5, "in_specialty": True, "verbatim": "vechime în specialitatea studiilor: minimum 3 ani"},
              "skill_list": [{"label": "Contabilitate", "evidence": "cunoștințe de operare Microsoft Excel"}],
              "credentials": [{"label": "CECCAR", "kind": "autorizatie"}]}
    ROLE_2 = {"title": "Referent", "education": {"minimum_level": "liceala", "verbatim": "studii liceale absolvite cu diplomă de bacalaureat"},
              "experience": {"none_required": True},
              "skill_list": [{"label": "Excel", "evidence": None}],
              "credentials": []}

    def test_a_decomposed_posting_has_rows_for_its_roles_only(self):
        schema = {**FLAT_SCHEMA, "positions": [self.ROLE_1, self.ROLE_2]}
        rows = rows_of(schema)
        assert {r["ordinal"] for r in rows} == {1, 2}                 # no ordinal-0 rows: the flat blocks are not a role
        assert {r["role_key"] for r in rows} == {f"src:{REV_A[:12]}:1", f"src:{REV_A[:12]}:2"}
        assert not [r for r in rows if r["fact_label"] == "Engleză"]   # flat skills are not inherited

    def test_each_role_reads_only_its_own_entry(self):
        rows = rows_of({**FLAT_SCHEMA, "positions": [self.ROLE_1, self.ROLE_2]})
        r1 = [r for r in rows if r["ordinal"] == 1]
        r2 = [r for r in rows if r["ordinal"] == 2]
        assert {r["fact_kind"] for r in r1} == {"education", "experience", "skill", "credential"}
        assert [(r["fact_kind"], r["fact_label"]) for r in r2] == [
            ("education", "liceala"), ("experience", "fără experiență"), ("skill", "Excel")]
        # role 1's quote lives only on role 1
        assert by(rows, "experience", 0, 1)["quote"] == "vechime în specialitatea studiilor: minimum 3 ani"
        assert by(rows, "experience", 0, 2)["quote"] is None
        assert all("bacalaureat" not in (r["quote"] or "") for r in r1)
        assert all("licență" not in (r["quote"] or "") for r in r2)

    def test_role_with_no_block_has_no_inherited_rows(self):
        schema = {**FLAT_SCHEMA, "positions": [{"title": "Muncitor", "education": None}]}
        assert rows_of(schema) == []

    def test_ordinal_is_the_array_position_even_across_a_junk_entry(self):
        schema = {"positions": ["junk", dict(self.ROLE_2)]}
        rows = rows_of(schema)
        assert {r["ordinal"] for r in rows} == {2}
        assert rows[0]["role_key"].endswith(":2")

    def test_rows_follow_the_roles_it_is_given(self):
        schema = {**FLAT_SCHEMA, "positions": [self.ROLE_1, self.ROLE_2]}
        only_second = [r for r in roles_for(schema) if r["ordinal"] == 2]
        rows = ev.evidence_rows(posting(), schema, only_second, BODY, ATTACH)
        assert {r["ordinal"] for r in rows} == {2}

    def test_rows_are_unique_per_primary_key(self):
        schema = {**FLAT_SCHEMA, "positions": [self.ROLE_1, self.ROLE_2, dict(self.ROLE_1)]}
        rows = rows_of(schema)
        keys = [(r["posting_id"], r["ordinal"], r["fact_kind"], r["fact_index"]) for r in rows]
        assert len(keys) == len(set(keys))


class TestCoverage:
    def test_lines_have_denominators_and_reasons(self):
        counts = [("education", "verified", None, 6), ("education", "unverified", "not_found", 1),
                  ("education", "unverified", "too_short", 1), ("education", "absent", None, 2),
                  ("credential", "absent", "no_evidence_field", 4)]
        lines = ev.coverage_lines(counts)
        assert lines[0].strip() == ("education  verified 6/10, unverified 2/10 (not_found 1, too_short 1), absent 2/10")
        assert lines[1].strip() == "credential verified 0/4, unverified 0/4, absent 4/4"

    def test_empty_counts_print_nothing(self):
        assert ev.coverage_lines([]) == []


# ---- the real export() ---------------------------------------------------------------------------

@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("evidence_export_under_test", REPO_ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["evidence_export_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


def export_posting(pid, schema, *, body=BODY, attachment=ATTACH, rev=REV_A, extraction=REV_A):
    row = posting_row(pid, schema_json=json.dumps(schema, ensure_ascii=False) if schema is not None else None,
                      revision=extraction)
    row["body_markdown"] = body
    row["detail_content_hash"] = rev
    row["attachment_text"] = attachment
    return row


@pytest.fixture
def exported(exp, tmp_path):
    two_roles = {**FLAT_SCHEMA, "positions": [TestEvidenceRows.ROLE_1, TestEvidenceRows.ROLE_2]}
    postings = [
        export_posting(1, FLAT_SCHEMA),
        export_posting(2, two_roles),
        export_posting(3, None, attachment=None),
        export_posting(4, FLAT_SCHEMA, rev=REV_B, extraction=REV_A, body=BODY.replace("minimum 3 ani", "minimum 4 ani")),
    ]
    path, con = run_export(exp, tmp_path, postings)
    yield path, con
    con.close()


def test_export_writes_evidence_rows(exported):
    _, con = exported
    n = {pid: c for pid, c in con.execute("SELECT posting_id, COUNT(*) FROM requirement_evidence GROUP BY posting_id")}
    assert n == {1: 8, 2: 7, 4: 8}      # posting 3 has no schema, hence no facts; posting 2: 4 rows for role 1, 3 for role 2
    row = con.execute("SELECT status, part, section, occurrences, quote, source_revision, extraction_revision, normalization "
                      "FROM requirement_evidence WHERE posting_id = 1 AND fact_kind = 'education'").fetchone()
    assert row == ("verified", "anunt", "Condiții de participare", 1, FLAT_SCHEMA["education"]["verbatim"], REV_A, REV_A, "fold-alnum-v1")
    assert con.execute("SELECT DISTINCT ordinal FROM requirement_evidence WHERE posting_id = 2 ORDER BY 1").fetchall() == [(1,), (2,)]


def test_export_never_writes_attachment_text_to_the_database(exported):
    path, con = exported
    cols = {r[1] for r in con.execute("PRAGMA table_info(job_postings)")}
    assert "attachment_text" not in cols
    con.commit()
    assert b"Bibliografie" not in Path(path).read_bytes()      # the attachment's words are nowhere in the file


def test_attachment_only_quote_reaches_the_table_as_anexe(exp, tmp_path):
    schema = {"education": {"minimum_level": "liceala", "verbatim": "Candidații trebuie să dețină permis de conducere categoria B"}}
    _, con = run_export(exp, tmp_path, [export_posting(1, schema)])
    assert con.execute("SELECT status, part, section FROM requirement_evidence").fetchall() == [("verified", "anexe", None)]
    con.close()


def test_stale_revision_and_changed_text_reach_the_table(exported):
    _, con = exported
    rows = dict(con.execute("SELECT fact_kind || ':' || fact_index, status || '/' || COALESCE(reason, '') "
                            "FROM requirement_evidence WHERE posting_id = 4").fetchall())
    assert rows["experience:0"] == "unverified/stale_revision"
    assert rows["education:0"] == "verified/"
    revs = con.execute("SELECT DISTINCT source_revision, extraction_revision FROM requirement_evidence WHERE posting_id = 4").fetchall()
    assert revs == [(REV_B, REV_A)]


def test_text_hash_is_per_posting_text(exported):
    _, con = exported
    hashes = {pid: h for pid, h in con.execute("SELECT posting_id, MIN(text_hash) FROM requirement_evidence GROUP BY posting_id")}
    assert hashes[1] == ev.source_hash([("anunt", BODY), ("anexe", ATTACH)])
    assert hashes[1] != hashes[4]
    assert con.execute("SELECT COUNT(DISTINCT text_hash) FROM requirement_evidence WHERE posting_id = 1").fetchone()[0] == 1


def test_primary_key_serves_the_page_lookup(exported):
    _, con = exported
    pk = [r[2] for r in con.execute("PRAGMA index_info('sqlite_autoindex_requirement_evidence_1')")]
    assert pk == ["posting_id", "ordinal", "fact_kind", "fact_index"]


def test_verify_passes_and_refuses_a_bad_evidence_table(exp, exported):
    path, con = exported
    con.commit()
    assert exp.verify(str(path), min_rows=0, previous=None, active_only=False) == 4
    con.execute("PRAGMA foreign_keys=OFF")
    con.execute("INSERT INTO requirement_evidence(posting_id, role_key, ordinal, fact_kind, fact_index, fact_label, basis, "
                "status, text_hash, normalization) VALUES (999, 'k', 0, 'skill', 0, 'x', 'extracted', 'absent', 'h', 'n')")
    con.commit()
    with pytest.raises(SystemExit, match="requirement_evidence"):
        exp.verify(str(path), min_rows=0, previous=None, active_only=False)
    con.execute("DELETE FROM requirement_evidence WHERE posting_id = 999")
    con.execute("UPDATE requirement_evidence SET quote = NULL WHERE posting_id = 1 AND fact_kind = 'education'")
    con.commit()
    with pytest.raises(SystemExit, match="requirement_evidence"):
        exp.verify(str(path), min_rows=0, previous=None, active_only=False)      # verified without a quote


def test_coverage_is_printed_with_denominators(exp, tmp_path, capsys):
    run_export(exp, tmp_path, [export_posting(1, FLAT_SCHEMA)])
    out = capsys.readouterr().out
    assert "requirement_evidence: 8 rows" in out
    assert "education  verified 1/1" in out
    assert "skill      verified 1/4, unverified 2/4 (not_found 1, too_short 1), absent 1/4" in out
    assert "credential verified 0/2, unverified 0/2, absent 2/2" in out


def test_a_cursor_without_attachment_text_still_exports(exp, tmp_path):
    row = posting_row(1, schema_json=json.dumps(FLAT_SCHEMA), revision=REV_A)     # the shared fixture has no such key
    row["body_markdown"] = BODY
    row["detail_content_hash"] = REV_A
    _, con = run_export(exp, tmp_path, [row])
    status = dict(con.execute("SELECT fact_kind, status FROM requirement_evidence WHERE fact_index = 0 AND fact_kind IN ('education', 'experience')"))
    assert status == {"education": "verified", "experience": "verified"}
    con.close()


def test_unicode_helpers_are_the_stdlib_ones():
    # guard against a platform whose unicodedata disagrees with the tables the folding relies on
    assert unicodedata.normalize("NFKD", "ș") == "ș"
    assert unicodedata.normalize("NFKD", "ţ") == "ţ"

"""Experience inference: label-first phrasing, and not confusing age with tenure."""

import pytest

from apps.jobs.management.commands.infer_postings import _infer_experience


class TestLabelFirstPhrasing:
    """"Vechime în muncă: minim 3 ani" is how Romanian postings actually write it."""

    @pytest.mark.parametrize(
        "body,years",
        [
            ("Vechime în muncă: minim 3 ani", 3),
            ("Vechimea în muncă: 15 ani", 15),
            ("Experiență în specialitate de minim 5 ani", 5),
            ("Condiții: experienta minim 1 an", 1),
            ("vechime in munca: vechime 25 ani", 25),
        ],
    )
    def test_label_before_number(self, body, years):
        assert _infer_experience(body) == (years, False)

    def test_number_before_label_still_works(self):
        """The older "3 ani de vechime" shape must keep parsing."""
        assert _infer_experience("Se cere 3 ani de vechime în domeniu") == (3, False)


class TestAgeIsNotExperience:
    def test_minimum_age_is_not_read_as_tenure(self):
        """The bug this guard exists for.

        _normalize() collapses newlines, so an age line runs straight into the
        next one and "…vârsta de minim 21 ani  Vechime in munca: minim 1 ani"
        used to yield 21 years of experience for a driver's job.
        """
        body = (
            "Conditii specifice de participare:\n"
            "Studii minime-studii generale/medii\n"
            "Sa aiba varsta de minim 21 ani\n"
            "Vechime in munca: minim 1 ani\n"
            "Permis categoria D"
        )
        assert _infer_experience(body) == (1, False)

    def test_age_alone_yields_no_experience_value(self):
        assert _infer_experience("Candidatul sa aiba varsta de minim 18 ani.") == (None, False)


class TestNoExperienceRequired:
    @pytest.mark.parametrize(
        "phrase",
        [
            "Vechime în specialitate: nu este cazul",
            "nu se solicită vechime",
            "Experiența nu este obligatorie",
        ],
    )
    def test_explicit_phrases_flag_zero(self, phrase):
        assert _infer_experience(phrase) == (0, True)

    def test_a_number_wins_over_the_phrase(self):
        """An explicit count is a stronger signal than a boilerplate phrase."""
        years, no_exp = _infer_experience("Vechime în muncă: 4 ani. Altele: nu este cazul")
        assert (years, no_exp) == (4, False)

    def test_silence_is_not_a_claim(self):
        assert _infer_experience("Post vacant de îngrijitor.") == (None, False)


# --------------------------------------------- LLM results survive a --no-llm run

@pytest.mark.django_db
class TestNoLlmPreservesLlmFamily:
    """A --force --no-llm pass must not downgrade paid LLM classifications.

    `--no-llm` means "do not call the LLM on this run", not "discard what a
    previous paid run established". Without the guard, re-running the dictionary
    pass to pick up an unrelated regex fix silently rewrote 598 LLM-classified
    postings to the dictionary's 'altele'.
    """

    def _posting(self, title, inferred):
        from apps.jobs.models import Employer, JobPosting

        employer, _ = Employer.objects.get_or_create(name="Test", defaults={"slug": "test"})
        return JobPosting.objects.create(
            url=f"https://example.org/{abs(hash(title))}",
            title=title,
            employer=employer,
            body_markdown="Un corp de anunț suficient de lung pentru a nu declanșa no_body.  " * 5,
            inferred=inferred,
        )

    def _infer(self, posting):
        from apps.jobs.management.commands.infer_postings import infer_posting

        return infer_posting(posting, provider="gemini", use_llm=False)

    def test_existing_llm_family_is_kept(self):
        out = self._infer(self._posting(
            "Ceva ce dicționarul nu recunoaște",
            {"profession_family": "cultură", "profession_family_confidence": 0.7,
             "profession_family_source": "llm"},
        ))
        assert out["profession_family"] == "cultură"
        assert out["profession_family_source"] == "llm"

    def test_dictionary_still_wins_when_it_is_confident(self):
        out = self._infer(self._posting(
            "Medic specialist cardiologie",
            {"profession_family": "altele", "profession_family_confidence": 0.7,
             "profession_family_source": "llm"},
        ))
        assert out["profession_family"] == "sănătate"
        assert out["profession_family_source"] == "dict"

    def test_no_previous_data_falls_back_to_the_dictionary(self):
        out = self._infer(self._posting("Ceva complet necunoscut", {}))
        assert out["profession_family_source"] == "dict"


# ------------------------------------------------------- skill / tag extraction

class TestSkillExtraction:
    """Keywords must match whole words, not substrings.

    A plain `in` test made "SAR" fire on 87% of postings — it is inside
    *necesare*, *comisar*, *sarcini* — and "atestat" on the boilerplate
    "starea de sănătate atestată". Both looked like data and were noise.
    """

    @pytest.mark.parametrize(
        "body",
        [
            "Sunt necesare cunoștințe de bază.",
            "Îndeplinește sarcinile stabilite de comisar.",
            "Atribuțiile necesare postului.",
        ],
    )
    def test_sar_does_not_match_inside_words(self, body):
        from apps.jobs.management.commands.infer_postings import _infer_skills

        assert "SAR" not in _infer_skills(body)

    def test_sar_still_matches_as_a_word(self):
        from apps.jobs.management.commands.infer_postings import _infer_skills

        assert "SAR" in _infer_skills("Experiență în aplicația SAR este un avantaj.")

    def test_atestat_does_not_match_atestata(self):
        from apps.jobs.management.commands.infer_postings import _infer_skills

        assert "atestat" not in _infer_skills("stare de sănătate atestată pe baza adeverinței")

    def test_real_skills_are_still_found(self):
        from apps.jobs.management.commands.infer_postings import _infer_skills

        found = _infer_skills("Cunoștințe Excel și Word, permis de conducere categoria B.")
        assert {"Excel", "Word", "permis de conducere"} <= set(found)


class TestTagDeduplication:
    def test_certifications_collapse_case_and_whitespace(self):
        from apps.jobs.management.commands.infer_postings import _infer_certifications

        body = "Certificat de absolvire ... certificat de absolvire ... Certificat\xa0 de absolvire"
        assert len(_infer_certifications(body)) == 1

    def test_languages_use_one_canonical_spelling(self):
        from apps.jobs.management.commands.infer_postings import _infer_languages

        assert _infer_languages("limba engleza si limba engleză") == ["engleză"]

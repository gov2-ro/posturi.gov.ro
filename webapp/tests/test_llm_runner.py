"""The retry, concurrency and grounding layers around the LLM call.

A full v3 run is ~9,600 calls per model. At that volume rate limits and 5xx
responses are certainties, not edge cases, and before the retry layer existed a
single 429 dropped that posting permanently — the loop printed "✗" and moved on.
These tests use fake `generate` callables, so none of them touch a provider.
"""

import importlib.util
import sys
import threading
import time
from pathlib import Path

import pytest
from pydantic import ValidationError

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from grounding import Source, check_grounding, is_grounded  # noqa: E402
from schema_models import EducationRequirement, LanguageRequirement  # noqa: E402


@pytest.fixture(scope="module")
def llm():
    """`llm-schema.py` is hyphenated, so it can only be loaded by path."""
    spec = importlib.util.spec_from_file_location(
        "llmschema_under_test", REPO_ROOT / "llm-schema.py"
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


# --------------------------------------------------------------- error triage

class _Status429(Exception):
    status_code = 429


class _GeminiStyle503(Exception):
    """google-genai exposes `.code`, not `.status_code`."""
    code = 503


class RateLimitError(Exception):
    """Named like the SDK exceptions that carry no numeric status."""


class _Status400(Exception):
    status_code = 400


class TestErrorTriage:
    def test_numeric_status_is_used_when_present(self, llm):
        assert llm._is_transient(_Status429())
        assert llm._is_transient(_GeminiStyle503())

    def test_client_errors_are_not_retried(self, llm):
        """A 400 will fail identically forever; retrying just burns money."""
        assert not llm._is_transient(_Status400())

    def test_falls_back_to_class_name_without_a_status(self, llm):
        assert llm._is_transient(RateLimitError())

    def test_validation_failures_are_repairable_not_transient(self, llm):
        exc = pytest.raises(
            ValidationError, EducationRequirement.model_validate, {"eqf_level": 99}
        ).value
        assert llm._is_repairable(exc)
        assert not llm._is_transient(exc)

    def test_missing_tool_block_is_repairable(self, llm):
        """The Anthropic branch pulls tool_use out with next(); no block raises."""
        assert llm._is_repairable(StopIteration())


# --------------------------------------------------------------------- retry

class TestGenerateWithRetry:
    def test_returns_immediately_on_success(self, llm):
        calls = []
        result = llm.generate_with_retry(lambda c: calls.append(c) or "ok", "body")
        assert result == "ok" and calls == ["body"]

    def test_retries_transient_then_succeeds(self, llm):
        attempts = []

        def generate(content):
            attempts.append(content)
            if len(attempts) < 3:
                raise _Status429()
            return "ok"

        assert llm.generate_with_retry(generate, "body", base_delay=0.001) == "ok"
        assert len(attempts) == 3
        # Same input every time — a transient failure is not the model's fault.
        assert attempts == ["body"] * 3

    def test_gives_up_after_max_attempts_and_reraises(self, llm):
        def generate(content):
            raise _Status429()

        with pytest.raises(_Status429):
            llm.generate_with_retry(generate, "body", max_attempts=2, base_delay=0.001)

    def test_client_error_is_not_retried(self, llm):
        attempts = []

        def generate(content):
            attempts.append(content)
            raise _Status400()

        with pytest.raises(_Status400):
            llm.generate_with_retry(generate, "body", base_delay=0.001)
        assert len(attempts) == 1

    def test_repair_appends_the_error_to_the_user_message(self, llm):
        attempts = []

        def generate(content):
            attempts.append(content)
            if len(attempts) == 1:
                raise ValueError("Expected dict, got str")
            return "ok"

        assert llm.generate_with_retry(generate, "body", base_delay=0.001) == "ok"
        assert attempts[0] == "body"
        assert attempts[1].startswith("body")
        assert "Expected dict, got str" in attempts[1]

    def test_repair_is_attempted_only_once(self, llm):
        """If showing the model its error does not help, a third full prompt won't."""
        attempts = []

        def generate(content):
            attempts.append(content)
            raise ValueError("still broken")

        with pytest.raises(ValueError):
            llm.generate_with_retry(generate, "body", max_attempts=5, base_delay=0.001)
        assert len(attempts) == 2

    def test_backoff_grows_and_on_retry_is_notified(self, llm, monkeypatch):
        slept, notified = [], []
        monkeypatch.setattr(llm.time, "sleep", slept.append)

        def generate(content):
            raise _Status429()

        with pytest.raises(_Status429):
            llm.generate_with_retry(
                generate, "body", max_attempts=4, base_delay=1.0,
                on_retry=lambda attempt, exc, delay: notified.append((attempt, delay)),
            )
        assert len(slept) == 3
        assert slept[0] < slept[1] < slept[2]      # exponential, jitter is ±20%
        assert [a for a, _ in notified] == [1, 2, 3]


# --------------------------------------------------------------- concurrency

class TestImapUnordered:
    def test_sequential_path_yields_every_item(self, llm):
        items = list(range(5))
        out = [(i, f.result()) for i, f in llm.imap_unordered(lambda x: x * 2, items, 1)]
        assert out == [(i, i * 2) for i in items]

    def test_concurrent_path_yields_every_item_exactly_once(self, llm):
        items = list(range(50))
        seen = [(i, f.result()) for i, f in llm.imap_unordered(lambda x: x * 2, items, 8)]
        assert sorted(seen) == [(i, i * 2) for i in items]

    def test_failures_surface_through_the_future_not_the_generator(self, llm):
        """One bad posting must not abort the run — the caller decides."""
        def work(x):
            if x == 3:
                raise RuntimeError("boom")
            return x

        results = dict(llm.imap_unordered(work, range(6), 4))
        assert pytest.raises(RuntimeError, results[3].result)
        assert [results[i].result() for i in (0, 1, 2, 4, 5)] == [0, 1, 2, 4, 5]

    def test_work_actually_runs_in_parallel(self, llm):
        threads = set()

        def work(x):
            threads.add(threading.get_ident())
            time.sleep(0.02)
            return x

        list(llm.imap_unordered(work, range(8), 4))
        assert len(threads) > 1

    def test_iterator_is_not_drained_up_front(self, llm):
        """A 9,600-row cursor must not be materialised to start work."""
        pulled = []

        def counting_source():
            for i in range(100):
                pulled.append(i)
                yield i

        gen = llm.imap_unordered(lambda x: x, counting_source(), 2, queue_depth=2)
        next(gen)
        assert len(pulled) < 100
        gen.close()


# ---------------------------------------------------------------- grounding

SOURCE = (
    "Studii universitare de licență absolvite cu diplomă de licență în domeniul "
    "silvicultură. Cunoștințe avansate de operare sisteme GIS și analiză spațială. "
    "Vechime în specialitate minim 3 ani."
)


class TestGrounding:
    def test_quotes_taken_from_the_source_pass(self):
        schema = {
            "education": {"verbatim": "Studii universitare de licență absolvite cu diplomă de licență"},
            "skill_list": [{"label": "GIS", "evidence": "Cunoștințe avansate de operare sisteme GIS"}],
        }
        assert check_grounding(schema, SOURCE) == []

    def test_invented_requirement_is_flagged(self):
        schema = {"skill_list": [{
            "label": "Kubernetes",
            "evidence": "Cunoștințe avansate de orchestrare containere Kubernetes și Docker Swarm",
        }]}
        findings = check_grounding(schema, SOURCE)
        assert len(findings) == 1
        assert findings[0].path == "skill_list[0].evidence"
        assert findings[0].coverage < 0.5

    def test_long_verbatim_span_survives_an_invented_lead_in(self):
        """Coverage alone punishes length; a reproduced phrase rescues it."""
        schema = {"education": {"verbatim":
            "Printre altele se solicită următoarele: Studii universitare de licență "
            "absolvite cu diplomă de licență în domeniul silvicultură"}}
        assert check_grounding(schema, SOURCE) == []

    def test_nested_positions_are_checked(self):
        schema = {"positions": [{"skill_list": [
            {"label": "inventat", "evidence": "certificare AWS Solutions Architect Professional"}
        ]}]}
        findings = check_grounding(schema, SOURCE)
        assert [f.path for f in findings] == ["positions[0].skill_list[0].evidence"]

    def test_missing_and_null_quotes_are_not_flagged(self):
        """`evidence` is optional — absent is not the same as unsupported."""
        schema = {"skill_list": [{"label": "GIS", "evidence": None}, {"label": "X"}]}
        assert check_grounding(schema, SOURCE) == []

    def test_diacritics_do_not_affect_matching(self):
        src = Source.build("Vechime în specialitate minim 3 ani")
        assert is_grounded("vechime in specialitate minim 3 ani", src, 0.6)[0]


# ------------------------------------------------------- derived, not asked

class TestDerivedFields:
    def test_eqf_level_is_derived_from_minimum_level(self):
        assert EducationRequirement(minimum_level="licenta").eqf_level == 6

    def test_a_wrong_model_supplied_eqf_is_overwritten(self):
        """The two fields are one fact in two notations; the table is authoritative."""
        assert EducationRequirement(minimum_level="doctorat", eqf_level=4).eqf_level == 8

    def test_eqf_survives_when_no_level_is_stated(self):
        assert EducationRequirement(eqf_level=7).eqf_level == 7

    @pytest.mark.parametrize("name,code", [
        ("engleză", "en"), ("Engleza", "en"), ("limba franceză", "fr"),
        ("GERMANĂ", "de"), ("maghiara", "hu"),
    ])
    def test_iso_code_is_derived_from_the_romanian_name(self, name, code):
        assert LanguageRequirement(language=name).iso_code == code

    def test_unknown_language_keeps_the_model_value(self):
        assert LanguageRequirement(language="klingoniană", iso_code="tlh").iso_code == "tlh"


# ------------------------------------------------------- cache-key collision

class TestFetchAnunturiSlug:
    """`get_slug` decides the HTML cache filename; a collision loses postings.

    The site links some cards by raw WordPress permalink
    (`/?post_type=pg_job&p=26404`) rather than `/joburi/{slug}/`. Those have an
    empty URL path, and the original `path.split('/')[-1] if path else 'index'`
    mapped every one of them to `index.html`. 80 postings collapsed into 28
    files and came out of the pipeline with no body, no attachment and no
    `expires_at`.
    """

    @staticmethod
    def _get_slug():
        spec = importlib.util.spec_from_file_location(
            "fetch_anunturi_under_test", REPO_ROOT / "fetch-anunturi.py"
        )
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module.get_slug

    def test_query_string_urls_do_not_collide(self):
        get_slug = self._get_slug()
        a = get_slug("https://posturi.gov.ro/?post_type=pg_job&p=26404")
        b = get_slug("https://posturi.gov.ro/?post_type=pg_job&p=23782")
        assert a != b

    def test_slug_is_filename_safe(self):
        slug = self._get_slug()("https://posturi.gov.ro/?post_type=pg_job&p=26404")
        assert "/" not in slug and "?" not in slug and "&" not in slug and "=" not in slug
        assert "26404" in slug

    def test_slug_is_stable_across_calls(self):
        """The cache key must not drift, or every run re-fetches everything."""
        get_slug = self._get_slug()
        url = "https://posturi.gov.ro/?post_type=pg_job&p=26404"
        assert get_slug(url) == get_slug(url)

    @pytest.mark.parametrize("url,expected", [
        ("https://posturi.gov.ro/joburi/economist-1/", "economist-1"),
        ("https://posturi.gov.ro/anunt/asistent-medical-pl-58/", "asistent-medical-pl-58"),
    ])
    def test_path_urls_are_unchanged(self, url, expected):
        assert self._get_slug()(url) == expected

    def test_bare_root_still_falls_back(self):
        assert self._get_slug()("https://posturi.gov.ro/") == "index"

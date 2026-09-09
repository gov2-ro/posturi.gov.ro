"""Tests for the LLM-fallback provider dispatch in `_llm_classify`.

These tests fake the `openai.OpenAI` client, so none of them touch a provider.
"""
import pytest

from apps.jobs.management.commands.infer_postings import _llm_classify, _match_family


class _FakeCompletions:
    def __init__(self, seen):
        self._seen = seen

    def create(self, **kwargs):
        self._seen.update(kwargs)
        msg = type("Message", (), {"content": "IT"})()
        return type("Resp", (), {"choices": [type("Choice", (), {"message": msg})()]})()


class _FakeClient:
    def __init__(self, seen, **kwargs):
        seen["client_kwargs"] = kwargs
        self.chat = type("Chat", (), {"completions": _FakeCompletions(seen)})()


def test_deepseek_branch(monkeypatch):
    """The deepseek branch calls DeepSeek over the OpenAI SDK and round-trips the answer."""
    import openai

    seen = {}
    monkeypatch.setenv("DEEPSEEK_API_KEY", "test-key")
    monkeypatch.setattr(
        openai, "OpenAI", lambda **kwargs: _FakeClient(seen, **kwargs)
    )

    assert _llm_classify("Inginer de sistem", "deepseek") == "IT"
    assert seen["client_kwargs"]["base_url"] == "https://api.deepseek.com"
    assert seen["model"] == "deepseek-v4-flash"
    assert seen["max_tokens"] == 20


def test_unknown_provider_raises():
    """An unrecognised provider raises instead of silently classifying `altele`."""
    with pytest.raises(RuntimeError, match="Unknown provider"):
        _llm_classify("Informatician", "unknown")


class TestResponseValidation:
    """`IT` used to absorb every answer the matcher could not parse.

    `PROFESSION_FAMILIES` is `sorted()` and `IT` is uppercase, so it sorts
    first; the old `norm in _normalize(fam)` test then matched `"" in "it"`.
    DeepSeek with reasoning enabled returns empty content, which is how 50 of
    60 `IT` postings became îngrijitoare, bibliotecari and infirmiere.
    """

    @pytest.mark.parametrize("raw", ["", "   ", "\n", "i", "???"])
    def test_unparseable_answers_are_altele_not_it(self, raw):
        assert _match_family(raw) == "altele"

    @pytest.mark.parametrize("raw", ["sanitar", "ingrijitoare", "spitalicesc"])
    def test_it_does_not_match_the_letters_inside_a_word(self, raw):
        assert _match_family(raw) == "altele"

    @pytest.mark.parametrize(
        "raw,family",
        [
            ("IT", "IT"),
            ("it", "IT"),
            ("**IT**", "IT"),
            ("sănătate", "sănătate"),
            ("Sănătate.", "sănătate"),
            ("SOCIAL", "social"),
            ("Îngrijitoare → sănătate", "sănătate"),
            ("ordine publică", "ordine publică"),
            ("ordine", "ordine publică"),  # truncated at max_tokens
            ("altele", "altele"),
        ],
    )
    def test_recognised_answers(self, raw, family):
        assert _match_family(raw) == family

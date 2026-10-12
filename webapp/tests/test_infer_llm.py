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


# --------------------------------------------------------------- FIX-10: the ledger

class TestInferLedger:
    """`infer` calls the LLM once per low-confidence title; each call is a ledger row."""

    def _ledger(self):
        import llm_ledger

        sink = llm_ledger.MemorySink()
        ledger = llm_ledger.Ledger(
            sink, step="infer", provider="deepseek", model="deepseek-v4-flash", run_id="R",
            # Like the real pricer: unknown tokens mean an unknown cost, never $0.
            pricer=lambda provider, model, usage, at: (
                None if usage is None or usage.input_tokens is None
                else usage.input_tokens / 1e6, {"peak": False}))
        return ledger, sink

    def _deepseek(self, monkeypatch, *, usage=True, content="IT", error=None):
        import openai

        def create(**kwargs):
            if error:
                raise error
            msg = type("Message", (), {"content": content})()
            resp = type("Resp", (), {"id": "cmpl-1",
                                     "choices": [type("Choice", (), {"message": msg})()]})()
            if usage:
                resp.usage = type("Usage", (), {
                    "prompt_tokens": 90, "completion_tokens": 2, "prompt_cache_hit_tokens": 60,
                    "completion_tokens_details": type("D", (), {"reasoning_tokens": 0})()})()
            return resp

        client = type("C", (), {"chat": type("Ch", (), {"completions": type(
            "Co", (), {"create": staticmethod(create)})()})()})()
        monkeypatch.setenv("DEEPSEEK_API_KEY", "test-key")
        monkeypatch.setattr(openai, "OpenAI", lambda **kw: client)

    def test_a_classification_is_one_ok_row_with_its_usage(self, monkeypatch):
        self._deepseek(monkeypatch)
        ledger, sink = self._ledger()
        assert _llm_classify("Inginer de sistem", "deepseek",
                             ledger=ledger.request("Inginer de sistem")) == "IT"
        (row,) = sink.rows
        assert (row["step"], row["outcome"], row["api_ok"], row["attempt"]) == ("infer", "ok", True, 1)
        assert (row["input_tokens"], row["output_tokens"], row["cache_read_tokens"]) == (90, 2, 60)
        assert row["subject"] == "Inginer de sistem" and row["request_id"] == "cmpl-1"

    def test_a_response_without_usage_is_a_row_with_unknown_tokens(self, monkeypatch):
        self._deepseek(monkeypatch, usage=False)
        ledger, sink = self._ledger()
        _llm_classify("Inginer", "deepseek", ledger=ledger.request("Inginer"))
        (row,) = sink.rows
        assert row["outcome"] == "ok" and row["input_tokens"] is None and row["cost_usd"] is None

    def test_a_failed_call_is_a_row_and_still_raises(self, monkeypatch):
        self._deepseek(monkeypatch, error=TimeoutError("took too long"))
        ledger, sink = self._ledger()
        with pytest.raises(RuntimeError, match="LLM call failed"):
            _llm_classify("Inginer", "deepseek", ledger=ledger.request("Inginer"))
        (row,) = sink.rows
        assert (row["outcome"], row["api_ok"], row["error_class"]) == ("error", False, "TimeoutError")

    def test_an_unrecognised_answer_is_still_a_paid_ok_call(self, monkeypatch):
        self._deepseek(monkeypatch, content="no idea")
        ledger, sink = self._ledger()
        assert _llm_classify("Zzz", "deepseek", ledger=ledger.request("Zzz")) == "altele"
        assert sink.rows[0]["outcome"] == "ok" and sink.rows[0]["input_tokens"] == 90

    def test_the_models_are_named_once(self):
        from apps.jobs.management.commands.infer_postings import LLM_MODELS

        assert LLM_MODELS["deepseek"] == "deepseek-v4-flash"
        assert set(LLM_MODELS) == {"gemini", "openai", "anthropic", "deepseek"}

    @pytest.mark.django_db
    def test_the_command_writes_one_usage_record_for_the_run(self, monkeypatch, tmp_path):
        import json

        from django.core.management import call_command

        from apps.jobs.models import Employer, JobPosting

        monkeypatch.setenv("POSTURI_LEDGER", "off")
        monkeypatch.setenv("POSTURI_RUN_ID", "2026-10-13T10:15:01Z")
        monkeypatch.setattr("time.sleep", lambda s: None)
        self._deepseek(monkeypatch)
        employer = Employer.objects.create(name="Test", slug="test")
        for n in range(2):
            JobPosting.objects.create(url=f"https://example.org/p{n}", title=f"Zzyzx qwv {n}",
                                      employer=employer, body_markdown="Corp. " * 40, inferred={})
        log = tmp_path / "runs.jsonl"
        call_command("infer_postings", provider="deepseek", run_log=str(log))
        (record,) = [json.loads(line) for line in log.read_text().splitlines()]
        assert record["kind"] == "llm-usage" and record["step"] == "infer"
        assert record["run_id"] == "2026-10-13T10:15:01Z" and record["calls"] == 2
        assert record["usage"]["input_tokens"] == 180 and record["usage"]["cost_usd"] > 0

    @pytest.mark.django_db
    def test_no_llm_means_no_ledger_and_no_record(self, monkeypatch, tmp_path):
        from django.core.management import call_command

        from apps.jobs.models import Employer, JobPosting

        monkeypatch.setenv("POSTURI_LEDGER", "off")
        employer = Employer.objects.create(name="Test", slug="test")
        JobPosting.objects.create(url="https://example.org/q", title="Zzyzx qwv", employer=employer,
                                  body_markdown="Corp. " * 40, inferred={})
        log = tmp_path / "runs.jsonl"
        call_command("infer_postings", provider="deepseek", no_llm=True, run_log=str(log))
        assert not log.exists()

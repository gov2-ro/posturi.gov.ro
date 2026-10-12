"""FIX-10 — generate_with_retry() records every attempt it makes.

The retry loop is the one place every schema and occupations call passes through, and
it already knows what each attempt was: a retry, a repair, a fatal stop. These tests
use fake `generate` callables and a real Ledger over a MemorySink, so nothing here
touches a provider or the database.
"""

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import llm_ledger as L  # noqa: E402


@pytest.fixture(scope="module")
def llm():
    spec = importlib.util.spec_from_file_location("llmschema_ledger_test", REPO_ROOT / "llm-schema.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def priced(provider, model, usage, ended_at):
    if usage is None:
        return None, None
    return (usage.input_tokens or 0) / 1e6, {"peak": False}


@pytest.fixture
def req():
    sink = L.MemorySink()
    ledger = L.Ledger(sink, step="schema", provider="deepseek", model="m",
                      prompt_version="v4", run_id="R", pricer=priced)
    request = ledger.request("posting-1", posting_id=1)
    request.rows = sink.rows
    request.ledger = ledger
    return request


class Status(Exception):
    def __init__(self, status):
        super().__init__(f"HTTP {status}")
        self.status_code = status


def shape(rows):
    return [(r["attempt"], r["is_repair"], r["outcome"], r["api_ok"]) for r in rows]


def test_a_clean_call_is_one_ok_row_with_the_usage_the_provider_reported(llm, req):
    def generate(payload):
        L.note_usage(input_tokens=500, output_tokens=40, cache_read_tokens=100)
        return ({"ok": True}, 500, 40, 100)

    assert llm.generate_with_retry(generate, "body", ledger=req)[0] == {"ok": True}
    assert shape(req.rows) == [(1, False, "ok", True)]
    assert (req.rows[0]["input_tokens"], req.rows[0]["output_tokens"],
            req.rows[0]["cache_read_tokens"]) == (500, 40, 100)


def test_a_call_that_reported_no_usage_is_still_a_row_with_unknown_tokens(llm, req):
    llm.generate_with_retry(lambda payload: "fine", "body", ledger=req)
    (row,) = req.rows
    assert row["outcome"] == "ok" and row["input_tokens"] is None and row["cost_usd"] is None


def test_a_transient_failure_and_its_retry_are_two_rows(llm, req):
    calls = []

    def generate(payload):
        calls.append(payload)
        if len(calls) == 1:
            raise Status(429)
        L.note_usage(input_tokens=10, output_tokens=2)
        return "ok"

    llm.generate_with_retry(generate, "body", base_delay=0.001, ledger=req)
    assert shape(req.rows) == [(1, False, "transient_error", False), (2, False, "ok", True)]
    assert req.rows[0]["error_class"] == "Status" and req.rows[0]["input_tokens"] is None


def test_a_rejected_answer_and_its_repair_are_both_kept_and_both_billed(llm, req):
    calls = []

    def generate(payload):
        calls.append(payload)
        L.note_usage(input_tokens=900, output_tokens=300)
        if len(calls) == 1:
            raise ValueError("stage 'proba_orala' is not allowed")
        return "repaired"

    assert llm.generate_with_retry(generate, "body", ledger=req) == "repaired"
    assert shape(req.rows) == [(1, False, "validation_failed", True), (2, True, "ok", True)]
    assert [r["cost_usd"] for r in req.rows] == [pytest.approx(0.0009)] * 2
    assert req.ledger.totals()["repair_calls"] == 1


def test_a_truncated_answer_is_billed_from_the_exception_when_nothing_was_noted(llm, req):
    def generate(payload):
        raise llm.OutputTruncated(16000, 2000, 16000, 1500)

    with pytest.raises(llm.OutputTruncated):
        llm.generate_with_retry(generate, "body", ledger=req)
    (row,) = req.rows
    assert (row["outcome"], row["api_ok"]) == ("truncated", True)
    assert (row["input_tokens"], row["output_tokens"], row["cache_read_tokens"]) == (2000, 16000, 1500)


def test_a_fatal_status_is_recorded_before_the_run_is_stopped(llm, req):
    with pytest.raises(llm.FatalError):
        llm.generate_with_retry(lambda payload: (_ for _ in ()).throw(Status(402)), "body", ledger=req)
    assert shape(req.rows) == [(1, False, "fatal_error", False)]


def test_a_fatal_error_raised_by_the_provider_code_is_recorded_too(llm, req):
    def generate(payload):
        raise llm.FatalError("insufficient balance")

    with pytest.raises(llm.FatalError):
        llm.generate_with_retry(generate, "body", ledger=req)
    assert shape(req.rows) == [(1, False, "fatal_error", False)]


def test_every_attempt_of_an_exhausted_call_is_a_row_and_the_last_error_surfaces(llm, req):
    with pytest.raises(Status):
        llm.generate_with_retry(lambda payload: (_ for _ in ()).throw(Status(503)), "body",
                                max_attempts=3, base_delay=0.001, ledger=req)
    assert shape(req.rows) == [(n, False, "transient_error", False) for n in (1, 2, 3)]


def test_an_error_that_is_neither_transient_nor_repairable_is_one_error_row(llm, req):
    with pytest.raises(Status):
        llm.generate_with_retry(lambda payload: (_ for _ in ()).throw(Status(400)), "body", ledger=req)
    assert shape(req.rows) == [(1, False, "error", False)]


def test_usage_left_over_from_an_earlier_call_is_not_charged_to_this_one(llm, req):
    L.note_usage(input_tokens=999_999, output_tokens=999_999)     # stale, same thread

    def generate(payload):
        raise Status(503)

    with pytest.raises(Status):
        llm.generate_with_retry(generate, "body", max_attempts=1, ledger=req)
    assert req.rows[0]["input_tokens"] is None


def test_a_ledger_that_misbehaves_cannot_change_the_result(llm):
    class Broken:
        def record_attempt(self, **kw):
            raise RuntimeError("ledger bug")

    assert llm.generate_with_retry(lambda payload: "answer", "body", ledger=Broken()) == "answer"


def test_without_a_ledger_nothing_changes(llm):
    assert llm.generate_with_retry(lambda payload: "answer", "body") == "answer"


# ------------------------------------------------- the provider closures note usage first
# A rejected answer is still billed. The closures validate the response they get back;
# if validation raised before the usage was read, the retry loop would record a paid
# attempt with unknown tokens. Fake SDK clients stand in for the providers.

class _Obj:
    def __init__(self, **kw):
        self.__dict__.update(kw)


def _openai_style_response(content, *, finish="stop"):
    return _Obj(
        id="chatcmpl-123",
        choices=[_Obj(message=_Obj(content=content), finish_reason=finish)],
        usage=_Obj(prompt_tokens=1200, completion_tokens=340, prompt_cache_hit_tokens=800,
                   completion_tokens_details=_Obj(reasoning_tokens=60)))


@pytest.fixture
def deepseek_generate(llm, monkeypatch):
    import openai
    box = {}

    class FakeClient:
        def __init__(self, **kw):
            self.chat = _Obj(completions=_Obj(create=lambda **k: box["response"]))

    monkeypatch.setattr(openai, "OpenAI", FakeClient)
    monkeypatch.setenv("DEEPSEEK_API_KEY", "sk-test")
    generate = llm.make_generator("deepseek", "deepseek-v4-flash", "SYSTEM", "v4")
    return generate, box


def test_deepseek_notes_usage_even_when_the_answer_fails_validation(llm, deepseek_generate):
    generate, box = deepseek_generate
    box["response"] = _openai_style_response("this is not json")
    L.discard_usage()
    with pytest.raises(Exception):
        generate("posting body")
    usage = L.take_usage()
    assert (usage.input_tokens, usage.output_tokens, usage.cache_read_tokens) == (1200, 340, 800)
    assert usage.reasoning_tokens == 60 and usage.request_id == "chatcmpl-123"


def test_deepseek_notes_usage_for_a_truncated_answer_too(llm, deepseek_generate):
    generate, box = deepseek_generate
    box["response"] = _openai_style_response('{"cut', finish="length")
    L.discard_usage()
    with pytest.raises(llm.OutputTruncated):
        generate("posting body")
    assert L.take_usage().output_tokens == 340


def test_a_rejected_answer_reaches_the_ledger_with_its_tokens_and_cost(llm, deepseek_generate, req):
    generate, box = deepseek_generate
    box["response"] = _openai_style_response("still not json")
    with pytest.raises(Exception):
        llm.generate_with_retry(generate, "posting body", max_attempts=2, ledger=req)
    # the first attempt is rejected and repaired; the repair is rejected too -> two paid rows
    assert [r["outcome"] for r in req.rows] == ["validation_failed", "validation_failed"]
    assert all(r["input_tokens"] == 1200 and r["output_tokens"] == 340 for r in req.rows)
    assert req.rows[1]["is_repair"] is True


def test_the_gemini_closure_reads_usage_before_validating(llm, monkeypatch):
    from google import genai

    resp = _Obj(parsed=None, text="not json at all", response_id="gem-1",
                usage_metadata=_Obj(prompt_token_count=700, candidates_token_count=90,
                                    cached_content_token_count=300))

    class FakeClient:
        def __init__(self, **kw):
            self.models = _Obj(generate_content=lambda **k: resp)

    monkeypatch.setattr(genai, "Client", FakeClient)
    generate = llm.make_generator("gemini", "gemini-2.5-flash", "SYSTEM", "v4")
    L.discard_usage()
    with pytest.raises(Exception):
        generate("posting body")
    usage = L.take_usage()
    assert (usage.input_tokens, usage.output_tokens, usage.cache_read_tokens) == (700, 90, 300)


def test_the_anthropic_closure_reads_usage_before_validating(llm, monkeypatch):
    import anthropic

    msg = _Obj(id="msg_1", stop_reason="end_turn", content=[],     # no tool block -> StopIteration
               usage=_Obj(input_tokens=200, output_tokens=50, cache_read_input_tokens=400,
                          cache_creation_input_tokens=120))

    class FakeClient:
        def __init__(self, **kw):
            self.messages = _Obj(create=lambda **k: msg)

    monkeypatch.setattr(anthropic, "Anthropic", FakeClient)
    generate = llm.make_generator("anthropic", "claude-haiku-4-5", "SYSTEM", "v4")
    L.discard_usage()
    with pytest.raises(StopIteration):
        generate("posting body")
    usage = L.take_usage()
    assert usage.input_tokens == 600, "cache reads are folded into the billable input"
    assert (usage.output_tokens, usage.cache_read_tokens, usage.cache_write_tokens) == (50, 400, 120)


def test_the_openai_closure_notes_usage_including_cached_and_reasoning_tokens(llm, monkeypatch):
    import openai

    resp = _Obj(id="chatcmpl-9", choices=[_Obj(message=_Obj(content="not json"), finish_reason="stop")],
                usage=_Obj(prompt_tokens=2000, completion_tokens=500,
                           prompt_tokens_details=_Obj(cached_tokens=1500),
                           completion_tokens_details=_Obj(reasoning_tokens=120)))

    class FakeClient:
        def __init__(self, **kw):
            self.chat = _Obj(completions=_Obj(create=lambda **k: resp))

    monkeypatch.setattr(openai, "OpenAI", FakeClient)
    generate = llm.make_generator("openai", "gpt-4o-mini", "SYSTEM", "v4")
    L.discard_usage()
    with pytest.raises(Exception):
        generate("posting body")
    usage = L.take_usage()
    assert (usage.input_tokens, usage.output_tokens, usage.cache_read_tokens,
            usage.reasoning_tokens, usage.request_id) == (2000, 500, 1500, 120, "chatcmpl-9")

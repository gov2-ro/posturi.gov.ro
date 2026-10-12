"""FIX-10 — the occupations step reports its provider usage to the ledger.

normalize-titles.py validates AFTER `generate` returns, so unlike the schema closures
the numbers are never lost to a validation error; what these pin is that every provider
path hands its usage over, folds Anthropic's cache reads into the billable input, and
that the step owns a ledger and a run-log record.
"""

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import llm_ledger as L  # noqa: E402


@pytest.fixture(scope="module")
def nt():
    spec = importlib.util.spec_from_file_location("normalize_titles_ledger_test",
                                                  REPO_ROOT / "normalize-titles.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["normalize_titles_ledger_test"] = module
    spec.loader.exec_module(module)
    return module


class _Obj:
    def __init__(self, **kw):
        self.__dict__.update(kw)


def test_deepseek_hands_over_usage_cache_hits_and_the_request_id(nt, monkeypatch):
    import openai

    resp = _Obj(id="chatcmpl-77", choices=[_Obj(message=_Obj(content='{"x": 1}'))],
                usage=_Obj(prompt_tokens=900, completion_tokens=120, prompt_cache_hit_tokens=600,
                           completion_tokens_details=_Obj(reasoning_tokens=0)))

    class FakeClient:
        def __init__(self, **kw):
            self.chat = _Obj(completions=_Obj(create=lambda **k: resp))

    monkeypatch.setattr(openai, "OpenAI", FakeClient)
    monkeypatch.setenv("DEEPSEEK_API_KEY", "sk-test")
    generate = nt.make_generator("deepseek", "deepseek-v4-flash", "SYSTEM")
    L.discard_usage()
    assert generate("title") == ('{"x": 1}', 900, 120, 600)
    usage = L.take_usage()
    assert (usage.input_tokens, usage.output_tokens, usage.cache_read_tokens,
            usage.request_id) == (900, 120, 600, "chatcmpl-77")


def test_gemini_hands_over_usage(nt, monkeypatch):
    from google import genai

    resp = _Obj(text="{}", usage_metadata=_Obj(prompt_token_count=400, candidates_token_count=60,
                                               cached_content_token_count=None))

    class FakeClient:
        def __init__(self, **kw):
            self.models = _Obj(generate_content=lambda **k: resp)

    monkeypatch.setattr(genai, "Client", FakeClient)
    monkeypatch.setenv("GEMINI_API_KEY", "k")
    generate = nt.make_generator("gemini", "gemini-2.5-flash", "SYSTEM")
    L.discard_usage()
    generate("title")
    usage = L.take_usage()
    assert (usage.input_tokens, usage.output_tokens, usage.cache_read_tokens) == (400, 60, 0)


def test_anthropic_folds_cache_reads_into_the_billable_input(nt, monkeypatch):
    import anthropic

    block = _Obj(type="tool_use", input={"x": 1})
    msg = _Obj(id="msg_9", content=[block],
               usage=_Obj(input_tokens=100, output_tokens=30, cache_read_input_tokens=500,
                          cache_creation_input_tokens=80))

    class FakeClient:
        def __init__(self, **kw):
            self.messages = _Obj(create=lambda **k: msg)

    monkeypatch.setattr(anthropic, "Anthropic", FakeClient)
    generate = nt.make_generator("anthropic", "claude-haiku-4-5", "SYSTEM")
    L.discard_usage()
    assert generate("title")[1:] == (600, 30, 500)
    usage = L.take_usage()
    assert (usage.input_tokens, usage.output_tokens, usage.cache_read_tokens,
            usage.cache_write_tokens, usage.request_id) == (600, 30, 500, 80, "msg_9")


def test_the_step_has_a_run_log_option_like_the_schema_step(nt):
    # --run-log / --no-run-log let a hand-run keep out of the pipeline's log.
    import argparse
    parser = nt.build_parser() if hasattr(nt, "build_parser") else None
    assert parser is not None, "main() builds its parser inline; expose build_parser()"
    args = parser.parse_args([])
    assert args.run_log.endswith("pipeline-runs.jsonl") and args.no_run_log is False
    assert parser.parse_args(["--no-run-log"]).no_run_log is True


# ---------------------------------------------------------------- the step itself

def _conninfo():
    """The Django test database, as a libpq string normalize-titles can connect to."""
    from django.db import connection

    s = connection.settings_dict
    parts = {"dbname": s["NAME"], "host": s.get("HOST"), "port": s.get("PORT"),
             "user": s.get("USER"), "password": s.get("PASSWORD")}
    return " ".join(f"{k}={v}" for k, v in parts.items() if v)


@pytest.mark.django_db(transaction=True)
def test_main_ledgers_every_title_and_writes_one_usage_record(nt, monkeypatch, tmp_path):
    """The whole step against the test database, with a fake provider: one ledger call
    per distinct title, one `llm-usage` run-log record, and nothing on a repeat run."""
    import json

    from apps.jobs.models import Employer, JobPosting

    employer = Employer.objects.create(name="Primaria Test", slug="primaria-test")
    for n, title in enumerate(("Referent de specialitate", "Inspector de specialitate")):
        JobPosting.objects.create(url=f"https://example.org/occ{n}", title=title, employer=employer,
                                  body_markdown="Corp. " * 40, inferred={})

    answer = json.dumps({"occupation_canonical": "Referent", "match_confidence": "none"})

    def fake_generator(provider, model, system_prompt):
        def generate(content):
            L.note_usage(input_tokens=700, output_tokens=120, cache_read_tokens=200)
            return (answer, 700, 120, 200)
        return generate

    monkeypatch.setattr(nt, "DATABASE_URL", _conninfo())
    monkeypatch.setattr(nt, "make_generator", fake_generator)
    monkeypatch.setenv("POSTURI_RUN_ID", "2026-10-13T10:15:01Z")
    log = tmp_path / "runs.jsonl"
    monkeypatch.setattr(sys, "argv", ["normalize-titles.py", "--provider", "deepseek",
                                      "--workers", "1", "--run-log", str(log)])

    assert nt.main() == 0
    (record,) = [json.loads(line) for line in log.read_text().splitlines()]
    assert record["kind"] == "llm-usage" and record["step"] == "occupations"
    assert record["run_id"] == "2026-10-13T10:15:01Z" and record["provider"] == "deepseek"
    assert record["calls"] == 2 and record["usage"]["input_tokens"] == 1400
    assert record["usage"]["cached_input_tokens"] == 400 and record["usage"]["cost_usd"] > 0

    assert nt.main() == 0, "a repeat run has nothing to map"
    assert len(log.read_text().splitlines()) == 1, "and so records nothing"


@pytest.mark.django_db(transaction=True)
def test_no_run_log_keeps_a_hand_run_out_of_the_pipeline_log(nt, monkeypatch, tmp_path):
    import json

    from apps.jobs.models import Employer, JobPosting

    employer = Employer.objects.create(name="Primaria Test", slug="primaria-test")
    JobPosting.objects.create(url="https://example.org/occ9", title="Economist", employer=employer,
                              body_markdown="Corp. " * 40, inferred={})
    answer = json.dumps({"occupation_canonical": "Economist", "match_confidence": "none"})

    def fake_generator(provider, model, system_prompt):
        return lambda content: (answer, 10, 5, 0)

    monkeypatch.setattr(nt, "DATABASE_URL", _conninfo())
    monkeypatch.setattr(nt, "make_generator", fake_generator)
    log = tmp_path / "runs.jsonl"
    monkeypatch.setattr(sys, "argv", ["normalize-titles.py", "--provider", "deepseek",
                                      "--workers", "1", "--run-log", str(log), "--no-run-log"])
    assert nt.main() == 0
    assert not log.exists()

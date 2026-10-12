"""
Extract structured display sections from cached job postings.

Reads body_markdown + attachment_text directly from Postgres (jobs_jobposting),
calls an LLM to extract 7 structured sections, and writes the result back to
jobs_jobposting.schema_json. Skips postings that already have schema_json
unless --force is passed.

Models and prompts are defined in models_config.json.

Usage:
    python llm-schema.py                                    # defaults from $LLM_PROVIDER /
                                                            # $LLM_MODEL / $LLM_PROMPT_VERSION,
                                                            # else models_config.json "defaults"
    python llm-schema.py --provider anthropic
    python llm-schema.py --provider openai --model gpt-4o-mini
    python llm-schema.py --slug subinginer-gradul-i        # single posting by URL fragment
    python llm-schema.py --force                            # re-generate existing outputs

    # Variant comparison (test multiple providers):
    python llm-schema.py --compare                          # run all enabled models, store variants only
    python llm-schema.py --compare --limit 10               # compare on first 10 postings
    python llm-schema.py --compare --model-filter "gemini-.*" --limit 5  # test only Gemini models

    # Long runs:
    python llm-schema.py --prompt-version v3 --workers 8 --resume   # restartable, concurrent

    # Prompt testing:
    python llm-schema.py --prompt-version v2                # use prompt v2 with default provider
    python llm-schema.py --compare --prompt-version v2      # compare all providers with prompt v2
    python llm-schema.py --compare --model-filter "gpt-.*" --prompt-version v2  # GPT with prompt v2

    # Affected-ID retry and preview (FIX-05-STAGES):
    python llm-schema.py --urls-file ids.txt --force --prompt-version v4 --dry-run   # what would run?
    python llm-schema.py --urls-file ids.txt --force --prompt-version v4 --workers 4 # exact URLs/slugs only
    python llm-schema.py --active-only --resume --dry-run   # count only: no API calls, no writes

Per-posting failures are appended to data/schema-failures.jsonl (--failure-log,
--no-failure-log): both attempts' rejected values, so a policy decision can read
the evidence. So are postings that were repaired and then succeeded
(kind "llm-schema-repaired"). `ops/stage-failures.py` summarises both.
"""

import argparse
import hashlib
import json
import os
import random
import re
import sys
import threading
import time
import itertools
from concurrent.futures import FIRST_COMPLETED, Future, ThreadPoolExecutor, wait
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import NamedTuple
import psycopg
from pydantic import ValidationError
from tqdm import tqdm
from decimal import Decimal
from dotenv import load_dotenv

from boilerplate import strip_hg_1336
from grounding import check_grounding
import llm_ledger
from llm_config import (
    MODELS_CONFIG,
    PROVIDERS,
    resolve_model,
    resolve_prompt_version,
    resolve_provider,
)
from schema_models import (
    EXTRACTION_MODELS,
    JobPostingExtraction,
    json_schema_for,
    model_for_version,
)

load_dotenv()

DATABASE_URL = os.getenv("DATABASE_URL", "postgres://localhost/posturi_dev")


def get_prompt(prompt_version="v1"):
    """Fetch prompt text from config by version."""
    return MODELS_CONFIG.get("prompts", {}).get(prompt_version, "")


#: Output-token budget per prompt version. A long posting truncates mid-JSON at
#: the cap — detected from the provider's stop reason as OutputTruncated (before
#: REV-14 it surfaced as "Expected dict, got str", because a truncated object
#: does not parse). Retrying does not help: the repair regenerates and truncates
#: again. Only generated tokens are billed, so a generous cap costs nothing on
#: postings that do not need it.
#:
#: v3 used to run on 2,000, which "fit comfortably" until deepseek-v4-flash: on
#: 2026-10-04 its successful v3 answers averaged 1,660–1,780 output tokens and
#: 634 of 645 attempts in the two runs failed at the cap — the whole active
#: residue, every run, at ~19 minutes each.
#:
#: Measured on the 20 newest postings: ordinary v4 answers peak at ~3,050 output
#: tokens even with a 19-event calendar. The cost driver is multi-role postings,
#: where every entry in `positions[]` repeats education, experience, skills and
#: credentials — one advertising eight roles blew past 4,000 on its own.
#:
#: 16,000 since 2026-10-05: at 8,000 five v3 postings truncated on every run
#: (REV-17) and the September v4 sample peaked at 7,551, so a corpus-wide v4
#: backfill would have paid for answers it then threw away. DeepSeek documents a
#: 384K output maximum. A runaway answer at the cap costs about a cent; an
#: Anthropic model would need a lower cap (3.5 Haiku stops at 8,192), but none
#: is enabled.
MAX_OUTPUT_TOKENS: dict[str, int] = {}
DEFAULT_MAX_OUTPUT_TOKENS = 16000


def max_output_tokens(prompt_version: str) -> int:
    return MAX_OUTPUT_TOKENS.get(prompt_version, DEFAULT_MAX_OUTPUT_TOKENS)


def get_enabled_models():
    """Return list of (provider, model_id) tuples for enabled models only."""
    enabled = []
    for provider, prov_config in MODELS_CONFIG.get("providers", {}).items():
        for model_id, model_config in prov_config.get("models", {}).items():
            if model_config.get("enabled", True):  # Default to enabled if not specified
                enabled.append((provider, model_id))
    return enabled

# Provider / model / prompt selection lives in llm_config, which applies
# CLI flag > $LLM_PROVIDER/$LLM_MODEL/$LLM_PROMPT_VERSION > models_config.json
# "defaults". PROMPT_VERSION is kept as a module constant because
# write_variant() takes it as a keyword default.
PROMPT_VERSION = resolve_prompt_version()


def is_peak(peak_config, at):
    """True when `at` (UTC) falls in the model's peak window.

    `peak_config` is the optional `peak` block of a model: `weekdays` (Monday=0)
    and `hours_utc`, a list of half-open [start, end) hour ranges.
    """
    if not peak_config:
        return False
    at = at.astimezone(timezone.utc) if at.tzinfo else at.replace(tzinfo=timezone.utc)
    if at.weekday() not in peak_config.get("weekdays", range(7)):
        return False
    return any(start <= at.hour < end for start, end in peak_config.get("hours_utc", []))


def _effective_rates(provider, model, at):
    """`(input, output, cache_read, peak_applied, multiplier, model_config)` or None.

    The three rates are USD per million tokens as Decimals, AFTER any peak multiplier;
    None when the provider/model has no pricing in the config. One lookup serves both
    compute_cost (the amount) and rate_card (the record of how it was computed), so the
    stored card can never drift from the price actually applied.
    """
    try:
        model_config = MODELS_CONFIG["providers"][provider]["models"][model]
        in_rate = Decimal(str(model_config["input_cost_per_million"]))
        out_rate = Decimal(str(model_config["output_cost_per_million"]))
        cache_rate = Decimal(str(model_config.get("cache_input_cost_per_million", model_config["input_cost_per_million"])))
    except (KeyError, TypeError):
        return None

    mult, peak_applied = Decimal(1), False
    peak = model_config.get("peak")
    if peak and is_peak(peak, at if at is not None else datetime.now(timezone.utc)):
        mult, peak_applied = Decimal(str(peak.get("multiplier", 1))), True
    return in_rate * mult, out_rate * mult, cache_rate * mult, peak_applied, mult, model_config


def compute_cost(provider, model, input_tokens, output_tokens, cached_input_tokens=0, *, at=None):
    """Calculate cost in USD based on model pricing from config.

    `cached_input_tokens` are billed at the model's `cache_input_cost_per_million`
    rate when defined; the remaining (input_tokens - cached_input_tokens) are
    billed at the standard `input_cost_per_million`.

    A model with a `peak` block has every rate multiplied by `peak.multiplier`
    when `at` (a datetime, default now in UTC; naive values are taken as UTC)
    falls inside a peak window. Price a call at the time it completed.
    """
    if not input_tokens or output_tokens is None:
        return None
    rates = _effective_rates(provider, model, at)
    if rates is None:
        return None
    in_rate, out_rate, cache_rate = rates[:3]
    try:
        cached = max(0, cached_input_tokens or 0)
        uncached = max(0, input_tokens - cached)

        input_cost = (Decimal(uncached) * in_rate + Decimal(cached) * cache_rate) / Decimal("1000000")
        output_cost = Decimal(output_tokens) * out_rate / Decimal("1000000")
        return float(input_cost + output_cost)
    except TypeError:
        return None


def rate_card(provider, model, *, at=None):
    """The rates a call completed at `at` is billed at, as plain JSON; None if unpriced.

    Stored on every ledger row (FIX-10) so that editing models_config.json later
    cannot restate old spend. `config_hash` identifies the rate revision (the pricing
    keys of the model block, not its notes); the per-million rates are the EFFECTIVE
    ones, peak multiplier included.
    """
    rates = _effective_rates(provider, model, at)
    if rates is None:
        return None
    in_rate, out_rate, cache_rate, peak_applied, mult, model_config = rates
    revision = {k: model_config.get(k) for k in (
        "input_cost_per_million", "output_cost_per_million",
        "cache_input_cost_per_million", "peak")}
    return {
        "input_per_million": float(in_rate),
        "output_per_million": float(out_rate),
        "cache_read_per_million": float(cache_rate),
        "peak": peak_applied,
        "multiplier": float(mult),
        "config_hash": hashlib.sha256(
            json.dumps(revision, sort_keys=True, default=str).encode()).hexdigest()[:12],
    }


# ---------------------------------------------------------------------------
# Retry
# ---------------------------------------------------------------------------
# A full run is ~9,600 calls per model. Rate limits and 5xx are certainties at
# that volume, and before this existed a single 429 dropped that posting for
# good — the loop printed "✗" and moved on.

#: HTTP statuses worth retrying. 409 and 425 show up as transient conflicts on
#: some gateways; 408/429/5xx are the usual suspects.
RETRYABLE_STATUS = frozenset({408, 409, 425, 429, 500, 502, 503, 504})

#: Substrings of exception class names that mean "transient" when no numeric
#: status is exposed. Each provider SDK names these differently, and none of
#: them share a base class, so matching on the name is the portable option.
_TRANSIENT_HINTS = (
    "ratelimit", "rate_limit", "resourceexhausted", "resource_exhausted",
    "serviceunavailable", "unavailable", "overloaded", "internalserver",
    "apiconnection", "apitimeout", "timeout", "deadlineexceeded",
    "servererror", "connectionerror", "remoteprotocol",
)

#: Appended to the user message on a repair attempt. The system prefix is left
#: untouched so the cached prefix stays byte-identical.
_REPAIR_SUFFIX = (
    "\n\n---\n"
    "ATENȚIE: încercarea anterioară a eșuat validarea cu eroarea de mai jos. "
    "Corectează problema și returnează DOAR obiectul JSON valid, complet.\n"
    "{error}\n"
)


def _status_of(exc):
    """Best-effort HTTP status from an SDK exception, or None."""
    for attr in ("status_code", "code", "http_status"):
        value = getattr(exc, attr, None)
        if isinstance(value, int):
            return value
    response = getattr(exc, "response", None)
    value = getattr(response, "status_code", None)
    return value if isinstance(value, int) else None


def _is_transient(exc) -> bool:
    """True for errors worth retrying with the same input."""
    status = _status_of(exc)
    if status is not None:
        return status in RETRYABLE_STATUS
    name = type(exc).__name__.lower()
    return any(hint in name for hint in _TRANSIENT_HINTS)


def _is_repairable(exc) -> bool:
    """True for errors where showing the model its own mistake may fix it.

    `StopIteration` is in here because the Anthropic branch pulls the tool_use
    block out with `next(...)`; a reply with no tool block raises it.
    """
    return isinstance(exc, (ValidationError, ValueError, StopIteration))


class OutputTruncated(Exception):
    """The model stopped at the output-token cap; the JSON is incomplete.

    Not repairable (a repair regenerates the same long answer and truncates
    again) and not transient, so generate_with_retry gives up at once. Carries
    the usage of the call, which was billed even though nothing was stored.
    """

    def __init__(self, budget, input_tokens=None, output_tokens=None, cached_tokens=0):
        super().__init__(f"output truncated at the {budget}-token cap "
                         f"({output_tokens} output tokens generated)")
        self.usage = (input_tokens, output_tokens, cached_tokens or 0)


class FatalError(Exception):
    """Provider-wide failure — no retry, no further calls scheduled.

    HTTP 402 (payment required) and 401/403 (invalid or revoked credentials)
    mean every remaining call would fail identically and burn the run's time;
    the correct response is to stop scheduling new work, drain what is already
    in flight, and report the run as failed.
    """


#: Statuses that are fatal for the whole provider, not the one posting.
_FATAL_STATUS = frozenset({401, 402, 403})

#: Exception-name hints for SDKs that hide the numeric status.
_FATAL_HINTS = (
    "paymentrequired", "payment_required", "insufficientbalance",
    "insufficient_balance", "insufficientquota", "invalidapi", "invalid_api",
    "apikey", "authentication", "unauthorized", "unauthenticated",
)


def _is_fatal(exc) -> bool:
    status = _status_of(exc)
    if status is not None:
        return status in _FATAL_STATUS
    name = type(exc).__name__.lower()
    return any(hint in name for hint in _FATAL_HINTS)


def attempt_outcome(exc) -> str:
    """The ledger's name for how an attempt that raised ended."""
    if isinstance(exc, FatalError) or _is_fatal(exc):
        return "fatal_error"
    if isinstance(exc, OutputTruncated):
        return "truncated"
    if _is_transient(exc):
        return "transient_error"
    if _is_repairable(exc):
        return "validation_failed"
    return "error"


def _ledger_attempt(ledger, attempt, is_repair, started, exc) -> None:
    """Hand one finished attempt to the ledger request. Never raises.

    The usage comes from `llm_ledger.note_usage()`, which the provider code calls as soon
    as the response arrives, before validating it. An OutputTruncated that reached us
    without a note carries the same numbers on the exception.
    """
    if ledger is None:
        return
    try:
        usage = llm_ledger.take_usage()
        if usage is None and isinstance(exc, OutputTruncated):
            in_tok, out_tok, cached = exc.usage
            usage = llm_ledger.Usage(input_tokens=in_tok, output_tokens=out_tok,
                                     cache_read_tokens=cached)
        ledger.record_attempt(
            attempt=attempt, is_repair=is_repair, started_at=started,
            ended_at=datetime.now(timezone.utc),
            outcome="ok" if exc is None else attempt_outcome(exc), error=exc, usage=usage)
    except Exception:  # noqa: BLE001 — recording must never change the outcome
        pass


def generate_with_retry(generate, content, *, max_attempts=4, base_delay=2.0, on_retry=None,
                        ledger=None):
    """Call `generate(content)`, retrying transient failures and repairing invalid output.

    Two different failures need two different responses:
      - transient (429/5xx/timeout) — same input, exponential backoff with jitter;
      - invalid output (schema or parse) — one repair attempt that appends the
        validation error to the *user* message, then give up.

    Only one repair is attempted: if the model cannot produce valid output when
    shown the error, a third try is not going to help and costs a full prompt.

    `ledger` is an `llm_ledger.Request`: every attempt, repair and failure included,
    becomes one row (FIX-10). It changes nothing about what is retried or returned.
    """
    payload = content
    repaired = False
    last_exc = None

    for attempt in range(1, max_attempts + 1):
        started = datetime.now(timezone.utc)
        if ledger is not None:
            llm_ledger.discard_usage()      # a previous call's numbers are not this attempt's
        try:
            result = generate(payload)
        except FatalError as exc:
            _ledger_attempt(ledger, attempt, repaired, started, exc)
            raise
        except Exception as exc:  # noqa: BLE001 — classified immediately below
            _ledger_attempt(ledger, attempt, repaired, started, exc)
            last_exc = exc
            if attempt == max_attempts:
                break
            if _is_fatal(exc):
                # The whole provider is unusable — retrying here would only
                # delay the same conclusion for the other postings.
                raise FatalError(f"{type(exc).__name__}: {exc}") from exc
            if _is_transient(exc):
                delay = base_delay * (2 ** (attempt - 1)) * random.uniform(0.8, 1.2)
                if on_retry:
                    on_retry(attempt, exc, delay)
                time.sleep(delay)
                continue
            if _is_repairable(exc) and not repaired:
                repaired = True
                payload = content + _REPAIR_SUFFIX.format(error=str(exc)[:800])
                if on_retry:
                    on_retry(attempt, exc, 0.0)
                continue
            break
        else:
            _ledger_attempt(ledger, attempt, repaired, started, None)
            return result

    raise last_exc


# ---------------------------------------------------------------------------
# Concurrency
# ---------------------------------------------------------------------------

def imap_unordered(fn, items, workers, queue_depth=2):
    """Yield `(item, future)` as `fn(item)` completes, at most a few in flight.

    Bounded rather than `executor.map` so a 9,600-row iterator is never
    materialised and an abort does not strand thousands of queued calls.

    Only the LLM call runs in the pool. Every database write stays on the
    calling thread — one psycopg connection is not safe to share, and
    `write_variant` commits per row.
    """
    if workers <= 1:
        for item in items:
            future = Future()
            try:
                future.set_result(fn(item))
            except BaseException as exc:  # noqa: BLE001 — re-raised by .result()
                future.set_exception(exc)
            yield item, future
        return

    with ThreadPoolExecutor(max_workers=workers) as pool:
        iterator = iter(items)
        pending = {
            pool.submit(fn, item): item
            for item in itertools.islice(iterator, workers * queue_depth)
        }
        while pending:
            done, _ = wait(pending, return_when=FIRST_COMPLETED)
            for future in done:
                item = pending.pop(future)
                for nxt in itertools.islice(iterator, 1):
                    pending[pool.submit(fn, nxt)] = nxt
                yield item, future


def parse_json_response(text):
    text = text.strip()
    if text.startswith('```'):
        text = text.split('\n', 1)[1].rsplit('```', 1)[0].strip()
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        m = re.search(r'(\{.*\}|\[.*\])', text, re.DOTALL)
        if m:
            try:
                return json.loads(m.group(1))
            except json.JSONDecodeError:
                pass
    return text


def _validate_extraction(raw, prompt_version) -> dict:
    """Validate raw parsed JSON against a prompt version's Pydantic model.

    Returns a JSON-safe dict. Raises pydantic.ValidationError on mismatch.
    """
    if isinstance(raw, str):
        raw = parse_json_response(raw)
    if not isinstance(raw, dict):
        raise ValueError(f"Expected dict, got {type(raw).__name__}: {repr(raw)[:120]}")
    model = model_for_version(prompt_version) or JobPostingExtraction
    obj = model.model_validate(raw)
    return obj.model_dump(mode="json")


def make_generator(provider, model, system_prefix, prompt_version):
    """Returns a generate(content) function that yields
    (schema, input_tokens, output_tokens, cached_input_tokens).

    `system_prefix` is the static instruction block sent on every call —
    identical across calls so providers can cache it. `content` is the
    per-posting body+attachment text.

    Any prompt version with a Pydantic model in schema_models.EXTRACTION_MODELS
    (v2, v3) uses provider-native structured output — OpenAI strict json_schema,
    Gemini response_schema, Anthropic tool-use — and is validated against that
    model. v1 keeps the loose JSON-parse path for back-compat.
    """
    use_schema = prompt_version in EXTRACTION_MODELS
    extraction_model = model_for_version(prompt_version)
    out_budget = max_output_tokens(prompt_version)

    if provider == 'gemini':
        from google import genai
        from google.genai import types as genai_types
        client = genai.Client(api_key=os.getenv('GOOGLE_API_KEY'))

        def generate(content):
            cfg_kwargs = {
                "system_instruction": system_prefix,
                "temperature": 0.2,
            }
            if use_schema:
                cfg_kwargs["response_mime_type"] = "application/json"
                cfg_kwargs["response_schema"] = extraction_model
            resp = client.models.generate_content(
                model=model,
                contents=content,
                config=genai_types.GenerateContentConfig(**cfg_kwargs),
            )
            usage = getattr(resp, "usage_metadata", None)
            input_tokens = getattr(usage, "prompt_token_count", None) if usage else None
            output_tokens = getattr(usage, "candidates_token_count", None) if usage else None
            cached_tokens = getattr(usage, "cached_content_token_count", 0) if usage else 0
            llm_ledger.note_usage(input_tokens, output_tokens, cached_tokens or 0,
                                  request_id=getattr(resp, "response_id", None))
            raw = resp.parsed if use_schema and getattr(resp, "parsed", None) is not None else resp.text
            if hasattr(raw, "model_dump"):
                raw = raw.model_dump(mode="json")
            schema = _validate_extraction(raw, prompt_version) if use_schema else parse_json_response(raw)
            return schema, input_tokens, output_tokens, cached_tokens or 0

    elif provider == 'openai':
        import openai
        client = openai.OpenAI()

        def generate(content):
            kwargs = {
                "model": model,
                "messages": [
                    {"role": "system", "content": system_prefix},
                    {"role": "user", "content": content},
                ],
                # GPT-5 family rejects `max_tokens` and requires `max_completion_tokens`;
                # GPT-4o family accepts both. We use the new one uniformly.
                "max_completion_tokens": out_budget,
            }
            # GPT-5 reasoning models eat the completion budget with internal
            # reasoning tokens (default reasoning_effort is high). For
            # structured-extraction work no reasoning is needed — force minimal.
            # They also reject custom temperature.
            if model.startswith("gpt-5"):
                kwargs["reasoning_effort"] = "minimal"
            else:
                kwargs["temperature"] = 0.2
            if use_schema:
                kwargs["response_format"] = {
                    "type": "json_schema",
                    "json_schema": json_schema_for(prompt_version),
                }
            resp = client.chat.completions.create(**kwargs)
            text = resp.choices[0].message.content
            usage = getattr(resp, "usage", None)
            input_tokens = getattr(usage, "prompt_tokens", None) if usage else None
            output_tokens = getattr(usage, "completion_tokens", None) if usage else None
            cached_tokens = 0
            details = getattr(usage, "prompt_tokens_details", None) if usage else None
            if details is not None:
                cached_tokens = getattr(details, "cached_tokens", 0) or 0
            llm_ledger.note_usage(input_tokens, output_tokens, cached_tokens,
                                  reasoning_tokens=_reasoning_tokens(usage),
                                  request_id=getattr(resp, "id", None))
            if getattr(resp.choices[0], "finish_reason", None) == "length":
                raise OutputTruncated(out_budget, input_tokens, output_tokens, cached_tokens)
            schema = _validate_extraction(text, prompt_version) if use_schema else parse_json_response(text)
            return schema, input_tokens, output_tokens, cached_tokens

    elif provider == 'anthropic':
        import anthropic
        client = anthropic.Anthropic()

        tool_name = "extract_job_posting"
        anthropic_tools = [{
            "name": tool_name,
            "description": "Return structured fields extracted from the job posting.",
            "input_schema": (extraction_model or JobPostingExtraction).model_json_schema(),
        }]

        def generate(content):
            kwargs = {
                "model": model,
                "max_tokens": out_budget,
                "system": [{
                    "type": "text",
                    "text": system_prefix,
                    "cache_control": {"type": "ephemeral"},
                }],
                "messages": [{"role": "user", "content": content}],
            }
            if use_schema:
                kwargs["tools"] = anthropic_tools
                kwargs["tool_choice"] = {"type": "tool", "name": tool_name}
            msg = client.messages.create(**kwargs)
            usage = getattr(msg, "usage", None)
            input_tokens = getattr(usage, "input_tokens", None) if usage else None
            output_tokens = getattr(usage, "output_tokens", None) if usage else None
            cached_tokens = (getattr(usage, "cache_read_input_tokens", 0) or 0) if usage else 0
            # Anthropic reports cache reads outside of input_tokens; fold them in
            # so cost math matches: total billable input = input_tokens + cache_read
            if cached_tokens and input_tokens is not None:
                input_tokens = input_tokens + cached_tokens
            llm_ledger.note_usage(input_tokens, output_tokens, cached_tokens,
                                  cache_write_tokens=(getattr(usage, "cache_creation_input_tokens", None)
                                                      if usage else None),
                                  request_id=getattr(msg, "id", None))
            if getattr(msg, "stop_reason", None) == "max_tokens":
                raise OutputTruncated(out_budget, input_tokens, output_tokens, cached_tokens)
            if use_schema:
                tool_block = next(b for b in msg.content if getattr(b, "type", "") == "tool_use")
                schema = _validate_extraction(tool_block.input, prompt_version)
            else:
                text_block = next(b for b in msg.content if getattr(b, "type", "") == "text")
                schema = parse_json_response(text_block.text)
            return schema, input_tokens, output_tokens, cached_tokens

    elif provider == 'deepseek':
        import openai
        client = openai.OpenAI(api_key=os.getenv('DEEPSEEK_API_KEY'), base_url="https://api.deepseek.com")

        def generate(content):
            kwargs = {
                "model": model,
                "messages": [
                    {"role": "system", "content": system_prefix},
                    {"role": "user", "content": content},
                ],
                "max_tokens": out_budget,
                "temperature": 0.2,
                # deepseek-v4-* are reasoning models: left on, they spend
                # 1,400–2,600 tokens thinking before emitting any JSON, blow the
                # max_tokens budget and return empty content — which parses to
                # `ValueError: Expected dict, got str: ''`. Field extraction needs
                # no chain-of-thought, so disable it.
                "extra_body": {"thinking": {"type": "disabled"}},
            }
            # DeepSeek supports loose JSON mode (no schema enforcement)
            if use_schema:
                kwargs["response_format"] = {"type": "json_object"}
            resp = client.chat.completions.create(**kwargs)
            text = resp.choices[0].message.content
            usage = getattr(resp, "usage", None)
            input_tokens = getattr(usage, "prompt_tokens", None) if usage else None
            output_tokens = getattr(usage, "completion_tokens", None) if usage else None
            cached_tokens = getattr(usage, "prompt_cache_hit_tokens", 0) if usage else 0
            llm_ledger.note_usage(input_tokens, output_tokens, cached_tokens or 0,
                                  reasoning_tokens=_reasoning_tokens(usage),
                                  request_id=getattr(resp, "id", None))
            if getattr(resp.choices[0], "finish_reason", None) == "length":
                raise OutputTruncated(out_budget, input_tokens, output_tokens, cached_tokens)
            schema = _validate_extraction(text, prompt_version) if use_schema else parse_json_response(text)
            return schema, input_tokens, output_tokens, cached_tokens or 0

    else:
        raise ValueError(f"Unknown provider: {provider}")

    return generate


def _reasoning_tokens(usage):
    """Reasoning tokens an OpenAI-compatible usage object reports, else None.

    Informational only: they are already inside completion_tokens, so the cost is
    never computed from them separately.
    """
    details = getattr(usage, "completion_tokens_details", None) if usage else None
    return getattr(details, "reasoning_tokens", None) if details is not None else None


def slug_of(url: str) -> str:
    """The last path segment of a posting URL — what the logs and --urls-file call its slug."""
    return url.rstrip("/").split("/")[-1]


class UrlEntry(NamedTuple):
    """One line of a --urls-file: a full posting URL or a bare slug."""
    raw: str        # the line as written (stripped) — what is echoed back in reports
    value: str      # the URL without its trailing slash, or the slug
    is_url: bool


def parse_url_entries(text: str) -> list[UrlEntry]:
    """Parse the contents of a --urls-file: one URL or slug per line.

    Blank lines and lines starting with `#` are ignored; duplicates collapse.
    An entry starting with http:// or https:// is a URL and matches by equality
    (a trailing slash does not matter); anything else is a slug and matches only
    a posting whose LAST path segment is exactly that. A bare `...-10` therefore
    never selects `...-100`, which is what the substring `--slug` would do.
    """
    entries, seen = [], set()
    for line in text.splitlines():
        raw = line.strip()
        if not raw or raw.startswith("#"):
            continue
        is_url = raw.lower().startswith(("http://", "https://"))
        entry = UrlEntry(raw, raw.rstrip("/"), is_url)
        if (entry.is_url, entry.value) not in seen:
            seen.add((entry.is_url, entry.value))
            entries.append(entry)
    return entries


def _entries_hit(entries, urls) -> set:
    """The entries that name at least one of `urls` — the Python twin of the SQL condition."""
    by_url = {e.value: e for e in entries if e.is_url}
    by_slug = {e.value: e for e in entries if not e.is_url}
    hit = set()
    for url in urls:
        for entry in (by_url.get(url.rstrip("/")), by_slug.get(slug_of(url))):
            if entry is not None:
                hit.add(entry)
    return hit


def _selection_where(slug_filter=None, active_only=False, resume_key=None,
                     upgrade_legacy=False, url_entries=None):
    """Build the shared WHERE clause for posting selection.

    Returns (sql_fragment, params) — used by both iter_postings and
    count_postings so the progress total always matches what is processed.

    `resume_key` is a (provider, model, prompt_version) triple. With it, a
    posting with a PRODUCTION extraction is re-paid only when that extraction
    is provably stale (FIX-03/FIX-05, REV-07):
      - the source changed: its recorded revision and the posting's current
        content hash are both known and differ; or
      - the configuration changed: the row has provenance and a different
        provider/model/prompt version.
    Unknown is not stale. A row with no provenance (extracted before migration
    0014) or no hash on either side is skipped — selecting those made the
    unattended --resume run a full paid backfill of every active posting, and
    re-select the unhashed ones on every run. Upgrading legacy rows is the
    reviewed FIX-05-RUN step, opted into with `upgrade_legacy`. A confirmed
    source change on a legacy row still gets it re-extracted: import stamps
    the pre-change hash as its revision (import_csvs.invalidate_enrichment).
    Without `resume_key` (a plain run) any non-null schema_json is skipped
    unless --force.

    `url_entries` (--urls-file) restricts the selection to EXACT matches — a full
    URL, or a slug equal to the URL's last path segment — and is ANDed with every
    other selector. An empty list selects nothing: a file that parsed to no
    entries must never widen into the whole corpus.
    """
    conds, params = [], []
    if slug_filter:
        conds.append("url LIKE %s")
        params.append(f"%{slug_filter}%")
    if url_entries is not None:
        urls = sorted({e.value for e in url_entries if e.is_url})
        slugs = sorted({e.value for e in url_entries if not e.is_url})
        parts = []
        if urls:
            parts.append("RTRIM(url, '/') = ANY(%s)")
            params.append(urls)
        if slugs:
            parts.append("SUBSTRING(RTRIM(url, '/') FROM '[^/]*$') = ANY(%s)")
            params.append(slugs)
        conds.append("(" + " OR ".join(parts) + ")" if parts else "FALSE")
    if active_only:
        conds.append("expires_at >= CURRENT_DATE")
    if resume_key:
        provider, model, prompt_version = resume_key
        jp = "jobs_jobposting"
        conds.append(
            f"({jp}.schema_json IS NULL"
            f" OR ({jp}.schema_source_revision != '' AND {jp}.detail_content_hash != ''"
            f"     AND {jp}.schema_source_revision != {jp}.detail_content_hash)"
            f" OR ({jp}.schema_provider != '' AND ({jp}.schema_provider != %s"
            f"     OR {jp}.schema_model != %s OR {jp}.schema_prompt_version != %s))"
            + (f" OR {jp}.schema_provider = ''" if upgrade_legacy else "")
            + ")"
        )
        params.extend([provider, model, prompt_version])
    return (" WHERE " + " AND ".join(conds) if conds else ""), params


#: Newest first, so `--limit N` means "the N most recent postings" and is
#: reproducible. Shared by the run and the --dry-run preview so both list the
#: same postings in the same order.
_SELECTION_ORDER = " ORDER BY published_at DESC NULLS LAST, id DESC"


def iter_postings(conn, slug_filter=None, force=False, strip_boilerplate=True,
                  active_only=False, resume_key=None, upgrade_legacy=False,
                  url_entries=None):
    """Yield (posting_id, url, content_hash, combined_content) rows needing schema work.

    Combines body_markdown (web page text) and attachment_text (extracted from
    attached docx/pdf) so the LLM sees the full picture for each posting.
    When `strip_boilerplate` is True (default) the HG 1.336/2022 generic
    eligibility lines are stripped before yielding — see boilerplate.py.
    Skips rows where schema_json is already set unless force=True — EXCEPT
    under resume_key, where the WHERE clause already encodes revision-aware
    currency and stale production rows must be yielded, not skipped here.
    """
    where, params = _selection_where(slug_filter, active_only, resume_key, upgrade_legacy,
                                     url_entries)
    with conn.cursor() as cur:
        # Ordered (see _SELECTION_ORDER): without an ORDER BY it returned
        # whatever Postgres happened to scan first, which made a --limit test
        # run un-repeatable and never showed the postings most likely to reveal
        # a prompt problem.
        cur.execute(
            "SELECT id, url, body_markdown, attachment_text, schema_json, "
            "detail_content_hash "
            "FROM jobs_jobposting" + where + _SELECTION_ORDER,
            params,
        )
        for row_id, url, body, attachment, existing_schema, content_hash in cur:
            if existing_schema is not None and not force and resume_key is None:
                continue
            content = (body or "").strip()
            if attachment and attachment.strip():
                content += "\n\n---\n\n" + attachment.strip()
            if content:
                if strip_boilerplate:
                    content = strip_hg_1336(content)
                if len(content) > 100_000:
                    print(f"  ⚠ content truncated ({len(content)} chars) for {url.rstrip('/').split('/')[-1]}")
                    content = content[:100_000]
                yield row_id, url, content_hash or "", content


def _full_where(slug_filter=None, force=False, active_only=False, resume_key=None,
                upgrade_legacy=False, url_entries=None):
    """The complete WHERE of a run: `_selection_where` plus the already-extracted skip.

    iter_postings applies that skip in Python (it needs the row anyway);
    counting and listing apply it in SQL. Same postings either way.
    """
    where, params = _selection_where(slug_filter, active_only, resume_key, upgrade_legacy,
                                     url_entries)
    if not force and resume_key is None:
        where += (" AND " if where else " WHERE ") + "schema_json IS NULL"
    return where, params


def count_postings(conn, slug_filter=None, force=False, active_only=False, resume_key=None,
                   upgrade_legacy=False, url_entries=None):
    """Return the number of postings that iter_postings would yield."""
    where, params = _full_where(slug_filter, force, active_only, resume_key, upgrade_legacy,
                                url_entries)
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM jobs_jobposting" + where, params)
        return cur.fetchone()[0]


def selection_report(conn, *, limit=None, preview=20, **selection) -> dict:
    """What a run with this selection WOULD process, from SELECTs alone (--dry-run).

    Uses the same `_full_where` and ordering as the run, so the counts are the
    run's own. `active_total` is the denominator for "would this revision select
    the whole active corpus?" — every posting with `expires_at >= CURRENT_DATE`,
    whether or not --active-only is in effect. Reads only: no API call, no write.
    """
    selected = count_postings(conn, **selection)
    where, params = _full_where(**selection)
    glue = " AND " if where else " WHERE "
    shown = min(preview, limit) if limit else preview
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM jobs_jobposting" + where + glue
                    + "expires_at >= CURRENT_DATE", params)
        selected_active = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM jobs_jobposting WHERE expires_at >= CURRENT_DATE")
        active_total = cur.fetchone()[0]
        cur.execute("SELECT url FROM jobs_jobposting" + where + _SELECTION_ORDER + " LIMIT %s",
                    [*params, shown])
        slugs = [slug_of(row[0]) for row in cur.fetchall()]
    return {
        "selected": selected,
        "would_process": min(selected, limit) if limit else selected,
        "selected_active": selected_active,
        "active_total": active_total,
        "whole_active_corpus": active_total > 0 and selected_active >= active_total,
        "slugs": slugs,
    }


def url_entry_report(conn, **selection) -> dict:
    """Reconcile the --urls-file entries against the database and the selection.

    `not_found`: the entry names no posting at all (typo, wrong slug, not yet
    imported). `not_selected`: it names a posting that the other selectors leave
    out — already extracted (add --force), expired under --active-only, or
    current under --resume. Both are reported rather than silently dropped.
    """
    entries = selection["url_entries"]

    def urls_for(where, params):
        with conn.cursor() as cur:
            cur.execute("SELECT url FROM jobs_jobposting" + where, params)
            return [row[0] for row in cur.fetchall()]

    found = _entries_hit(entries, urls_for(*_selection_where(url_entries=entries)))
    chosen = _entries_hit(entries, urls_for(*_full_where(**selection)))
    return {
        "entries": len(entries),
        "selected_entries": len(chosen),
        "not_found": [e.raw for e in entries if e not in found],
        "not_selected": [e.raw for e in entries if e in found and e not in chosen],
    }


def write_schema(conn, posting_id, schema, *, provider, model, prompt_version,
                 source_revision, extracted_at):
    """Write the extracted schema AND its production provenance, atomically.

    One UPDATE, one commit: provenance can never disagree with schema_json
    (a crash between the two would otherwise leave a production row that
    --resume cannot recognize as current and would re-extract). Compare-only
    runs never call this — variants alone are not production.
    """
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE jobs_jobposting SET schema_json = %s, "
            "schema_provider = %s, schema_model = %s, schema_prompt_version = %s, "
            "schema_source_revision = %s, schema_extracted_at = %s "
            "WHERE id = %s",
            (json.dumps(schema, ensure_ascii=False), provider, model,
             prompt_version, source_revision or "", extracted_at, posting_id),
        )
    conn.commit()


def write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost_usd, latency_ms, prompt_version=PROMPT_VERSION, source_revision=""):
    """Write variant to jobs_jobpostingschemavariant (upsert on unique constraint).

    `source_revision` records which source content this variant saw, so a
    stale compare result can never be mistaken for current evidence (FIX-05).
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO jobs_jobpostingschemavariant
                (posting_id, provider, model, prompt_version, schema_json,
                 input_tokens, output_tokens, cost_usd, latency_ms,
                 source_revision, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
            ON CONFLICT (posting_id, provider, model, prompt_version)
            DO UPDATE SET
                schema_json     = EXCLUDED.schema_json,
                input_tokens    = EXCLUDED.input_tokens,
                output_tokens   = EXCLUDED.output_tokens,
                cost_usd        = EXCLUDED.cost_usd,
                latency_ms      = EXCLUDED.latency_ms,
                source_revision = EXCLUDED.source_revision,
                created_at      = NOW()
            """,
            (posting_id, provider, model, prompt_version,
             json.dumps(schema, ensure_ascii=False),
             input_tokens, output_tokens, cost_usd, latency_ms,
             source_revision or ""),
        )
    conn.commit()


# ---------------------------------------------------------------------------
# Run summary and exit status (FIX-04)
# ---------------------------------------------------------------------------
# A run that printed "0 ok, N failed" and exited 0 was indistinguishable from
# success. The summary below is the durable, machine-readable record of one
# model-run — appended to the pipeline run log under the same run id — and
# `evaluate_exit` turns it into the exit status.

@dataclass
class RunSummary:
    """What one (provider, model, prompt) run did.

    All counts are POSTINGS, except `retried`, which counts the extra API
    attempts those postings needed. `skipped` is postings the selection
    yielded with no usable body+attachment text — they were never callable.
    """
    provider: str
    model: str
    prompt_version: str
    selected: int = 0
    attempted: int = 0
    ok: int = 0
    failed: int = 0
    skipped: int = 0
    retried: int = 0
    ungrounded: int = 0
    fatal: bool = False
    fatal_reason: str = ""
    failure_classes: dict = field(default_factory=dict)
    input_tokens: int = 0
    output_tokens: int = 0
    cached_input_tokens: int = 0
    cost_usd: float = 0.0
    duration_s: float = 0.0

    @property
    def zero_work(self) -> bool:
        return self.attempted == 0 and self.skipped == 0

    def to_record(self, run_id: str) -> dict:
        return {
            "kind": "llm-schema",
            "format": 1,
            "run_id": run_id,
            "step": "schema",
            "provider": self.provider,
            "model": self.model,
            "prompt_version": self.prompt_version,
            # Postings, except retried (extra API attempts) — see the dataclass.
            "selected": self.selected,
            "attempted": self.attempted,
            "ok": self.ok,
            "failed": self.failed,
            "skipped": self.skipped,
            "retried": self.retried,
            "ungrounded": self.ungrounded,
            "failure_classes": self.failure_classes,
            "fatal": self.fatal,
            "fatal_reason": self.fatal_reason,
            "zero_work": self.zero_work,
            "duration_s": round(self.duration_s, 1),
            "usage": {
                "input_tokens": self.input_tokens,
                "output_tokens": self.output_tokens,
                "cached_input_tokens": self.cached_input_tokens,
                "cost_usd": round(self.cost_usd, 6),
            },
        }


#: Failure classes that belong to ONE posting's content, not to the provider:
#: the answer was cut off at the cap, or came back but failed parsing/validation.
#: The same postings fail the same way every run (--resume reselects rows with
#: no schema), so on a quiet run — a weekend has no new postings — a residue of
#: five turned "5 of 5 failed" into a blocked deploy (REV-08/17). They count
#: toward the failure share only once a run attempted CONTENT_GATE_MIN_SAMPLE
#: postings: a broken prompt or a model change still trips the gate on any
#: normal weekday (the 04.10 truncation outage was 321 of 326), the residue
#: alone never does. Anything else — HTTP errors, timeouts, connection
#: failures, an unclassified exception — is systemic and always counts.
CONTENT_FAILURE_CLASSES = frozenset({
    "OutputTruncated", "ValidationError", "ValueError", "StopIteration", "non_dict",
})
CONTENT_GATE_MIN_SAMPLE = 20


def evaluate_exit(summaries: list, max_failure_share: float,
                  min_sample: int = CONTENT_GATE_MIN_SAMPLE) -> int:
    """Exit code for a run, from its per-model summaries.

    0 — healthy work, including a legitimately empty selection (`zero_work`)
        and a small residue of postings whose own content keeps failing;
    1 — systemic failures (provider/transport, or unclassified) exceed
        `max_failure_share` of the attempted postings; or, on a sample of at
        least `min_sample` postings, all failures together do; or at least
        `min_sample` selected postings had no usable content at all;
    2 — a provider-wide fatal error (402 / invalid auth): the run stopped
        scheduling new calls.
    """
    code = 0
    for s in summaries:
        if s.fatal:
            code = max(code, 2)
            continue
        content = sum(n for cls, n in (s.failure_classes or {}).items()
                      if cls in CONTENT_FAILURE_CLASSES)
        systemic = max(s.failed - content, 0)
        if s.attempted > 0:
            if systemic / s.attempted > max_failure_share:
                code = max(code, 1)
            elif s.attempted >= min_sample and s.failed / s.attempted > max_failure_share:
                code = max(code, 1)
        elif s.skipped >= min_sample:
            # Selected work, none of it callable: every posting had an empty
            # body+attachment — a data problem (extraction or import broke), not
            # a healthy no-op. A couple of genuinely empty postings is not that.
            code = max(code, 1)
    return code


def _append_schema_record(run_log: Path, summary: RunSummary, run_id: str) -> None:
    """Append the summary to the pipeline run log (same shape as pipeline.py).

    Instrumentation never fails a run: errors are reported and swallowed.
    """
    try:
        sys.path.insert(0, str(Path(__file__).parent))
        from pipeline import append_run_record

        append_run_record(run_log, summary.to_record(run_id))
    except Exception as exc:  # noqa: BLE001
        print(f"  WARNING: could not write the schema run record: {exc}", file=sys.stderr)


# ---------------------------------------------------------------------------
# Per-posting failure diagnostics (FIX-05-STAGES)
# ---------------------------------------------------------------------------
# A posting that fails for good used to leave one console line and nothing else:
# the first (repaired) attempt's error went only into the repair prompt, so which
# value the model emitted — an unlisted calendar stage, say — was lost the moment
# the run ended. `data/schema-failures.jsonl` keeps one line per failed posting,
# with every failed attempt, so a policy decision can read the evidence.

FAILURE_LOG_KIND = "llm-schema-failure"
#: A posting that failed validation, was repaired and then SUCCEEDED. Same file and
#: shape as a failure; it is evidence that the model emitted a bad value (an unlisted
#: stage, say) even though nothing was lost — the policy question is how often.
REPAIRED_LOG_KIND = "llm-schema-repaired"
DEFAULT_FAILURE_LOG = str(Path("data") / "schema-failures.jsonl")

MAX_LOGGED_MESSAGE = 800    # the final exception's message
MAX_ATTEMPT_MESSAGE = 300   # a non-validation attempt's message
MAX_LOGGED_INPUT = 200      # one rejected value
MAX_LOGGED_ERRORS = 25      # validation errors kept per attempt (the total is recorded)

_FAILURE_LOG_LOCK = threading.Lock()


def failure_class(exc) -> str:
    """`HTTP_<status>` when the SDK exposes one, else the exception's class name.

    The vocabulary of RunSummary.failure_classes and of the failure log alike.
    """
    status = _status_of(exc)
    return f"HTTP_{status}" if status is not None else type(exc).__name__


def _clip(value, limit: int) -> str:
    try:
        text = value if isinstance(value, str) else str(value)
    except Exception:  # noqa: BLE001 — a hostile __str__ must not lose the record
        text = "<unprintable>"
    return text[:limit]


def describe_attempt(exc, attempt: int) -> dict:
    """One failed attempt as a JSON-safe dict.

    A pydantic ValidationError carries `errors`: the dotted path, the error type
    and the raw input the model produced, so the rejected value survives. Only
    those three keys are taken — `ctx` can hold exception objects that do not
    serialise.
    """
    entry = {"attempt": attempt, "error_class": failure_class(exc)}
    if isinstance(exc, ValidationError):
        try:
            errors = exc.errors(include_url=False, include_context=False)
        except Exception:  # noqa: BLE001
            errors = []
        entry["error_count"] = len(errors)
        entry["errors"] = [
            {
                "loc": ".".join(str(part) for part in e.get("loc", ())) or "(root)",
                "type": e.get("type", ""),
                "input": _clip(e.get("input"), MAX_LOGGED_INPUT),
            }
            for e in errors[:MAX_LOGGED_ERRORS]
        ]
    else:
        entry["message"] = _clip(exc, MAX_ATTEMPT_MESSAGE)
    return entry


@dataclass
class AttemptTrail:
    """The failed attempts of ONE posting, in order. Written by the worker, read once."""
    attempts: list = field(default_factory=list)
    last_message: str = ""      # str() of the most recent failed attempt's exception

    def note(self, attempt: int, exc) -> None:
        self.attempts.append(describe_attempt(exc, attempt))
        self.last_message = _clip(exc, MAX_LOGGED_MESSAGE)

    @property
    def was_repaired(self) -> bool:
        """True when some failed attempt was a validation error — the repair's trigger."""
        return any(a.get("error_class") == "ValidationError" for a in self.attempts)


def build_failure_record(*, run_id, posting_id, url, provider, model, prompt_version,
                         source_revision, compare, error_class, message, attempts,
                         at=None, kind=FAILURE_LOG_KIND) -> dict:
    return {
        "kind": kind,
        "format": 1,
        "run_id": run_id,
        "at": (at or datetime.now(timezone.utc)).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "posting_id": posting_id,
        "url": url,
        "slug": slug_of(url),
        "provider": provider,
        "model": model,
        "prompt_version": prompt_version,
        # The revision a successful run would have stamped: the posting's
        # detail_content_hash ("" when it has none).
        "source_revision": source_revision or "",
        "compare": bool(compare),
        "error_class": error_class,
        "message": _clip(message, MAX_LOGGED_MESSAGE),
        "attempts": attempts,
    }


def append_failure_record(path: Path, record: dict) -> bool:
    """Append one JSON line to the failure log. Never fails a run.

    One `os.write` of the whole line on an O_APPEND descriptor, under a lock:
    concurrent workers (and a hand-run beside the cron) cannot interleave two
    records. Errors are reported and swallowed, like the run log's.
    """
    line = (json.dumps(record, ensure_ascii=False) + "\n").encode("utf-8")
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        with _FAILURE_LOG_LOCK:
            fd = os.open(path, os.O_WRONLY | os.O_APPEND | os.O_CREAT, 0o644)
            try:
                view = memoryview(line)
                while view:
                    view = view[os.write(fd, view):]
            finally:
                os.close(fd)
        return True
    except OSError as exc:
        print(f"  WARNING: could not write the failure log at {path}: {exc}", file=sys.stderr)
        return False


def _print_url_entry_report(report: dict) -> None:
    def names(items, shown=20):
        extra = f", ... and {len(items) - shown} more" if len(items) > shown else ""
        return ", ".join(items[:shown]) + extra

    print(f"  --urls-file: {report['entries']} entries, {report['selected_entries']} "
          f"selected for this run")
    if report["not_found"]:
        print(f"  ⚠ {len(report['not_found'])} entries match no posting in the database: "
              f"{names(report['not_found'])}")
    if report["not_selected"]:
        print(f"  ⚠ {len(report['not_selected'])} entries match a posting this run leaves out "
              f"(already extracted — add --force; or excluded by --active-only/--resume): "
              f"{names(report['not_selected'])}")


def _print_dry_run(provider, model, prompt_version, report: dict, limit) -> None:
    print(f"  [dry run] {provider}/{model} @ {prompt_version} — no API calls, no database writes")
    print(f"  selected:       {report['selected']} postings "
          f"({report['selected_active']} of them active)")
    print(f"  active corpus:  {report['active_total']} postings (expires_at >= today)")
    if report["whole_active_corpus"]:
        print("  ⚠ this selection covers the ENTIRE active corpus — a run would re-pay for all of it")
    elif report["active_total"]:
        print(f"  coverage:       {report['selected_active'] / report['active_total']:.1%} "
              f"of the active corpus")
    if limit:
        print(f"  would process:  {report['would_process']} (--limit {limit})")
    print(f"  first {len(report['slugs'])} selected (newest first):")
    for slug in report["slugs"]:
        print(f"    {slug}")


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description='Extract structured job sections and store in Postgres schema_json')
    parser.add_argument('--provider', choices=list(PROVIDERS), default=None,
                        help='LLM provider. Defaults to $LLM_PROVIDER, then models_config.json '
                             '"defaults.provider".')
    parser.add_argument('--model', default=None,
                        help='Override the provider default model. Defaults to $LLM_MODEL when it '
                             'names a model of the selected provider, then models_config.json.')
    parser.add_argument('--slug', default=None, help='Process only postings whose URL contains this string')
    parser.add_argument('--urls-file', default=None, metavar='PATH',
                        help='Process only the postings named in this file — one full URL or slug '
                             'per line (blank and # lines ignored). EXACT matches only: a URL is '
                             'compared whole, a slug against the last path segment, so "...-10" '
                             'never selects "...-100". Combines with --force, --resume, --compare, '
                             '--active-only and --prompt-version; entries that match nothing, or '
                             'that another selector leaves out, are reported.')
    parser.add_argument('--dry-run', action='store_true',
                        help='Compute the selection exactly as a real run would, print the selected '
                             'count, the active-corpus size and the first 20 slugs, then exit 0 — no '
                             'API calls, no database writes, no run-log or failure-log records.')
    parser.add_argument('--force', action='store_true', help='Re-generate even if schema_json already set')
    parser.add_argument('--limit', type=int, default=None, help='Process at most N postings')
    parser.add_argument('--compare', action='store_true', help='Run all enabled providers and save variants only (does NOT overwrite schema_json)')
    parser.add_argument('--model-filter', default=None, help='Only test models matching this regex (e.g., "gemini-.*" or "gpt-.*")')
    parser.add_argument('--prompt-version', default=None,
                        help='Prompt version from models_config.json. v2 = descriptive, '
                             'v3 = descriptive + structured match keys. Defaults to '
                             f'$LLM_PROMPT_VERSION, then models_config.json (currently {PROMPT_VERSION}).')
    parser.add_argument('--active-only', action='store_true',
                        help='Only process postings whose deadline has not passed (expires_at >= today).')
    parser.add_argument('--no-strip', action='store_true', help='Do NOT strip HG 1.336/2022 boilerplate from the input (kept for comparison runs).')
    parser.add_argument('--workers', type=int, default=4, metavar='N',
                        help='Concurrent LLM calls (default: %(default)s). Database writes stay '
                             'single-threaded. Use 1 for the old strictly-sequential behaviour.')
    parser.add_argument('--max-attempts', type=int, default=4, metavar='N',
                        help='Attempts per posting before giving up (default: %(default)s): '
                             'transient errors back off exponentially, invalid output gets one '
                             'repair attempt.')
    parser.add_argument('--resume', action='store_true',
                        help='Skip postings that already have a variant row for this exact '
                             'provider/model/prompt-version. Makes an interrupted run restartable.')
    parser.add_argument('--upgrade-legacy', action='store_true',
                        help='With --resume: also re-extract production rows that have no '
                             'provenance (extracted before migration 0014). This is the paid '
                             'FIX-05-RUN backfill — run it deliberately, never from the cron.')
    parser.add_argument('--max-failure-share', type=float, default=0.5, metavar='F',
                        help='Exit non-zero when more than this share of attempted postings '
                             'fails (default: %(default)s). A provider-wide fatal error '
                             '(402 / invalid auth) always exits non-zero.')
    parser.add_argument('--run-log', default=str(Path('data/pipeline-runs.jsonl')), metavar='PATH',
                        help='Append one JSON summary record per model to this file '
                             '(default: %(default)s).')
    parser.add_argument('--no-run-log', action='store_true',
                        help='Do not write the schema run summary records.')
    parser.add_argument('--failure-log', default=DEFAULT_FAILURE_LOG, metavar='PATH',
                        help='Append one JSON line per posting that fails for good — every '
                             'failed attempt, with the rejected values — to this file '
                             '(default: %(default)s); a posting repaired after a validation '
                             'error and then successful is recorded as kind '
                             '"llm-schema-repaired". `ops/stage-failures.py` summarises it.')
    parser.add_argument('--no-failure-log', action='store_true',
                        help='Do not write per-posting failure records.')
    args = parser.parse_args(argv)

    if args.workers < 1:
        parser.error("--workers must be at least 1")

    url_entries = None
    if args.urls_file:
        try:
            url_entries = parse_url_entries(Path(args.urls_file).read_text(encoding="utf-8"))
        except OSError as exc:
            parser.error(f"--urls-file: cannot read {args.urls_file}: {exc}")
        if not url_entries:
            parser.error(f"--urls-file: {args.urls_file} has no entries (blank and # lines are "
                         "ignored) — refusing to run an empty restriction")
    failure_log = None if args.no_failure_log else Path(args.failure_log)

    args.prompt_version = resolve_prompt_version(args.prompt_version)

    # One id ties this summary to the pipeline record that spawned it, and to
    # the export-check record that follows — see ops/run-pipeline.sh.
    run_id = os.environ.get("POSTURI_RUN_ID") or datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    # Load the prompt version
    system_prefix = get_prompt(args.prompt_version)
    if not system_prefix:
        print(f"✗ Prompt version '{args.prompt_version}' not found in config")
        return 1

    with psycopg.connect(DATABASE_URL) as conn:
        if args.compare:
            # Get all enabled models, optionally filtered by regex
            all_enabled = get_enabled_models()
            if args.model_filter:
                pattern = re.compile(args.model_filter)
                providers_to_run = [(p, m) for p, m in all_enabled if pattern.search(m)]
                print(f"Running comparison (filtered by '{args.model_filter}') on {len(providers_to_run)} models...")
            else:
                providers_to_run = all_enabled
                print(f"Running comparison on {len(providers_to_run)} enabled models...")
        else:
            provider = resolve_provider(args.provider)
            model = resolve_model(provider, args.model)
            providers_to_run = [(provider, model)]
            print(f"Provider: {provider}, model: {model}, prompt: {args.prompt_version}")

        summaries = []
        ledgers = []     # one per (provider, model): FIX-10 usage, written beside the summaries
        for provider, model in providers_to_run:
            print(f"\n[{provider}/{model} @ {args.prompt_version}]")
            force_flag = args.force or args.compare
            resume_key = (provider, model, args.prompt_version) if args.resume else None
            selection = dict(
                slug_filter=args.slug,
                force=force_flag,
                active_only=args.active_only,
                resume_key=resume_key,
                upgrade_legacy=args.upgrade_legacy and resume_key is not None,
                url_entries=url_entries,
            )
            if url_entries is not None:
                _print_url_entry_report(url_entry_report(conn, **selection))
            if args.dry_run:
                # Before make_generator: a preview must not need provider
                # credentials, and nothing below it may run.
                _print_dry_run(provider, model, args.prompt_version,
                               selection_report(conn, limit=args.limit, **selection), args.limit)
                continue
            generate = make_generator(provider, model, system_prefix, args.prompt_version)
            ledger = llm_ledger.make_ledger(
                "schema", provider, model, args.prompt_version, run_id=run_id,
                pricer=llm_ledger.pricer_from(compute_cost, rate_card))
            ledgers.append(ledger)
            total = count_postings(conn, **selection)
            if args.limit:
                total = min(total, args.limit)
            postings = iter_postings(conn, strip_boilerplate=not args.no_strip, **selection)
            if args.limit:
                postings = itertools.islice(postings, args.limit)

            summary = RunSummary(provider=provider, model=model,
                                 prompt_version=args.prompt_version, selected=total)
            run_start = time.monotonic()

            #: posting id -> the failed attempts of that posting. A worker adds its
            #: own key; the calling thread pops it once the future completes.
            trails: dict = {}

            def call(row, _generate=generate, _ledger=ledger):
                """Runs on a worker thread: LLM call + local checks, no database.

                The ledger writes its own rows (one lock-serialised connection), so this
                stays off the calling thread's connection.
                """
                posting_id, url, content_hash, content = row
                retries = 0
                trail = trails[posting_id] = AttemptTrail()

                def note_retry(attempt, exc, delay):
                    nonlocal retries
                    retries += 1
                    # The failed attempt's own error — for a repair this is the
                    # first rejection, which otherwise only reaches the repair
                    # prompt. The console line below is unchanged.
                    trail.note(attempt, exc)
                    wait_note = f", retrying in {delay:.1f}s" if delay else ", repairing"
                    tqdm.write(f"  ⟳ {url.rstrip('/').split('/')[-1][:45]}: "
                               f"{type(exc).__name__} on attempt {attempt}{wait_note}")

                t0 = time.monotonic()
                try:
                    schema, input_tokens, output_tokens, cached_tokens = generate_with_retry(
                        _generate, content,
                        max_attempts=args.max_attempts,
                        on_retry=note_retry,
                        ledger=_ledger.request(slug_of(url), posting_id=posting_id,
                                               source_revision=content_hash or ""),
                    )
                except Exception as exc:
                    trail.note(len(trail.attempts) + 1, exc)   # the attempt that raised
                    raise
                latency_ms = int((time.monotonic() - t0) * 1000)
                # Grounding is a local string check against the same text the
                # model saw — no extra call, so it runs on every posting.
                ungrounded = check_grounding(schema, content) if isinstance(schema, dict) else []
                return schema, input_tokens, output_tokens, cached_tokens, latency_ms, ungrounded, retries

            repaired_logged = 0

            def log_failure(posting_id, url, content_hash, error_class, message, attempts,
                            kind=FAILURE_LOG_KIND):
                if failure_log is not None:
                    append_failure_record(failure_log, build_failure_record(
                        run_id=run_id, posting_id=posting_id, url=url, provider=provider,
                        model=model, prompt_version=args.prompt_version,
                        source_revision=content_hash, compare=args.compare,
                        error_class=error_class, message=message, attempts=attempts,
                        kind=kind))

            fatal_error = None
            with tqdm(total=total, unit="post", dynamic_ncols=True) as bar:
                for (posting_id, url, content_hash, content), future in imap_unordered(
                    call, postings, args.workers
                ):
                    slug = url.rstrip('/').split('/')[-1]
                    bar.set_description(slug[:55])
                    bar.update(1)
                    summary.attempted += 1
                    trail = trails.pop(posting_id, None) or AttemptTrail()
                    try:
                        (schema, input_tokens, output_tokens, cached_tokens,
                         latency_ms, ungrounded, retries) = future.result()
                    except FatalError as e:
                        # Provider-wide: stop pulling the iterator (no new calls
                        # are scheduled) and let the in-flight ones drain — the
                        # pool shuts down when this generator closes.
                        summary.fatal = True
                        summary.fatal_reason = str(e)
                        summary.failed += 1
                        fatal_error = e
                        tqdm.write(f"  ✗✗ FATAL ({provider}/{model}): {e} — stopping this model, "
                                   f"already-running calls will drain")
                        break
                    except Exception as e:
                        summary.failed += 1
                        summary.failure_classes[failure_class(e)] = \
                            summary.failure_classes.get(failure_class(e), 0) + 1
                        if isinstance(e, OutputTruncated):
                            # Billed though nothing is stored: keep the run's
                            # usage and cost honest (they were understated).
                            t_in, t_out, t_cached = e.usage
                            summary.input_tokens += t_in or 0
                            summary.output_tokens += t_out or 0
                            summary.cached_input_tokens += t_cached or 0
                            t_cost = compute_cost(provider, model, t_in, t_out, t_cached)
                            if t_cost:
                                summary.cost_usd += t_cost
                        tqdm.write(f"  ✗ {slug}: {type(e).__name__}: {e}")
                        # Nothing was stored, so the posting's previous
                        # schema_json and provenance are untouched.
                        log_failure(posting_id, url, content_hash, failure_class(e), str(e),
                                    trail.attempts)
                        continue

                    summary.retried += bool(retries)

                    if not isinstance(schema, dict):
                        summary.failed += 1
                        summary.failure_classes["non_dict"] = summary.failure_classes.get("non_dict", 0) + 1
                        tqdm.write(f"  ✗ {slug}: non-dict: {repr(schema)[:80]}")
                        log_failure(posting_id, url, content_hash, "non_dict",
                                    f"non-dict: {repr(schema)}",
                                    trail.attempts + [{"attempt": len(trail.attempts) + 1,
                                                       "error_class": "non_dict",
                                                       "message": _clip(repr(schema), MAX_ATTEMPT_MESSAGE)}])
                        continue

                    if trail.was_repaired:
                        # Succeeded after a rejected first attempt: nothing is lost,
                        # but the rejected value is evidence. Logging it changes no
                        # database write below.
                        log_failure(posting_id, url, content_hash, trail.attempts[-1]["error_class"],
                                    trail.last_message, trail.attempts, kind=REPAIRED_LOG_KIND)
                        repaired_logged += failure_log is not None

                    for finding in ungrounded:
                        summary.ungrounded += 1
                        tqdm.write(f"  ⚠ {slug}: unsupported quote — {finding}")

                    cost = compute_cost(provider, model, input_tokens, output_tokens, cached_tokens)
                    write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost, latency_ms, args.prompt_version,
                                  source_revision=content_hash)

                    if not args.compare:
                        write_schema(conn, posting_id, schema,
                                     provider=provider, model=model,
                                     prompt_version=args.prompt_version,
                                     source_revision=content_hash,
                                     extracted_at=datetime.now(timezone.utc))

                    summary.ok += 1
                    summary.input_tokens += input_tokens or 0
                    summary.output_tokens += output_tokens or 0
                    summary.cached_input_tokens += cached_tokens or 0
                    if cost:
                        summary.cost_usd += cost
                    tok_parts = []
                    if input_tokens and output_tokens:
                        tok_parts.append(f"in={input_tokens}")
                        if cached_tokens:
                            tok_parts.append(f"cached={cached_tokens}")
                        tok_parts.append(f"out={output_tokens}")
                    token_info = " ".join(tok_parts) if tok_parts else "tokens=?"
                    cost_info = f"${cost:.6f}" if cost else "cost=?"
                    bar.set_postfix_str(f"✓ {latency_ms}ms {token_info} {cost_info}")

            summary.skipped = max(0, summary.selected - summary.attempted)
            summary.duration_s = time.monotonic() - run_start
            summaries.append(summary)

            if fatal_error:
                print(f"  ✗✗ {provider}/{model}: provider-wide failure — "
                      f"{summary.ok} ok, {summary.failed} failed ({summary.fatal_reason})")
            elif summary.zero_work:
                print("  ✓ 0 eligible postings — the selection succeeded and there is "
                      "nothing to do; this is a healthy run")
            else:
                print(f"  {summary.ok} ok, {summary.failed} failed, "
                      f"{summary.skipped} skipped (empty content), "
                      f"{summary.retried} needed a retry, "
                      f"{summary.ungrounded} unsupported quote(s)")
            if (summary.failed or repaired_logged) and failure_log is not None:
                print(f"  failure diagnostics appended to {failure_log} "
                      f"({summary.failed} failed, {repaired_logged} repaired)")

        if args.dry_run:
            return 0

        if not args.no_run_log:
            for summary in summaries:
                _append_schema_record(Path(args.run_log), summary, run_id)
            for ledger in ledgers:
                ledger.write_usage_record(args.run_log)

        exit_code = evaluate_exit(summaries, args.max_failure_share)
        if exit_code == 2:
            print("\n✗✗ Extraction stopped: a provider-wide fatal error (402 / invalid auth).")
        elif exit_code == 1:
            print("\n✗ Extraction finished with too many failures — see the summary above.")
        return exit_code


if __name__ == '__main__':
    sys.exit(main())

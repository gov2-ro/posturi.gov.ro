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
"""

import argparse
import json
import os
import random
import re
import sys
import time
import itertools
from concurrent.futures import FIRST_COMPLETED, Future, ThreadPoolExecutor, wait
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
import psycopg
from pydantic import ValidationError
from tqdm import tqdm
from decimal import Decimal
from dotenv import load_dotenv

from boilerplate import strip_hg_1336
from grounding import check_grounding
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
MAX_OUTPUT_TOKENS = {"v4": 8000}
DEFAULT_MAX_OUTPUT_TOKENS = 8000


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


def compute_cost(provider, model, input_tokens, output_tokens, cached_input_tokens=0):
    """Calculate cost in USD based on model pricing from config.

    `cached_input_tokens` are billed at the model's `cache_input_cost_per_million`
    rate when defined; the remaining (input_tokens - cached_input_tokens) are
    billed at the standard `input_cost_per_million`.
    """
    if not input_tokens or output_tokens is None:
        return None
    try:
        model_config = MODELS_CONFIG["providers"][provider]["models"][model]
        in_rate = Decimal(str(model_config["input_cost_per_million"]))
        out_rate = Decimal(str(model_config["output_cost_per_million"]))
        cache_rate = Decimal(str(model_config.get("cache_input_cost_per_million", model_config["input_cost_per_million"])))

        cached = max(0, cached_input_tokens or 0)
        uncached = max(0, input_tokens - cached)

        input_cost = (Decimal(uncached) * in_rate + Decimal(cached) * cache_rate) / Decimal("1000000")
        output_cost = Decimal(output_tokens) * out_rate / Decimal("1000000")
        return float(input_cost + output_cost)
    except (KeyError, TypeError):
        return None


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


def generate_with_retry(generate, content, *, max_attempts=4, base_delay=2.0, on_retry=None):
    """Call `generate(content)`, retrying transient failures and repairing invalid output.

    Two different failures need two different responses:
      - transient (429/5xx/timeout) — same input, exponential backoff with jitter;
      - invalid output (schema or parse) — one repair attempt that appends the
        validation error to the *user* message, then give up.

    Only one repair is attempted: if the model cannot produce valid output when
    shown the error, a third try is not going to help and costs a full prompt.
    """
    payload = content
    repaired = False
    last_exc = None

    for attempt in range(1, max_attempts + 1):
        try:
            return generate(payload)
        except FatalError:
            raise
        except Exception as exc:  # noqa: BLE001 — classified immediately below
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
            raw = resp.parsed if use_schema and getattr(resp, "parsed", None) is not None else resp.text
            if hasattr(raw, "model_dump"):
                raw = raw.model_dump(mode="json")
            schema = _validate_extraction(raw, prompt_version) if use_schema else parse_json_response(raw)
            usage = getattr(resp, "usage_metadata", None)
            input_tokens = getattr(usage, "prompt_token_count", None) if usage else None
            output_tokens = getattr(usage, "candidates_token_count", None) if usage else None
            cached_tokens = getattr(usage, "cached_content_token_count", 0) if usage else 0
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
            if getattr(msg, "stop_reason", None) == "max_tokens":
                u = getattr(msg, "usage", None)
                raise OutputTruncated(out_budget, getattr(u, "input_tokens", None),
                                      getattr(u, "output_tokens", None))
            if use_schema:
                tool_block = next(b for b in msg.content if getattr(b, "type", "") == "tool_use")
                schema = _validate_extraction(tool_block.input, prompt_version)
            else:
                text_block = next(b for b in msg.content if getattr(b, "type", "") == "text")
                schema = parse_json_response(text_block.text)
            usage = getattr(msg, "usage", None)
            input_tokens = getattr(usage, "input_tokens", None) if usage else None
            output_tokens = getattr(usage, "output_tokens", None) if usage else None
            cached_tokens = (getattr(usage, "cache_read_input_tokens", 0) or 0) if usage else 0
            # Anthropic reports cache reads outside of input_tokens; fold them in
            # so cost math matches: total billable input = input_tokens + cache_read
            if cached_tokens and input_tokens is not None:
                input_tokens = input_tokens + cached_tokens
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
            if getattr(resp.choices[0], "finish_reason", None) == "length":
                raise OutputTruncated(out_budget, input_tokens, output_tokens, cached_tokens)
            schema = _validate_extraction(text, prompt_version) if use_schema else parse_json_response(text)
            return schema, input_tokens, output_tokens, cached_tokens or 0

    else:
        raise ValueError(f"Unknown provider: {provider}")

    return generate


def _selection_where(slug_filter=None, active_only=False, resume_key=None,
                     upgrade_legacy=False):
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
    """
    conds, params = [], []
    if slug_filter:
        conds.append("url LIKE %s")
        params.append(f"%{slug_filter}%")
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


def iter_postings(conn, slug_filter=None, force=False, strip_boilerplate=True,
                  active_only=False, resume_key=None, upgrade_legacy=False):
    """Yield (posting_id, url, content_hash, combined_content) rows needing schema work.

    Combines body_markdown (web page text) and attachment_text (extracted from
    attached docx/pdf) so the LLM sees the full picture for each posting.
    When `strip_boilerplate` is True (default) the HG 1.336/2022 generic
    eligibility lines are stripped before yielding — see boilerplate.py.
    Skips rows where schema_json is already set unless force=True — EXCEPT
    under resume_key, where the WHERE clause already encodes revision-aware
    currency and stale production rows must be yielded, not skipped here.
    """
    where, params = _selection_where(slug_filter, active_only, resume_key, upgrade_legacy)
    with conn.cursor() as cur:
        # Newest first, so `--limit N` means "the N most recent postings" and is
        # reproducible. Without an ORDER BY it returned whatever Postgres
        # happened to scan first, which made a --limit test run un-repeatable
        # and never showed the postings most likely to reveal a prompt problem.
        cur.execute(
            "SELECT id, url, body_markdown, attachment_text, schema_json, "
            "detail_content_hash "
            "FROM jobs_jobposting" + where +
            " ORDER BY published_at DESC NULLS LAST, id DESC",
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


def count_postings(conn, slug_filter=None, force=False, active_only=False, resume_key=None,
                   upgrade_legacy=False):
    """Return the number of postings that iter_postings would yield."""
    where, params = _selection_where(slug_filter, active_only, resume_key, upgrade_legacy)
    if not force and resume_key is None:
        where += (" AND " if where else " WHERE ") + "schema_json IS NULL"
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM jobs_jobposting" + where, params)
        return cur.fetchone()[0]


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


def evaluate_exit(summaries: list, max_failure_share: float) -> int:
    """Exit code for a run, from its per-model summaries.

    0 — every model did healthy work (including a legitimately empty
        selection, which the summary reports explicitly as `zero_work`);
    1 — a model failed on more than `max_failure_share` of its attempted
        postings, or was selected work it could not even attempt;
    2 — a provider-wide fatal error (402 / invalid auth): the run stopped
        scheduling new calls.
    """
    code = 0
    for s in summaries:
        if s.fatal:
            code = max(code, 2)
        elif s.attempted > 0:
            if s.failed / s.attempted > max_failure_share:
                code = max(code, 1)
        elif s.skipped > 0:
            # Selected but nothing attemptable: every posting had an empty
            # body+attachment. That is a data problem, not a healthy no-op.
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


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Extract structured job sections and store in Postgres schema_json')
    parser.add_argument('--provider', choices=list(PROVIDERS), default=None,
                        help='LLM provider. Defaults to $LLM_PROVIDER, then models_config.json '
                             '"defaults.provider".')
    parser.add_argument('--model', default=None,
                        help='Override the provider default model. Defaults to $LLM_MODEL when it '
                             'names a model of the selected provider, then models_config.json.')
    parser.add_argument('--slug', default=None, help='Process only postings whose URL contains this string')
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
    args = parser.parse_args()

    if args.workers < 1:
        parser.error("--workers must be at least 1")

    args.prompt_version = resolve_prompt_version(args.prompt_version)

    # One id ties this summary to the pipeline record that spawned it, and to
    # the export-check record that follows — see ops/run-pipeline.sh.
    run_id = os.environ.get("POSTURI_RUN_ID") or datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    # Load the prompt version
    system_prefix = get_prompt(args.prompt_version)
    if not system_prefix:
        print(f"✗ Prompt version '{args.prompt_version}' not found in config")
        sys.exit(1)

    with psycopg.connect(DATABASE_URL) as conn:
        if args.compare:
            # Get all enabled models, optionally filtered by regex
            all_enabled = get_enabled_models()
            if args.model_filter:
                import re
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
        for provider, model in providers_to_run:
            print(f"\n[{provider}/{model} @ {args.prompt_version}]")
            generate = make_generator(provider, model, system_prefix, args.prompt_version)
            force_flag = args.force or args.compare
            resume_key = (provider, model, args.prompt_version) if args.resume else None
            selection = dict(
                slug_filter=args.slug,
                force=force_flag,
                active_only=args.active_only,
                resume_key=resume_key,
                upgrade_legacy=args.upgrade_legacy and resume_key is not None,
            )
            total = count_postings(conn, **selection)
            if args.limit:
                total = min(total, args.limit)
            postings = iter_postings(conn, strip_boilerplate=not args.no_strip, **selection)
            if args.limit:
                postings = itertools.islice(postings, args.limit)

            summary = RunSummary(provider=provider, model=model,
                                 prompt_version=args.prompt_version, selected=total)
            run_start = time.monotonic()

            def call(row, _generate=generate):
                """Runs on a worker thread: LLM call + local checks, no database."""
                posting_id, url, content_hash, content = row
                retries = 0

                def note_retry(attempt, exc, delay):
                    nonlocal retries
                    retries += 1
                    wait_note = f", retrying in {delay:.1f}s" if delay else ", repairing"
                    tqdm.write(f"  ⟳ {url.rstrip('/').split('/')[-1][:45]}: "
                               f"{type(exc).__name__} on attempt {attempt}{wait_note}")

                t0 = time.monotonic()
                schema, input_tokens, output_tokens, cached_tokens = generate_with_retry(
                    _generate, content,
                    max_attempts=args.max_attempts,
                    on_retry=note_retry,
                )
                latency_ms = int((time.monotonic() - t0) * 1000)
                # Grounding is a local string check against the same text the
                # model saw — no extra call, so it runs on every posting.
                ungrounded = check_grounding(schema, content) if isinstance(schema, dict) else []
                return schema, input_tokens, output_tokens, cached_tokens, latency_ms, ungrounded, retries

            def failure_class(exc) -> str:
                status = _status_of(exc)
                return f"HTTP_{status}" if status is not None else type(exc).__name__

            fatal_error = None
            with tqdm(total=total, unit="post", dynamic_ncols=True) as bar:
                for (posting_id, url, content_hash, content), future in imap_unordered(
                    call, postings, args.workers
                ):
                    slug = url.rstrip('/').split('/')[-1]
                    bar.set_description(slug[:55])
                    bar.update(1)
                    summary.attempted += 1
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
                        continue

                    summary.retried += bool(retries)

                    if not isinstance(schema, dict):
                        summary.failed += 1
                        summary.failure_classes["non_dict"] = summary.failure_classes.get("non_dict", 0) + 1
                        tqdm.write(f"  ✗ {slug}: non-dict: {repr(schema)[:80]}")
                        continue

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

        if not args.no_run_log:
            for summary in summaries:
                _append_schema_record(Path(args.run_log), summary, run_id)

        exit_code = evaluate_exit(summaries, args.max_failure_share)
        if exit_code == 2:
            print("\n✗✗ Extraction stopped: a provider-wide fatal error (402 / invalid auth).")
        elif exit_code == 1:
            print("\n✗ Extraction finished with too many failures — see the summary above.")
        sys.exit(exit_code)

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
    openai_json_schema,
)

load_dotenv()

DATABASE_URL = os.getenv("DATABASE_URL", "postgres://localhost/posturi_dev")


def get_prompt(prompt_version="v1"):
    """Fetch prompt text from config by version."""
    return MODELS_CONFIG.get("prompts", {}).get(prompt_version, "")


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
        except Exception as exc:  # noqa: BLE001 — classified immediately below
            last_exc = exc
            if attempt == max_attempts:
                break
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
                "max_completion_tokens": 2000,
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
            schema = _validate_extraction(text, prompt_version) if use_schema else parse_json_response(text)
            usage = getattr(resp, "usage", None)
            input_tokens = getattr(usage, "prompt_tokens", None) if usage else None
            output_tokens = getattr(usage, "completion_tokens", None) if usage else None
            cached_tokens = 0
            details = getattr(usage, "prompt_tokens_details", None) if usage else None
            if details is not None:
                cached_tokens = getattr(details, "cached_tokens", 0) or 0
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
                "max_tokens": 2000,
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
                "max_tokens": 2000,
                "temperature": 0.2,
            }
            # DeepSeek supports loose JSON mode (no schema enforcement)
            if use_schema:
                kwargs["response_format"] = {"type": "json_object"}
            resp = client.chat.completions.create(**kwargs)
            text = resp.choices[0].message.content
            schema = _validate_extraction(text, prompt_version) if use_schema else parse_json_response(text)
            usage = getattr(resp, "usage", None)
            input_tokens = getattr(usage, "prompt_tokens", None) if usage else None
            output_tokens = getattr(usage, "completion_tokens", None) if usage else None
            cached_tokens = getattr(usage, "prompt_cache_hit_tokens", 0) if usage else 0
            return schema, input_tokens, output_tokens, cached_tokens or 0

    else:
        raise ValueError(f"Unknown provider: {provider}")

    return generate


def _selection_where(slug_filter=None, active_only=False, resume_key=None):
    """Build the shared WHERE clause for posting selection.

    Returns (sql_fragment, params) — used by both iter_postings and
    count_postings so the progress total always matches what is processed.

    `resume_key` is a (provider, model, prompt_version) triple: when given,
    postings that already have a variant row for exactly that combination are
    excluded. Done in SQL rather than by filtering the generator so the count
    and the iteration cannot drift apart.
    """
    conds, params = [], []
    if slug_filter:
        conds.append("url LIKE %s")
        params.append(f"%{slug_filter}%")
    if active_only:
        conds.append("expires_at >= CURRENT_DATE")
    if resume_key:
        conds.append(
            "NOT EXISTS (SELECT 1 FROM jobs_jobpostingschemavariant v "
            "WHERE v.posting_id = jobs_jobposting.id AND v.provider = %s "
            "AND v.model = %s AND v.prompt_version = %s)"
        )
        params.extend(resume_key)
    return (" WHERE " + " AND ".join(conds) if conds else ""), params


def iter_postings(conn, slug_filter=None, force=False, strip_boilerplate=True,
                  active_only=False, resume_key=None):
    """Yield (posting_id, url, combined_content) rows that need schema generation.

    Combines body_markdown (web page text) and attachment_text (extracted from
    attached docx/pdf) so the LLM sees the full picture for each posting.
    When `strip_boilerplate` is True (default) the HG 1.336/2022 generic
    eligibility lines are stripped before yielding — see boilerplate.py.
    Skips rows where schema_json is already set unless force=True.
    """
    where, params = _selection_where(slug_filter, active_only, resume_key)
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, url, body_markdown, attachment_text, schema_json "
            "FROM jobs_jobposting" + where,
            params,
        )
        for row_id, url, body, attachment, existing_schema in cur:
            if existing_schema is not None and not force:
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
                yield row_id, url, content


def count_postings(conn, slug_filter=None, force=False, active_only=False, resume_key=None):
    """Return the number of postings that iter_postings would yield."""
    where, params = _selection_where(slug_filter, active_only, resume_key)
    if not force:
        where += (" AND " if where else " WHERE ") + "schema_json IS NULL"
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM jobs_jobposting" + where, params)
        return cur.fetchone()[0]


def write_schema(conn, posting_id, schema):
    """Write the extracted schema dict to jobs_jobposting.schema_json."""
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE jobs_jobposting SET schema_json = %s WHERE id = %s",
            (json.dumps(schema, ensure_ascii=False), posting_id),
        )
    conn.commit()


def write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost_usd, latency_ms, prompt_version=PROMPT_VERSION):
    """Write variant to jobs_jobpostingschemavariant (upsert on unique constraint)."""
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO jobs_jobpostingschemavariant
                (posting_id, provider, model, prompt_version, schema_json,
                 input_tokens, output_tokens, cost_usd, latency_ms, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
            ON CONFLICT (posting_id, provider, model, prompt_version)
            DO UPDATE SET
                schema_json   = EXCLUDED.schema_json,
                input_tokens  = EXCLUDED.input_tokens,
                output_tokens = EXCLUDED.output_tokens,
                cost_usd      = EXCLUDED.cost_usd,
                latency_ms    = EXCLUDED.latency_ms,
                created_at    = NOW()
            """,
            (posting_id, provider, model, prompt_version,
             json.dumps(schema, ensure_ascii=False),
             input_tokens, output_tokens, cost_usd, latency_ms),
        )
    conn.commit()


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
    args = parser.parse_args()

    if args.workers < 1:
        parser.error("--workers must be at least 1")

    args.prompt_version = resolve_prompt_version(args.prompt_version)

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
            )
            total = count_postings(conn, **selection)
            if args.limit:
                total = min(total, args.limit)
            postings = iter_postings(conn, strip_boilerplate=not args.no_strip, **selection)
            if args.limit:
                postings = itertools.islice(postings, args.limit)

            stats = {"ok": 0, "failed": 0, "retried": 0, "ungrounded": 0}

            def call(row, _generate=generate):
                """Runs on a worker thread: LLM call + local checks, no database."""
                posting_id, url, content = row
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

            with tqdm(total=total, unit="post", dynamic_ncols=True) as bar:
                for (posting_id, url, content), future in imap_unordered(
                    call, postings, args.workers
                ):
                    slug = url.rstrip('/').split('/')[-1]
                    bar.set_description(slug[:55])
                    bar.update(1)
                    try:
                        (schema, input_tokens, output_tokens, cached_tokens,
                         latency_ms, ungrounded, retries) = future.result()
                    except Exception as e:
                        stats["failed"] += 1
                        tqdm.write(f"  ✗ {slug}: {type(e).__name__}: {e}")
                        continue

                    stats["retried"] += bool(retries)

                    if not isinstance(schema, dict):
                        stats["failed"] += 1
                        tqdm.write(f"  ✗ {slug}: non-dict: {repr(schema)[:80]}")
                        continue

                    for finding in ungrounded:
                        stats["ungrounded"] += 1
                        tqdm.write(f"  ⚠ {slug}: unsupported quote — {finding}")

                    cost = compute_cost(provider, model, input_tokens, output_tokens, cached_tokens)
                    write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost, latency_ms, args.prompt_version)

                    if not args.compare:
                        write_schema(conn, posting_id, schema)

                    stats["ok"] += 1
                    tok_parts = []
                    if input_tokens and output_tokens:
                        tok_parts.append(f"in={input_tokens}")
                        if cached_tokens:
                            tok_parts.append(f"cached={cached_tokens}")
                        tok_parts.append(f"out={output_tokens}")
                    token_info = " ".join(tok_parts) if tok_parts else "tokens=?"
                    cost_info = f"${cost:.6f}" if cost else "cost=?"
                    bar.set_postfix_str(f"✓ {latency_ms}ms {token_info} {cost_info}")

            print(f"  {stats['ok']} ok, {stats['failed']} failed, "
                  f"{stats['retried']} needed a retry, "
                  f"{stats['ungrounded']} unsupported quote(s)")

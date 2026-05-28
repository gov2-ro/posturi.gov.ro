"""
Extract structured display sections from cached job postings.

Reads body_markdown + attachment_text directly from Postgres (jobs_jobposting),
calls an LLM to extract 7 structured sections, and writes the result back to
jobs_jobposting.schema_json. Skips postings that already have schema_json
unless --force is passed.

Models and prompts are defined in models_config.json.

Usage:
    python llm-schema.py                                    # default provider (gemini) + v2 prompt
    python llm-schema.py --provider anthropic
    python llm-schema.py --provider openai --model gpt-4o-mini
    python llm-schema.py --slug subinginer-gradul-i        # single posting by URL fragment
    python llm-schema.py --force                            # re-generate existing outputs

    # Variant comparison (test multiple providers):
    python llm-schema.py --compare                          # run all enabled models, store variants only
    python llm-schema.py --compare --limit 10               # compare on first 10 postings
    python llm-schema.py --compare --model-filter "gemini-.*" --limit 5  # test only Gemini models

    # Prompt testing:
    python llm-schema.py --prompt-version v2                # use prompt v2 with default provider
    python llm-schema.py --compare --prompt-version v2      # compare all providers with prompt v2
    python llm-schema.py --compare --model-filter "gpt-.*" --prompt-version v2  # GPT with prompt v2
"""

import argparse
import json
import os
import re
import sys
import time
import itertools
import psycopg
from tqdm import tqdm
from decimal import Decimal
from dotenv import load_dotenv

from boilerplate import strip_hg_1336
from schema_models import JobPostingExtraction, openai_json_schema

load_dotenv()

DATABASE_URL = os.getenv("DATABASE_URL", "postgres://localhost/posturi_dev")

with open("models_config.json") as f:
    MODELS_CONFIG = json.load(f)


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

DEFAULTS = {
    'gemini':    'gemini-2.5-flash',
    'openai':    'gpt-4o-mini',
    'anthropic': 'claude-3-5-haiku-20241022',
    'deepseek':  'deepseek-v4-flash',
}

PROMPT_VERSION = "v2"


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


def _validate_v2(raw) -> dict:
    """Validate raw parsed JSON against the v2 Pydantic model. Returns a
    JSON-safe dict. Raises pydantic.ValidationError on schema mismatch."""
    if isinstance(raw, str):
        raw = parse_json_response(raw)
    if not isinstance(raw, dict):
        raise ValueError(f"Expected dict, got {type(raw).__name__}: {repr(raw)[:120]}")
    obj = JobPostingExtraction.model_validate(raw)
    return obj.model_dump(mode="json")


def make_generator(provider, model, system_prefix, prompt_version):
    """Returns a generate(content) function that yields
    (schema, input_tokens, output_tokens, cached_input_tokens).

    `system_prefix` is the static instruction block sent on every call —
    identical across calls so providers can cache it. `content` is the
    per-posting body+attachment text.

    For prompt_version == 'v2' we use provider-native structured output
    (OpenAI strict json_schema, Gemini response_schema, Anthropic tool-use)
    and validate against the JobPostingExtraction Pydantic model. For v1
    we keep the loose JSON-parse path (back-compat).
    """
    use_schema = (prompt_version == "v2")

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
                cfg_kwargs["response_schema"] = JobPostingExtraction
            resp = client.models.generate_content(
                model=model,
                contents=content,
                config=genai_types.GenerateContentConfig(**cfg_kwargs),
            )
            raw = resp.parsed if use_schema and getattr(resp, "parsed", None) is not None else resp.text
            if hasattr(raw, "model_dump"):
                raw = raw.model_dump(mode="json")
            schema = _validate_v2(raw) if use_schema else parse_json_response(raw)
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
                    "json_schema": openai_json_schema(),
                }
            resp = client.chat.completions.create(**kwargs)
            text = resp.choices[0].message.content
            schema = _validate_v2(text) if use_schema else parse_json_response(text)
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
            "input_schema": JobPostingExtraction.model_json_schema(),
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
                schema = _validate_v2(tool_block.input)
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
            schema = _validate_v2(text) if use_schema else parse_json_response(text)
            usage = getattr(resp, "usage", None)
            input_tokens = getattr(usage, "prompt_tokens", None) if usage else None
            output_tokens = getattr(usage, "completion_tokens", None) if usage else None
            cached_tokens = getattr(usage, "prompt_cache_hit_tokens", 0) if usage else 0
            return schema, input_tokens, output_tokens, cached_tokens or 0

    else:
        raise ValueError(f"Unknown provider: {provider}")

    return generate


def iter_postings(conn, slug_filter=None, force=False, strip_boilerplate=True):
    """Yield (posting_id, url, combined_content) rows that need schema generation.

    Combines body_markdown (web page text) and attachment_text (extracted from
    attached docx/pdf) so the LLM sees the full picture for each posting.
    When `strip_boilerplate` is True (default) the HG 1.336/2022 generic
    eligibility lines are stripped before yielding — see boilerplate.py.
    Skips rows where schema_json is already set unless force=True.
    """
    with conn.cursor() as cur:
        if slug_filter:
            cur.execute(
                "SELECT id, url, body_markdown, attachment_text, schema_json "
                "FROM jobs_jobposting WHERE url LIKE %s",
                (f"%{slug_filter}%",),
            )
        else:
            cur.execute(
                "SELECT id, url, body_markdown, attachment_text, schema_json "
                "FROM jobs_jobposting"
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


def count_postings(conn, slug_filter=None, force=False):
    """Return the number of postings that iter_postings would yield."""
    with conn.cursor() as cur:
        if slug_filter:
            cur.execute(
                "SELECT COUNT(*) FROM jobs_jobposting WHERE url LIKE %s"
                + ("" if force else " AND (body_markdown IS NOT NULL OR attachment_text IS NOT NULL)"),
                (f"%{slug_filter}%",),
            )
        else:
            if force:
                cur.execute("SELECT COUNT(*) FROM jobs_jobposting")
            else:
                cur.execute("SELECT COUNT(*) FROM jobs_jobposting WHERE schema_json IS NULL")
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
    parser.add_argument('--provider', choices=['gemini', 'openai', 'anthropic', 'deepseek'], default='gemini')
    parser.add_argument('--model', default=None, help='Override default model for the provider')
    parser.add_argument('--slug', default=None, help='Process only postings whose URL contains this string')
    parser.add_argument('--force', action='store_true', help='Re-generate even if schema_json already set')
    parser.add_argument('--limit', type=int, default=None, help='Process at most N postings')
    parser.add_argument('--compare', action='store_true', help='Run all enabled providers and save variants only (does NOT overwrite schema_json)')
    parser.add_argument('--model-filter', default=None, help='Only test models matching this regex (e.g., "gemini-.*" or "gpt-.*")')
    parser.add_argument('--prompt-version', default='v2', help='Prompt version to use (from config; default v2)')
    parser.add_argument('--no-strip', action='store_true', help='Do NOT strip HG 1.336/2022 boilerplate from the input (kept for comparison runs).')
    args = parser.parse_args()

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
            model = args.model or DEFAULTS[args.provider]
            providers_to_run = [(args.provider, model)]
            print(f"Provider: {args.provider}, model: {model}, prompt: {args.prompt_version}")

        for provider, model in providers_to_run:
            print(f"\n[{provider}/{model} @ {args.prompt_version}]")
            generate = make_generator(provider, model, system_prefix, args.prompt_version)
            force_flag = args.force or args.compare
            total = count_postings(conn, slug_filter=args.slug, force=force_flag)
            if args.limit:
                total = min(total, args.limit)
            postings = iter_postings(
                conn,
                slug_filter=args.slug,
                force=force_flag,
                strip_boilerplate=not args.no_strip,
            )
            if args.limit:
                postings = itertools.islice(postings, args.limit)

            with tqdm(postings, total=total, unit="post", dynamic_ncols=True) as bar:
                for posting_id, url, content in bar:
                    slug = url.rstrip('/').split('/')[-1]
                    bar.set_description(slug[:55])
                    try:
                        t0 = time.monotonic()
                        schema, input_tokens, output_tokens, cached_tokens = generate(content)
                        latency_ms = int((time.monotonic() - t0) * 1000)
                    except Exception as e:
                        tqdm.write(f"  ✗ {slug}: {e}")
                        continue

                    if not isinstance(schema, dict):
                        tqdm.write(f"  ✗ {slug}: non-dict: {repr(schema)[:80]}")
                        continue

                    cost = compute_cost(provider, model, input_tokens, output_tokens, cached_tokens)
                    write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost, latency_ms, args.prompt_version)

                    if not args.compare:
                        write_schema(conn, posting_id, schema)

                    tok_parts = []
                    if input_tokens and output_tokens:
                        tok_parts.append(f"in={input_tokens}")
                        if cached_tokens:
                            tok_parts.append(f"cached={cached_tokens}")
                        tok_parts.append(f"out={output_tokens}")
                    token_info = " ".join(tok_parts) if tok_parts else "tokens=?"
                    cost_info = f"${cost:.6f}" if cost else "cost=?"
                    bar.set_postfix_str(f"✓ {latency_ms}ms {token_info} {cost_info}")

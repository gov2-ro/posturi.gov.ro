"""
Extract structured display sections from cached job postings.

Reads body_markdown + attachment_text directly from Postgres (jobs_jobposting),
calls an LLM to extract 7 structured sections, and writes the result back to
jobs_jobposting.schema_json. Skips postings that already have schema_json
unless --force is passed.

Usage:
    python llm-schema.py                       # default provider (gemini), all postings
    python llm-schema.py --provider anthropic
    python llm-schema.py --provider openai --model gpt-4o
    python llm-schema.py --slug subinginer-gradul-i   # single posting by URL fragment
    python llm-schema.py --force               # re-generate existing outputs
    python llm-schema.py --compare             # run all providers; store variants only
    python llm-schema.py --compare --limit 5   # compare on first 5 postings
"""

import argparse
import json
import os
import re
import time
import itertools
import psycopg
from decimal import Decimal
from dotenv import load_dotenv

load_dotenv()

DATABASE_URL = os.getenv("DATABASE_URL", "postgres://localhost/posturi_dev")

with open("models_config.json") as f:
    MODELS_CONFIG = json.load(f)

PROMPT = """\
Extract structured sections from this Romanian government job posting.
Return a JSON object with exactly these 7 keys. Values must be in Romanian.
Use markdown bullet lists (lines starting with -) for lists.
Use null for any section not mentioned in the posting.

{
  "responsibilities": "markdown list of job duties and tasks, or null",
  "qualifications": "markdown describing required education and eligibility conditions, or null",
  "skills": "markdown list of required skills, competencies, computer skills, languages, certifications, or null",
  "application_docs": "markdown list of documents required to apply (dosar de candidatură), or null",
  "salary": "salary description as plain text, or null if not stated",
  "application_fee": "application fee amount and payment details as plain text, or null if none",
  "work_conditions": "work schedule, location details, or benefits as plain text, or null if not stated"
}

Return only valid JSON. No explanation, no markdown code blocks."""

DEFAULTS = {
    'gemini':    'gemini-2-5-flash',
    'openai':    'gpt-4o-mini',
    'anthropic': 'claude-3-5-haiku-20241022',
}

PROMPT_VERSION = "v1"


def compute_cost(provider, model, input_tokens, output_tokens):
    """Calculate cost in USD based on model pricing from config."""
    if not input_tokens or not output_tokens:
        return None
    try:
        model_config = MODELS_CONFIG["providers"][provider]["models"][model]
        input_cost = Decimal(input_tokens) * Decimal(model_config["input_cost_per_million"]) / Decimal("1000000")
        output_cost = Decimal(output_tokens) * Decimal(model_config["output_cost_per_million"]) / Decimal("1000000")
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


def make_generator(provider, model):
    """Returns a generate(content) function that yields (schema, input_tokens, output_tokens)."""
    if provider == 'gemini':
        from google import genai
        from google.genai import types as genai_types
        client = genai.Client(api_key=os.getenv('GOOGLE_API_KEY'))
        def generate(content):
            resp = client.models.generate_content(
                model=model,
                contents=f"{PROMPT}\n\n{content}",
                config=genai_types.GenerateContentConfig(temperature=0.2),
            )
            schema = parse_json_response(resp.text)
            input_tokens = resp.usage_metadata.prompt_token_count if hasattr(resp, 'usage_metadata') else None
            output_tokens = resp.usage_metadata.candidates_token_count if hasattr(resp, 'usage_metadata') else None
            return schema, input_tokens, output_tokens

    elif provider == 'openai':
        import openai
        client = openai.OpenAI()
        def generate(content):
            resp = client.chat.completions.create(
                model=model,
                messages=[
                    {'role': 'system', 'content': 'You extract structured sections from Romanian job postings. Return only valid JSON.'},
                    {'role': 'user', 'content': f"{PROMPT}\n\n{content}"},
                ],
                max_tokens=2000,
                temperature=0.2,
            )
            schema = parse_json_response(resp.choices[0].message.content)
            input_tokens = resp.usage.prompt_tokens if hasattr(resp, 'usage') else None
            output_tokens = resp.usage.completion_tokens if hasattr(resp, 'usage') else None
            return schema, input_tokens, output_tokens

    elif provider == 'anthropic':
        import anthropic
        client = anthropic.Anthropic()
        def generate(content):
            msg = client.messages.create(
                model=model,
                max_tokens=2000,
                messages=[{'role': 'user', 'content': f"{PROMPT}\n\n{content}"}],
            )
            schema = parse_json_response(msg.content[0].text)
            input_tokens = msg.usage.input_tokens if hasattr(msg, 'usage') else None
            output_tokens = msg.usage.output_tokens if hasattr(msg, 'usage') else None
            return schema, input_tokens, output_tokens

    elif provider == 'deepseek':
        import openai
        client = openai.OpenAI(api_key=os.getenv('DEEPSEEK_API_KEY'), base_url="https://api.deepseek.com")
        def generate(content):
            resp = client.chat.completions.create(
                model=model,
                messages=[
                    {'role': 'system', 'content': 'You extract structured sections from Romanian job postings. Return only valid JSON.'},
                    {'role': 'user', 'content': f"{PROMPT}\n\n{content}"},
                ],
                max_tokens=2000,
                temperature=0.2,
            )
            schema = parse_json_response(resp.choices[0].message.content)
            input_tokens = resp.usage.prompt_tokens if hasattr(resp, 'usage') else None
            output_tokens = resp.usage.completion_tokens if hasattr(resp, 'usage') else None
            return schema, input_tokens, output_tokens

    else:
        raise ValueError(f"Unknown provider: {provider}")

    return generate


def iter_postings(conn, slug_filter=None, force=False):
    """Yield (posting_id, url, combined_content) rows that need schema generation.

    Combines body_markdown (web page text) and attachment_text (extracted from
    attached docx/pdf) so the LLM sees the full picture for each posting.
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
                print(f"Skipping {url.rstrip('/').split('/')[-1]} (already has schema_json)")
                continue
            content = (body or "").strip()
            if attachment and attachment.strip():
                content += "\n\n---\n\n" + attachment.strip()
            if content:
                if len(content) > 100_000:
                    print(f"  ⚠ content truncated ({len(content)} chars) for {url.rstrip('/').split('/')[-1]}")
                    content = content[:100_000]
                yield row_id, url, content


def write_schema(conn, posting_id, schema):
    """Write the extracted schema dict to jobs_jobposting.schema_json."""
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE jobs_jobposting SET schema_json = %s WHERE id = %s",
            (json.dumps(schema, ensure_ascii=False), posting_id),
        )
    conn.commit()


def write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost_usd, latency_ms):
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
            (posting_id, provider, model, PROMPT_VERSION,
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
    parser.add_argument('--compare', action='store_true', help='Run all providers and save variants only (does NOT overwrite schema_json)')
    args = parser.parse_args()

    with psycopg.connect(DATABASE_URL) as conn:
        if args.compare:
            providers_to_run = [
                ('gemini', 'gemini-2-5-flash'),
                ('gemini', 'gemini-3-1-flash-lite'),
                ('openai', 'gpt-5-nano'),
                ('openai', 'gpt-4o-mini'),
                ('anthropic', 'claude-3-5-haiku-20241022'),
                ('deepseek', 'deepseek-v4-flash'),
            ]
            print(f"Running comparison on {len(providers_to_run)} providers...")
        else:
            model = args.model or DEFAULTS[args.provider]
            providers_to_run = [(args.provider, model)]
            print(f"Provider: {args.provider}, model: {model}")

        for provider, model in providers_to_run:
            print(f"\n[{provider}/{model}]")
            generate = make_generator(provider, model)
            postings = iter_postings(conn, slug_filter=args.slug, force=args.force or args.compare)
            if args.limit:
                postings = itertools.islice(postings, args.limit)

            for posting_id, url, content in postings:
                slug = url.rstrip('/').split('/')[-1]
                print(f"  {slug}...", end=' ', flush=True)
                try:
                    t0 = time.monotonic()
                    schema, input_tokens, output_tokens = generate(content)
                    latency_ms = int((time.monotonic() - t0) * 1000)
                except Exception as e:
                    print(f"✗ {e}")
                    continue

                if not isinstance(schema, dict):
                    print(f"✗ non-dict: {repr(schema)[:80]}")
                    continue

                cost = compute_cost(provider, model, input_tokens, output_tokens)
                write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost, latency_ms)

                if not args.compare:
                    write_schema(conn, posting_id, schema)

                token_info = f"in={input_tokens} out={output_tokens}" if input_tokens and output_tokens else "tokens=?"
                cost_info = f"${cost:.6f}" if cost else "cost=?"
                print(f"✓ {latency_ms}ms {token_info} {cost_info}")

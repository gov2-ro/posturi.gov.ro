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
"""

import argparse
import json
import os
import re
import psycopg
from dotenv import load_dotenv

load_dotenv()

DATABASE_URL = os.getenv("DATABASE_URL", "postgres://localhost/posturi_dev")

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
    'gemini':    'gemini/gemini-2.5-flash',
    'openai':    'gpt-4o',
    'anthropic': 'claude-3-5-haiku-20241022',
}


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
    if provider == 'gemini':
        import llm
        m = llm.get_model(model)
        m.key = os.getenv('GOOGLE_API_KEY')
        def generate(content):
            return parse_json_response(m.prompt(f"{PROMPT}\n\n{content}").text())

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
            return parse_json_response(resp.choices[0].message.content)

    elif provider == 'anthropic':
        import anthropic
        client = anthropic.Anthropic()
        def generate(content):
            msg = client.messages.create(
                model=model,
                max_tokens=2000,
                messages=[{'role': 'user', 'content': f"{PROMPT}\n\n{content}"}],
            )
            return parse_json_response(msg.content[0].text)

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


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Extract structured job sections and store in Postgres schema_json')
    parser.add_argument('--provider', choices=['gemini', 'openai', 'anthropic'], default='gemini')
    parser.add_argument('--model', default=None, help='Override default model for the provider')
    parser.add_argument('--slug', default=None, help='Process only postings whose URL contains this string')
    parser.add_argument('--force', action='store_true', help='Re-generate even if schema_json already set')
    args = parser.parse_args()

    model = args.model or DEFAULTS[args.provider]
    print(f"Provider: {args.provider}, model: {model}")

    generate = make_generator(args.provider, model)

    with psycopg.connect(DATABASE_URL) as conn:
        for posting_id, url, content in iter_postings(conn, slug_filter=args.slug, force=args.force):
            slug = url.rstrip('/').split('/')[-1]
            print(f"Processing {slug}...")
            try:
                schema = generate(content)
            except Exception as e:
                print(f"  ✗ API error: {e}")
                continue
            if isinstance(schema, dict):
                write_schema(conn, posting_id, schema)
                print(f"  ✓ saved to DB (id={posting_id})")
            else:
                print(f"  ✗ LLM returned non-dict: {repr(schema)[:120]}")

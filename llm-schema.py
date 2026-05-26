"""
Convert cached job postings to schema.org/JobPosting JSON-LD.

Reads data/anunturi/anunturi.csv, calls an LLM per row, writes
data/schema/<slug>.json. Skips slugs that already exist.

Usage:
    python llm-schema.py                    # default provider (gemini)
    python llm-schema.py --provider gemini
    python llm-schema.py --provider openai --model gpt-4o
    python llm-schema.py --provider anthropic
"""

import argparse
import csv
import json
import os
import re
from pathlib import Path
from dotenv import load_dotenv

load_dotenv()

input_csv = 'data/anunturi/anunturi.csv'
output_dir = Path('data/schema')
output_dir.mkdir(parents=True, exist_ok=True)

PROMPT = "Convert this job posting to schema.org/JobPosting JSON-LD format. Return only valid JSON."

DEFAULTS = {
    'gemini':    'gemini/gemini-2.5-flash',
    'openai':    'gpt-4o',
    'anthropic': 'claude-3-5-haiku-20241022',
}


def slug_from_url(url):
    return url.rstrip('/').split('/')[-1] or 'unknown'


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
                    {'role': 'system', 'content': 'You are a helpful assistant that converts job postings to schema.org/JobPosting JSON-LD. Return only valid JSON.'},
                    {'role': 'user', 'content': f"{PROMPT}\n\n{content}"},
                ],
                max_tokens=1500,
                temperature=0.3,
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


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Generate schema.org JSON-LD for job postings')
    parser.add_argument('--provider', choices=['gemini', 'openai', 'anthropic'], default='gemini')
    parser.add_argument('--model', default=None, help='Override default model for the provider')
    args = parser.parse_args()

    model = args.model or DEFAULTS[args.provider]
    print(f"Provider: {args.provider}, model: {model}")

    generate = make_generator(args.provider, model)

    with open(input_csv, newline='', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        for row in reader:
            markdown = row.get('Main Body Markdown', '').replace('\\n', '\n')
            if not markdown:
                continue
            slug = slug_from_url(row.get('Announcement URL', ''))
            out_path = output_dir / f"{slug}.json"
            if out_path.exists():
                print(f"Skipping {slug} (already exists)")
                continue
            print(f"Processing {slug}...")
            schema = generate(markdown)
            out_path.write_text(json.dumps(schema, indent=2, ensure_ascii=False), encoding='utf-8')
            print(f"Saved {out_path}")

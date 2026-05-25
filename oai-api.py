import openai
import json
import csv
import os
from pathlib import Path
from dotenv import load_dotenv

load_dotenv()

input_csv = 'data/anunturi/anunturi.csv'
output_dir = Path('data/schema')
output_dir.mkdir(parents=True, exist_ok=True)

PROMPT = "Convert this job posting to schema.org/JobPosting JSON-LD format. Return only valid JSON."

def slug_from_url(url):
    return url.rstrip('/').split('/')[-1] or 'unknown'

def generate_schema(markdown_content):
    client = openai.OpenAI()
    response = client.chat.completions.create(
        model="gpt-3.5-turbo",
        messages=[
            {"role": "system", "content": "You are a helpful assistant."},
            {"role": "user", "content": f"{PROMPT}\n\n{markdown_content}"}
        ]
    )
    generated = response.choices[0].message.content
    try:
        return json.loads(generated)
    except json.JSONDecodeError:
        return generated

if __name__ == "__main__":
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
            schema = generate_schema(markdown)
            out_path.write_text(json.dumps(schema, indent=2, ensure_ascii=False), encoding='utf-8')
            print(f"Saved {out_path}")

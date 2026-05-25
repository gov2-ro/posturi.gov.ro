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

def generate_jobposting_schema(markdown_content):
    client = openai.OpenAI()
    response = client.chat.completions.create(
        model="gpt-4o",
        messages=[
            {
                "role": "system",
                "content": "You are a helpful assistant that converts job postings in markdown into schema.org/JobPosting JSON-LD format. Return only valid JSON."
            },
            {
                "role": "user",
                "content": f"Convert this job posting to schema.org/JobPosting JSON-LD:\n\n{markdown_content}"
            }
        ],
        max_tokens=1500,
        temperature=0.3,
    )
    generated = response.choices[0].message.content
    try:
        return json.loads(generated)
    except json.JSONDecodeError:
        return generated

def slug_from_url(url):
    return url.rstrip('/').split('/')[-1] or 'unknown'

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
            schema = generate_jobposting_schema(markdown)
            out_path.write_text(json.dumps(schema, indent=2, ensure_ascii=False), encoding='utf-8')
            print(f"Saved {out_path}")

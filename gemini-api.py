import os
import json
import csv
import re
from pathlib import Path
import llm
from dotenv import load_dotenv

load_dotenv()

input_csv = 'data/anunturi/anunturi.csv'
output_dir = Path('data/schema')
output_dir.mkdir(parents=True, exist_ok=True)

model = llm.get_model('gemini/gemini-2.5-flash')
model.key = os.getenv("GOOGLE_API_KEY")

PROMPT = "Convert this job posting to schema.org/JobPosting JSON-LD format. Return only valid JSON."

def slug_from_url(url):
    return url.rstrip('/').split('/')[-1] or 'unknown'

def parse_json_response(text):
    text = text.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[1].rsplit("```", 1)[0].strip()
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

def generate_schema(markdown_content):
    response = model.prompt(f"{PROMPT}\n\n{markdown_content}")
    return parse_json_response(response.text())

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

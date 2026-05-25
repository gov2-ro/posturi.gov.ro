import os
import json
import csv
from pathlib import Path
import google.generativeai as genai
from dotenv import load_dotenv

load_dotenv()
genai.configure(api_key=os.getenv("GOOGLE_API_KEY"))

input_csv = 'data/anunturi/anunturi.csv'
output_dir = Path('data/schema')
output_dir.mkdir(parents=True, exist_ok=True)

model = genai.GenerativeModel('gemini-1.5-flash')
PROMPT = "Convert this job posting to schema.org/JobPosting JSON-LD format. Return only valid JSON."

def slug_from_url(url):
    return url.rstrip('/').split('/')[-1] or 'unknown'

def generate_schema(markdown_content):
    response = model.generate_content(f"{PROMPT}\n\n{markdown_content}")
    try:
        return json.loads(response.text)
    except json.JSONDecodeError:
        return response.text

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

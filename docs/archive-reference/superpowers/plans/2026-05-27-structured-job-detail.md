# Structured Job Detail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Display job postings as structured named sections (Atribuții, Condiții, Competențe, Dosar, Salarizare) extracted by an LLM from page text + attachment text, instead of a raw markdown blob.

**Architecture:** `llm-schema.py` reads `body_markdown + attachment_text` from Postgres, calls an LLM, and writes structured JSON back to a new `schema_json` column on `jobs_jobposting`. The Django detail view reads that column and passes rendered HTML sections to the template, which shows them in place of the raw body — falling back to the raw markdown when `schema_json` is absent.

**Tech Stack:** Python + psycopg (standalone script), Django ORM (model + view), Jinja2-style Django templates, Tailwind CSS (existing classes), pytest-django (tests).

---

## File map

| File | Action | Purpose |
|------|--------|---------|
| `webapp/apps/jobs/models.py` | Modify | Add `schema_json` JSONField |
| `webapp/apps/jobs/migrations/0006_jobposting_schema_json.py` | Create | Migration for the new field |
| `llm-schema.py` | Modify | New prompt + write to DB instead of flat files |
| `pipeline.py` | Modify | Add `schema` step after `infer` |
| `webapp/apps/jobs/views.py` | Modify | Parse schema_json → section list; pass to template |
| `webapp/templates/jobs/detail.html` | Modify | Render schema sections; fall back to body_html |
| `webapp/tests/test_schema_detail.py` | Create | Tests for view helper and template rendering |

---

## Task 1: Add `schema_json` field to `JobPosting`

**Files:**
- Modify: `webapp/apps/jobs/models.py:107`
- Create: `webapp/apps/jobs/migrations/0006_jobposting_schema_json.py`

- [ ] **Step 1: Add field to model**

In `webapp/apps/jobs/models.py`, add the new field after the `inferred` field (line ~107):

```python
    inferred = models.JSONField(default=dict, blank=True, help_text="Reserved for v2/v3 derived fields")
    schema_json = models.JSONField(null=True, blank=True, help_text="LLM-extracted structured sections for display (responsibilities, qualifications, skills, etc.)")
```

- [ ] **Step 2: Create migration**

Create `webapp/apps/jobs/migrations/0006_jobposting_schema_json.py`:

```python
from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('jobs', '0005_jobpostingupdate'),
    ]

    operations = [
        migrations.AddField(
            model_name='jobposting',
            name='schema_json',
            field=models.JSONField(blank=True, help_text='LLM-extracted structured sections for display (responsibilities, qualifications, skills, etc.)', null=True),
        ),
    ]
```

- [ ] **Step 3: Apply migration and verify**

```bash
cd webapp
.venv/bin/python manage.py migrate
```

Expected: `Applying jobs.0006_jobposting_schema_json... OK`

- [ ] **Step 4: Verify field in DB**

```bash
.venv/bin/python manage.py shell -c "
from apps.jobs.models import JobPosting
p = JobPosting.objects.first()
print(hasattr(p, 'schema_json'))   # True
print(p.schema_json)               # None
"
```

Expected: `True` then `None`

- [ ] **Step 5: Commit**

```bash
git add webapp/apps/jobs/models.py webapp/apps/jobs/migrations/0006_jobposting_schema_json.py
git commit -m "feat(model): add schema_json JSONField to JobPosting"
```

---

## Task 2: Update `llm-schema.py` — new prompt, write to DB

**Files:**
- Modify: `llm-schema.py`

The script already connects to Postgres via psycopg and has `--force` / `--slug` flags (added in the previous session). This task replaces the prompt and the output path: instead of writing `data/schema/<slug>.json` files, it writes to `jobs_jobposting.schema_json`.

- [ ] **Step 1: Replace the PROMPT constant**

Replace the existing `PROMPT` in `llm-schema.py`:

```python
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
```

- [ ] **Step 2: Remove flat-file output, add DB write function**

Remove the `output_dir` lines and add a DB writer. Replace the entire `if __name__ == '__main__':` block and add `write_to_db` helper. The full updated file:

```python
"""
Convert cached job postings to schema.org/JobPosting JSON-LD.

Reads body_markdown + attachment_text directly from Postgres (jobs_jobposting),
calls an LLM per row, writes structured sections back to jobs_jobposting.schema_json.
Skips postings that already have schema_json unless --force is passed.

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
    """Yield (posting_id, url, combined_content) rows that need schema generation."""
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
        for row_id, url, body, attachment, existing_schema in cur.fetchall():
            if existing_schema is not None and not force:
                print(f"Skipping {url.rstrip('/').split('/')[-1]} (already has schema_json)")
                continue
            content = (body or "").strip()
            if attachment and attachment.strip():
                content += "\n\n---\n\n" + attachment.strip()
            if content:
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

    import psycopg
    generate = make_generator(args.provider, model)

    with psycopg.connect(DATABASE_URL) as conn:
        for posting_id, url, content in iter_postings(conn, slug_filter=args.slug, force=args.force):
            slug = url.rstrip('/').split('/')[-1]
            print(f"Processing {slug}...")
            schema = generate(content)
            if isinstance(schema, dict):
                write_schema(conn, posting_id, schema)
                print(f"  ✓ saved to DB (id={posting_id})")
            else:
                print(f"  ✗ LLM returned non-dict: {repr(schema)[:120]}")
```

- [ ] **Step 3: Verify the script runs (dry-run with --slug on a known posting)**

```bash
# Pick any slug from the DB
cd /path/to/project
python llm-schema.py --provider anthropic --slug subinginer-gradul-i
```

Expected: prints `Processing subinginer-gradul-i...` then `✓ saved to DB (id=NNN)`

- [ ] **Step 4: Verify data in DB**

```bash
cd webapp
.venv/bin/python manage.py shell -c "
from apps.jobs.models import JobPosting
p = JobPosting.objects.filter(url__contains='subinginer-gradul-i').first()
import json; print(json.dumps(p.schema_json, indent=2, ensure_ascii=False))
"
```

Expected: JSON with 7 keys (`responsibilities`, `qualifications`, `skills`, `application_docs`, `salary`, `application_fee`, `work_conditions`), values in Romanian or null.

- [ ] **Step 5: Commit**

```bash
git add llm-schema.py
git commit -m "feat(schema): rewrite llm-schema.py to write structured sections to DB schema_json"
```

---

## Task 3: Update `pipeline.py` — add `schema` step

**Files:**
- Modify: `pipeline.py`

- [ ] **Step 1: Add `schema` to ALL_STEPS and _SCRAPER_STEPS**

```python
ALL_STEPS = [
    "fetch-index",
    "fetch-detail",
    "parse",
    "download",
    "import",
    "extract",
    "infer",
    "schema",           # ← add this
]

_SCRAPER_STEPS = {
    "fetch-index":  ROOT / "fetch-index.py",
    "fetch-detail": ROOT / "fetch-anunturi.py",
    "parse":        ROOT / "parse-anunturi.py",
    "download":     ROOT / "download-attachments.py",
    "schema":       ROOT / "llm-schema.py",   # ← add this
}
```

- [ ] **Step 2: Update `_build_cmd` to pass flags to the schema step**

The current `_build_cmd` returns `[sys.executable, script_path]` for all `_SCRAPER_STEPS` with no arguments. Change that block to also handle `schema`:

```python
def _build_cmd(
    step: str,
    *,
    force: bool,
    no_llm: bool,
    provider: str,
    limit: int | None,
) -> list[str | Path]:
    """Return the subprocess command for a given step."""
    if step in _SCRAPER_STEPS:
        cmd: list[str | Path] = [sys.executable, _SCRAPER_STEPS[step]]
        if step == "schema":
            if force:
                cmd.append("--force")
            if provider:
                cmd.extend(["--provider", provider])
        return cmd

    if step not in _MANAGE_STEPS:
        raise ValueError(f"Unknown step: {step!r}")

    manage_cmd = _MANAGE_STEPS[step]
    cmd = [VENV_PYTHON, WEBAPP_DIR / "manage.py", manage_cmd]

    if force:
        cmd.append("--force")

    if step == "infer":
        if no_llm:
            cmd.append("--no-llm")
        else:
            cmd.extend(["--provider", provider])
        if limit is not None:
            cmd.extend(["--limit", str(limit)])

    return cmd
```

- [ ] **Step 3: Update the docstring at the top of pipeline.py**

```python
"""pipeline.py — run the full posturi.gov.ro data pipeline.

Steps (in order):
  fetch-index   fetch-index.py          — scrape index pages → data/posturi_gov_ro.csv
  fetch-detail  fetch-anunturi.py       — scrape individual posting pages → data/anunturi/
  parse         parse-anunturi.py       — parse HTML cache → data/anunturi/anunturi.csv + data/calendar.csv
  download      download-attachments.py — download linked attachments → data/downloads/
  import        manage.py import_csvs   — load CSVs into Postgres
  extract       manage.py extract_attachments  — extract text from downloaded files
  infer         manage.py infer_postings       — run metadata inference (dict + optional LLM)
  schema        llm-schema.py                  — extract structured display sections → jobs_jobposting.schema_json

Usage:
  python pipeline.py                          # run all steps
  python pipeline.py --steps fetch-index,parse,import
  python pipeline.py --skip download,infer
  python pipeline.py --force --no-llm
  python pipeline.py --steps infer --provider anthropic --limit 100
  python pipeline.py --steps schema --provider anthropic
"""
```

- [ ] **Step 4: Verify pipeline --help lists schema**

```bash
python pipeline.py --help
```

Expected: `Available steps (in order): fetch-index, fetch-detail, parse, download, import, extract, infer, schema`

- [ ] **Step 5: Commit**

```bash
git add pipeline.py
git commit -m "feat(pipeline): add schema step to extract structured job sections"
```

---

## Task 4: Update `job_detail` view — parse schema_json into display sections

**Files:**
- Modify: `webapp/apps/jobs/views.py`
- Create: `webapp/tests/test_schema_detail.py`

- [ ] **Step 1: Write failing tests**

Create `webapp/tests/test_schema_detail.py`:

```python
"""Tests for schema_json display section rendering in job_detail view."""
import pytest
from apps.jobs.models import Employer, JobPosting
from django.test import Client


# ---------------------------------------------------------------------------
# Unit tests for _render_schema_sections
# ---------------------------------------------------------------------------

def test_render_schema_sections_all_present():
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": "- Elaborează proiecte\n- Verifică documente",
        "qualifications": "Studii superioare juridice",
        "skills": "- Cunoştinţe PC\n- Limbă engleză",
        "application_docs": "- CV\n- Copii studii",
        "salary": None,
        "application_fee": "150 lei",
        "work_conditions": None,
    }
    sections = _render_schema_sections(schema)
    assert sections is not None
    labels = [s["label"] for s in sections]
    assert "Atribuții principale" in labels
    assert "Condiții de participare" in labels
    assert "Competențe" in labels
    assert "Dosar de candidatură" in labels
    assert "Taxă de participare" in labels
    # null fields must NOT appear
    assert "Salarizare" not in labels
    assert "Condiții de muncă" not in labels


def test_render_schema_sections_null_returns_none():
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": None,
        "qualifications": None,
        "skills": None,
        "application_docs": None,
        "salary": None,
        "application_fee": None,
        "work_conditions": None,
    }
    assert _render_schema_sections(schema) is None


def test_render_schema_sections_html_in_output():
    from apps.jobs.views import _render_schema_sections
    schema = {
        "responsibilities": "- Elaborează proiecte",
        "qualifications": None,
        "skills": None,
        "application_docs": None,
        "salary": None,
        "application_fee": None,
        "work_conditions": None,
    }
    sections = _render_schema_sections(schema)
    assert sections is not None
    resp_section = sections[0]
    assert resp_section["label"] == "Atribuții principale"
    assert "<ul>" in resp_section["html"] or "<li>" in resp_section["html"]


# ---------------------------------------------------------------------------
# Integration tests for job_detail view
# ---------------------------------------------------------------------------

@pytest.fixture
def posting_with_schema(db):
    employer, _ = Employer.objects.get_or_create(name="Test Employer", defaults={"slug": "test-employer"})
    return JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-schema-job/",
        title="Inspector principal",
        employer=employer,
        body_markdown="Angajam inspector.",
        schema_json={
            "responsibilities": "- Verifică documente",
            "qualifications": "Studii superioare",
            "skills": None,
            "application_docs": "- CV",
            "salary": None,
            "application_fee": None,
            "work_conditions": None,
        },
    )


@pytest.fixture
def posting_without_schema(db):
    employer, _ = Employer.objects.get_or_create(name="Test Employer 2", defaults={"slug": "test-employer-2"})
    return JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-no-schema-job/",
        title="Referent",
        employer=employer,
        body_markdown="Angajam referent pentru departamentul administrativ.",
        schema_json=None,
    )


def test_detail_with_schema_json_passes_sections(posting_with_schema):
    client = Client()
    resp = client.get(f"/job/{posting_with_schema.pk}/")
    assert resp.status_code == 200
    assert resp.context["schema_sections"] is not None
    assert len(resp.context["schema_sections"]) >= 2
    # body_html still computed as fallback but schema_sections takes priority in template
    assert "schema_sections" in resp.context


def test_detail_without_schema_json_passes_body_html(posting_without_schema):
    client = Client()
    resp = client.get(f"/job/{posting_without_schema.pk}/")
    assert resp.status_code == 200
    assert resp.context["schema_sections"] is None
    assert resp.context["body_html"] != ""
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd webapp
.venv/bin/python -m pytest tests/test_schema_detail.py -v 2>&1 | head -30
```

Expected: `ImportError: cannot import name '_render_schema_sections'` or `FAILED` for all tests.

- [ ] **Step 3: Add `_render_schema_sections` helper and update `job_detail` in views.py**

In `webapp/apps/jobs/views.py`, add after the imports (after `import markdown as md`):

```python
# Ordered list of (schema_json key, Romanian display label)
_SCHEMA_SECTION_LABELS = [
    ("responsibilities", "Atribuții principale"),
    ("qualifications",   "Condiții de participare"),
    ("skills",           "Competențe"),
    ("application_docs", "Dosar de candidatură"),
    ("salary",           "Salarizare"),
    ("application_fee",  "Taxă de participare"),
    ("work_conditions",  "Condiții de muncă"),
]


def _render_schema_sections(schema_json: dict) -> list[dict] | None:
    """Convert schema_json dict to a list of {label, html} dicts for the template.

    Sections whose value is None or empty string are omitted.
    Returns None if no sections have content (so the template can fall back to body_html).
    """
    sections = []
    for key, label in _SCHEMA_SECTION_LABELS:
        value = schema_json.get(key)
        if value and str(value).strip():
            html = md.markdown(str(value), extensions=["nl2br"])
            sections.append({"label": label, "html": html})
    return sections or None
```

Then update the `job_detail` view function at the bottom of `views.py`:

```python
def job_detail(request, pk):
    posting = get_object_or_404(
        JobPosting.objects.select_related("employer", "judet").prefetch_related("calendar_events"),
        pk=pk,
    )
    body_html = ""
    if posting.body_markdown:
        body_html = md.markdown(posting.body_markdown, extensions=["nl2br", "tables"])

    schema_sections = None
    if posting.schema_json:
        schema_sections = _render_schema_sections(posting.schema_json)

    return render(request, "jobs/detail.html", {
        "posting": posting,
        "body_html": body_html,
        "schema_sections": schema_sections,
        "today": date.today(),
    })
```

- [ ] **Step 4: Run tests — expect pass**

```bash
cd webapp
.venv/bin/python -m pytest tests/test_schema_detail.py -v
```

Expected: all 5 tests `PASSED`.

- [ ] **Step 5: Commit**

```bash
git add webapp/apps/jobs/views.py webapp/tests/test_schema_detail.py
git commit -m "feat(views): add _render_schema_sections and pass schema_sections to job_detail"
```

---

## Task 5: Update `detail.html` — render structured sections

**Files:**
- Modify: `webapp/templates/jobs/detail.html`

- [ ] **Step 1: Replace body content block with schema-aware rendering**

In `detail.html`, find the `<!-- Body content -->` block (currently lines 217–227 after our calendar reorder). Replace it with:

```django
      <!-- Structured sections (from schema_json) or raw body fallback -->
      {% if schema_sections %}
        {% for section in schema_sections %}
        <div class="mb-6">
          <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm">
            {{ section.label }}
          </h2>
          <div class="prose-body text-sm leading-relaxed text-ink max-w-none">
            {{ section.html|safe }}
          </div>
        </div>
        {% endfor %}
      {% elif body_html %}
      <div class="mb-6">
        <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm">
          Detalii post
        </h2>
        <div class="prose-body text-sm leading-relaxed text-ink max-w-none">
          {{ body_html|safe }}
        </div>
      </div>
      {% endif %}
```

- [ ] **Step 2: Confirm detail page renders correctly for a posting with schema_json**

```bash
cd webapp && .venv/bin/python manage.py runserver
```

Open `http://127.0.0.1:8000/job/<pk>/` where `<pk>` is the posting you processed in Task 2 Step 3.

Verify:
- Structured sections appear with their Romanian headings
- Raw "Detalii post" blob is gone
- Calendar still shows after the sections

- [ ] **Step 3: Confirm fallback for posting without schema_json**

Open `http://127.0.0.1:8000/job/<pk>/` for a posting where `schema_json IS NULL` (any posting not yet processed by `llm-schema.py`).

Verify:
- "Detalii post" raw markdown block appears
- No empty section headings visible

- [ ] **Step 4: Run the full test suite to check for regressions**

```bash
cd webapp
.venv/bin/python -m pytest -v
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add webapp/templates/jobs/detail.html
git commit -m "feat(detail): render schema_json structured sections; fall back to body_html"
```

---

## Task 6: Update docs

**Files:**
- Modify: `README.md` (Steps table)
- Modify: `CLAUDE.md` (Running scripts)
- Modify: `docs/activity-log.md`

- [ ] **Step 1: Update the Steps table in README.md**

Add the `schema` row to the Steps table:

```markdown
| `schema` | `llm-schema.py` | `JobPosting.schema_json` |
```

The full updated table:

```markdown
| Step | Script / command | Output |
|------|-----------------|--------|
| `fetch-index` | `fetch-index.py` | `data/posturi_gov_ro.csv` |
| `fetch-detail` | `fetch-anunturi.py` | `data/anunturi/**/*.html` |
| `parse` | `parse-anunturi.py` | `data/anunturi/anunturi.csv` + `data/calendar.csv` |
| `download` | `download-attachments.py` | `data/downloads/` |
| `import` | `manage.py import_csvs` | Postgres `jobs_jobposting` table |
| `extract` | `manage.py extract_attachments` | `JobPosting.attachment_text` |
| `infer` | `manage.py infer_postings` | `JobPosting.inferred` JSONB |
| `schema` | `llm-schema.py` | `JobPosting.schema_json` JSONB |
```

- [ ] **Step 2: Update activity log**

Add entry to `docs/activity-log.md`:

```markdown
### 2026-05-27 — Structured job detail display from schema_json

Added `schema_json` JSONField to `JobPosting`. Rewrote `llm-schema.py` to
extract 7 structured display sections (responsibilities, qualifications, skills,
application_docs, salary, application_fee, work_conditions) from page +
attachment text via LLM, storing results in the DB. Detail page now shows
named sections instead of raw markdown blob when schema_json is populated,
with fallback to body_html. Pipeline step `schema` added after `infer`.
Term highlighting deferred — will be a post-processing step on rendered HTML.
```

- [ ] **Step 3: Commit**

```bash
git add README.md docs/activity-log.md
git commit -m "docs: update README steps table and activity log for schema_json feature"
```

---

## Self-review

**Spec coverage check:**
- ✅ `schema_json` field added (Task 1)
- ✅ `llm-schema.py` reads body + attachment, writes to DB (Task 2)
- ✅ Pipeline `schema` step with `--provider` / `--force` passthrough (Task 3)
- ✅ View helper `_render_schema_sections` with null filtering (Task 4)
- ✅ Template renders structured sections, falls back to raw body (Task 5)
- ✅ Calendar always shown (unchanged position after Task 5 — calendar block is separate)
- ✅ Docs updated (Task 6)

**Type consistency check:**
- `_render_schema_sections(schema_json: dict) -> list[dict] | None` — used in Task 4 tests and Task 4 view, consistent.
- `schema_sections` context key — set in Task 4, read in Task 5 template, consistent.
- `iter_postings(conn, slug_filter, force)` — defined and called in Task 2, consistent.
- `write_schema(conn, posting_id, schema)` — defined and called in Task 2, consistent.

**No placeholders:** All steps contain complete code. No TBD / TODO / "similar to" references.

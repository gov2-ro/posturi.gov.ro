# LLM-Variants Side-by-Side Viewer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild `/job/<pk>/variants/` from a 2-column JSON blob grid into a field × variant matrix with smart diff highlighting, three view modes (rendered/json/diff-only), and URL-persisted toolbar filters.

**Architecture:** A single `PRODUCTION_PROMPT_VERSION` constant drives the default prompt filter. `_section_status` computes per-row agreement status. The view builds a `matrix: list[dict]` that templates iterate; a `_variant_matrix.html` partial is served by HTMX when toolbar controls change, full page otherwise.

**Tech Stack:** Django, django-htmx (already wired), Tailwind CSS (CDN), Python `unicodedata`/`json`/`re`, pytest-django.

---

## File Structure

| Path | Action | Responsibility |
|------|--------|----------------|
| `webapp/apps/jobs/views.py` | Modify | Add `PRODUCTION_PROMPT_VERSION`, `_NULL`, `_normalize_section_value`, `_section_status`; rewrite `variant_comparison` |
| `webapp/tests/test_variant_comparison.py` | Create | Unit tests for `_section_status`; integration tests for the view |
| `webapp/templates/jobs/variant_comparison.html` | Rewrite | Full page: header + toolbar (with HTMX) + `#variants-body` container |
| `webapp/templates/jobs/partials/_variant_matrix.html` | Create | HTMX partial: metadata strip + comparison matrix table |

---

## Task 1: Add `_section_status` and helpers to `views.py`

**Files:**
- Modify: `webapp/apps/jobs/views.py` (lines 1–15 for imports, after line 39 for new constants/functions)

- [ ] **Step 1: Write the failing unit tests for `_section_status`**

Create `webapp/tests/test_variant_comparison.py`:

```python
"""Tests for the rewritten variant_comparison view and _section_status helper."""
import pytest
from django.test import Client

from apps.jobs.models import Employer, JobPosting, JobPostingSchemaVariant
from apps.jobs.views import _section_status


# ---------------------------------------------------------------------------
# Unit tests for _section_status
# ---------------------------------------------------------------------------

class TestSectionStatus:

    def test_all_none_is_all_null(self):
        assert _section_status([None, None, None], "responsibilities") == "all_null"

    def test_empty_string_treated_as_null(self):
        assert _section_status(["", None, ""], "responsibilities") == "all_null"

    def test_identical_strings_is_agree(self):
        assert _section_status(["Studii superioare", "Studii superioare"], "educationRequirements") == "agree"

    def test_case_whitespace_normalization_agree(self):
        # Differ only in case and extra whitespace
        assert _section_status(["Studii  SUPERIOARE", "studii superioare"], "educationRequirements") == "agree"

    def test_bullet_stripping_agree(self):
        # One variant has bullet list, other is prose — normalize to same
        assert _section_status(["- item one\n- item two", "item one item two"], "responsibilities") == "agree"

    def test_partial_some_null(self):
        assert _section_status(["Studii superioare", None, "Studii superioare"], "educationRequirements") == "partial"

    def test_diverge_two_distinct_non_null(self):
        assert _section_status(["Studii superioare", "Studii medii"], "educationRequirements") == "diverge"

    def test_diverge_with_some_null(self):
        # ≥2 distinct non-null → diverge regardless of nulls
        assert _section_status(["Studii superioare", None, "Studii medii"], "educationRequirements") == "diverge"

    def test_dict_equality_agree(self):
        d1 = {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"}
        d2 = {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"}
        assert _section_status([d1, d2], "baseSalary") == "agree"

    def test_dict_key_order_independent(self):
        d1 = {"minValue": 4500, "currency": "RON", "maxValue": 6000, "unitText": "MONTH"}
        d2 = {"unitText": "MONTH", "maxValue": 6000, "minValue": 4500, "currency": "RON"}
        assert _section_status([d1, d2], "baseSalary") == "agree"

    def test_dict_diverge(self):
        d1 = {"minValue": 4500, "currency": "RON"}
        d2 = {"minValue": 5000, "currency": "RON"}
        assert _section_status([d1, d2], "baseSalary") == "diverge"

    def test_single_value_agree(self):
        assert _section_status(["some value"], "responsibilities") == "agree"

    def test_single_value_with_null_partial(self):
        assert _section_status(["some value", None], "responsibilities") == "partial"
```

- [ ] **Step 2: Run tests to confirm they fail (function not yet defined)**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro/webapp
python -m pytest tests/test_variant_comparison.py::TestSectionStatus -v 2>&1 | head -30
```

Expected: `ImportError` or `AttributeError` — `_section_status` does not exist yet.

- [ ] **Step 3: Add imports and `_section_status` to `views.py`**

Add to the top of `webapp/apps/jobs/views.py` (after existing stdlib imports, before `import markdown`):

```python
import json
import re
import unicodedata
from typing import Literal
```

After the `_STRUCTURED_RENDERERS` dict (after line 99 in the current file), add:

```python
# Sentinel for "null / empty" in section-status normalization.
_NULL = object()

PRODUCTION_PROMPT_VERSION = "v2"


def _normalize_section_value(value):
    """Normalize a section value for diff comparison.

    Returns the _NULL sentinel for None or "". For dicts, returns a
    canonical JSON string (sort_keys=True). For strings, applies NFKC
    normalization, lowercases, collapses whitespace, and strips leading
    bullet markers per line.
    """
    if value is None or value == "":
        return _NULL
    if isinstance(value, dict):
        return json.dumps(value, sort_keys=True, ensure_ascii=False)
    s = unicodedata.normalize("NFKC", str(value)).lower()
    result_lines = []
    for line in s.splitlines():
        line = re.sub(r"^-\s+", "", line)          # strip leading "- " bullets
        line = re.sub(r"\s+", " ", line).strip()   # collapse whitespace
        if line:
            result_lines.append(line)
    return re.sub(r"\s+", " ", " ".join(result_lines)).strip()


def _section_status(
    values: list, key: str
) -> Literal["agree", "partial", "diverge", "all_null"]:
    """Compute the agreement status for one matrix row.

    Args:
        values: raw section values from each included variant, in column order.
        key:    the schema_json key for this row (unused in logic; kept for
                signature compatibility with the spec).

    Returns:
        "all_null"  — every value normalizes to null/empty (row omitted).
        "agree"     — all non-null values normalize equal AND no nulls present.
        "partial"   — all non-null values normalize equal AND ≥1 null present.
        "diverge"   — ≥2 distinct non-null normalized values.
    """
    normalized = [_normalize_section_value(v) for v in values]
    non_null = [n for n in normalized if n is not _NULL]
    if not non_null:
        return "all_null"
    distinct_non_null = set(non_null)
    has_null = any(n is _NULL for n in normalized)
    if len(distinct_non_null) == 1 and not has_null:
        return "agree"
    if len(distinct_non_null) == 1 and has_null:
        return "partial"
    return "diverge"
```

- [ ] **Step 4: Run unit tests to confirm they pass**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro/webapp
python -m pytest tests/test_variant_comparison.py::TestSectionStatus -v
```

Expected output:
```
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_all_none_is_all_null
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_empty_string_treated_as_null
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_identical_strings_is_agree
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_case_whitespace_normalization_agree
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_bullet_stripping_agree
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_partial_some_null
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_diverge_two_distinct_non_null
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_diverge_with_some_null
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_dict_equality_agree
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_dict_key_order_independent
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_dict_diverge
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_single_value_agree
PASSED tests/test_variant_comparison.py::TestSectionStatus::test_single_value_with_null_partial
13 passed
```

- [ ] **Step 5: Commit**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro
git add webapp/apps/jobs/views.py webapp/tests/test_variant_comparison.py
git commit -m "feat(variants): add _section_status, _normalize_section_value, PRODUCTION_PROMPT_VERSION"
```

---

## Task 2: Write integration tests for the view (failing), then rewrite `variant_comparison`

**Files:**
- Modify: `webapp/tests/test_variant_comparison.py` — add integration test fixtures and tests
- Modify: `webapp/apps/jobs/views.py` — rewrite `variant_comparison`

- [ ] **Step 1: Add integration test fixtures and failing tests to `test_variant_comparison.py`**

Append to `webapp/tests/test_variant_comparison.py`:

```python
# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture
def posting_with_4_v2_variants(db):
    """4 variants (gemini, anthropic, openai, deepseek) all prompt_version=v2.

    Row statuses in the fixture data:
      - educationRequirements: agree (all "Studii superioare")
      - qualifications:        partial (2 have value, 2 are None)
      - responsibilities:      diverge (variant 2 has a different value)
      - workHours:             all_null (all None)
    """
    employer, _ = Employer.objects.get_or_create(
        name="Test Employer VC", defaults={"slug": "test-employer-vc"}
    )
    posting = JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-4variants/",
        title="Inspector cu 4 variante",
        employer=employer,
    )
    variants_data = [
        ("gemini",    "gemini-1.5-pro",      "Elaborează documente",  "Aviz CSAT"),
        ("anthropic", "claude-3-5-sonnet",   "Verifică dosare",       None),          # diverge on responsibilities, partial on qualifications
        ("openai",    "gpt-4o",              "Elaborează documente",  None),           # partial on qualifications
        ("deepseek",  "deepseek-chat",       "Elaborează documente",  "Aviz CSAT"),
    ]
    for provider, model, responsibilities, qualifications in variants_data:
        JobPostingSchemaVariant.objects.create(
            posting=posting,
            provider=provider,
            model=model,
            prompt_version="v2",
            schema_json={
                "responsibilities": responsibilities,
                "educationRequirements": "Studii superioare",
                "qualifications": qualifications,
                "workHours": None,
            },
        )
    return posting


@pytest.fixture
def posting_with_v1_and_v2_variants(db):
    """1 v2 variant + 1 v1 variant with legacy keys."""
    employer, _ = Employer.objects.get_or_create(
        name="Test Employer BC", defaults={"slug": "test-employer-bc"}
    )
    posting = JobPosting.objects.create(
        url="https://posturi.gov.ro/anunt/test-backcompat/",
        title="Referent backcompat",
        employer=employer,
    )
    # v2 variant — has educationRequirements and baseSalary
    JobPostingSchemaVariant.objects.create(
        posting=posting,
        provider="gemini",
        model="gemini-1.5-pro",
        prompt_version="v2",
        schema_json={
            "responsibilities": "Elaborează proiecte",
            "educationRequirements": "Studii superioare",
            "baseSalary": {"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"},
        },
    )
    # v1 variant — flat strings, legacy keys, no educationRequirements
    JobPostingSchemaVariant.objects.create(
        posting=posting,
        provider="openai",
        model="gpt-3.5-turbo",
        prompt_version="v1",
        schema_json={
            "responsibilities": "Elaborează proiecte",
            "qualifications": "Studii superioare juridice",
            "salary": "4500–6000 RON/lună",
            "work_conditions": "Sediu, 8h/zi",
            "educationRequirements": None,
        },
    )
    return posting


# ---------------------------------------------------------------------------
# Integration tests — view with full page response
# ---------------------------------------------------------------------------

@pytest.mark.django_db
def test_rendered_view_returns_200_with_all_non_null_statuses(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=rendered"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    assert "agree" in content
    assert "partial" in content
    assert "diverge" in content


@pytest.mark.django_db
def test_all_null_row_absent_from_rendered_view(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=rendered"
    )
    assert resp.status_code == 200
    # "Program de lucru" is the label for workHours — all_null row must be omitted
    assert "Program de lucru" not in resp.content.decode()


@pytest.mark.django_db
def test_diff_only_collapses_agree_rows(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=diff-only"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # agree row is collapsed: shows "variants agree" merged cell
    assert "variants agree" in content
    # diverge row is still expanded
    assert "diverge" in content


@pytest.mark.django_db
def test_diff_only_diverge_row_has_multiple_cells(posting_with_4_v2_variants):
    """In diff-only mode, diverge rows must not be collapsed."""
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=diff-only"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # Both distinct responsibility values must appear
    assert "Elaborează documente" in content
    assert "Verifică dosare" in content


@pytest.mark.django_db
def test_empty_state_when_no_matching_prompt(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v1"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # Empty-state yellow box with the CLI hint
    assert "llm-schema.py" in content
    # No matrix rows
    assert "agree" not in content
    assert "diverge" not in content


@pytest.mark.django_db
def test_json_view_shows_raw_json(posting_with_4_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_4_v2_variants.pk}/variants/?prompt=v2&view=json"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # JSON view should expose the raw string values
    assert "Elaborează documente" in content
    assert "Studii superioare" in content


# ---------------------------------------------------------------------------
# Back-compat test: v1 + v2 variants mixed
# ---------------------------------------------------------------------------

@pytest.mark.django_db
def test_renderer_backcompat_v1_v2_mixed(posting_with_v1_and_v2_variants):
    client = Client()
    resp = client.get(
        f"/job/{posting_with_v1_and_v2_variants.pk}/variants/"
        "?prompt=v1&prompt=v2&view=rendered"
    )
    assert resp.status_code == 200
    content = resp.content.decode()
    # v1-only "salary" key renders under "Salarizare" label
    assert "Salarizare" in content
    # v1-only "work_conditions" key renders under "Condiții de muncă" label
    assert "Condiții de muncă" in content
    # v2-only "educationRequirements" → "Studii" label appears (partial: v2 has it, v1 null)
    assert "Studii" in content
    # partial status because v1 variant has null for educationRequirements
    assert "partial" in content
```

- [ ] **Step 2: Run integration tests to confirm they fail**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro/webapp
python -m pytest tests/test_variant_comparison.py -k "not TestSectionStatus" -v 2>&1 | tail -20
```

Expected: several `AssertionError` failures because the view still uses the old template which doesn't have `agree`/`diverge` chips or `variants agree` collapsed cells.

- [ ] **Step 3: Rewrite `variant_comparison` in `views.py`**

Replace the entire `variant_comparison` function (lines 555–589) with:

```python
def variant_comparison(request, pk):
    """Field × variant matrix for LLM schema comparison."""
    posting = get_object_or_404(
        JobPosting.objects.select_related("employer", "judet"), pk=pk
    )
    all_variants = list(posting.schema_variants.all())

    # --- Parse toolbar filters from URL query string ---
    prompt_filter = request.GET.getlist("prompt") or [PRODUCTION_PROMPT_VERSION]
    providers_filter = request.GET.getlist("providers")  # empty = all providers
    view_mode = request.GET.get("view", "rendered")

    # --- Filter ---
    included = [v for v in all_variants if v.prompt_version in prompt_filter]
    if providers_filter:
        included = [v for v in included if v.provider in providers_filter]

    # --- Stable column order: (provider, model, prompt_version) ---
    included.sort(key=lambda v: (v.provider, v.model, v.prompt_version))

    # --- Toolbar option sets ---
    all_providers = sorted({v.provider for v in all_variants})
    all_prompts = sorted({v.prompt_version for v in all_variants})

    # --- Min/max for metadata strip highlighting ---
    costs = [v.cost_usd for v in included if v.cost_usd]
    latencies = [v.latency_ms for v in included if v.latency_ms]
    min_cost = min(costs) if costs else None
    max_cost = max(costs) if costs else None
    min_latency = min(latencies) if latencies else None
    max_latency = max(latencies) if latencies else None

    # --- Build comparison matrix ---
    matrix = []
    for key, label in _SCHEMA_SECTION_LABELS:
        values = [
            (v.schema_json.get(key) if isinstance(v.schema_json, dict) else None)
            for v in included
        ]
        if not included:
            continue
        status = _section_status(values, key)
        if status == "all_null":
            continue

        cells = []
        for value in values:
            if value is None or value == "":
                html = ""
                raw_json = "null"
            elif isinstance(value, dict) and key in _STRUCTURED_RENDERERS:
                rendered_text = _STRUCTURED_RENDERERS[key](value)
                html = md.markdown(rendered_text, extensions=["nl2br"]) if rendered_text.strip() else ""
                raw_json = json.dumps(value, indent=2, ensure_ascii=False)
            else:
                html = md.markdown(str(value), extensions=["nl2br"])
                raw_json = json.dumps(str(value), ensure_ascii=False)
            cells.append({"html": html, "raw_json": raw_json})

        # merged_html used in diff-only mode for agree rows
        merged_html = ""
        if status == "agree":
            for cell in cells:
                if cell["html"]:
                    merged_html = cell["html"]
                    break

        matrix.append({
            "key": key,
            "label": label,
            "status": status,
            "cells": cells,
            "merged_html": merged_html,
        })

    ctx = {
        "posting": posting,
        "included_variants": included,
        "matrix": matrix,
        "view_mode": view_mode,
        "prompt_filter": prompt_filter,
        "providers_filter": providers_filter,
        "all_providers": all_providers,
        "all_prompts": all_prompts,
        "variant_count": len(included),
        "min_cost": min_cost,
        "max_cost": max_cost,
        "min_latency": min_latency,
        "max_latency": max_latency,
    }

    if request.htmx:
        return render(request, "jobs/partials/_variant_matrix.html", ctx)
    return render(request, "jobs/variant_comparison.html", ctx)
```

---

## Task 3: Create `_variant_matrix.html` partial

**Files:**
- Create: `webapp/templates/jobs/partials/_variant_matrix.html`

- [ ] **Step 1: Create the partial template**

Create `webapp/templates/jobs/partials/_variant_matrix.html`:

```html
{% if included_variants %}

  {# ── Metadata strip ── #}
  <div class="overflow-x-auto mb-6">
    <table class="w-full border-collapse text-sm">
      <thead class="bg-gray-100">
        <tr>
          <th class="border border-border-warm px-3 py-2 text-left font-medium">Provider / Model</th>
          <th class="border border-border-warm px-3 py-2 text-center font-medium">Prompt</th>
          <th class="border border-border-warm px-3 py-2 text-right font-medium">Input (tok)</th>
          <th class="border border-border-warm px-3 py-2 text-right font-medium">Output (tok)</th>
          <th class="border border-border-warm px-3 py-2 text-right font-medium"
              {% if min_latency == max_latency %}title="Toate au aceeași latență"{% endif %}>
            Latență (ms)
          </th>
          <th class="border border-border-warm px-3 py-2 text-right font-medium"
              {% if min_cost == max_cost %}title="Toate costă la fel"{% endif %}>
            Cost (USD)
          </th>
          <th class="border border-border-warm px-3 py-2 text-center font-medium">Data</th>
        </tr>
      </thead>
      <tbody>
        {% for variant in included_variants %}
        <tr class="hover:bg-parchment-dark">
          <td class="border border-border-warm px-3 py-2 font-medium font-mono text-xs">
            {{ variant.provider }}/{{ variant.model }}
          </td>
          <td class="border border-border-warm px-3 py-2 text-center">
            <span class="inline-block bg-gray-200 text-xs px-1.5 py-0.5 rounded font-mono">
              {{ variant.prompt_version }}
            </span>
          </td>
          <td class="border border-border-warm px-3 py-2 text-right font-mono text-xs">
            {% if variant.input_tokens %}{{ variant.input_tokens }}{% else %}—{% endif %}
          </td>
          <td class="border border-border-warm px-3 py-2 text-right font-mono text-xs">
            {% if variant.output_tokens %}{{ variant.output_tokens }}{% else %}—{% endif %}
          </td>
          <td class="border border-border-warm px-3 py-2 text-right font-mono text-xs
            {% if variant.latency_ms and min_latency == variant.latency_ms %}bg-green-100
            {% elif variant.latency_ms and max_latency == variant.latency_ms %}bg-red-100
            {% endif %}">
            {% if variant.latency_ms %}{{ variant.latency_ms }}{% else %}—{% endif %}
          </td>
          <td class="border border-border-warm px-3 py-2 text-right font-mono text-xs
            {% if variant.cost_usd and min_cost == variant.cost_usd %}bg-green-100
            {% elif variant.cost_usd and max_cost == variant.cost_usd %}bg-red-100
            {% endif %}">
            {% if variant.cost_usd %}${{ variant.cost_usd|floatformat:6 }}{% else %}—{% endif %}
          </td>
          <td class="border border-border-warm px-3 py-2 text-center text-xs text-ink-muted">
            {{ variant.created_at|date:"Y-m-d H:i" }}
          </td>
        </tr>
        {% endfor %}
      </tbody>
    </table>
    {% if min_cost and max_cost and min_cost != max_cost %}
    <p class="text-xs text-ink-muted mt-2">💚 Verde = cel mai bun | 🔴 Roșu = cel mai prost</p>
    {% endif %}
  </div>

  {# ── Comparison matrix ── #}
  {% if matrix %}
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-sm">
      <thead>
        <tr class="bg-parchment" style="position: sticky; top: 0; z-index: 10; box-shadow: 0 1px 3px rgba(0,0,0,0.15);">
          <th class="border border-border-warm px-3 py-2 text-left bg-parchment min-w-[140px]">Câmp</th>
          {% for variant in included_variants %}
          <th class="border border-border-warm px-3 py-2 text-center bg-parchment min-w-[220px]">
            <div class="font-medium font-mono text-xs leading-tight">
              {{ variant.provider }}/{{ variant.model }}
            </div>
            <span class="inline-block bg-gray-200 text-xs px-1.5 py-0.5 rounded font-mono mt-1">
              {{ variant.prompt_version }}
            </span>
          </th>
          {% endfor %}
        </tr>
      </thead>
      <tbody>
        {% for row in matrix %}
          {% if view_mode == "diff-only" and row.status == "agree" %}
          {# Collapsed agree row in diff-only mode #}
          <tr class="bg-gray-50">
            <td class="border border-border-warm px-3 py-2 align-top">
              <div class="text-xs text-ink-muted">{{ row.label }}</div>
              <span class="inline-block bg-green-100 text-green-800 text-xs px-1.5 py-0.5 rounded mt-1">
                agree
              </span>
            </td>
            <td class="border border-border-warm px-3 py-2 text-ink-muted text-xs italic align-top"
                colspan="{{ included_variants|length }}">
              {{ included_variants|length }}/{{ included_variants|length }} variants agree:
              <div class="mt-1 not-italic text-ink prose prose-sm max-w-none">
                {{ row.merged_html|safe }}
              </div>
            </td>
          </tr>
          {% else %}
          <tr class="{% cycle 'bg-white' 'bg-parchment' %}">
            <td class="border border-border-warm px-3 py-2 align-top min-w-[140px]">
              <div class="text-xs font-medium text-ink">{{ row.label }}</div>
              {% if row.status == "agree" %}
              <span class="inline-block bg-green-100 text-green-800 text-xs px-1.5 py-0.5 rounded mt-1">agree</span>
              {% elif row.status == "partial" %}
              <span class="inline-block bg-yellow-100 text-yellow-800 text-xs px-1.5 py-0.5 rounded mt-1">partial</span>
              {% elif row.status == "diverge" %}
              <span class="inline-block bg-red-100 text-red-800 text-xs px-1.5 py-0.5 rounded mt-1">diverge</span>
              {% endif %}
            </td>
            {% for cell in row.cells %}
            <td class="border border-border-warm px-3 py-2 align-top
              {% if row.status == "diverge" and cell.html %}bg-red-50{% endif %}">
              {% if view_mode == "json" %}
              <pre class="text-xs font-mono overflow-auto max-h-80 whitespace-pre-wrap bg-gray-50 p-2 rounded">{{ cell.raw_json }}</pre>
              {% elif cell.html %}
              <div class="prose prose-sm max-w-none text-xs">{{ cell.html|safe }}</div>
              {% else %}
              <span class="text-ink-faint">—</span>
              {% endif %}
            </td>
            {% endfor %}
          </tr>
          {% endif %}
        {% endfor %}
      </tbody>
    </table>
  </div>
  {% endif %}

{% else %}
  {# Empty state — preserved from original #}
  <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
    <p class="text-gray-700 mb-4">Nu au fost generate variante schema pentru acest anunț.</p>
    <p class="text-sm text-gray-600">
      Rulați
      <code class="bg-gray-100 px-2 py-1 rounded">python llm-schema.py --compare --slug {{ posting.url|lower }}</code>
      pentru a genera comparații LLM.
    </p>
  </div>
{% endif %}
```

- [ ] **Step 2: Run integration tests to see how many pass now**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro/webapp
python -m pytest tests/test_variant_comparison.py -k "not TestSectionStatus" -v 2>&1 | tail -25
```

Expected: most tests still fail because `variant_comparison.html` hasn't been rewritten yet (it doesn't have `#variants-body` or include the partial). Continue to Task 4.

---

## Task 4: Rewrite `variant_comparison.html` full page

**Files:**
- Rewrite: `webapp/templates/jobs/variant_comparison.html`

- [ ] **Step 1: Rewrite the full page template**

Overwrite `webapp/templates/jobs/variant_comparison.html` entirely:

```html
{% extends "base.html" %}
{% load static %}

{% block title %}Comparație variante schema — {{ posting.title }} — posturi.gov.ro{% endblock %}

{% block content %}
<div class="container mx-auto px-4 py-8 max-w-7xl">

  {# ── Header — preserved ── #}
  <div class="mb-4">
    <a href="{% url 'job_detail' posting.pk %}"
       class="text-gov hover:underline text-sm">← Înapoi la anunț</a>
  </div>
  <h1 class="text-3xl font-bold font-display mb-1">{{ posting.title }}</h1>
  <p class="text-ink-muted mb-6">
    {{ posting.employer.name }}{% if posting.judet %} • {{ posting.judet.name }}{% endif %}
  </p>

  {# ── Toolbar ── #}
  <form id="variant-toolbar"
        hx-get="{% url 'variant_comparison' posting.pk %}"
        hx-target="#variants-body"
        hx-push-url="true"
        hx-trigger="change"
        class="bg-white border border-border-warm rounded-lg px-5 py-4 mb-6 flex flex-wrap gap-6 items-start shadow-sm">

    {# Prompt version checkboxes #}
    {% if all_prompts %}
    <fieldset>
      <legend class="text-xs font-semibold text-ink-muted uppercase tracking-wider mb-2">Versiune prompt</legend>
      <div class="flex flex-wrap gap-3">
        {% for pv in all_prompts %}
        <label class="flex items-center gap-1.5 cursor-pointer">
          <input type="checkbox" name="prompt" value="{{ pv }}"
                 {% if pv in prompt_filter %}checked{% endif %}
                 class="rounded border-gray-400 text-gov focus:ring-gov">
          <span class="text-sm font-mono">{{ pv }}</span>
        </label>
        {% endfor %}
      </div>
    </fieldset>
    {% endif %}

    {# View mode #}
    <fieldset>
      <legend class="text-xs font-semibold text-ink-muted uppercase tracking-wider mb-2">Afișare</legend>
      <select name="view"
              class="text-sm border border-border-warm rounded-md px-2 py-1.5 bg-white focus:ring-gov focus:border-gov">
        <option value="rendered"  {% if view_mode == "rendered"  %}selected{% endif %}>Redat</option>
        <option value="json"      {% if view_mode == "json"      %}selected{% endif %}>JSON brut</option>
        <option value="diff-only" {% if view_mode == "diff-only" %}selected{% endif %}>Doar diferențe</option>
      </select>
    </fieldset>

    {# Provider checkboxes #}
    {% if all_providers %}
    <fieldset>
      <legend class="text-xs font-semibold text-ink-muted uppercase tracking-wider mb-2">Provideri</legend>
      <div class="flex flex-wrap gap-3">
        {% for provider in all_providers %}
        <label class="flex items-center gap-1.5 cursor-pointer">
          <input type="checkbox" name="providers" value="{{ provider }}"
                 {% if not providers_filter or provider in providers_filter %}checked{% endif %}
                 class="rounded border-gray-400 text-gov focus:ring-gov">
          <span class="text-sm">{{ provider }}</span>
        </label>
        {% endfor %}
      </div>
    </fieldset>
    {% endif %}

    {# Variant count badge #}
    <div class="ml-auto self-center">
      <span class="inline-block bg-gov-light text-gov text-xs font-semibold px-2.5 py-1 rounded-full">
        {{ variant_count }} variant{% if variant_count != 1 %}e{% endif %}
      </span>
    </div>
  </form>

  {# ── HTMX target: metadata strip + matrix ── #}
  <div id="variants-body">
    {% include "jobs/partials/_variant_matrix.html" %}
  </div>

</div>
{% endblock %}
```

- [ ] **Step 2: Run all integration tests**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro/webapp
python -m pytest tests/test_variant_comparison.py -v
```

Expected: all 13 unit tests + all 7 integration tests pass (20 total). If any fail, debug before continuing.

- [ ] **Step 3: Run the full existing test suite to confirm no regressions**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro/webapp
python -m pytest tests/ -v 2>&1 | tail -30
```

Expected: All pre-existing tests in `test_schema_detail.py` and `test_import_idempotency.py` still pass.

- [ ] **Step 4: Commit**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro
git add webapp/apps/jobs/views.py \
        webapp/tests/test_variant_comparison.py \
        webapp/templates/jobs/variant_comparison.html \
        webapp/templates/jobs/partials/_variant_matrix.html
git commit -m "feat(variants): rebuild side-by-side viewer as field×variant matrix with diff highlighting"
```

---

## Task 5: Manual UI smoke test

**Files:** None (read-only verification)

- [ ] **Step 1: Start the dev server**

```bash
cd /Users/pax/devbox/gov2/posturi.gov.ro/webapp
python manage.py runserver
```

- [ ] **Step 2: Open posting 4437 variants page**

Navigate to: `http://127.0.0.1:8000/job/4437/variants/`

Verify:
- [ ] Page loads with the header (title, employer, județ, back link)
- [ ] Toolbar shows checkboxes for prompt versions, view mode select, provider checkboxes
- [ ] Badge shows "N variante" (should be ≥4 for posting 4437)
- [ ] Metadata strip shows one row per variant with correct columns
- [ ] Comparison matrix shows rows with Romanian labels and agree/partial/diverge chips

- [ ] **Step 3: Test view=diff-only**

Click "Doar diferențe" in the toolbar select. Verify:
- [ ] URL updates to `?view=diff-only` (no full page reload — HTMX)
- [ ] Rows where all variants agree collapse into a single merged cell ("N/N variants agree: …")
- [ ] Rows with divergent values remain expanded

- [ ] **Step 4: Test view=json**

Click "JSON brut". Verify:
- [ ] All cells show raw JSON in `<pre>` blocks
- [ ] Structured fields (`application_contact`, `baseSalary`) show dict JSON

- [ ] **Step 5: Test URL persistence**

Manually set URL to `?view=diff-only&prompt=v2`. Reload page. Verify toolbar reflects the filter state correctly.

- [ ] **Step 6: Test sticky header**

Scroll down past a long `application_docs` or `responsibilities` row. Verify the column header row stays fixed at the top of the viewport.

---

## Self-review against spec

**Spec coverage check:**

| Spec requirement | Task |
|------------------|------|
| `PRODUCTION_PROMPT_VERSION` constant | Task 1 Step 3 |
| `?prompt`, `?view`, `?providers` URL params | Task 2 Step 3 |
| Default prompt = production version | Task 2 Step 3 |
| Toolbar shows active filter + variant count | Task 4 Step 1 |
| Metadata strip: one row per variant, min/max highlight | Task 3 Step 1 |
| Comparison matrix: rows = union of section keys | Task 2 Step 3 |
| Column order: alphabetical by (provider, model, prompt_version) | Task 2 Step 3 |
| Cells: full rendered HTML or `—` for null | Task 3 Step 1 |
| Row status chip: agree/partial/diverge | Task 3 Step 1 |
| Sticky header row | Task 3 Step 1 |
| `rendered` mode: full HTML per cell | Task 3 Step 1 |
| `json` mode: raw JSON in `<pre>` | Task 3 Step 1 |
| `diff-only` mode: agree rows collapsed, partial/diverge expanded | Task 3 Step 1 |
| Empty state preserved | Task 3 Step 1 |
| `_section_status` normalization rules | Task 1 Step 3 |
| HTMX partial: `hx-get`, `hx-target="#variants-body"`, `hx-push-url="true"` | Task 4 Step 1 |
| View returns partial when `request.htmx` | Task 2 Step 3 |
| Unit tests: all 4 statuses, mixed types, normalization | Task 1 Step 1 |
| Integration test: rendered returns 200 with all statuses | Task 2 Step 1 |
| Integration test: diff-only collapses agree, keeps diverge | Task 2 Step 1 |
| Integration test: `?prompt=v1` → empty state | Task 2 Step 1 |
| Back-compat: v1 + v2 mixed renders correctly | Task 2 Step 1 |

**Placeholder scan:** No TBD/TODO markers. All code blocks complete.

**Type consistency check:**
- `_NULL` is module-level `object()` — used consistently in `_normalize_section_value` and `_section_status`.
- `_section_status` returns the same four literals as checked in tests.
- `matrix` list shape `{key, label, status, cells, merged_html}` matches template loop.
- `cells` shape `{html, raw_json}` matches template's `cell.html`/`cell.raw_json` references.
- `included_variants` in context matches loop variable in partial.
- `included_variants|length` in template's `colspan` attribute — correct Django filter.

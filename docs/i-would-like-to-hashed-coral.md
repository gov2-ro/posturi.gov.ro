# Plan: Job Condition Attributes — Extraction, Summary & Filters

## Context

Job postings on posturi.gov.ro contain rich work-condition data buried in unstructured body text and LLM-extracted free-text fields (`workHours`, `skills`). Currently none of it is normalized, filterable, or shown as a quick summary. Users cannot filter by "normă parțială", "remote", "without computer requirements", or experience level — forcing manual reading of each posting.

This plan adds:
1. **Extraction layer** — new structured attributes into `inferred` JSONField (local regex + LLM queue for low-confidence cases)
2. **Card badges** — compact condition pills on each result row
3. **Detail summary block** — "Condiții la locul de muncă" grid on the job detail page
4. **Browse facets** — 6 new sidebar filter groups wired to the new `inferred` fields

Approach: **hybrid** — local keyword/regex for ~85% of postings (free, instant), confidence-gated LLM queue for ambiguous cases (~15%, ~$0.25–0.50 total).

---

## New `inferred` Fields

All stored in the existing `JobPosting.inferred` JSONField — no migration needed.

```json
{
  "work_type": "norma_intreaga",     // norma_intreaga | norma_partiala | schimburi | null
  "work_type_confidence": 0.92,      // 0.0–1.0; < 0.6 → LLM queue
  "remote_eligible": true,           // true | null  (null = not mentioned, NOT false)
  "requires_computer": true,         // true | false | null  (false only if explicitly "nu se solicită")
  "computer_level": "basic",         // basic | advanced | null
  "salary_min": 4500,                // denormalized from schema_json.baseSalary.minValue
  "salary_max": 4500,                // denormalized from schema_json.baseSalary.maxValue
  "conditions_inferred_at": "..."
}
```

**Null semantics matter:**
- `remote_eligible: null` = not mentioned (filter only returns `true` postings — no negative filter)
- `requires_computer: null` = not mentioned (distinct from `false` = explicitly not required)

---

## Keyword Patterns (diacritic-tolerant, Romanian)

| Field | Match patterns | Value |
|---|---|---|
| `work_type` | "normă întreagă", "norma intreaga", "8h/zi", "normă completă", "40h/săptămână", "timp normal" | `norma_intreaga` |
| | "normă parțială", "norma partiala", "0,5 normă", "4h/zi", "fracțiune de normă", "part.?time" | `norma_partiala` |
| | "schimburi", "ture", "tură de noapte", "lucrul în ture", "program de noapte" | `schimburi` |
| `remote_eligible` | "telemuncă", "muncă la distanță", "munca la distanta", "remote", "hibrid", "lucru de acasă" | `true` |
| `requires_computer` | "operare PC", "operare calculator", "MS Office", "Microsoft Office", "Excel", "cunoștințe IT", "SEAP", "SAP", "utilizare calculator" | `true` |
| | regex: `nu\s+se\s+solicit[ăa]\s+.{0,30}(calculator|IT|PC|informatică)` | `false` |
| `computer_level` | "cunoștințe avansate", "nivel ridicat", "avansat" | `advanced` |
| | (default when `requires_computer=true`) | `basic` |

**Confidence scoring for `work_type`:**
- 2+ distinct pattern matches → `0.95`
- 1 match → `0.75`
- 0 matches, `schema_json.workHours` exists → normalize from that text, confidence `0.70`
- 0 matches, no workHours, body ≥ 250 chars → `0.0` (queue for LLM)
- body < 250 chars → `null`, skip LLM (not enough signal)

**Salary denormalization:** Read `schema_json.baseSalary.minValue` / `maxValue` if `schema_json` is populated and `baseSalary` is not null.

---

## Implementation Steps

### Step 1 — Extend `infer_postings.py`

**File:** `webapp/apps/jobs/management/commands/infer_postings.py`

Add to Layer 3 (after existing `_infer_skills`, `_infer_languages`):

```python
def _infer_work_type(body: str, work_hours_text: str | None) -> tuple[str | None, float]:
    """Returns (work_type, confidence). work_type ∈ {norma_intreaga, norma_partiala, schimburi, None}."""
    ...

def _infer_remote(body: str) -> bool | None:
    """Returns True if remote/hybrid mentioned, None otherwise."""
    ...

def _infer_computer(body: str, existing_skills: list[str]) -> tuple[bool | None, str | None]:
    """Returns (requires_computer, computer_level)."""
    ...

def _extract_salary_range(schema_json: dict | None) -> tuple[int | None, int | None]:
    """Denormalizes baseSalary.minValue/maxValue from schema_json."""
    ...
```

In the main `_infer_posting()` function, call these and merge results into the `inferred` dict before saving. Add `conditions_inferred_at` timestamp.

Add `--conditions-only` flag to `infer_postings` command to re-run just the new Layer 3 fields without touching existing inferred data (useful for backfill without clobbering profession_family etc.).

### Step 2 — LLM queue command

**New file:** `webapp/apps/jobs/management/commands/infer_conditions_llm.py`

```bash
python manage.py infer_conditions_llm --limit 100   # process N low-confidence postings
python manage.py infer_conditions_llm               # process all queued
```

Queries: `inferred__work_type_confidence=0.0` (explicitly queued) + `inferred__work_type__isnull=True AND body_markdown length > 250`.

Sends a minimal LLM prompt (not the full schema extraction prompt) asking for just 3 fields:
- work_type (one of: norma_intreaga / norma_partiala / schimburi / nespecificat)  
- remote_eligible (da / nu / nespecificat)  
- requires_computer (da / nu / nespecificat)

Uses GPT-5 Nano (cheapest, already wired in `llm-schema.py`'s infrastructure). Updates `inferred` in-place.

### Step 3 — Browse view: new filters + facet counts

**File:** `webapp/apps/jobs/views.py`

Extend `_apply_filters(qs, params)`:
```python
# New params:
work_type  → filter(inferred__work_type=v)               # "norma_intreaga" etc.
remote     → filter(inferred__remote_eligible=True)       # positive filter only
computer   → "solicitat": filter(inferred__requires_computer=True)
           → "nesolicitat": filter(inferred__requires_computer__in=[False, None]) -- NOT True
exp_level  → bucketed: 0=no condition, 1=1-2yr, 2=3-5yr, 3=5+yr (map to experience_years range)
studies_level → filter(inferred__studies_required=v)      # already extracted
salary_bucket → filter(inferred__salary_min__gte=lo, inferred__salary_min__lt=hi)
```

Add facet count queries for each new filter (same `Count + filter=Q(...)` pattern used for `profession_family`).

Conditionally include salary facet only if `JobPosting.objects.filter(inferred__salary_min__isnull=False).count() >= 100`.

New GET param names: `work_type`, `remote`, `computer`, `exp_level`, `studies_level`, `salary_bucket`.

### Step 4 — Result card badges

**File:** `webapp/templates/jobs/partials/result_row.html`

After the existing badge row, add a second row (only renders if ≥1 attribute populated):

```html
{% with cond=job.inferred %}
{% if cond.work_type or cond.remote_eligible or cond.requires_computer is not None or cond.experience_years %}
<div class="flex flex-wrap gap-1 mt-1">
  {% if cond.work_type %}
    <span class="condition-badge">{{ cond.work_type|work_type_label }}</span>
  {% endif %}
  {% if cond.remote_eligible %}
    <span class="condition-badge">🏠 Telemuncă</span>
  {% endif %}
  {% if cond.requires_computer %}
    <span class="condition-badge">💻 Calculator</span>
  {% endif %}
  {% if cond.experience_years %}
    <span class="condition-badge">Min. {{ cond.experience_years }} ani</span>
  {% endif %}
</div>
{% endif %}
{% endwith %}
```

Badge style: `px-1.5 py-0.5 text-xs font-medium bg-stone-50 text-stone-600 border border-stone-200 rounded`.

Add `work_type_label` template filter to `apps/jobs/templatetags/jobs_extras.py`:
- `norma_intreaga` → "Normă întreagă"
- `norma_partiala` → "Normă parțială"  
- `schimburi` → "Schimburi"

### Step 5 — Detail page summary block

**File:** `webapp/templates/jobs/detail.html`

Insert "Condiții la locul de muncă" grid after the page `<h1>` and before the schema sections:

```html
{% if job has any conditions %}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 p-4 
            bg-amber-50/30 border border-amber-200/60 rounded-lg mb-6">
  <!-- Each tile: icon + label + value -->
  <!-- work_type, remote_eligible, requires_computer, experience_years, studies_required, salary -->
  <!-- null values show "—" in muted color, grid always fills all slots -->
</div>
{% endif %}
```

Each tile:
```html
<div class="flex flex-col gap-0.5">
  <span class="text-xs font-semibold uppercase tracking-widest text-ink-faint">Program</span>
  <span class="text-sm font-medium text-ink">Normă întreagă</span>
</div>
```

Block renders if ≥2 of the 6 attributes are non-null.

### Step 6 — Facet sidebar

**File:** `webapp/templates/jobs/list.html` + `templates/jobs/partials/facet_group.html`

Add 6 new `{% include "jobs/partials/facet_group.html" %}` blocks below existing "Grad/funcție". Sidebar order after change:

1. Județ (existing)
2. Domeniu (existing)
3. Grad/funcție (existing)
4. Categorie / Tip / Nivel (existing)
5. **Tip normă** (new) — checkboxes, multi-select (consistent with existing sidebar)
6. **Experiență minimă** (new) — bucketed checkboxes
7. **Studii minime** (new) — checkboxes (data already in inferred)
8. **Calculator** (new) — Solicitat / Nesolicitat
9. **Telemuncă** (new) — single checkbox
10. **Salariu** (new, conditional) — bucketed
11. Angajator (existing)

---

## Files to Create/Modify

| File | Change |
|---|---|
| `webapp/apps/jobs/management/commands/infer_postings.py` | Add 4 new Layer 3 helpers + `--conditions-only` flag |
| `webapp/apps/jobs/management/commands/infer_conditions_llm.py` | **New** — LLM queue command |
| `webapp/apps/jobs/views.py` | Extend `_apply_filters()`, add 6 new facet count queries, add condition block context to `job_detail` |
| `webapp/apps/jobs/templatetags/jobs_extras.py` | Add `work_type_label` filter |
| `webapp/templates/jobs/partials/result_row.html` | Add condition badge row |
| `webapp/templates/jobs/detail.html` | Add "Condiții la locul de muncă" grid block |
| `webapp/templates/jobs/list.html` | Add 6 new facet groups |
| `webapp/tests/` | Tests for new inference helpers + filter params |

### Reuse existing patterns
- Keyword matching: follow the `FAMILIES` dict pattern in `infer_postings.py` (word-boundary regex, NFKD normalization, diacritic stripping via `unicodedata`)
- JSONB facet counts: follow `family_counts` / `seniority_counts` pattern in `views.py` (`values('inferred__profession_family').annotate(n=Count('id'))`)
- Template filter: follow `days_until` in `jobs_extras.py`
- Badge style: reuse existing badge classes from `result_row.html`
- LLM call: reuse `make_generator()` from `llm-schema.py` (or a lighter ad-hoc call via the openai SDK directly in the management command)

---

## Verification

```bash
# 1. Run local inference (conditions only) on all postings
python manage.py infer_postings --conditions-only

# 2. Spot-check inferred values
python manage.py shell -c "
from apps.jobs.models import JobPosting
qs = JobPosting.objects.exclude(inferred__work_type=None)[:5]
for j in qs: print(j.title, j.inferred.get('work_type'), j.inferred.get('remote_eligible'))
"

# 3. Run LLM queue for low-confidence postings
python manage.py infer_conditions_llm --limit 20  # spot check first

# 4. Run tests
cd webapp && pytest tests/ -v

# 5. Start dev server and verify in browser:
#    - Browse page: new facets appear, counts are non-zero
#    - Filter by "Normă parțială" → only partiala results
#    - Filter by "Telemuncă" → only remote postings
#    - Filter by "Calculator: Nesolicitat" → jobs without IT requirement
#    - Job card: condition pills appear below badge row
#    - Job detail: "Condiții la locul de muncă" grid renders correctly
#    - Detail page: null attributes show "—", grid doesn't break layout
```

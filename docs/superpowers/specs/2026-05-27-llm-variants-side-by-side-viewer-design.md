# LLM-variants side-by-side viewer (per-posting)

## Context

The existing per-posting comparison page at `/job/<pk>/variants/` is technically "side-by-side" — a metadata table on top, then a 2-column grid of cards, each card dumping the variant's `schema_json` as raw `<pre>` JSON. In practice it's unreadable for actual comparison work: 4 unbounded JSON blobs in a 2-column grid means your eye has to jump between cards at different vertical offsets, and JSON keys are English while the production detail page uses Romanian labels, so the mental mapping cost is constant.

Now that the v2 prompt produces 12 structured fields per posting and we routinely run 4 enabled models × 2 prompt versions = up to 8 variants, the page needs to actually answer the question we open it to answer: *"For this posting, where do the models disagree, and on what?"*

Goal: rebuild the page as a **field × variant matrix** with smart diff highlighting and a small set of URL-persisted controls. Cross-posting aggregation is explicitly out of scope (tracked separately in `docs/backlog.md`).

## User-facing behavior

URL: `/job/<int:pk>/variants/` (unchanged).

**Page header (unchanged):** title, employer, județ, "← Înapoi la anunț" link.

**Toolbar** (all controls reflected in URL query string so links are shareable):

- `?prompt=v1,v2` — multi-select prompt versions to include. Default: the current production version only (read from a single source of truth in `views.py`; today `v1`, after promotion `v2`).
- `?view=rendered|json|diff-only` — display mode. Default `rendered`.
- `?providers=gemini,openai,anthropic,deepseek` — multi-select. Default: all that have variants for this posting.

Toolbar shows the active filter set and the resulting variant count ("4 variants").

**Metadata strip** (replaces the existing wide metadata table): one row per variant in the chosen filter set, columns: provider/model, prompt_version, input tokens, cached tokens (if reported), output tokens, latency (ms), cost (USD), created_at. Min/max highlighting on cost + latency (already done by the current view — preserve).

**Comparison matrix** (the main content):

- **Rows**: the union of section keys present in any included variant's `schema_json`, in the order defined by `_SCHEMA_SECTION_LABELS` in `webapp/apps/jobs/views.py`. Rows where every included variant has `null`/empty are omitted entirely.
- **Columns**: one per variant, header showing `provider/model` and a small `v1`/`v2` chip. Stable order: alphabetical by `(provider, model, prompt_version)`.
- **Cells**: full rendered HTML from `_render_schema_sections` for that one section in that one variant. No truncation, no popovers. Null/empty cells show a muted `—`.
- **Row status tag** (small chip in the left-hand "Câmp" cell): `agree` (green), `partial` (amber — some null, some not), `diverge` (red — ≥2 distinct non-null values).
- **Sticky header row** (`position: sticky; top: 0;`) so column labels stay visible while scrolling the tall table.

**View modes:**

- `rendered` — every cell renders the section via `_render_schema_sections`. For structured fields (`baseSalary`, `application_fee`, `application_contact`) the existing render helpers `_render_base_salary` / `_render_application_fee` / `_render_application_contact` apply.
- `json` — every cell shows raw `JSON.stringify(value, null, 2)` in `<pre>`. Useful for inspecting structured fields exactly.
- `diff-only` — rows tagged `agree` collapse into a single merged cell ("4/4 variants agree: <value>"); rows tagged `partial` or `diverge` stay expanded. Drops the noise so only the disagreements remain.

**Empty state** (no variants for this posting): the existing yellow box with the `python llm-schema.py --compare --slug <slug>` hint. Preserved.

## Diff detection rules

Implemented as a single `_section_status(values: list, key: str) -> Literal["agree","partial","diverge","all_null"]` function alongside `_render_schema_sections` in `views.py`. `values` is the raw list of section values from each included variant (in column order); `key` is the section key.

1. **Normalize each value:**
   - `None` and empty string `""` → sentinel `_NULL`.
   - Dict (structured field) → `json.dumps(value, sort_keys=True, ensure_ascii=False)`.
   - String → `unicodedata.normalize("NFKC", s).lower()`, collapse whitespace runs to one space, strip leading `- ` bullet markers per line, strip leading/trailing whitespace.
2. **Status:**
   - All values normalize to `_NULL` → `all_null` (the row is omitted entirely from the matrix).
   - All non-null values normalize equal AND no `_NULL` → `agree`.
   - All non-null values normalize equal AND at least one `_NULL` → `partial`.
   - ≥2 distinct non-null normalized values → `diverge`.

No semantic comparison. No sub-string diff coloring. Keep it predictable and fast.

## Files to modify / create

- `webapp/apps/jobs/views.py` — `variant_comparison(request, pk)` rewritten:
  - Parse `prompt`, `view`, `providers` from `request.GET`; compute the filter set.
  - A single `PRODUCTION_PROMPT_VERSION` constant defined near the top of the file is the source of truth for the default `prompt` filter. (Same constant should be referenced by `llm-schema.py::PROMPT_VERSION` later — not part of this spec but the future promotion only changes one line.)
  - Build the variant list, group by column order.
  - For each section key in `_SCHEMA_SECTION_LABELS`, collect the value from every included variant; compute status; render via `_render_schema_sections`-style markdown pass for the `rendered` view, or `json.dumps` for the `json` view.
  - Pass a `matrix: list[dict]` to the template: `[{key, label, status, cells: [{html, raw_value}, ...], merged_html (when collapsed in diff-only)}]`.
- `webapp/templates/jobs/variant_comparison.html` — full rewrite of the body (header/empty-state preserved): toolbar (HTMX `hx-get` on each control for instant re-render without a full page reload), metadata strip, matrix table with sticky header.
- `webapp/templates/jobs/partials/_variant_matrix.html` (new) — the partial returned by HTMX when toolbar controls change; renders just the metadata strip + matrix table. Toolbar controls use `hx-get="{% url 'variant_comparison' posting.pk %}" hx-target="#variants-body" hx-push-url="true"`. The view returns the partial when `request.htmx` is truthy, the full page otherwise (django-htmx is already wired into the project per backlog).
- `webapp/tests/test_variant_comparison.py` (new) — unit tests for `_section_status` (the 4 status categories, mixed-type values, structured-field equality), plus an integration test that GETs `/job/<pk>/variants/?prompt=v2&view=diff-only` against a posting with 4 hand-crafted variants and asserts: the `agree` rows are collapsed, the `diverge` rows are present, the `all_null` rows are absent.

## Reusable code already in repo

- `_render_schema_sections`, `_render_base_salary`, `_render_application_fee`, `_render_application_contact`, `_SCHEMA_SECTION_LABELS`, `_STRUCTURED_RENDERERS` — all in `webapp/apps/jobs/views.py`. Used as-is to render each cell.
- `JobPostingSchemaVariant` model (already wired with `posting.schema_variants` related manager). Unique constraint on `(posting, provider, model, prompt_version)` guarantees at most one variant per cell.
- Tailwind + HTMX both already loaded in `base.html`.
- The min/max cost/latency highlighting logic in the current `variant_comparison` view — keep it for the metadata strip.

## Verification

1. **Unit tests for `_section_status`**: cover all 4 statuses, string-vs-dict-vs-null mixes, whitespace/case/bullet normalization.
2. **Integration test** for the rewritten view: a fixture creates a posting + 4 `JobPostingSchemaVariant` rows (4 distinct models × prompt_version=v2) with hand-crafted `schema_json` exhibiting one `agree` row, one `partial` row, one `diverge` row, and one `all_null` row. Test that:
   - `GET /job/<pk>/variants/?view=rendered` returns 200, all 3 non-null statuses are in the response HTML, `all_null` row absent.
   - `GET /job/<pk>/variants/?view=diff-only` returns 200, `agree` row is collapsed to one cell, `diverge` row has 4 distinct cells.
   - `GET /job/<pk>/variants/?prompt=v1` returns 200 with zero variants and the empty-state yellow box (none of the fixture variants are v1).
3. **Renderer back-compat**: a 5th fixture variant with `prompt_version=v1` and a pre-v2 `schema_json` shape (flat `salary` string, `work_conditions` string, no `educationRequirements`); confirm the matrix still renders it correctly under `?prompt=v1,v2` and that the row labels for the v1-only keys appear.
4. **Manual UI smoke test**: `python manage.py runserver` → open the page for posting id 4437 (has 4 v2 variants from the recent comparison run). Confirm:
   - Default load shows 4 v2 variants (no v1) with rendered Romanian sections.
   - Toggle `view=diff-only` collapses the `Studii`, `Experiență`, `Locație` rows (all agree) and keeps `Cond. specifice` and `Atribuții principale` expanded.
   - Toggle `view=json` shows raw JSON in every cell, including the structured `application_contact` dict.
   - URL updates on each toolbar interaction; reload preserves the filter state.
   - Sticky header stays visible when scrolling past a long `application_docs` row.

## Out of scope (deliberate YAGNI)

- Cross-posting aggregation / leaderboard (backlog item; separate spec).
- Sub-string diff coloring (word-level red/green).
- Editing or "promote this variant to production".
- Modal/popover drill-in per cell (the `view=json` mode already covers the inspect-raw use case).
- Mobile-optimized layout (this is a dev/QA tool — desktop only is fine; the table just horizontally scrolls on narrow viewports).

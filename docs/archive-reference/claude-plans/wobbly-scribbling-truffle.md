# LLM Comparison / QA Storage

## Context

The project uses `jobs_jobposting.schema_json` (a single JSONField) to store LLM-extracted sections.
To compare providers and prompts, we need to store every LLM run separately — with token counts, cost,
and latency — without touching the production `schema_json`. A new `--compare` flag runs all three
providers on the same postings and saves results to the new variant table only.

---

## Files to change

| File | Change |
|---|---|
| `webapp/apps/jobs/models.py` | Add `JobPostingSchemaVariant` model |
| `webapp/apps/jobs/migrations/0007_jobpostingschemavariant.py` | New migration |
| `webapp/apps/jobs/admin.py` | Inline on `JobPostingAdmin` + standalone registration |
| `llm-schema.py` | Token capture, `write_variant()`, `--compare` flag |
| `webapp/tests/test_llm_schema_variants.py` | 3 new tests |

---

## 1. New model — `models.py`

Append after `CalendarEvent`:

```python
class JobPostingSchemaVariant(models.Model):
    posting       = models.ForeignKey(JobPosting, on_delete=models.CASCADE, related_name="schema_variants")
    provider      = models.CharField(max_length=50)
    model         = models.CharField(max_length=100)
    prompt_version = models.CharField(max_length=20, default="v1")
    schema_json   = models.JSONField()
    input_tokens  = models.IntegerField(null=True, blank=True)
    output_tokens = models.IntegerField(null=True, blank=True)
    cost_usd      = models.DecimalField(max_digits=12, decimal_places=8, null=True, blank=True)
    latency_ms    = models.IntegerField(null=True, blank=True)
    created_at    = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["-created_at"]
        verbose_name = "Variantă schemă LLM"
        verbose_name_plural = "Variante schemă LLM"
        constraints = [
            models.UniqueConstraint(
                fields=["posting", "provider", "model", "prompt_version"],
                name="unique_schema_variant",
            )
        ]

    def __str__(self):
        return f"{self.provider}/{self.model}@{self.prompt_version} — posting {self.posting_id}"
```

---

## 2. Migration 0007

Hand-write `webapp/apps/jobs/migrations/0007_jobpostingschemavariant.py`:
- `dependencies = [('jobs', '0006_jobposting_schema_json')]`
- `CreateModel` with all fields above (follow existing migration style)
- Include `UniqueConstraint` in `options['constraints']`

Or run `webapp/.venv/bin/python manage.py makemigrations` (either is fine).

---

## 3. Admin — `admin.py`

Add import: `JobPostingSchemaVariant` alongside existing model imports.

```python
class SchemaVariantInline(admin.TabularInline):
    model = JobPostingSchemaVariant
    extra = 0
    can_delete = False
    show_change_link = True
    readonly_fields = ("provider", "model", "prompt_version", "input_tokens",
                       "output_tokens", "cost_usd", "latency_ms", "created_at")
    fields = readonly_fields
    ordering = ("-created_at",)
```

Add to `JobPostingAdmin.inlines`:
```python
inlines = [CalendarEventInline, SchemaVariantInline]
```

Standalone registration:
```python
@admin.register(JobPostingSchemaVariant)
class JobPostingSchemaVariantAdmin(admin.ModelAdmin):
    list_display  = ("posting", "provider", "model", "prompt_version",
                     "input_tokens", "output_tokens", "cost_usd", "latency_ms", "created_at")
    list_filter   = ("provider", "model", "prompt_version")
    search_fields = ("posting__title", "posting__url")
    raw_id_fields = ("posting",)
    readonly_fields = ("created_at",)
    date_hierarchy = "created_at"
```

---

## 4. `llm-schema.py` changes

### a) Add constants near top (after `DEFAULTS`)

```python
PROMPT_VERSION = "v1"

from decimal import Decimal

COST_PER_MILLION = {
    "gemini-2.5-flash":          {"input": Decimal("0.075"),  "output": Decimal("0.30")},
    "gpt-4o":                    {"input": Decimal("2.50"),   "output": Decimal("10.00")},
    "claude-3-5-haiku-20241022": {"input": Decimal("0.80"),   "output": Decimal("4.00")},
}

def compute_cost(model, input_tokens, output_tokens):
    rates = COST_PER_MILLION.get(model)
    if rates is None or input_tokens is None or output_tokens is None:
        return None
    return (Decimal(input_tokens) * rates["input"] +
            Decimal(output_tokens) * rates["output"]) / Decimal("1000000")
```

### b) Refactor `make_generator` closures → return `(schema, input_tok, output_tok)`

- Gemini: `resp.usage_metadata.prompt_token_count`, `resp.usage_metadata.candidates_token_count`
- OpenAI: `resp.usage.prompt_tokens`, `resp.usage.completion_tokens`
- Anthropic: `msg.usage.input_tokens`, `msg.usage.output_tokens`

### c) Add `write_variant()` using raw SQL upsert

```python
def write_variant(conn, posting_id, provider, model, schema, input_tokens, output_tokens, cost_usd, latency_ms):
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO jobs_jobpostingschemavariant
                (posting_id, provider, model, prompt_version, schema_json,
                 input_tokens, output_tokens, cost_usd, latency_ms, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
            ON CONFLICT (posting_id, provider, model, prompt_version)
            DO UPDATE SET
                schema_json   = EXCLUDED.schema_json,
                input_tokens  = EXCLUDED.input_tokens,
                output_tokens = EXCLUDED.output_tokens,
                cost_usd      = EXCLUDED.cost_usd,
                latency_ms    = EXCLUDED.latency_ms,
                created_at    = NOW()
            """,
            (posting_id, provider, model, PROMPT_VERSION,
             json.dumps(schema, ensure_ascii=False),
             input_tokens, output_tokens, cost_usd, latency_ms),
        )
    conn.commit()
```

Table name `jobs_jobpostingschemavariant` follows Django's `<app>_<modelname_lower>` convention.

### d) Add `--compare` flag and update `__main__` loop

```python
parser.add_argument('--compare', action='store_true',
    help='Run all providers and save variants only (does NOT overwrite schema_json)')
```

Processing loop:
```python
providers_to_run = list(DEFAULTS.items()) if args.compare else [(args.provider, model)]

for prov, mdl in providers_to_run:
    generate = make_generator(prov, mdl)
    postings = iter_postings(conn, slug_filter=args.slug,
                             force=args.force or args.compare)
    if args.limit:
        postings = itertools.islice(postings, args.limit)

    for posting_id, url, content in postings:
        slug = url.rstrip('/').split('/')[-1]
        print(f"[{prov}/{mdl}] Processing {slug}...")
        try:
            t0 = time.monotonic()
            schema, input_tokens, output_tokens = generate(content)
            latency_ms = int((time.monotonic() - t0) * 1000)
        except Exception as e:
            print(f"  ✗ API error: {e}")
            continue
        if not isinstance(schema, dict):
            print(f"  ✗ non-dict response: {repr(schema)[:120]}")
            continue
        cost = compute_cost(mdl, input_tokens, output_tokens)
        write_variant(conn, posting_id, prov, mdl, schema,
                      input_tokens, output_tokens, cost, latency_ms)
        if not args.compare:
            write_schema(conn, posting_id, schema)
        print(f"  ✓ {latency_ms}ms | in={input_tokens} out={output_tokens} cost=${cost}")
```

---

## 5. Tests — `webapp/tests/test_llm_schema_variants.py`

Three tests (use `@pytest.mark.django_db`, direct ORM — no factory-boy needed):

1. **`test_compare_mode_does_not_overwrite_schema_json`** — create a variant, `refresh_from_db()`, assert original `schema_json` unchanged
2. **`test_variant_row_created_with_correct_fields`** — assert all fields (provider, model, input_tokens, cost_usd, latency_ms, created_at) stored correctly
3. **`test_variant_unique_constraint_enforced`** — second insert with same (posting, provider, model, prompt_version) raises `IntegrityError`

---

## Verification

```bash
# Apply migration
.venv/bin/python webapp/manage.py migrate

# Run tests
.venv/bin/python -m pytest webapp/tests/test_llm_schema_variants.py -v

# Test compare mode on 2 postings (reads from DB, writes variants only)
.venv/bin/python llm-schema.py --compare --limit 2 --slug inspector

# Check results in DB
psql $DATABASE_URL -c "SELECT provider, model, input_tokens, output_tokens, cost_usd, latency_ms FROM jobs_jobpostingschemavariant ORDER BY created_at DESC LIMIT 10;"

# Check via Django admin → Job postings → any posting → Schema variant inline
```

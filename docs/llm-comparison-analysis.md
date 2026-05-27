# LLM Provider Comparison Analysis
**posturi.gov.ro — structured extraction pipeline**
_Last updated: 2026-05-27_

---

## Context

The pipeline sends each job posting (body markdown + optional attachment text) through an LLM to extract 12 structured fields aligned to Schema.org `JobPosting` properties plus three Romania-specific keys. The extraction runs in `--compare` mode: every enabled model is called on the same postings, results are stored in `jobs_jobpostingschemavariant`, and the winner becomes the default for production `schema_json`.

**Prompt v2** (current) improved on v1 by:
- Splitting the generic `qualifications` blob into separate `educationRequirements`, `experienceRequirements`, `qualifications`
- Adding structured `baseSalary`, `application_fee`, `application_contact` objects
- Stripping HG 1.336/2022 boilerplate before the API call (`boilerplate.py`: 13–25% input reduction)
- Two few-shot examples embedded in the system prompt (cacheable prefix)

The benchmark run was: `python llm-schema.py --compare --prompt-version v2 --limit 5 --force` on the 4 enabled models.

---

## Models Evaluated

| Provider | Model | Status | Input $/M | Output $/M | Cache $/M |
|---|---|---|---|---|---|
| OpenAI | GPT-5 Nano | ✅ Enabled | $0.05 | $0.40 | $0.005 |
| Google | Gemini 2.5 Flash | ✅ Enabled | $0.10 | $0.40 | $0.010 |
| DeepSeek | DeepSeek-V4-Flash | ✅ Enabled | $0.14 | $0.28 | $0.028 |
| OpenAI | GPT-4o Mini | ✅ Enabled | $0.15 | $0.60 | $0.075 |
| Anthropic | Claude 3.5 Haiku | ⛔ Disabled | $0.80 | $4.00 | — |
| Anthropic | Claude 3 Haiku | ⛔ Disabled | $0.25 | $1.25 | — |
| OpenAI | GPT-4o | ⛔ Disabled | $2.50 | $10.00 | — |

Claude 3.5 Haiku and GPT-4o were disabled before the benchmark — cost profiles too high for a pipeline running over 4,357 postings. They remain in `models_config.json` for reference.

---

## Cost Benchmark (per 1,000 postings, prompt v2, boilerplate stripped)

| Rank | Model | Cost / 1,000 posts | Full backfill (4,357 posts) |
|---|---|---|---|
| 🥇 | GPT-5 Nano | **$0.36** | ~$1.57 |
| 🥈 | Gemini 2.5 Flash | $0.61 | ~$2.66 |
| 🥉 | DeepSeek-V4-Flash | $0.70 | ~$3.05 |
| 4th | GPT-4o Mini | $0.88 | ~$3.83 |

**Key finding:** All four models cost less than $4 to process the entire 4,357-posting corpus. Cost is not a deciding factor — quality and reliability are.

---

## Cache Effectiveness

Prompt v2 separates the long system prefix (field definitions + 2 few-shot examples, ~1,800 tokens) from the per-posting content. This prefix is sent once and cached; subsequent calls reuse it at a 10–20× lower rate.

Observed cache hit rates from the second call onward:

| Model | Cache Hit Rate |
|---|---|
| GPT-4o Mini | ~94% |
| DeepSeek-V4-Flash | ~99% |
| GPT-5 Nano | implicit (consistent system message) |
| Gemini 2.5 Flash | `system_instruction` path (always cached) |

DeepSeek shows the highest cache utilization (~99%), which explains why its effective cost is competitive despite having a higher nominal input rate than GPT-5 Nano.

---

## Output Quality

All four enabled models validated successfully against the `JobPostingExtraction` Pydantic schema on all 5 benchmark postings. No malformed JSON, no missing required keys.

### GPT-5 Nano — 🥇 Cheapest
- Requires `reasoning_effort=minimal` + `max_completion_tokens` to prevent the reasoning budget from eating the extraction output
- Strict JSON schema mode works natively (OpenAI strict)
- No quality regressions observed on the benchmark sample

### Gemini 2.5 Flash — 🥈 Runner-up
- Uses `response_schema` for native structured output (not parsing)
- `system_instruction` path provides implicit caching
- Model ID must be `gemini-2.5-flash` (not `gemini-2-5-flash` — dots, not dashes)
- Reliable output; previously used as the sole production model before multi-provider comparison

### DeepSeek-V4-Flash — 🥉 Third
- Highest cache hit rate in the batch (~99%)
- Uses `json_object` mode (not strict schema), so output fidelity relies more on the prompt
- Cheapest output token rate ($0.28/M vs $0.40/M for the others)
- Effective cost competitive despite higher nominal input rate

### GPT-4o Mini — 4th
- Most expensive of the four enabled models ($0.88/1,000 posts)
- ~94% cache hit rate
- Full strict JSON schema compliance
- No quality advantage over GPT-5 Nano in this extraction task to justify the premium

---

## Quality vs v1

Prompt v2 on a representative posting (id 4437, muncitor calificat):

| Field | v1 output | v2 output |
|---|---|---|
| `qualifications` | ~2,300 chars of HG 1.336/2022 boilerplate | 76–295 chars: _"Nivel de acces la informații clasificate: Secret"_ |
| `educationRequirements` | bundled into `qualifications` | _"Școală profesională sau curs de calificare."_ |
| `experienceRequirements` | bundled into `qualifications` | _"Experiență minim 9 ani."_ |
| `application_contact` | missing | `{name, phone, email, address}` object |
| `baseSalary` | sometimes hallucinated from `taxa de concurs` | null (protected by explicit anti-pattern rule) |

The boilerplate stripping (HG 1.336/2022 removal) reduces input by 13–25% and eliminates the main quality problem in v1: `qualifications` being 80% legal-citation noise on every posting.

---

## Structured Output API Differences

Each provider exposes native structured output differently — all wired in `llm-schema.py`:

| Provider | Mechanism | Notes |
|---|---|---|
| OpenAI | `response_format={"type":"json_schema", "json_schema":{...}}` strict mode | `openai_json_schema()` mutates Pydantic schema to add `additionalProperties:false` + explicit `required` lists on every object |
| Gemini | `generation_config={"response_schema": ..., "response_mime_type": "application/json"}` | Uses `genai.types.Schema` conversion of the Pydantic model |
| Anthropic | Tool-use: single tool `extract_job_posting` with `input_schema` from Pydantic | LLM is forced to call the tool; the tool `input` is the structured output |
| DeepSeek | `response_format={"type":"json_object"}` (loose) | Falls through to JSON parsing; schema enforced by prompt only |

---

## Recommendation

**Primary recommendation: GPT-5 Nano** (`gpt-5-nano`) for production backfill.
- Cheapest by 40% over Gemini, 51% over DeepSeek, 59% over GPT-4o Mini
- Full strict JSON schema compliance (no silent field drift)
- Only requirement: `reasoning_effort=minimal` + `max_completion_tokens` in the API call (already wired in `llm-schema.py`)
- Full 4,357-posting backfill costs ~$1.57

**Fallback recommendation: Gemini 2.5 Flash** (`gemini-2.5-flash`)
- Already used as the sole production model before multi-provider comparison
- Proven reliability on Romanian government text
- `response_schema` native structured output (not prompt-reliant)
- Full backfill costs ~$2.66

**DeepSeek-V4-Flash** is a viable budget option but the loose `json_object` mode is a reliability risk at scale — one malformed response in 4,357 breaks a posting. The cache hit rate is excellent but doesn't compensate for the lack of strict schema enforcement.

**GPT-4o Mini** offers no quality advantage over GPT-5 Nano in this extraction task and is the most expensive enabled model. No case for it as primary.

---

## Next Steps

1. **Promote GPT-5 Nano to default** — set `PROMPT_VERSION = "v2"` and `DEFAULT_PROVIDER = "openai"` / `DEFAULT_MODEL = "gpt-5-nano"` in `llm-schema.py`
2. **Run full backfill** — `python llm-schema.py --provider openai --prompt-version v2 --force` (~$1.57, ~4,357 calls)
3. **Spot-check in UI** — view 10 random postings in the detail page to confirm `educationRequirements`, `baseSalary`, `application_contact` render correctly
4. **Enable Claude 3.5 Haiku for quality comparison** (optional) — once the production corpus is backfilled, a spot-check on 20–50 postings would quantify whether the 8× cost premium buys measurable extraction quality
5. **Consider DeepSeek for async/batch processing** — the ~99% cache hit rate makes it attractive for high-volume nightly refresh if the strict-schema risk is mitigated by post-hoc Pydantic validation

---

## Configuration Reference

`models_config.json` — `providers` section controls which models run in `--compare` mode:

```json
"enabled": true   // participates in --compare runs
"enabled": false  // skipped; kept for reference pricing
```

Costs are in USD per million tokens. `cache_input_cost_per_million` is the rate for cache-hit input tokens (OpenAI: 50% of input; Gemini: 10× cheaper; DeepSeek: 20% of input). Anthropic doesn't have a `cache_input_cost_per_million` entry because the Haiku models are disabled — add it when re-enabling.

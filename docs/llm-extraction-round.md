# The LLM extraction round: what it costs, what it wastes, what to fix

> Status: **measured 2026-09-08** on `gemini-2.5-flash` + prompt v3, against the
> live `posturi_dev` database (9,603 postings, 1,278 with v2 `schema_json`, 27
> with a v3 variant). Numbers below are observed, not estimated. The retry,
> concurrency, resume, grounding and derived-field work described in
> "What changed" is built; everything under "Still open" is not.

## The shape of one call

| | |
|---|---|
| v3 system prompt | **6,960 tokens** |
| Posting body + attachment | median 14,169 chars, p90 27,629, max 120,180 |
| Typical request | `in≈8,600  out≈1,400` |
| Cost per posting | ~$0.0015 cold, ~$0.0008 with a cache hit |
| Latency per posting | ~15 s (p90 closer to 30 s) |
| Full 9,603-posting run | **~$15 and ~40 h** strictly sequential; ~14 h at `--workers 4` |

The first line is the one that governs prompt design: **the static system prompt
is ~81% of a typical request's input.** The per-posting body is the small half.

## Caching: real, but not something to budget for

The v3 prompt clears every provider's minimum cacheable prefix by a wide margin,
and the code already sends it as a stable prefix — Gemini `system_instruction`,
OpenAI system message, Anthropic `system` with a `cache_control` breakpoint.

It does get cached. Observed on a repeat run:

```
in=8909 cached=7092 out=1467 $0.000839   ← hit, billed at 10% of input rate
in=8358              out=807  $0.001159   ← miss
in=8620              out=973  $0.001251   ← miss
in=8337              out=1435 $0.001408   ← miss
in=6122              out=434  $0.000786   ← miss
in=11849             out=1309 $0.001708   ← miss
```

**One hit in six.** Gemini's implicit cache is best-effort and a ~15 s gap
between calls is evidently enough to lose it. Two consequences:

- Do not plan around implicit caching. If the prefix discount matters for the
  full backfill, use Gemini **explicit** caching (`client.caches.create` with a
  TTL), which guarantees it. Anthropic's `cache_control` breakpoint is the
  equivalent and is already wired; its 5-minute TTL refreshes on every hit, so a
  continuous run stays warm on its own.
- Cost accounting was under-reporting the benefit: `compute_cost()` falls back to
  the full input rate when `cache_input_cost_per_million` is absent from
  `models_config.json`, and it was absent on **both Anthropic models and
  `gpt-4o`**. The Anthropic branch has been paying for cache writes and getting
  cache reads all along without ever showing the discount. Fixed.

### What this means for prompt size

At ~$0.0015 per posting, 1,500 extra tokens of static prompt costs **~$1.40
across the entire 9,603-posting corpus with zero cache hits** — about $0.14 with
explicit caching. Prompt size is not a constraint at this scale. Wall-clock is.

## Controlled vocabularies: worth it for `skill_list`, but soft ones

The question that prompted this: would giving the model a list of allowed values
for `skill_list` and the domain fields improve the extraction?

**For `skill_list`, yes, and the current output shows why.** The 2,664 skill
bullets across the existing v2 extractions are **66% distinct**, and it takes
**1,214 distinct strings to cover 80% of occurrences**. That is not a facet, it
is a pile. Free text does not aggregate.

A 30-tag seed list written by hand already word-boundary-matches 31% of them:

```
240  comunicare       68  operare PC          46  corectitudine
125  Word             62  analiză și sinteză  42  responsabilitate
122  Excel            59  Microsoft Office    39  rezistență la stres
112  relaționare      57  muncă în echipă     37  flexibilitate
```

Three design constraints follow.

**Soft list in the prompt, not a Pydantic `Literal`.** A closed enum forces every
unlisted competence into a wrong bucket or drops it, and the tail is where the
signal is — "FOREXEBUG", "SICAP", "autorizație ISCIR" are precisely the tags that
make a posting findable. Phrase it as *"if a competence matches one of these
labels use that exact string, otherwise emit your own short tag"*. That
consolidates the head without truncating the tail, and it sidesteps OpenAI strict
mode's enum limits (past 250 values, total enum string length must stay under
15,000 chars).

**Mine it from the data, do not invent it.** Run v3 free-form over a stratified
sample of ~500 postings, count the labels, keep everything above a frequency
floor, curate by hand, ship as v3.1. Keep `evidence` in the schema either way —
it is what makes re-normalising old rows against a revised vocabulary possible
without paying for extraction again.

**Where enums are already right, they are already there.** `policy_domains`,
`isced_field`, `exam_stages`, `credentials.kind` and `cefr` are closed and should
stay closed. The remaining free-text fields split cleanly:

| Field | Verdict |
|---|---|
| `skill_list[].label` | Soft list. Highest value. |
| `fields_of_study[].label_ro` | Soft list, ~60 Romanian study fields. High CV-match value. |
| `occupation_title` | Wants COR (Clasificarea Ocupațiilor din România), but ~4,000 codes is a second-stage offline mapping, not a prompt. |
| `experience.domain` | Low volume; leave free. |
| `bibliography_topics` | High cardinality, low match value; leave free. |

### One vocabulary, not two

`infer_postings.py` already carries `FAMILIES`, `_SKILLS_KW`, `_LANGUAGE_RE` and
`_CERT_RE` — a hand-built vocabulary for the same concepts on the deterministic
side. Adding a second one inside the prompt would give the same facet two
disagreeing sources of truth. `judete.py` is the precedent worth copying: one
module owns normalisation, and the prompt, the regex inference and the webapp
facet labels all read from it.

## What changed (2026-09-08)

- **Retry.** There was none. A `ValidationError` or a 429 printed `✗` and dropped
  that posting permanently — at ~9,600 calls per model both are certainties.
  Transient errors (429/5xx/timeout, classified per-SDK by status code then by
  exception class name) now back off exponentially with jitter; invalid output
  gets exactly one repair attempt that appends the validation error to the *user*
  message, leaving the cached system prefix byte-identical.
- **Concurrency.** `--workers` (default 4) runs the LLM calls in a bounded thread
  pool while every database write stays on the calling thread — one psycopg
  connection is not safe to share, and `write_variant` commits per row. The
  iterator is consumed lazily, so a 9,600-row cursor is never materialised to
  start work. Measured on 8 real postings: **5.13 s/post at 4 workers** against
  15-20 s each sequentially, i.e. ~14 h for the full corpus rather than ~40 h.
- **Resume.** `--resume` excludes postings that already have a variant row for the
  exact provider/model/prompt-version, in SQL rather than by filtering the
  generator, so the progress total and the iteration cannot drift apart. Before
  this, an interrupted `--compare` restarted from zero.
- **Grounding check** (`grounding.py`). Every v3 requirement already carries the
  phrase it came from — `evidence` on a skill, `verbatim` on education and
  experience. Comparing those against the source text catches invented
  requirements with no second LLM call. A quote passes if 60% of its content
  words appear in the source *or* any four consecutive content words appear
  consecutively — the second rule matters because coverage punishes length
  asymmetrically, so a long real span with a short invented lead-in would
  otherwise score like a fabrication. All 30 quotes in the 11 real v3 extractions
  pass; injected fabrications score 12–14%.
- **Derived fields.** `eqf_level` is a pure function of `minimum_level`, and
  `STUDY_LEVEL_TO_EQF` already existed in `schema_models.py` — unused, while the
  prompt asked the model to do the lookup. Both it and `iso_code` are now derived
  in `model_validator`s. Asking for the same fact twice can only introduce
  disagreement.

## Still open

Ranked. Detail and context in `docs/backlog.md` § "LLM round".

1. **Explicit prompt caching**, since implicit hits 1 in 6.
2. **Bootstrap the `skill_list` vocabulary** from a stratified sample, ship v3.1.
3. **Batch APIs are ~50% off** on all four providers — a bigger lever than caching
   for a one-off backfill, at the cost of latency and weaker cache interaction.
4. **Anthropic cache *writes* cost 1.25×** and are not modelled: `compute_cost()`
   reads `cache_read_input_tokens` but ignores `cache_creation_input_tokens`.
5. **Content truncation is a hard cut at 100,000 chars**, which lands mid-document
   on the longest postings.
6. **Cheap-then-expensive routing**: flag contradictory or suspiciously empty
   extractions deterministically, re-run only those on a stronger model.

## The thing that is already built and underused

`--compare`, `prompt_version` and the variants dashboard exist and currently only
compare *providers*. The same machinery answers "does the vocabulary help?" —
run v3 against v3.1 over the same 100 postings and read the difference. Every
question in this document is measurable with what is already here.

# Metadata schema v3 — granular extraction for CV-to-job matching

> Status: **first run done on 5 postings (Gemini 2.5 Flash, 2026-09-07); prompt
> revised from what it showed.** OpenAI and DeepSeek could not run — no credits
> — so cross-provider agreement is still unmeasured. The revised prompt has not
> been run.

## Why a v3

v2 answers *"what does this posting say?"* — it returns readable Romanian prose
under Schema.org property names. That is the right shape for a detail page and
the wrong shape for a filter.

The gap shows in one line from a real posting:

> Studii universitare de licență absolvite cu diploma de licență sau echivalentă
> în domeniul **geografie/ silvicultură/ geologie/ biologie/ știința mediului/
> inginerie geodezică**.
> Cunoștințe **avansate** de operare sisteme **GIS** (lucrul cu baze de date,
> elaborare și actualizare hărți tematice, analiză spațială, integrare date
> geospațiale).

v2 stores that as two strings. Nothing can be filtered from them: not "show me
jobs open to a biology graduate", not "show me jobs needing GIS", not "advanced
level". A geologist cannot discover that this posting is open to them.

v3 keeps both strings **and** adds a structured layer beside them:

```json
"education": {
  "minimum_level": "licenta", "eqf_level": 6,
  "fields_of_study": [
    {"label_ro": "geografie",           "isced_field": "05_stiinte_naturale_matematica"},
    {"label_ro": "silvicultură",        "isced_field": "08_agricultura_silvicultura_veterinara"},
    {"label_ro": "inginerie geodezică", "isced_field": "07_inginerie_constructii"}
  ]
},
"skill_list": [
  {"label": "GIS", "type": "knowledge", "proficiency": "avansat", "required": true,
   "evidence": "Cunoștințe avansate de operare sisteme GIS"},
  {"label": "analiză spațială", "type": "skill", "required": true}
]
```

The rule throughout: **anything a filter or a CV comparison depends on gets a
controlled value, and the original Romanian is kept in a `verbatim` / `evidence`
field for display.** Free text cannot be matched; enums can.

## How Schema.org JobPosting relates to Europass

They solve mirror-image problems and meet in the middle at the EU reference
vocabularies. Neither replaces the other.

| | Schema.org `JobPosting` | Europass CV |
|---|---|---|
| Describes | the **vacancy** | the **person** |
| Purpose | search-engine markup, job aggregation | portable, comparable qualifications |
| Consumer | Google Jobs, aggregators | employers, EURES, EPSO |
| Shape | flat properties, mostly free text | sectioned, vocabulary-backed |
| Vocabularies | almost none (`employmentType` is the exception) | EQF, ISCED-F, CEFR, ESCO, DigComp, ISCO |

Schema.org is deliberately loose: `educationRequirements` is "text or
`EducationalOccupationalCredential`", which search engines can index but nothing
can compare. Europass is deliberately strict, because its entire point is that a
diploma from one member state can be understood in another.

**A matching system needs both**: Schema.org so the posting stays indexable and
Google-Jobs-eligible, Europass vocabularies so the posting and a CV become
comparable. So v3 does not choose — it emits Schema.org property names at the
top level and hangs EU-vocabulary values underneath them.

### The four vocabularies that do the work

| Vocabulary | Europass section | v3 field | Why this one |
|---|---|---|---|
| **EQF** 1–8 | Education | `education.eqf_level` | The one number that makes "licență" comparable to any European qualification. Romanian levels map cleanly: generală 2, profesională 3, liceală 4, postliceală 5, licență 6, master 7, doctorat 8. |
| **ISCED-F 2013** broad fields | Education → Field of study | `education.fields_of_study[].isced_field` | Turns "geografie/silvicultură/geologie" into three matchable codes. A CV's field of study resolves to the same 11 codes. |
| **CEFR** A1–C2 | Language skills | `language_list[].cefr` | Europass already stores language levels this way; postings say "nivel avansat", which maps to C1. |
| **ESCO** skill pillars | Skills | `skill_list[].type` | `knowledge` / `skill` / `transversal` / `language` — ESCO's own top-level split, so tags can later be resolved to ESCO URIs rather than to an invented taxonomy. |

`policy_domains` has no Europass analogue: it is the *subject matter* of the
work, borrowed from the `Domenii` facet on
[cariere.gov.md](https://cariere.gov.md/ro/search?active=1), whose 33 domains
(achiziții publice, securitate energetică, protecția consumatorului …) separate
*what field the work is in* from *what job it is*. Our `profession_family`
captures only the latter; a lawyer in an environment agency is `drept_justitie`
**and** `mediu`, and someone searching either way should find them.

## Field map

Descriptive fields are unchanged from v2 — v3 inherits them, so every existing
page, feed and renderer keeps working on a v3 row.

| v3 field | Schema.org | Europass / vocabulary | Enables the filter |
|---|---|---|---|
| `education.minimum_level` | `educationRequirements` | EQF via `eqf_level` | "Studii minime" (already exists, currently inferred by regex) |
| `education.eqf_level` | — | **EQF 1–8** | CV level ≥ posting level |
| `education.fields_of_study[]` | — | **ISCED-F 2013** | **"Domeniu de studii"** — new, the GIS case |
| `education.specialization` | — | — | free-text refinement |
| `experience.years_minimum` | `experienceRequirements` | Europass work experience | "Experiență" (exists; v3 removes the age/tenure ambiguity) |
| `experience.in_specialty` | — | — | distinguishes tenure from relevant tenure |
| `skill_list[].label` | `skills` | ESCO concept (future URI) | **"Competențe"** — new |
| `skill_list[].type` | — | **ESCO pillar** | knowledge vs ability vs soft |
| `skill_list[].proficiency` | — | DigComp-like 3-level | "nivel avansat" as a filter |
| `language_list[].cefr` | — | **CEFR** | **"Limbi străine"** — new |
| `credentials[]` | `qualifications` | Europass certificates / driving licence | **"Permise și autorizații"** — new, and the hardest filter (you hold it or you don't) |
| `contract.*` | `employmentType`, `workHours` | — | normă, durată, telemuncă, ture — structured at last |
| `policy_domains[]` | `occupationalCategory` | — (cariere.gov.md) | **"Domeniu de activitate"** — new |
| `occupation_title` | `title` | ESCO occupation (future) | dedupe and "same role elsewhere" |
| `seniority_hint` | — | — | grade filter, currently regex-inferred |
| `exam_stages[]` | — | — | **"Etape concurs"** — new |
| `bibliography_topics[]` | — | — | **"Tematică"** — new |

## What this unlocks, in order

1. **Now** — filters that cannot exist today: field of study, named competence,
   proficiency, language + CEFR, licence held, exam stages, subject domain.
2. **Next** — the reverse query. Given `{eqf_level, isced_fields, skills,
   languages, credentials, years}` from a form, rank postings by how many hard
   requirements are met. No CV needed; the same shape a CV would produce.
3. **Then** — Europass CV upload. Europass exports XML/JSON already carrying
   EQF, ISCED-F, CEFR and ESCO values, so the CV parses straight into the same
   structure and matching is a set comparison, not an NLP problem. That is the
   payoff for choosing EU vocabularies over convenient local ones.

## Cost

The v3 system prompt is 17,342 characters against v2's 6,355 — roughly 5k
tokens vs 1.9k (12,377 before the multi-role fix). It is byte-identical on every call and therefore cacheable, and
in any case the per-posting body dominates at 6,000–9,000 tokens. Expect
something under a 15% increase per posting, less if prefix caching is ever made
to work (see the Gemini caching item in `docs/backlog.md`).

The JSON Schema sent for structured output grows from 6.2 KB to 19.2 KB, which
is the more real cost on providers that count it as input.

## Implementation

| Piece | Where |
|---|---|
| Prompt | `models_config.json` → `prompts.v3` |
| Models + vocabularies | `schema_models.py` (`JobPostingExtractionV3` and the `Literal` enums) |
| Version → model registry | `schema_models.EXTRACTION_MODELS`, `model_for_version()`, `json_schema_for()` |
| Provider wiring | `llm-schema.py::make_generator` — structured output for any version in the registry |
| Tests | `webapp/tests/test_extraction_v3.py` (26) |

Run it with:

```bash
python llm-schema.py --prompt-version v3 --active-only --limit 20
```

Compare against v2 on the same postings before committing to a backfill:

```bash
python llm-schema.py --compare --prompt-version v3 --active-only --limit 5
```

`--compare` writes to `JobPostingSchemaVariant` only and never overwrites
`schema_json`, so it is safe to run against production data.

## What the first run showed

Five postings through `--compare --prompt-version v3 --active-only --limit 5`.
Gemini completed all five; OpenAI returned 429 (no credits) and DeepSeek hit a
bug of mine (a rename left three call sites pointing at `_validate_v2`, fixed,
with a test that now catches undefined names in any provider branch).
`schema_json` was untouched at 1,278 rows, so `--compare` behaved as promised.

**Worked, and these were the open questions:**

- **`fields_of_study` really does split.** One posting yielded seven
  alternatives across three ISCED fields — psihologie → 03, asistență socială →
  09, științe administrative → 04. This was the whole point of v3 and it holds.
- **`credentials` is the strongest field.** "certificat de membru OAMGMAMR",
  "aviz anual pentru autorizarea exercitării profesiei", "permis categoria B",
  "asigurare malpraxis" — exactly the hard yes/no filters.
- **`contract` is richer than expected**: `duration_months: 33`,
  `shift_work: true`, `hours_per_week: 40`.
- `experience` handled "6 luni" as `0.5` with `in_specialty: true`.

**Broke, and the prompt has been revised for each:**

| Problem | Seen | Fix |
|---|---|---|
| **Multi-role postings returned all-nulls** | 2 of 5 | New `positions[]` array + Example 3 |
| `fields_of_study` labels were adjectives or filler — `juridic`, `medical`, `specialitate`, `sanitară` | 3 of 5 | "Normalise to the name of the field, not the adjective"; explicit ban on filler |
| `bibliography_topics` over-extracted — 37 entries, some genitive fragments | 2 of 5 | Cap at 12, nominative form |
| `credential.kind` fell back to `altele` for things with a home | 1 of 5 | Expanded per-kind guidance |

The multi-role case is the substantial one. **8.4% of active postings (143 of
1,710)** advertise several distinct roles — "1 post de expert IT senior; 2
posturi de expert administrație publică I" — usually with *different*
requirements (3 years of tenure vs 7). A flat extraction cannot hold two
contradictory requirement sets, and rather than pick one the model nulled out
education, skills, occupation and seniority, losing a posting that plainly
stated "Cerințe specifice: Cunoașterea procesului de utilizare a fondurilor
europene…". `positions[]` now carries one entry per role, while the flat fields
keep describing the first role so a consumer that ignores `positions` still gets
something real.

One correction to an earlier reading: `skill_list` looked under-extracted (1, 1,
0, 0, 0 across the five). It is not. A substring count of "abilit" suggested
21 mentions in one posting, but every one was inside *dizabilități*,
*reabilitarea* or *unitățile sanitare abilitate* — the same substring trap that
made "SAR" a skill on 87% of postings. Counted on word boundaries, three of the
five postings contain no competence list at all, and the empty result is
correct. The one genuine miss was the multi-role posting above.

## Open questions

- **Should `skill_list` labels be resolved to ESCO URIs?** ESCO has 13,939 skill
  concepts with Romanian labels. Resolving locally against a downloaded ESCO
  dump would beat asking the LLM for a URI it will hallucinate. Worth doing once
  there is a body of extracted labels to resolve.
- **`policy_domains` is ours, not a standard.** cariere.gov.md's 33 domains were
  compressed to 24. If an EU-level equivalent exists (NACE is about employers,
  not jobs), switching would be better than maintaining a local list.
- **ISCED-F assignment is the LLM's judgement.** "inginerie geodezică" could
  defensibly be 07 (engineering) or 05 (natural sciences). Worth measuring
  agreement across providers with `--compare` before trusting it for filtering.
- **Grandfathering.** 380 active postings hold v2 `schema_json`. Re-running v3
  over them costs a full extraction each; the v2 output is a strict subset, so
  there is no migration path short of re-running.

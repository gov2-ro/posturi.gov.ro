# BETA-V5 — what a v5 extraction prompt should add, drop and change

Analysis for the beta-weekend decisions D19/D24 (`docs/backlog.md` § Beta weekend).
It is an analysis, not an implementation: no code, prompt or config was changed.
Written 2026-10-16. pax decides, and the filter redesign (D4, BETA-FILTERS) builds on it.

**Data used.** The Mac's Postgres is a stale copy (newest posting 2026-09-09), so "recent"
here means postings published 2026-08-15 … 09-09. That is **1,708 postings** with a body
(corpus keyword counts), and a **stratified sample of 40** drawn from them with a fixed
seed (`samples/sample.py`, list in `samples/sample-index.md`). The strata are hospitals 8,
primării and local services 6, schools 5, central bodies 5, social services 4,
military/order 4, culture 3, management contests 2 and multi-role postings 3. No police
posting fell into the window: the portal carries few. Output-size and fill-rate figures
come from the **22 v4 and 124 v3 DeepSeek variants** in the local
`jobs_jobpostingschemavariant` table.

**Paid calls: 8, total $0.0201.** Four postings were run through v4 and through the draft
v5 prompt with a standalone script (`run_sample.py`). It reads local files, calls DeepSeek
directly and writes nothing to any database. Outputs are in `samples/*.json` and costs in
`samples/usage.jsonl`.

## 0. Headline findings

1. **The posting rarely states pay.** A salary figure appears in about **0.5%** of
   postings, and a percentage-bearing *spor* in 0%. So "sort by salary" and "salariu net"
   (261016 notes) must work from the grid estimate: net from gross is arithmetic, not
   extraction (§1.9). `baseSalary` and `jobBenefits` stay because they are cheap nulls,
   but they will not feed a filter.
2. **v4 invents three things the posting does not say:**
   - `contract.remote_mode = "la_sediu"` in 7 of 22 v4 variants, although "telemuncă"
     occurs in **0** of 1,708 postings.
   - `employer_context.parent_institution = "Ministerul Sănătății"` for county and
     municipal hospitals, which answer to a county or local council (SJU Reșița, Bihor,
     Constanța, Dorohoi in the variants).
   - `contract.duration = "nedeterminata"` for a hospital **manager** contest (both v4 and
     v5, sample 36).

   Drop the first two and add a rule for the third.
3. **Four v4 keys cost output and nobody reads them:**
   - `note_suplimentare` is filled in **95%** of v4 rows, mostly restating other keys
     ("Post temporar vacant…", "0,75 normă", "Tematica se afișează la avizier…"), and has
     **zero consumers** in the PHP or the export.
   - `educationRequirements`, `experienceRequirements` and `skills` duplicate
     `education.verbatim`, `experience.verbatim` and `skill_list[].evidence`.

   Together with the dosar list, these are the cheapest output savings (§2).
4. **About 20% of v4 output is spent on calendar events that the site never shows.** The
   detail page and the checklist render the *regex* calendar
   (`calendar_events`, `export-to-sqlite.py:607-625`, `webapp-php/pages/detail.php:32`).
   Only `application_deadline` is used from the LLM calendar. Rendering the typed events
   as the one normalised calendar table answers the 261016 "render tables" note (§1.12).
5. **The new fields worth adding are about who can apply and how:**
   - promotion / internal-only postings
   - fraction of a norm and end date
   - the post's own "temporar vacant" status
   - the total number of seats (this fixes DATA-02's project-code-as-`nr_posturi` bug)
   - submission channels (email or post: you need not travel)
   - explicit age limits
   - work localities (feeds UX-02)
   - document-level evidence quotes and role scoping (unblocks UX-10, UX-11, UX-13)
6. **The draft v5 cut output tokens by 20% (11,922 → 9,593 on the same 4 postings) while
   adding all of the above.** Per posting the change was −36%, −30%, −15%, and +0.5% on
   the multi-role posting, which now lists every role. 25 of 27 v5 evidence quotes
   verified with `evidence.locate`. The 2 that failed were the 2-word quote
   "aviz psihologic;", rejected as `too_short` (§3.4).
7. **85% of postings with attachment text repeat the page body almost verbatim** (median
   86% of the body's 6-grams recur in the attachments). Dropping or deduplicating the body
   removes about **35% of posting input**. Keep the attachment's copy, not the body's: in
   sample 40 the portal body lost the day of the written exam ("Proba scrisă: 09.2026"),
   and only the attachment has "30.09.2026".

## 1. Candidate new fields

Prevalence uses two denominators. `n/40` counts keyword hits in the sample, hand-checked
where marked ✓. `% of 1,708` is a keyword count over the recent corpus. Keyword counts
are an upper bound: boilerplate and bibliography matches inflate them. For example
"promovare" hits 10/40, but only 1/40 (sample 20) is a promotion exam ✓.

| # | Field (proposed type) | n/40 | % of 1,708 | User value | Difficulty / risk |
|---|---|---|---|---|---|
| 1.1 | `recruitment.kind`: `recrutare`\|`promovare`\|`management`\|`rezidentiat`\|`altele`; `recruitment.internal_only`: bool\|null | promotion 1 ✓, management 2 ✓ | promotion ~4% (title/head), management 0.5%, rezidentiat 2.2% | **Filter / default exclusion.** A promotion exam is closed to outsiders, yet today it shows in the default "can apply" view. | Low. Titles and openings say it explicitly. |
| 1.2 | `vacancies_total`: int | 12/40 multi-seat or multi-role | 6.7% have ≥2 seats in the title | Stats (seats, not announcements), sorting, "most offered jobs". **Fixes DATA-02**, where a project code read as `nr_posturi`. | Low. The prompt forbids codes and article numbers. Trial: 4 of 4 correct. |
| 1.3 | `contract.fte`: 0.25–1.0 | 7/40 | ~6% fraction + ~9% reduced hours/day | **Filter** ("part-time"). Matters for students, second jobs and pensioners. | Low–medium. "84 ore/lună" gave `hours_per_week` 19.5 but `fte` null; a deterministic `hours_per_week/40` fallback fixes it. |
| 1.4 | `contract.end_date`: ISO date | 0/40 by keyword (sample 17 ✓ by hand) | ~3% "determinată … până la <data>" | Detail; filter combined with duration ("at least 12 months"). | Low. |
| 1.5 | `contract.post_status`: `vacant`\|`temporar_vacant` | 6/40 | **8%** own status; the generic "vacant sau temporar vacant" phrase is in 33.5% and must be ignored | **Filter / badge** ("replacement, ends when the holder returns"). | Medium. The generic phrase is a trap; the rule names it. Trial correct 4 of 4. |
| 1.6 | `application.channels` ⊆ {`sediu`,`posta`,`email`,`online`}; `application.window_start` | email ~4 ✓, post/courier 6 | email ~17%, post/courier ~16%, in person ~90% | **Filter** ("you can apply without travelling"). | Low–medium. The text has to *allow* the channel, not merely list an address. Many e-mails are contact or notification addresses (HG 1.336 asks for one for result notices). |
| 1.7 | `age_limit`: {`min_years`,`max_years`,`verbatim`} | 1/40 | ~4.6% | Detail; matching (UX-07). | Low. Mostly the "not past standard retirement age" rule for managers. |
| 1.8 | `work_localities`: [string] | 3/40 | ~14% name a work point or locality | **UX-02 locality filter**, county-wide employers (DGASPC, ambulance substations, hospital sections in other towns). | Medium. The trial put the employer's own seat in it once ("Matca"). Add the rule "not the employer's seat". |
| 1.9 | Salary details (base vs total, sporuri, **net**) | 1/40 | figure 0.5%, *spor* with % 0% | Sort by salary and net display use the **estimate**. | **Not an extraction task.** Compute net from gross in the export or PHP, using a dated, versioned tax table (CAS 25%, CASS 10%, income tax 10% after the personal deduction; verify the 2026 rules before shipping). Label it "estimat". |
| 1.10 | `credentials[].evidence` (verbatim ≤20 words), `credentials[].licence_category` | permit 5, membership/practice permit 6, psych assessment 5 | permit category 7.4%, membership/permit ~5–8% | **UX-10, UX-13**: documents are always "De verificat" today because they carry no evidence. The category is a filter candidate ("no driving licence needed"). | Low. Quotes verified in the trial except the too-short ones. |
| 1.11 | `documents`: {`standard`: [codes], `specific`: [{`label`,`evidence`,`roles`}]}, replaces `application_docs` | 38/40 have a dosar list | ~90% | **UX-11 checklist with role scoping**; the standard part becomes a fixed, translated template. Saves 10–15% of output. | Medium. `checklist.php` needs a v5 branch (§6). Trial: codes correct; sample 36 kept the 4 manager-specific documents. |
| 1.12 | Calendar: `events[].relative` for undated rows; the `verbatim` cap; render the events | relative rows 12/40, calendar table 12/40 | relative rules **25%**, a markdown calendar table ~26–39% | **Detail: one normalised calendar table** (261016 note); the checklist; UX-12. | Low. In the trial the "Proba scrisă: 09.2026" date was resolved correctly from the attachment. |
| 1.13 | `positions[]` lists EVERY role, and shared requirements are copied into each | multi-role 3 sampled + others | 7–13% multi-role | **DATA-04**: role-scoped search loses about 17 of 787 licență matches today because a shared requirement is never inherited. v3 left the first role out of `positions` (sample 40: 3 of 4 roles). | Low. Output +0.5% on the multi-role trial posting. |
| 1.14 | `employer_context.subordinated_to` (stated only), replaces `parent_institution` | 2/40 | ~5% state it | Employer grouping; better from the registry (gov2-ro/institutii-publice, backlog "Later"). | Low as "stated only". The guessed version is wrong for hospitals. |
| 1.15 | Shift / night / weekend (`shift_work` true only when stated, else null) | 4/40 | ~1–5% | Detail; weak filter. | v4's default `false` reads as "no shifts" when the posting is silent. Make it null. |

Considered and **not proposed** (prevalence below 2% in 1,708 postings, or already covered):

| Considered | Why not |
|---|---|
| Telework | 0% |
| Meal tickets / vouchers | 0.8% |
| Housing / accommodation | 2%. Keep for `note_suplimentare` (rural doctors). |
| Sex/gender criteria | 0.2%, and already an anomaly flag |
| Online exam | 4.3%; it belongs in the calendar `verbatim` |
| Fitness test | 0.8%; `exam_stages` has it |
| Security clearance | 0.9%; already a credential kind |
| Foreign language | 1.4%; `language_list` exists |
| Exam venue | Almost always the employer's seat |
| Bibliography per role | 7%; `bibliography_topics` is used only on the detail page |

## 2. v4 keys to drop or change (output ≈ 95% of cost)

Share of output is measured over the 22 v4 variants (JSON characters, a proxy for tokens).
Consumers come from a grep of `webapp-php/` (minus tests and vendored libraries), the
export modules and the Django app.

| Key | Filled | Share of output | Consumers | Proposal |
|---|---|---|---|---|
| `competition_calendar` | 95% | **19.6%** | `export-to-sqlite.py:888-895` (deadline only), `posting_facts.py:237-244` | **Keep and render it** (§1.12). Cap `verbatim` at 12 words. |
| `application_docs` | 73% | **14.7%** | `helpers.php:647`, `inc/checklist.php:244`, `detail.php:798` | **Replace** with `documents` codes plus specific items (1.11). |
| `positions` | 9% | 11.8% | `posting_roles.py`, export, eligibility | Keep; make it complete (1.13). |
| `skill_list` | 45% | 10.7% | export, `posting_roles.py`, evidence, facets | Keep. |
| `note_suplimentare` | **95%** | 6.1% | **none** | Tighten to "null in most postings, ≤3 bullets, never restate" (draft), **or drop**. If kept, render it as "De reținut", or nobody sees it. |
| `responsibilities` | 14% | 4.3% | detail | Keep. |
| `educationRequirements`, `experienceRequirements`, `skills` | 100% / 91% / 45% | ≈4.8% together | `helpers.php:643-646`, `detail.php:171-178` (JSON-LD), `posting_facts.py:230-232` (v2 fallback) | **Drop.** Render from `education.verbatim`, `experience.verbatim` and `skill_list`. |
| `contract.remote_mode` | 32% | small | `detail.php:531`, `export-to-sqlite.py:952` | **Drop.** It is invented: 0% of postings mention it. |
| `employer_context.parent_institution` | 77% | small | export column `v4_parent_institution` (`export-to-sqlite.py:895`), **rendered nowhere** (`db.php:89` shim only) | **Drop**; use `subordinated_to` (stated) plus the registry match. |
| `occupation_title` | 100% | 0.3% | none found in PHP or export | Keep; it is cheap and could feed "most offered jobs". |
| `baseSalary`, `jobBenefits` | 0–1% | 0.3% | detail | Keep; nulls cost little. |
| `funding.project_code` / `project_name` | 9% | small | none | Keep: provenance for EU-project posts. Unrendered. |
| `employer_context.uat_type` / `uat_name` | 45% | small | none (`uat.py` parses the employer name instead) | Keep and **wire into the DATA-06 salary band**; this is what they were added for. |

## 3. Prompt optimisation

1. **Cost shape.** Per v5 call in the trial: about 13–15k input tokens, of which the
   ~8.8k system prompt is a DeepSeek cache hit from the second call on ($0.003/M), and
   1.4–3.1k output tokens. Output is ~70–75% of the bill at off-peak list price. Prompt
   length is not the constraint; output length is. The levers, in order:
   - document codes instead of transcribed bullets
   - drop the duplicated free-text keys
   - a short calendar `verbatim`
   - a strict `note_suplimentare`
2. **Input deduplication** (cheap, deterministic, in `iter_postings`, `llm-schema.py:828-841`):
   drop body paragraphs whose 6-grams are ≥80% present in the attachment text, and keep
   the attachment's version. That is about −35% of posting input, roughly −15% of cost,
   with faster calls and less "two versions of the same post" confusion. A v4 note on
   sample "ÎNGRIJITOR Timișoara" literally reports "două variante suprapuse ale aceluiași
   post".
3. **Enum robustness (the FIX-05-STAGES lesson).** One unknown enum value must never void
   a whole extraction:
   - Every closed list gets `altele` or null, and the prompt says "never invent or combine
     codes".
   - In the Pydantic model, a `mode="before"` validator coerces an unknown value to
     `altele`, keeps the raw value in `verbatim`, and logs it to `schema-failures.jsonl`.
     This is the fallback pax rejected for stages on 2026-10-11 (the enum was extended
     instead); revisit it only for the *new* v5 enums (`documents.standard`, `channels`),
     where a miss should not cost the deadline.
   - Prefer provider-native strict schemas where available (OpenAI `json_schema` strict,
     Gemini `response_schema`): DeepSeek's `json_object` mode does not enforce enums.
4. **Evidence strategy.** Every quote must be a character-for-character copy, ≤20 words
   (checked by `evidence.locate`, `fold-alnum-v1`). The trial failures were 2-word quotes
   rejected as `too_short`. Either:
   - (a) ask for "the whole sentence or list item, at least 5 words", or
   - (b) accept a short quote when it equals the label and occurs as a whole list item.

   (a) is a prompt-only change and preferable.
5. **Rule 1 enforcement.** The over-inferred keys were all ones with a natural default
   (`la_sediu`, `shift_work: false`, a ministry for a hospital). Make "null when silent"
   explicit per key, as the draft does. Add: "a manager / management contract is not
   `nedeterminata`; leave `duration` null unless stated".
6. **Examples.** The four v4 few-shot examples still show the removed keys; the draft only
   tells the model to ignore them. The real v5 should rewrite the examples in v5 shape.
   Long examples bias output length upward, so keep them short and show nulls.
7. **Truncation** is no longer the problem it was (16k budget; all 8 trial calls
   `finish: stop`). Still, order the schema so the decision-critical keys come first:
   calendar deadline, education, experience, recruitment, contract.

The draft is `draft-v5-prompt.txt`: v4 plus the edits above, generated from v4
by `build_v5.py` (`python3 build_v5.py draft-v5-prompt.txt`), so every edit is reviewable. **It is a test
draft, not the final prompt.** The examples are not rewritten and the §3.4/§3.5
refinements are not in yet.

## 4. Filters vs detail-only (input for D4 / BETA-FILTERS)

| Good filters (high prevalence × clear yes/no for a candidate) | Detail-only |
|---|---|
| **Cine poate aplica**: hide `internal_only` (promotion) from the default view, with an opt-in. Plain promotion is ~4% of postings, all noise for outsiders. | `documents` (checklist), the calendar table incl. relative rows |
| **Durată**: nedeterminată / determinată (+ "ends after <date>") — 42% / 12% | `age_limit` (rare), `subordinated_to`, `funding.project_*` |
| **Normă**: full / partial (`fte` < 1) — ~6–15% | `note_suplimentare` ("De reținut") |
| **Post temporar vacant** — 8% (badge + filter) | `shift_work` / night (badge, too rare and too fuzzy to filter on) |
| **Depunere fără deplasare** (`channels` ∋ email\|online\|poștă) — ~16–17% | `credentials[].evidence` (quotes for UX-13 / UX-10) |
| **Localitate** (`work_localities` ∪ employer locality) — UX-02 | `responsibilities`, bibliography topics |
| **Permis de conducere** (category) — 7%; consider "fără permis" only if analytics show demand | `vacancies_total` → stats, sorting and "most offered jobs" rather than a filter |
| **Salary** — sort and range on the *estimate*; net shown beside it, computed | |
| Keep existing: sector, funding source, study level/EQF, experience, field, county | |

## 5. Model comparison plan

**Price per v5 posting.** The estimate assumes about 13.5k input tokens (8.8k of them
cacheable), 2.4k output tokens and no reasoning tokens. It uses each provider's official
list price, read 2026-10-16.

| Model | Price / 1M (in / cached / out) | ≈ $ per posting | Notes |
|---|---|---|---|
| DeepSeek `deepseek-v4-flash` (served as V4.1 "deepseek-flash") | 0.15 / 0.003 / 0.60 off-peak; ×2 peak (Mon–Fri 01–04, 06–10 UTC) | **0.0022** off-peak, 0.0044 peak | Current. `json_object` only (no enum enforcement). Weekends are entirely off-peak. Trial measured $0.0015–0.0039. |
| Gemini `gemini-2.5-flash-lite` | 0.10 / 0.01 / 0.40; batch 0.05 / – / 0.20 | ~0.0023 (implicit cache unreliable), ~0.0012 batch | Strict `response_schema`. **The key is IP-blocked from the Mac, so run it from the VPS.** |
| Gemini `gemini-3.1-flash-lite` | 0.25 / 0.025 / 1.50 | ~0.007 | ~3× DeepSeek; only if quality is clearly better. |
| OpenAI `gpt-5-nano` | 0.05 / 0.005 / 0.40; batch/flex 0.025 / – / 0.20 | ~0.0018 (+ reasoning tokens at `minimal`) | Strict `json_schema`. Ranked cheapest in the v2 comparison. **`OPENAI_API_KEY` is not set.** |
| OpenAI `gpt-5.4-nano` | 0.20 / 0.02 / 1.25 | ~0.005 | Newer; only if nano-5 quality fails. |
| OpenRouter (e.g. Mistral Small 3.2/4) | provider pass-through, ~0.07–0.15 / – / 0.20–0.60 (third-party snapshots, verify on the model page) | ~0.0015–0.0025 + **5.5% fee on credit purchases** | One key, many models. OpenAI-compatible (`base_url=https://openrouter.ai/api/v1`). Structured-output support varies by model. |

All candidates land within about ±30% of DeepSeek off-peak. At the expected volume (200
newest + ~150 new postings per weekday, roughly $5–8 a month at DeepSeek off-peak) the
saving is **a few dollars a month at most**. Choose on reliability:
- valid JSON and enum compliance
- evidence verification rate
- deadline accuracy
- `vacancies_total` / `fte` accuracy

Not on price.

**Procedure**, once v5 exists in `models_config.json` and `schema_models.py`:
1. Freeze a gold set: 30 postings from this sample (`samples/sample-index.md`), plus
   hand-checked values for deadline, `vacancies_total`, `fte`, `post_status`, `channels`
   and `recruitment.kind`. About 1 hour of human review.
2. Run `llm-schema.py --compare --prompt-version v5 --urls-file gold.txt --model-filter '<regex>'`
   per provider. Compare writes variants only, never production rows; Gemini runs on the
   VPS. Cost: 30 × 4 models × ~$0.003 ≈ **$0.40**.
3. Score with an extension of `ops/check-v4-sample.py --evidence` (v5 fields), and compare
   output tokens, latency and `OutputTruncated` / validation-failure rate.
4. Switching model re-extracts every row under the current staleness rule; see §6 for the
   accept-list that avoids this.

**Keys pax needs to provide:**
- `OPENROUTER_API_KEY` for the router path. It needs a small provider branch in
  `make_generator` (`llm-schema.py:495-675`), about 20 lines like the DeepSeek branch,
  plus `models_config.json` rates.
- Optionally `OPENAI_API_KEY` for gpt-5-nano.

Gemini needs nothing new; it has to run on the VPS.

## 6. Rollout: v5 for the ~200 newest plus new intake, v4 rows stay

**What blocks it today.** Under `--resume`, `_selection_where` (`llm-schema.py:780-792`)
marks a row stale when its provider, model **or `schema_prompt_version`** differs from the
run's. Pinning `LLM_PROMPT_VERSION=v5` (`ops/run-pipeline.sh:45`) would therefore
re-extract every active posting (~2,500, ≈$6 off-peak), which is what D24 wants to avoid.

**Changes:**
1. **The staleness rule accepts older prompt versions.** Add
   `LLM_ACCEPT_PROMPT_VERSIONS` (e.g. `v4,v5`), and change
   `schema_prompt_version != %s` to `NOT (schema_prompt_version = ANY(%s))`. Do the same
   in `count_postings` and `selection_report`; they share `_selection_where`.
   - A v4 row is then current unless **its source changes**. The source-revision clause
     (`llm-schema.py:785-787`) is untouched, so a revised posting comes back as v5 on its
     own.
   - Postings expire within weeks, so the corpus turns over to v5 naturally.
2. **The one-off 200.** Selection is newest-first (`_SELECTION_ORDER`,
   `llm-schema.py:796-799`):

   ```
   llm-schema.py --active-only --force --prompt-version v5 --limit 200 --dry-run
   ```

   Then the same command without `--dry-run`, at `--workers 4`, off-peak. Cost ≈
   200 × $0.0022 ≈ **$0.45**.
3. **`schema_models.py`.** Add `JobPostingExtractionV5(JobPostingExtractionV4)`:
   - Remove `educationRequirements`, `experienceRequirements`, `skills`,
     `application_docs`, `contract.remote_mode` and `employer_context.parent_institution`.
   - Add `Recruitment`, `Application`, `AgeLimit` and `Documents`.
   - Add `fte`, `end_date` and `post_status` to `ContractTerms`.
   - Add `relative` to `CalendarEntry` (`schema_models.py:604-612`).
   - Add `evidence` and `licence_category` to `Credential` (`schema_models.py:404-418`).
   - Register it in `EXTRACTION_MODELS` (`schema_models.py:814`).
   - Use the unknown-enum validators from §3.3.
   - Add `prompts.v5` to `models_config.json`.
4. **Export (`export-to-sqlite.py`).** Mixed v4/v5 rows must both work:
   - Add a `_v5_columns()` next to `_v4_columns()` (`export-to-sqlite.py:870-895`), with
     empty defaults for v4 rows: `recruitment_kind`, `internal_only`, `fte`,
     `post_status`, `end_date`, `channels`, `vacancies_total`, `work_localities`.
   - Every new column needs a `shim_legacy_export()` entry in `webapp-php/db.php` (next to
     `:89`), per the PHP-COMPAT rule. Otherwise use a VPS-first rollout.
   - `_v3_columns` keeps reading `contract.*` and `skill_list` unchanged. `remote_mode`
     simply becomes "" for v5 rows.
5. **PHP (mixed rows).**
   - `render_schema_sections` (`helpers.php:641-687`) and the JSON-LD list
     (`detail.php:171-178`) fall back to `education.verbatim`, `experience.verbatim` and
     the `skill_list` labels when the free-text keys are absent.
   - `checklist_docs()` (`inc/checklist.php:244`) gets a v5 branch: the `standard` codes
     map to a fixed Romanian label table, and `specific` items carry `roles` and
     `evidence`.
   - Remove the `remote_mode` line (`detail.php:531`).
   - The UX-10 eligibility gate for documents (`inc/eligibility.php`, `static/eligibility.js`
     strict policy) can verify v5 credential/document evidence once the export writes
     those quotes into `requirement_evidence` (`evidence.py`, a new fact kind `document`).
6. **posting_facts.py / UX-12.** The observation facts read `competition_calendar` and the
   requirement text (`posting_facts.py:230-244`). A v4→v5 re-extraction of an unchanged
   source must be classified "extraction-only", not a source change. It already is
   (UX-12 capture slice), but the facts compared must ignore keys that exist only in v5.
7. **Tests to add.**
   - A mixed v4/v5 fixture for `compat_test.php`, `discovery_test.php` and
     `checklist_test.php`.
   - A Python test that `_selection_where` with an accept-list does not select v4 rows
     with an unchanged source.
   - The v5 enum-coercion test, replacing the stage rule's "reject" expectation only for
     the new enums.

## 7. Implementation status (2026-10-16, evening)

Done (not committed by the implementer):

- `schema_models.py`: `JobPostingExtractionV5` registered as `"v5"`. The dropped keys stay
  declared (so mixed v4/v5 rows read alike) but a `mode="before"` validator removes them
  from a v5 answer: `educationRequirements`, `experienceRequirements`, `skills`,
  `application_docs`, `contract.remote_mode`, `employer_context.parent_institution`. New:
  `recruitment`, `vacancies_total`, `application` (`channels`, `window_start`),
  `age_limit`, `work_localities`, `documents` (`standard` codes + `specific` items),
  `contract.fte|end_date|post_status`, `competition_calendar.events[].relative`,
  `credentials[].evidence|licence_category`, `employer_context.subordinated_to`.
  New closed lists coerce instead of failing (unknown kind → `altele`, unknown channel
  dropped, unknown dosar code → a `specific` item, bad seat count/age/date → null);
  the calendar stage list stays strict. Derived: `fte` from `hours_per_week` (< 40),
  `internal_only` for a promotion; `shift_work` is null when silent.
- `models_config.json` `prompts.v5`, built by `build_v5_final.py` from the draft
  (`prompt-v5.txt` is the same text for review): v5-shaped examples, rule 6 (null when
  silent), rule 7 (quotes of 5–20 words, copied exactly), the manager-contest rule.
- `llm-schema.py`: `--accept-prompt-versions` / `$LLM_ACCEPT_PROMPT_VERSIONS` accept-list
  under `--resume`; `dedup_body()` drops body paragraphs the attachments carry (≥80% of
  6-grams and every number present there), **v5 only**. On the 400 newest local postings
  with attachments it removes 67% of body characters, with 0 dropped paragraphs carrying a
  date the attachments lack.
- `evidence.py`: a v5 answer's credential quotes are checked (`credential` rows can now be
  `verified`); v4 credentials stay `absent / no_evidence_field`.
- `ops/check-export.py`: the v3 floor is skipped under v5 as under v4.

**Trial of the final prompt** (`trial_v5.py`, 8 local postings incl. management,
promotion, multi-role, 0,75-norm, multi-site; $0.016): 8/8 valid on the first answer, all
`finish: stop`; 31/32 quotes verified (the miss is "aviz psihologic;", which is all the
source says); input sent −30% (dedup); output on the 4 postings also run under v4:
11,922 → 9,840 tokens (−17%). Facts checked by hand: promotion → `promovare` +
`internal_only`; seats 4 / 2 / 3 on the multi-seat postings; 0,75 norm + end date
2027-06-30 + `temporar_vacant`; manager contest `duration: null`; the Apele Române post's
three work points.

### VPS command sequence (pax runs it; code must be pulled first)

```
# 1. Preview the one-off: the 200 newest active postings, no calls, no writes.
.venv/bin/python llm-schema.py --active-only --force --prompt-version v5 --limit 200 --dry-run
# 2. Run it off-peak (weekends are off-peak all day). ≈ $0.45.
.venv/bin/python llm-schema.py --active-only --force --prompt-version v5 --limit 200 --workers 4
# 3. From then on the cron extracts new and revised postings with v5 and leaves v4 rows alone:
#    in .env (or ops/run-pipeline.sh's environment)
LLM_PROMPT_VERSION=v5
LLM_ACCEPT_PROMPT_VERSIONS=v4,v5
# 4. Check the next scheduled run selected only new/changed postings:
.venv/bin/python llm-schema.py --active-only --resume --prompt-version v5 --dry-run
```

**Export and PHP (done, same evening):** `_v5_columns()` writes eight `v5_*` columns
(empty on v4 rows; every one shimmed in `db.php`, covered by `compat_test.php`'s pre-v5
export); `webapp-php/inc/v5.php` holds the readers: `v5_facts()` (shown in "Condiții
contractuale"), `llm_calendar_rows()` (the extracted calendar, v4 and v5, is now the one
calendar table, the scraped rows only a fallback), `schema_text_fallbacks()` (description
sections and JSON-LD for v5 rows), `v5_document_items()` (checklist: standard codes with
fixed labels, specific items with their quote and roles). The remote/on-site line is gone.
Filters, hidden until v5 rows exist: Tip concurs, Normă parțială, Post temporar vacant,
Depunere fără deplasare. Promotions are NOT hidden from the default view (pax's call).
Tests: `tests/v5_test.php` (mixed v4/v5 fixture `tests/fixtures/v5_db.php`, in CI).

**Order:** deploy this code (`deploy-php.sh --code-only`) and let the VPS pull the export
change BEFORE step 2, so the first v5 rows are exported and read by code that knows them.

## Files here

| File | What it is |
|---|---|
| `README.md` | This report |
| `draft-v5-prompt.txt` | Test draft of the v5 system prompt (v4 plus edits; not final) |
| `build_v5.py` | Builds the draft from `prompts.v4` by explicit replacements |
| `run_sample.py` | Standalone v4/v5 DeepSeek trial; no database |
| `samples/*.v4.json`, `samples/*.v5.json`, `samples/usage.jsonl` | Trial outputs and per-call cost ($0.0201 total) |
| `samples/sample-index.md`, `samples/sample.py` | The 40-posting stratified sample (seed 16; reads the local Postgres) |
| `samples/scan3.py`, `samples/scan40.py`, `samples/fill.py`, `samples/dup.py`, `samples/tables.py` | The prevalence, fill-rate, duplication and table-type scans quoted above (scratchpad paths inside; reference only) |

Sources for prices (read 2026-10-16):
- [DeepSeek pricing](https://api-docs.deepseek.com/quick_start/pricing)
- [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing)
- [OpenAI API pricing](https://developers.openai.com/api/docs/pricing)
- [OpenRouter FAQ (fees)](https://openrouter.ai/docs/faq)
- Third-party OpenRouter model price snapshots: [anotherwrapper](https://anotherwrapper.com/tools/llm-pricing/qwen3.6-35b-a3b/mistral-small-3.1-24b-instruct-2503), [getmaxim](https://www.getmaxim.ai/bifrost/llm-cost-calculator/provider/openrouter/model/mistral-small-3.2-24b-instruct), [OpenRouter compare](https://openrouter.ai/compare/qwen/qwen3.8-27b/mistralai/mistral-small-2603)

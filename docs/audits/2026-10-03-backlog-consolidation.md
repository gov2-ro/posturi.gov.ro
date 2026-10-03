# Backlog consolidation record — 2026-10-03

Baseline: `2c5e480`, plus the current working backlog at the start of consolidation.
The [original backlog](../archive-reference/backlog-2026-10-03-before-consolidation.md)
is preserved verbatim. The [active backlog](../backlog.md) is the sole task queue;
this record explains destinations, not a second set of checkboxes.

## Decisions and evidence

- Preserve all historical completion notes and incident measurements in the original archive/activity log. Consolidate duplicate problem statements instead of treating each historical percentage as a separate task.
- Map every formerly open checkbox, including nested ideas. Superseded means contradicted by source or later dated evidence; it does not mean the broader reliability problem is solved.
- Live October 3 evidence: 1,875 application-active announcements, 42 counties, October 2 postings, 821 schema extractions recorded October 3, data build `2c5e480`. This supersedes old global outage statements but does not verify VPS scheduling/alerts.
- Source evidence: `--limit`, retries/concurrency, employer scope, localities and `positions[]` rendering already exist. Credential aliases include `certificat_professional`. Main Django body/schema rendering uses `nh3`; PHP's sanitizer remains unsafe. The CI workflow is archived, so restore active CI under FIX-07.
- The index currently early-stops after three unchanged pages once a change is seen. Old text claiming every run is a full scan is not a reliable absence/removal guarantee; FIX-03 addresses that contract.
- Correct the DST runbook task: if the live cron remains `45 8`/`33 15` UTC, winter equivalents of the same local slots are `45 9`/`33 16`, retaining minutes. Verify current configuration before changing the host; this consolidation performs no host action.
- The initial audit is saved separately in [2026-10-03-project-audit.md](2026-10-03-project-audit.md). The delegation specs are proposed remedies, not claims of implementation.

## Migration map

Original line numbers refer to the preserved snapshot, so they remain stable.
Task IDs below resolve in the active backlog; all FIX packages link to detailed
specs there. Deferred items require their own focused spec before coding.

| Old line | Original open item (abbreviated) | Destination/disposition |
|---|---|---|
| 15 | `judet_name` mixes two formats — the Județ facet splits every county | Superseded; county normalization already shipped |
| 16 | OCR backfill for scanned PDFs | DATA-01 |
| 23 | FAMILIES dict misses feminine/plural title forms | FIX-11 |
| 24 | `quality_check.py` duplicates the whole inference layer from `infer_postings.py` | FIX-11 |
| 25 | `v3_facet()` scans and decodes JSON in PHP | LATER-01 |
| 27 | `infer_conditions_llm.py` lacks deepseek | FIX-11 |
| 35 | Drop CSV layer eventually | LATER-01 |
| 39 | Topped up 2026-10-02 ($9.98, `/user/balance` reports `is_available: true`); close once a run shows `N ok` again — DeepSeek balance e… | OPS-02; recovery observed, host verification remains |
| 40 | IMPORTANT — The pipeline reports success when a step does nothing — add the guards that would have caught both of the above | FIX-04 |
| 41 | IMPORTANT — LLM cost baseline, and make spend visible before the balance hits zero again | FIX-10 |
| 42 | Cost visibility is half-built — `/statistici` shows daily `schema` spend (2026-10-03), but the spend is not a ledger and two of thre… | FIX-10 |
| 43 | IMPORTANT — Cancellation detection went blind with the 2026-09-30 listing change | FIX-03 |
| 44 | `data_limita_depunere` is filled for ~3–5% of rows since 2026-08 (was ~30%) | FIX-05 |
| 69 | Grow the skills keyword list | FIX-11 |
| 71 | `altele` is 14% of active postings | Superseded count/blocker; FIX-05/DATA-03 remeasure |
| 73 | Romanian gazetteer for locality extraction | UX-02 |
| 74 | `is_repost_of` detection | UX-08 |
| 81 | Live site is serving a five-week-stale database — needs a deploy | Superseded incident snapshot; OPS-02 checks recovery |
| 95 | Verify `MAILTO` actually delivers | OPS-02; recovery observed, host verification remains |
| 99 | Watch the first unattended run through the new gate | OPS-02; recovery observed, host verification remains |
| 102 | Shift posturi crontab by -1h at the 2026-10-25 DST changeover | OPS-01 |
| 104 | LLM-judge sampling pass over freshly extracted postings | DATA-03 |
| 106 | Tighten the soft thresholds in `ops/check-export.py` once there is run history | FIX-04 |
| 108 | LLM cost and per-error-class tally per run | FIX-10 |
| 110 | Surface the latest run record on `/despre` | FIX-06 |
| 112 | Nothing reminds anyone to run a code deploy after a PHP template commit. | FIX-06 |
| 118 | Four ordinal facets are dressed as nominal ones | UX-01 |
| 120 | Fourteen facet groups sit above "Mai multe filtre" | UX-01 |
| 132 | Landing page is just the search page | UX-05 |
| 142 | Dark mode + bilingual RO/EN | UX-06 |
| 144 | Category / profession hub pages | UX-05 |
| 154 | Port the 2026-09-07 UX pass to the Django templates | FIX-12 |
| 156 | Expired postings should render a real page, not a 404 — with similar jobs | UX-03 |
| 168 | Use `locality` for real geo | UX-02 |
| 170 | Backfill `locality` for the ~6,600 postings that have none | UX-02 |
| 172 | Facet fan-out is ~24 COUNT queries per render | LATER-01 |
| 174 | Feeds link to posturi.gov.ro, not to our own detail pages | UX-04 |
| 176 | No `og:image` | UX-04 |
| 179 | 79% of active postings show raw scraped text, not the LLM's structured sections | Superseded count/blocker; FIX-05/DATA-03 remeasure |
| 181 | `responsibilities` is only 18% filled, the weakest of the standard sections | DATA-03 |
| 183 | ~980 attachments have no text layer (scanned PDFs) | DATA-01 |
| 185 | `work_conditions` is rendered but never produced | DATA-02 |
| 191 | ~16 .docx files raise KeyError on extraction | DATA-01 |
| 193 | Search and filter by angajator, and detect the institution type | DATA-05 |
| 207 | Re-run v3 with the revised prompt, and get cross-provider agreement | DATA-03 |
| 209 | A posting can advertise several roles — the UI assumes one | DATA-04 |
| 213 | Reverse query: match a candidate profile against postings | UX-07 |
| 215 | Europass CV upload | UX-07 |
| 217 | Resolve `skill_list` labels to ESCO URIs | DATA-07 |
| 220 | The Django webapp has no skins and has drifted | FIX-12 |
| 221 | Font preloads follow the default skin, not the active one | UX-06 |
| 222 | No dark theme axis | UX-06 |
| 223 | `posturi.css` hangs its card shadow on `.rounded-lg.border` | UX-06 |
| 225 | The "Funcție contractuală" shortcut selects 95% of the corpus | UX-01 |
| 226 | `categorie` and `employer_category` look crossed for post-redesign rows | DATA-02 |
| 230 | Bilingual UI (RO/EN) | UX-06 |
| 234 | Retire the duplicated Django frontend; `webapp/` is the ETL, not a web app | FIX-12 |
| 250 | Auth (v3) | UX-07 |
| 252 | Stats dashboard v3 additions | UX-08 |
| 256 | `nr_posturi` picks numbers out of project codes | DATA-02 |
| 261 | `Other Links` parsing is naive | DATA-02 |
| 262 | HTML sanitization in _render_schema_sections | FIX-01 (PHP); superseded Django core-sanitizer request |
| 263 | Add --limit flag to llm-schema.py | Superseded; --limit already implemented |
| 271 | Expereință: wehere explicit, ie: "nu este cazul", flag as _nu necesită_ | FIX-11 |
| 273 | Gemini prompt caching never engages — and thinking tokens are not counted | FIX-10 |
| 277 | `cached_tokens` is computed but never persisted | FIX-10 |
| 278 | Caching paths for the other three providers are unverified | LATER-02; usage correctness is FIX-10 |
| 279 | BLOCKED: all four LLM providers unusable as of 2026-09-06 | Superseded incident snapshot; OPS-02 checks recovery |
| 285 | Promote prompt v2 to default + backfill | Superseded count/blocker; FIX-05/DATA-03 remeasure |
| 288 | some posts cover more jobs, how to address? | DATA-04 |
| 290 | stats, show all judete. norm to population | UX-08 |
| 297 | for expired postings keep page, metadata, use in stats, list in company profile archive, but instead of description show similar job… | UX-03 |
| 298 | add a relevant emoji next to domeniu and domeniu de studii. 2 emojis, if needed. | UX-05 |
| 299 | normalize limbi străine | FIX-11 |
| 300 | extract specific requirements, GIS, programming languages, etc - use domain specific filters?! | DATA-07 |
| 301 | normalize job titles - list or standard? | Superseded; title normalization exists, per-role gap is DATA-04 |
| 302 | add icons | UX-05 |
| 306 | 13343-expert-comunicare-proiect-cod-smis-330790 - should have a 'comunicare' keyword | DATA-07 |
| 307 | reformat rendered text, markdown, catch lists, headings. Mark relevant parts? | Superseded general rendering note; FIX-01/FIX-02/UX-01 own concrete gaps |
| 308 | normalize titles | Superseded; title normalization exists, per-role gap is DATA-04 |
| 315 | 80% of the live site has no LLM extraction. | Superseded count/blocker; FIX-05/DATA-03 remeasure |
| 347 | Surface cancellation in the UI. | UX-03 |
| 351 | 36 recent postings still lack an expiry date | OPS-03; current inventory/remeasurement required |
| 355 | The 78 recovered postings have no LLM extraction | Superseded count/blocker; FIX-05/DATA-03 remeasure |
| 361 | Stale `posturi.sqlite` (73 MB, 9 June) in the repo root | OPS-03; current inventory/remeasurement required |
| 364 | 88 active postings have empty `inferred` | OPS-03; current inventory/remeasurement required |
| 367 | Nothing schedules any of this. | OPS-02; recovery observed, host verification remains |
| 371 | deployment pipeline | OPS-02; recovery observed, host verification remains |
| 372 | `/pipeline-check` data quality command self-improving and suggesting improvements to the script – with a deterministic version to ru… | FIX-04 |
| 383 | Controlled vocabulary for `skill_list.label`. | FIX-11 |
| 403 | Explicit prompt caching, since implicit is unreliable (1/6 hits measured). | LATER-02; usage correctness is FIX-10 |
| 417 | Batch APIs are ~50% off | LATER-02; usage correctness is FIX-10 |
| 427 | Cheap-then-expensive routing. | DATA-03 |
| 431 | Anthropic cache *writes* cost 1.25x and are not modelled. | FIX-10 |
| 434 | Content truncation is a hard cut at 100,000 chars | DATA-03 |
| 437 | add a note, something like: "conținutul anunțurilor a fost rescris de un LLM, vă recomandăm să verificați și [sursa] înainte de a ap… | Superseded; detail already has source-verification disclosure |
| 438 | deployment: could we run it via github actions, or need VPS? | OPS-02; recovery observed, host verification remains |
| 439 | check expiry w LLM, sometimes mismatch, see https://posturi.gov.ro/joburi/expert-comunicare-proiect-cod-smis-330790/ | FIX-05 |
| 442 | upload your cv & match daily quota, create an account for more options, daily updates. | UX-07 |
| 443 | use vector db? | UX-07 |
| 444 | provide MCP endpoint? | UX-07 |
| 445 | scrape anunturi job-uri din site-uri individuale, vezi stiri.gov2.ro Ex: https://www.umpcultura.ro/ctg_3_oportunitati-de-angajare_pg… | LATER-03 |
| 446 | check against posturi.gov.ro | LATER-03 |
| 447 | one job posting might have more than one attachments? Do we ever have `other_links` ? | Superseded; other_links/multiple attachments already supported |
| 448 | assess extracted/inferred data quality, if not sure, send to smarter LLM? | DATA-03 |
| 449 | sometimes attachments might need to be OCR'ed? | DATA-01 |
| 450 | enhance slugs – use the original? – add judet as folder? - use alias? | UX-04 |
| 451 | in site attachment renderer? doc/x, pdf | LATER-03 |
| 452 | propose a input form for posturi.gov.ro | LATER-04 |
| 453 | upload cv, get job recommendations | UX-07 |
| 454 | enhanced stats, compare counties, regions, employment trends, norm per capita | UX-08 |
| 459 | this `job/14242-referent-de-specialitate-gradul-iii` should be label as 'ministerul apărării' ? by unitate militară — addressed in p… | DATA-05 |
| 475 | UAT population table | DATA-06 |
| 495 | Multi-role titles map to one occupation. | DATA-04 |
| 498 | One posting fails detail/calendar import: `value too long for character varying(40)`. | OPS-03; current inventory/remeasurement required |
| 499 | `credentials.kind` sometimes comes back misspelled. | Superseded; credential-kind alias already implemented, DATA-03 tracks new failures |
| 500 | Audit the other `_find_calendar_date` callers. | FIX-05 |
| 501 | Run prompt v4 over the corpus. | FIX-05 |
| 502 | 12 grid rows are flagged `needs_review`. | DATA-06 |
| 503 | Only the July 2026 variant has a materialised grid. | DATA-06 |
| 504 | Extend "explain choices" past the salary card. | UX-01 |
| 505 | `expires_at` overstates the real deadline on essentially every posting. | FIX-05 |
| 507 | Reconcile `inf_seniority` with v3 `seniority_hint`, and `job_type` with `contract.duration`. | UX-01 |
| 508 | `skill_list` has drifted into a long tail. | FIX-11 |
| 509 | `policy_domains` has a dead tail. | DATA-07 |
| 510 | The PHP app keeps a second, hand-maintained copy of every v3 vocabulary | FIX-11 |
| 511 | Three of the five `SALARY_BUCKETS` can never match an estimate. | DATA-06 |
| 512 | `occ_confidence` is exported but not surfaced in the list. | UX-01 |

Mapped **128 of 128** previously open checkboxes. Completed entries remain in the exact archived snapshot. No implementation or production operations were performed during this consolidation.

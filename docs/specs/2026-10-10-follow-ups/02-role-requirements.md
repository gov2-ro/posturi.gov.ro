# DATA-04 — Role-scoped occupation, requirements and search

Status: specified. Dependencies: preserve FIX-05 provenance and FIX-11 vocabulary.

## Outcome and first slice

Each displayed role has its own requirements, occupation and salary provenance.
First deliver a role-aware read model and prevent cross-role filter matches;
separate role-result indexing remains a later measured decision. Preserve
announcement IDs, URLs, announcement counts, feed identity and pagination.
Read schema_models.py positions, occupation/salary commands, export-to-sqlite.py,
PHP build_filters(), detail rendering and current fixtures.

## Contract

- Produce a versioned role representation with parent source URL, role key,
  source revision, title, vacancy count, occupation/confidence, requirements and
  salary facts/estimates. Keep raw evidence and explicit unknown values.
- Prefer source-provided role identity. Otherwise use revision-scoped keys and
  mark mappings across revisions unresolved unless evidence proves continuity.
  Array position alone is not stable role identity; identical titles may describe
  different roles. Never carry candidate progress to another role by guess.
- Keep shared competition facts separate from role facts. Assign a shared
  requirement only when the source explicitly applies it to all relevant roles.
- Conditions across role-specific facet groups must be satisfied by the same
  role (OR within a group, AND across groups). Example: role A has university
  education and five years' experience; role B has secondary education and zero
  experience. A search for university plus zero experience must not match.
- Announcement-level location/status/employer filters keep existing semantics.
  Count each matching announcement once, even if two roles match. Use the same
  predicate for selection and facet counts. Explain any changed counts.
- A legacy posting with no role decomposition exposes unknown role information,
  not fabricated per-role qualifications. A single positions entry must not
  trigger a multiple-role warning. Estimated salaries retain their own scope and
  source, and never become stated pay by association.

## Acceptance and rollout

Fixtures: one role, two contrasting roles, duplicate titles, missing roles,
shared/role-specific requirements, conflicting salaries and role reorder/change.
Assert no cross-role match, consistent facets/feed selection, stable announcement
identity and old-export rendering. Report current corpus decomposition coverage
and changed matches on copied data; historical percentages are not targets.
Stage additive export/read compatibility before switching search predicates.

## Delivery and validation

Read AGENTS.md, this package, relevant existing specs and current source before
implementation. Confirm actual field shapes rather than treating proposed
contracts as existing APIs. Keep PHP/SQLite public delivery and Django/PostgreSQL
processing. Use token-based styles and deterministic fixtures; run relevant PHP,
Python and browser suites. Document additive migrations, old-export behavior,
before/after metrics on copied data, and rollback where applicable.

This specification authorizes no paid calls, broad backfill, deployment, email
delivery or host changes. Prepare bounded commands and expected evidence for
those later steps. Record implementation separately from rollout in the backlog
and activity log. Writing this spec does not complete its backlog item.

## Implementation notes (first slice)

Status of this slice: implemented locally, not deployed, not backfilled. It delivers the
role-aware read model and removes cross-role matches for the requirement filters. Per-role
occupation and salary, and separate role results, are not in it (see "What remains").

### Table shape

`posting_roles` is a new additive table in the SQLite export (`export-to-sqlite.py`). The
rules live in the repo-root module `posting_roles.py` (`role_rows(posting, schema)`, pure:
no Django, no psycopg), so the export and a later history command share one definition.

| column | meaning |
| --- | --- |
| `posting_id`, `ordinal` | primary key. `ordinal` is the `positions[]` array position, 1..N; 0 for the implicit row |
| `role_key` | `"<basis>:<rev12>:<ordinal>"`, see below |
| `decomposed` | 1 = built from a `positions[]` entry; 0 = implicit posting-level row |
| `title`, `vacancy_count` | from the entry; NULL on the implicit row |
| `study_level`, `eqf_level` | `education.minimum_level` / `eqf_level` of that role (implicit: `inf_studies_required`, `v3_eqf_level`) |
| `isced_fields`, `skills`, `credentials` | sorted unique JSON arrays, the `v3_*` format (credentials are `kind`s). NULL = the entry has no such block/list; `"[]"` = a block that lists none |
| `exp_years`, `exp_in_specialty`, `exp_none_required` | `years_minimum` (0 when only `none_required` is stated); tri-state flags |
| `seniority_hint`, `source_revision`, `role_format` | hint; the full `schema_source_revision` or NULL; format version (1) |

Indexes: the primary key `(posting_id, ordinal)` (its prefix serves every `posting_id` lookup, so no
separate index), plus `(eqf_level)` and `(study_level)`. The export runs `ANALYZE posting_roles` and the
EQF/study conditions carry a unary `+` inside the correlated `EXISTS`: with no statistics SQLite chose the
EQF index and rescanned every EQF-6 role once per posting (about 250 ms per query on 1,848 postings
against about 1 ms probing by `posting_id`); the facet joins still use the index. `job_postings` also gained
`schema_source_revision` and `schema_prompt_version` (provenance for later per-role history).
`verify()` refuses a build in which a posting has no role row.

Rules: a posting with one or more `positions[]` entries yields one decomposed row per entry,
values from that entry only; a fact the entry does not state stays NULL (unknown). Posting-level
education, skills, credentials and experience are never copied into a role: the extraction does
not record that a requirement applies to all roles, and the spec only allows sharing when the
source says so. Every other posting (no positions, v2 payload, no schema, a positions list with
no usable entry) yields exactly one implicit row, ordinal 0, `decomposed = 0`, copying the flat
search columns, so a search over it returns what it returned before. That row is "the posting",
not a role, and carries no title or vacancy count.

### Role keys are not stable

`role_key` is `src:<first 12 chars of schema_source_revision>:<ordinal>` when the extraction
recorded the page revision it ran against, else `pay:<first 12 hex of sha256 of the canonical
payload>:<ordinal>`, else `none:-:0`. Array position is not identity: a re-extraction can reorder
roles, and two roles can share a title (fixture 1010). Within one `src` revision a reorder keeps
the keys and swaps the roles under them; under `pay` every key changes. No mapping between
revisions is attempted and none is implied: it is unresolved until a source-provided role id or
other continuity evidence exists. A key must never carry saved progress, a checklist or a
notification from one extraction to another. `(posting_id, role_key)` identifies a role within
one build only.

### What is role-scoped, what is not

Role-scoped (read `posting_roles`, alias `r`): EQF, study level (`studies_level`), experience
buckets (`exp_level`), field of study (`isced`), skill (`skill`, with the existing AND/OR mode)
and credential. `build_filters()` collects their conditions and emits ONE clause,
`EXISTS (SELECT 1 FROM posting_roles r WHERE r.posting_id = j.id AND (c1) AND (c2) ...)`: OR inside
a group, AND across groups, all on the same role. An announcement is returned once however many
roles match. `$excl` still drops exactly one group. `build_filters()` also returns
`posting_where/posting_binds` and `role_where/role_binds` for callers that count role facets.
The experience bucket condition is one helper, `exp_bucket_condition()`, used by the filter and by
the facet counts (the hand-copied SQL in `pages/list.php` is gone; the lowest bucket still takes
NULL).

Posting-level, unchanged: language (the schema holds it only per posting), policy domain, exam
stage, anomaly flags, occupation, profession family, seniority, work type, salary, contract
duration/schedule/shift, remote/computer, funding, sector, structured-or-raw, and every
announcement-level filter (county, level, type, category, employer, status, deadlines, text).

Facet counts use the same predicate. EQF and study level: `COUNT(DISTINCT j.id)` over
`job_postings j JOIN posting_roles r`, with the posting-level conditions on `j` and the other
role groups applied to that same `r`. Field/skill/credential: the (announcement, role) rows of
roles satisfying the other role groups, values unioned per announcement, distinct announcements
counted per value (an AND group keeps its residual semantics, now on the same role). Experience:
distinct announcements with a role in each bucket. The count beside a value therefore equals the
list size when that value is added, and `tests/roles_test.php` asserts it for 11 selections.

### Old exports and rollback

An export without the table gets a TEMP view of the same name (`shim_posting_roles()` in
`webapp-php/db.php`): one implicit row per posting built from the posting's flat columns,
`role_format = 0` marking it as a stand-in. The role-scoped filters then reduce to the old
posting-level predicates row for row (asserted against a no-table copy of the fixture and
against the oldest stacked-view shape). Old PHP against a new export is unaffected: the table
and the two provenance columns are additive. Rollback is reverting the EXISTS rewrite in
`build_filters()` and the role facets in `pages/list.php`; the table and columns can stay.

### Detail page

A single `positions[]` entry now reads "Post scos la concurs", without a count and without the
"mai multe roluri" sentence; two or more keep the old text. Fractional years render ("6 luni",
"1,5 ani") instead of `(int)0.5` = "0 ani". A role shows "în specialitate" when the extraction
says so, "Fără experiență" only when `none_required` is true or the minimum is explicitly 0, and
nothing when experience is unstated. Role credentials are listed by label. Everything is
escaped and uses token classes only.

### Changed results

Fixture: no pinned set changed. The first run of the new predicates did change one, `discovery_test.php`
"skills any" (1008 dropped out): fixture 1008 lists two positions and neither stated a skill list, so
Excel and Word existed only at posting level and, by the no-inheritance rule, on no role. That also emptied
the Ilfov skill facet and broke the committed `discovery.spec.js` feed-links test. The fixture was made
realistic instead (1008 role 1 now states Excel and Word; role 2 states none and so has none) rather than
editing the pinned set: the corpus below shows roles normally do carry their own skills (Excel 39 -> 40
matches, none lost). The new role-case postings 1009-1012 exist only in the databases built with
`build_fixture_db($path, true)` (`roles_test.php`, `compat_test.php`), so the 38-row fixture counts that
other suites pin are untouched.

Corpus, illustrative: the only export on this machine is the 2026-09-09 active export (1,848
postings, 1,812 with a schema, built before this change). Running `role_rows` over it:
1,155 postings are v3+ with no positions, 345 are v2, 36 have no schema (all implicit rows);
312 are decomposed (309 with two or more roles, 3 with exactly one), giving 3,261 role rows.
Role scope moves a minority of results in both directions. Examples (flat -> role matches, lost,
gained): EQF 6 and no experience 297 -> 327 (8, 38); licență and 3-5 years 151 -> 165 (9, 23);
EQF 6 and 5+ years 119 -> 151 (2, 34); EQF 4 and licență 32 -> 23 (9, 0). "Gained" is a decomposed posting matching through a role that the single flat value did not
describe: in 133 of the 312 decomposed postings the roles state different EQF levels, and the flat
`v3_eqf_level` is just one of them (it equals a role's level in 305 of 312). "Lost" is
a posting whose flat value is not restated by any of its roles: mostly study level, where the flat
`inf_studies_required` comes from the regex inference and 53 of the 312 values appear on no role,
and a few EQF values (4) the roles do not carry. These are
a five-week-old export and a first reading, not targets. Reproduce on a current export with:

```sql
SELECT decomposed, COUNT(*) FROM posting_roles GROUP BY decomposed;
SELECT COUNT(*) FROM (SELECT posting_id FROM posting_roles GROUP BY posting_id HAVING COUNT(*) > 1);
-- one pair, flat vs role scope
SELECT COUNT(*) FROM job_postings
 WHERE v3_eqf_level = 6 AND (inf_experience_years IS NULL OR inf_experience_years < 1);
SELECT COUNT(DISTINCT posting_id) FROM posting_roles
 WHERE eqf_level = 6 AND (exp_years IS NULL OR exp_years < 1);
```

### Tests

`webapp/tests/test_posting_roles.py` (48 tests: one role, contrasting roles, duplicate titles,
missing positions, shared vs role-specific, `none_required`, reorder keys, `src` vs `pay` basis,
and the real `export()` against a fake cursor). `webapp-php/tests/roles_test.php` (no cross-role
match, announcement once, facet counts = list, feeds = list, old export, detail disclosure and
experience text, escaping); `compat_test.php` gained the "no posting_roles" shapes. The PHP
fixture (`build_fixture_db($path, true)`) builds `posting_roles` with a PHP port of
`role_rows` (`fixture_role_rows()`); keep the two in step. CI runs `roles_test.php`.

### What remains

- Per-role occupation and salary provenance: `occ_*` and `sal_*` are still posting-level, so a
  search or sort on occupation or pay can still blend roles. Estimated salaries keep their scope.
- The role-result indexing decision: results are still announcements; a role-level result list
  (and per-role saved progress, which needs a stable role identity) is a later, measured decision.
- Corpus metrics on a current export (the numbers above are from 2026-09-09), including the
  recall effect of "no inheritance" for decomposed postings that state a shared requirement only at
  posting level. On the 2026-09-09 export it was small for skills, fields and credentials (0-2 postings
  lost per single filter) and larger for study level (about 17 of 787 for licență), where the flat value is
  a regex inference. If it matters on current data, the fix belongs in the extraction (an explicit
  "applies to all roles" marker), not in guessing during export.
- A deploy-gate check in `ops/check-export.py` (every posting has a role row; decomposition
  coverage reported), the export's own `verify()` already refuses an inconsistent build.
- Reusing `posting_roles.py` from the history-observation command; cross-language parity between
  `role_rows` and the PHP fixture port (a shared JSON case file would make drift a test failure).
- The "Cerințe structurate" block above the roles on the detail page still shows the posting-level
  education, skills and credentials, which for a multi-role posting are not role facts.

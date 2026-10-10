# UX-12 — Verified change notices for saved announcements

Status: specified. Dependencies: FIX-03 refresh, UX-09 identity, DATA-04 roles.

## Outcome and scope

On return visits, saved jobs show verified deadline, cancellation and requirement
changes, with old/new facts and observation time. This requires durable revision
history; current detail hashes and stale flags alone do not prove old/new facts.
First inspect retained history and implement missing history additively. No email
or background browser notification delivery in this package.

## Observation contract

Store immutable observations keyed by source URL and source revision, with
successful retrieval time, normalized tracked facts, evidence references and
extraction revision. Keep source changes separate from model-only corrections.
Only compare successfully observed, valid revisions; a failed refresh or invalid
extraction reports unavailable verification, never no change. A newly established
baseline is not a change notice. Bytes changed without tracked fact changes are
not a deadline/requirement alert.

Each verified change has a stable event ID, old/new values, role association,
observation time and source evidence. Distinguish an observed cancellation from
missing export/source rows. Do not guess role continuity after restructuring.
Define bounded history retention and expose the oldest available observation;
never claim changes outside retained history were checked. Prepare retention
volume estimates and a rollback plan before choosing storage policy.

## Read surface and browser behavior

Resolve only saved identities needed by the visible page, using a bounded public
lookup with validated IDs and source-URL identity checks. Export bounded histories
or design a compatible read-only endpoint; do not ship the full archive to the
browser. History export and endpoint caps must be visible and deterministic.

Show old/new facts and “observed at” in Bucharest; observation time is not the
institution's exact edit time. Reader acknowledgements store event IDs locally
under a separate versioned key. Acknowledging must not erase server evidence.
Replayed events retain IDs and are not announced twice. Preserve snapshots when
current data is absent and warn when history gaps prevent complete comparison.

## Acceptance

Fixtures cover changed deadline, cancellation, role-specific requirement change,
model-only correction, successful unchanged refresh, failed fetch/extraction,
missing export, history gap, role remap ambiguity and duplicate replay. Verify
acknowledgement/reload/cross-tab behavior and old exports without history. Prove
that absence of an event is never presented as successful source verification.

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

## Implementation notes (first slice: capture)

Status 2026-10-10: capture implemented; the read surface (bounded history export,
lookup endpoint, acknowledgements) is **not** built. Nothing is deployed and no
migration has been applied to a shared database.

### Correction: FIX-03 did not retain revisions

This spec's dependency line and the FIX-03 spec ("keep valid source history") read as
if FIX-03 left a trail of source revisions. It did not. FIX-03 keeps exactly one
`detail_fetched_at` and one `detail_content_hash` per posting, overwritten by every
successful fetch; a failed fetch writes nothing. `schema_json`, the variants table, the
cached HTML and sidecars, the CSVs and `cancelled` (a boolean recomputed each import)
are also overwritten in place. Today no old value can be recovered from anywhere, so
the history this feature needs starts the day capture does.

### Why capture comes first

History only accumulates from the day it is recorded, and recording needs no UI,
export or PHP change. Shipping capture alone starts the clock; the read slice can then
be designed against real observation data. Until the read slice exists, nothing is shown
to readers and the deployed SQLite/PHP are untouched.

### What is captured

`PostingObservation` (`apps/jobs/models.py`, migration `0016_postingobservation`,
additive: one new table, no change to an existing one). One immutable row per
*difference*, never updated:

| Column | Meaning |
|---|---|
| `url` | source URL — the identity (UX-09), not the FK |
| `posting` | FK to `JobPosting`, `SET_NULL` (convenience only) |
| `observed_at` | when the observe step recorded the row (one timestamp per run) |
| `retrieved_at` | `JobPosting.detail_fetched_at`, the last *successful* retrieval |
| `source_revision` | `detail_content_hash` (`''` = unknown) |
| `extraction_provider/model/prompt_version/source_revision/extracted_at` | copied `schema_*` provenance — the **extraction revision**, distinct from the source revision |
| `facts`, `facts_hash`, `facts_format` (1) | normalized tracked facts and their hash |
| `valid` | `False` when no valid current extraction backed the row |

Index `(url, -observed_at)`. No unique constraint on the revision columns, on purpose:
a deadline that moves and moves back with no new source revision is two real
observations with the same key columns, and a constraint would drop the second. De-dup
is "the latest observation of this URL is equal", computed in one `DISTINCT ON` query
inside a transaction that holds a Postgres advisory lock (so overlapping runs cannot
both baseline a URL).

`posting_facts.py` (repo root, pure) defines `facts` (format 1): `deadline` (+
`deadline_source` `concurs|anunt|expirare|''`, resolved exactly like
`export-to-sqlite._apply_deadline`; a parity test pins the duplicate), `deadline_time`
(v4 only), `cancelled`, `expires_at`, `requirements_available`, `roles` (the requirement
columns of `posting_roles.role_rows`: `role_key, ordinal, decomposed, title,
vacancy_count, study_level, eqf_level, isced_fields, exp_years, exp_in_specialty,
exp_none_required, skills, credentials`; arrays parsed, tri-state as bool/null, sorted by
ordinal) and `evidence` (the deadline calendar row's `verbatim`, and per role the
education/experience `verbatim` sentences).

**`facts_hash` is not a hash of the whole dict.** It excludes `evidence` (a model-only
correction can rephrase a sentence without moving a fact) and each role's `role_key` (it
embeds the source revision, so every new revision would look like a change of every
role, defeating "bytes changed without tracked-fact changes is not an alert"). Both stay
in the stored `facts`.

**Validity.** `valid`/`requirements_available` is False when there is no usable
`schema_json`, when the extraction's recorded source revision and the posting's hash are
both known and differ (made from an older page), or when the importer's
`inferred.stale_revision` marker is set. An extraction with *no* recorded revision is not
provably stale and counts as valid — the repo's convention (`llm-schema.py --resume`,
`invalidate_enrichment`); `extraction_source_revision` is stored as-is so the read slice
can be stricter. An invalid observation holds only non-extraction facts: the v4 calendar
date is *not* used (it describes the previous page), so `deadline_source` falls to
`anunt`/`expirare`, `roles` is `null` and evidence for roles is `null`. **Readers must
compare deadlines only like-for-like** (same `deadline_source`): `concurs` -> `anunt` is
a change of evidence, not of deadline. "Unavailable" never means "no roles" or "no change".

### Classification (`posting_facts.classify(prev, cur)`)

* `baseline` — no earlier observation. **Establishing a baseline is not a change.**
* `source_change` — `source_revision` differs (including unknown -> known; whether that
  is a *confirmed* change needs both ends known, like `invalidate_enrichment`, and is for
  the event derivation to decide).
* `extraction_only` — same source revision, different extraction revision (a model-only
  correction: new model, prompt version, provider, source stamp, or re-run time).
* `facts_only` — same source and extraction, different facts (cancellation, expiry).
* `unchanged` — insert nothing. A *successful* refresh with identical content is
  unchanged too; its verification time lives on the posting (`detail_fetched_at`, which
  the export already carries), not on the immutable observation.

Only the first four insert. **No change events are derived yet.** Event derivation —
old/new facts, role association, stable event IDs — belongs to the read slice, and role
continuity across revisions is unresolved by design: role keys are revision-scoped, a
reorder under one revision reuses `src:<rev>:1` for a different role, and under the
`pay` basis a reorder changes every key. The facts carry no old-key -> new-key mapping and
the tests assert none is invented.

### The command

`manage.py observe_postings [--window-days 30] [--dry-run] [--retain-days N]`

* Scope: successful retrieval (`detail_fetched_at` not null) **and** (`expires_at >=
  today - window` **or** `cancelled`). A posting never retrieved is never observed (it is
  counted as `skipped_no_retrieval`), even when cancelled.
* One query for the latest observation per URL, bulk inserts of 500, one transaction.
* `--dry-run` prints the counts by class and writes nothing (it also reports what
  `--retain-days` would delete, measured against the table as it is now).
* `--retain-days N` deletes observations older than N days except the newest of each URL.
  Default: keep everything.
* One summary line, e.g. `observe_postings: in_scope=1848 baseline=1848 source_change=0
  extraction_only=0 facts_only=0 unchanged=0 skipped_no_retrieval=0 inserted=1848 errors=0
  stored=1848 oldest_observed_at=2026-10-10T18:40:03+03:00`. `oldest_observed_at` is what
  the read slice will expose as "history starts here"; with `--retain-days` it is the
  oldest *retained* row. Exit status is non-zero only on a real error (a posting whose facts
  could not be computed — the rest are still recorded — a bad flag, a database failure).

### Pipeline

`pipeline.py` gains an `observe` step between `salary` and `export-sqlite` (after import,
schema, infer, occupations — so a run's observation describes the data that run
publishes). It is the first **optional** step (`OPTIONAL_STEPS`): a failure prints a
warning, is recorded on the run log (`optional: true` on the step, `warned_steps` on the
run), is not in `failed_steps` and does not change the exit code, so the export and deploy
still happen — even under `--continue-on-error`, where a required failure would withhold
the deploy. `POSTURI_SKIP_OBSERVE=1` skips it (like `POSTURI_SKIP_BALANCE_CHECK`);
`ops/run-pipeline.sh` is unchanged. `run-pipeline.sh` already aborts on unapplied
migrations before anything runs, so the table is guaranteed present.

### Cancellation versus a missing row

The deploy export drops cancelled rows (`active_filter ... AND NOT jp.cancelled`), so on
the public side a cancelled posting and an absent one are indistinguishable. Observations
close that gap on the Django side: a withdrawn posting is recorded with
`facts.cancelled = true` (cancelled postings are in scope whatever their expiry), while a
posting that merely disappears from the listing or the export keeps its last observation,
which still says it was live. Whatever the read slice exports must carry that
distinction explicitly; absence of an event must never be shown as verification.

### Remaining gaps (capture is not verification)

* **Failed attempts are not persisted.** A failed fetch writes nothing, so capture
  inserts nothing — correct, but a "we tried and could not check" record does not exist.
  FIX-03 records nothing per posting about a failed attempt (only run-level counts). The
  read slice can say "last verified at `detail_fetched_at`"; it cannot say "a refresh
  failed on Tuesday". Per-posting attempt logging is a separate, later decision.
* `retrieved_at` stamps the detail page. `cancelled` and `expires_at` come from the
  listing (`last_seen_at`), refreshed independently of the detail fetch; an observation
  recorded for a cancellation flip carries the older detail retrieval time. A
  `listing_seen_on` column would be additive if the read slice needs the distinction.
* Observations older than capture do not exist; nothing here backfills.

### Volume and retention estimate

Measured read-only on the local `posturi_dev` copy (a stale September export: last
`last_seen_at` 2026-09-09, no `detail_fetched_at` at all, so the strict scope is 0 rows
there). As a proxy the same window was applied ignoring the retrieval condition
(`expires_at >= 2026-09-10 OR cancelled`):

* postings in scope: **1,848** (80 cancelled; 1,756 with `schema_json`; 291 with more than
  one role, up to 35 roles);
* facts JSON bytes: mean **936**, median **725**, p95 1,713, p99 5,131, max 14,528 (mean
  670 without evidence); `jsonb` datum size mean 1,099, max 18,006;
* estimated baseline: 1,848 x ~1.6 KB (jsonb + ~520 B of row and index overhead) = **~3 MB**;
* **measured** on a throwaway copy (a `pg_dump` of the dev DB into a scratch database,
  `detail_fetched_at`/`detail_content_hash` synthesised for the 1,848 in-window rows, then
  migrated and run): 1,848 observations = **2.6 MB** for table + TOAST + the
  `(url, -observed_at)` index (~1.4 KB per row); 1,756 valid, 92 invalid (no extraction).
  The baseline run took 1.1 s, a second run (`unchanged=1848`) 0.8 s, a dry run 0.8 s, all
  including Django start-up;
* per-year upper bound, assuming ~400 refreshes/day all of which change something:
  146,000 rows/year x ~1.4-1.6 KB = **~205-236 MB/year** (a ceiling: observations are written
  only on a difference, and most refreshes are unchanged; the largest single row is ~18 KB).

Computed by an uncommitted scratch script that runs `posting_facts.tracked_facts` over the
copy through a read-only connection and measures `pg_column_size` on jsonb literals (no
table written). Prompt v3 payloads dominate this copy; v4 adds only the deadline evidence sentence.

### Decision left open: storage policy

Not decided here. **Keep everything** (default, `--retain-days` unset): ~3 MB baseline
and an upper bound near 200-240 MB/year, trivial for the VPS Postgres, and it preserves the
evidence the spec says to keep. **Keep N days** (`--retain-days N`, e.g. 365 or 400):
caps growth, always keeps the newest observation per URL so a saved job's last known
facts survive, and makes `oldest_observed_at` the honest "never claim changes outside
retained history" boundary the read slice must show. The numbers do not force a choice;
a policy should be recorded before the read slice, because it defines the claim readers
are shown.

### Rollout (capture only, no deploy to the shared host)

1. Code deploy to the VPS (`--code-only`), then `manage.py migrate` there (additive:
   `jobs.0016_postingobservation`; `run-pipeline.sh` refuses to run until it is applied).
2. `manage.py observe_postings --dry-run`: expect `baseline` ~ the number of postings with a
   successful retrieval in the window, every other class 0, `inserted=0`.
3. Let the next scheduled run (or one manual `manage.py observe_postings`) create the
   baseline. Verify: `stored` equals the dry-run `baseline`; `SELECT count(*) FROM
   jobs_postingobservation`; `SELECT valid, count(*) ... GROUP BY 1`; one URL's `facts`.
4. On the following runs expect mostly `unchanged`, a few `facts_only`/`source_change`
   rows per day. A sudden jump in `extraction_only` means a prompt/model change or a
   `--force` re-extraction, not a source change.
5. Set `POSTURI_SKIP_OBSERVE=1` in the environment to switch capture off without a deploy.

Rollback: remove the `observe` step (or set `POSTURI_SKIP_OBSERVE=1`); nothing else
reads the table. To drop it: `manage.py migrate jobs 0015` (reverse of `CreateModel`
drops `jobs_postingobservation`). That discards the history, so prefer leaving the
table in place when rolling back only the step.

### Tests

`webapp/tests/test_posting_facts.py` (facts, validity, parity with the export, hash
rules, `classify`), `test_observe_postings.py` (command against the throwaway DB) and
`test_observe_pipeline_step.py` (the optional step and the skip variable).

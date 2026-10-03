# Backlog

Consolidated 2026-10-03 against source at `2c5e480` and the live-site audit.
This is the active queue. An unchecked item remains open; writing a spec does
not complete implementation or rollout. Historical counts are dated evidence,
not current acceptance thresholds.

- [Initial project audit](audits/2026-10-03-project-audit.md)
- [Delegation-ready technical specifications](specs/2026-10-03-project-audit/README.md)
- [Consolidation decisions and old-item mapping](audits/2026-10-03-backlog-consolidation.md)
- [Original backlog, preserved before consolidation](archive-reference/backlog-2026-10-03-before-consolidation.md)
- [Activity log](activity-log.md) and [broader product spec](ui-spec.md)

## Immediate fixes

P0 is security; P1 is correctness or unattended reliability. Each FIX ID has a
separate spec with scope, dependencies, acceptance tests and rollout guidance.

- [ ] **FIX-01 / P0 — Sanitize PHP-rendered Markdown.** Replace attribute-preserving `strip_tags()` with parser-based sanitization across raw and LLM sections. Local unsafe-link/event-handler reproduction is confirmed. [Spec](specs/2026-10-03-project-audit/01-markdown-sanitization.md).
- [ ] **FIX-02 / P1 — Consistent deadline display and uncertainty.** Use one date for the countdown and printed date; visibly qualify expiry fallbacks. Check calendar event duration and precision. This corrects presentation without claiming a backfill happened. [Spec](specs/2026-10-03-project-audit/02-deadline-presentation.md).
- [ ] **FIX-04 / P1 — Meaningful pipeline failure status and quality gates.** Fail on unusable extraction, stop provider-wide fatal errors, write structured summaries, and catch sustained decline against healthy baselines. Keep zero-work runs valid and calibrate thresholds before blocking legitimate exports. [Spec](specs/2026-10-03-project-audit/04-pipeline-health.md).
- [ ] **FIX-07 / P1 — PHP request validation and regression CI.** `?q[]=medic` currently returns 500. Validate query shapes centrally; add isolated PHP/Playwright fixture coverage and restore an active CI workflow. The archived workflow is not active CI. [Spec](specs/2026-10-03-project-audit/07-php-request-validation.md).
- [ ] **FIX-08 / P1 — Atomic attachment downloads.** Validate downloads before rename; repair partial legacy cache files; handle URL identity/collisions with extraction lookup compatibility. [Spec](specs/2026-10-03-project-audit/08-attachment-downloads.md).

## Source correctness and operational completion

- [ ] **FIX-03 / P1 — Refresh source details, cancellation and stale enrichment.** Existing HTML is never refreshed; detail status is not the cancellation authority. Add bounded refreshing, successful-fetch metadata and revision-aware enrichment. Handle incomplete index scans and changed attachments explicitly. [Spec](specs/2026-10-03-project-audit/03-source-refresh.md).
- [ ] **FIX-05 / P1 — Version-aware v4 rollout and reliable application eligibility.** Re-sample current refreshed inputs; upgrade production rows rather than only compare variants; distinguish confirmed open, closed, uncertain and cancelled across pages/feeds/counts. Keep expiry separate. [Spec](specs/2026-10-03-project-audit/05-application-deadlines.md).
- [ ] **FIX-05-RUN / P1 — Verify production deadline backfill.** After FIX-03/04/05 code is ready, record the bounded sample, current cost estimate, reviewed backfill, pinned intake version, before/after deadline coverage and deployed build identity. Do not close from a sample or a non-null `schema_json` count alone.
- [ ] **FIX-06 / P1 — Truthful freshness and code/data release markers.** Stop presenting cached import time as source verification. Export real source/run metadata; verify the expected artifact after deploy and expose pending code releases. [Spec](specs/2026-10-03-project-audit/06-provenance-deployment.md).
- [ ] **OPS-01 / P1 — Make scheduling DST-safe before 2026-10-25.** The September 16 activity entry reports literal UTC cron at `45 8` / `33 15`, not working `CRON_TZ`. Verify the current host schedule. If those slots remain, their winter equivalents for 11:45/18:33 Bucharest are `45 9` / `33 16` UTC, with unchanged minutes. Prefer a reviewed systemd timer migration with an explicit timezone after checking user/path/state ownership; do not leave cron and timer both enabled. Validate next firings, locking, catch-up and healthcheck gaps. Earlier `46 9` / `34 16` notes conflict with the intended times and are not authoritative. VPS action remains unperformed by this audit.
- [ ] **OPS-02 / P1 — Verify unattended recovery and alert delivery.** Read recent VPS run/export/deploy records after the intake fix and balance top-up; verify healthy intake, failed-call rate, last successful source retrieval, deployed artifact and a controlled alert failure. The live October 3 recovery does not prove cron or alerts. Confirm `MAILTO` delivery or document it as unused; verify backups/restore expectations and log retention rather than assuming them. The old “watch first run” item is superseded by evidence of recorded runs, but current end-to-end health still needs checking.
- [ ] **OPS-03 / P2 — Audit legacy artifacts and remaining import gaps.** Check whether the root-level stale SQLite/sidecars still exist and whether anything reads them before removal. Re-measure missing expiry/body/inference/attachment gaps; distinguish absent source information from fetch/extraction failures. Investigate the historical `varchar(40)` error for `1-post-muncitor-treapta-i` and malformed DOCX failures against current data; do not silently truncate source facts or reuse old counts.

## Analytics and maintainability

- [ ] **FIX-09 / P2 — Statistics with explicit scope.** Correct current-export/monthly labels, separate contract category from institution type, and export historical publication aggregates from PostgreSQL. Historical active-stock curves require their own observations. [Spec](specs/2026-10-03-project-audit/09-statistics-scope.md).
- [ ] **FIX-10 / P2 — Append-only LLM call accounting.** Count schema/infer/occupation attempts, repairs and usage without mutable variant upserts erasing spend. Persist pricing/cache/reasoning semantics and add optional balance alerts. Keep legacy estimates visibly incomplete. [Spec](specs/2026-10-03-project-audit/10-llm-cost-ledger.md).
- [ ] **FIX-11 / P2 — Shared inference rules and vocabulary.** Remove duplicate inference fixes, cover feminine/plural titles and explicit zero experience, normalize skills/studies/languages using preserved evidence, and generate PHP labels from one vocabulary source. [Spec](specs/2026-10-03-project-audit/11-shared-inference.md).
- [ ] **FIX-12 / P2 — Retire duplicate public UI and correct architecture docs.** Retain Django models/migrations/admin/commands and useful comparison tools; establish PHP regression coverage before removing public routes/templates. Correct stale agent/setup/context guidance. [Spec](specs/2026-10-03-project-audit/12-frontend-retirement.md).

## Data-quality follow-ups

These remain valid, but their old counts/blockers need fresh measurement. They are
bounded follow-ups, not permission for broad paid backfills.

- [ ] **DATA-01 — OCR and attachment recovery.** Consolidates the historical 389/980/996 scanned-PDF items. Inventory current unreadable files, share extraction/OCR logic, add a bounded opt-in portable OCR path with Romanian language support and a cache keyed by content. Preserve per-reason failures and provenance, including broken DOCX/JPG cases. Re-extract changed attachments and invalidate enrichment through FIX-03. Report actual recovered coverage.
- [ ] **DATA-02 — Parser correctness and field semantics.** Reproduce project-code numbers being mistaken for `nr_posturi`; preserve legitimate large competitions rather than blindly capping counts. Replace naive attachment-link splitting with a round-trip-safe representation and old-CSV compatibility. Trace crossed `categorie`/`employer_category` labels on old/new fixtures. Review broad calendar matchers under FIX-05 and remove/implement dead `work_conditions` output deliberately.
- [ ] **DATA-03 — Independent extraction fidelity review.** Add cost-bounded sampling of fresh extractions (starting proposal: 15–25 weekly), persisted grounding findings and source-backed review of deadlines, duties, fees vs salary, ISCED and multiple roles. Re-measure sparse responsibilities and unsupported claims; repair/reroute only justified failures. Preserve calendar/bibliography under section-aware truncation. Evaluate the quality-check tool independently of shared inference functions; report denominators and residual error classes.
- [ ] **DATA-04 — Multi-role occupation and search semantics.** Detail `positions[]` already renders; the remaining gap is single-occupation title mapping and posting-level filters that combine different roles' requirements. Use per-position mapping and salary provenance, then decide whether role-specific search entries are needed while preserving announcement IDs/URLs and feed semantics. Correct the single-role disclosure that currently claims multiple roles for one `positions[]` entry. Measure current corpus prevalence rather than retaining the old 8.4%/21% estimates.
- [ ] **DATA-05 — Institution identity and employer discovery.** Employer slug/id scoping already exists in `build_filters()`; keep it. Add employer text search/typeahead and a canonical institution-kind classifier on Employer with unknown/review state, plus parent-institution context where available. Keep institution kind separate from contract type and source labels; apply aliases at import consistently and review obsolete zero-posting employers before cleanup.
- [ ] **DATA-06 — Salary estimate accuracy and explanation.** Supply a sourced, versioned UAT population table in the supported `data/uat-populatie.csv` format (`nume`, `judet`, `populatie`; optional stable SIRUTA/type). Verify source vintage, county disambiguation and administrative-unit scope before recalculation. Review flagged grid rows; materialize another grid only from its actual coefficient workbook, not by changing reference value alone. Keep draft-law provenance, announced-vs-estimated pay and occupation confidence visible. Recalibrate salary buckets only from current distributions; estimates remain secondary to source facts.
- [ ] **DATA-07 — Vocabulary interoperability.** After FIX-11 normalization, consider local ESCO URI resolution from a versioned official dump; keep original evidence and unresolved tags. Assess rare policy-domain values with usage data rather than deleting legitimate niche categories. Grow hard-skill/licence coverage, including the historical communication-project example, through measured dictionaries and reviewed extraction.

## Product follow-ups

These require a focused design/acceptance spec before implementation. The broad
UI spec is context, not a direction to build every listed feature at once.

- [ ] **UX-01 — Simplify facets and explain inferred choices.** Consolidate overlapping study/EQF, role/seniority and source/v3 contract/program controls. Define nominal vs min/max semantics and matching count queries; preserve shared URLs. Reduce default-open groups, move pipeline diagnostics behind an explicit advanced/debug disclosure, translate internal enum labels, and reconsider shortcuts that select almost the entire corpus. Explain family/seniority/occupation uncertainty alongside salary provenance.
- [ ] **UX-02 — Locality discovery.** Add county-scoped locality filtering, conservative employer/body locality backfill with unknown confidence, and a sourced gazetteer for coordinates/map drill-in. Existing normalized locality names do not themselves provide coordinates. Review before/after matches and same-name localities across counties.
- [ ] **UX-03 — Useful expired/cancelled pages.** Design retained lightweight records or an archive export rather than simply dropping `--active-only`. Preserve title/employer/dates and a clear closed/cancelled state; decide body/attachment retention, similar-job ranking and employer archive presentation. No unknown missing ID may be labelled expired by guess. Specify crawler/sitemap/JobPosting metadata behavior and performance before rollout. Source cancellation detection is FIX-03; archived-page display remains separate.
- [ ] **UX-04 — Feed/API and sharing improvements.** Consider linking Atom entries to local detail with a separate official-source link; document/export cap and pagination or subscription behavior. Add a static Open Graph image first if justified, then per-post cards. Preserve canonical numeric/slug URLs already supported; new county paths/aliases require evidence. FIX-02 owns deadline/iCal correctness, FIX-07 owns request/format regression coverage.
- [ ] **UX-05 — Landing/category discovery.** Current home already has shortcuts; retain that shipped behavior. Evaluate new-today/closing-soon sections and profession hub pages rather than rebuilding KPI tiles from an outdated spec. Add icons/emoji only after a consistent accessible design is agreed; prioritize useful discovery over decoration.
- [ ] **UX-06 — Theme/language and asset cleanup.** Keep skins; consider a separate light/dark axis with contrast checks and RO/EN translations. Review active-skin font preload behavior (current default is Manrope, not the old DM Sans/Fraunces note). Replace structural card-shadow selectors only if another skin needs the utility. Avoid cache-fragmenting cookies merely to choose a preload.
- [ ] **UX-07 — Candidate profile matching before CV upload.** Validate hard requirements and uncertainty on a structured profile form first; distinguish unobserved requirements from satisfied ones and prevent cross-role matches. CV/Europass parsing, accounts, saved searches, alerts and application tracking follow an explicit privacy/retention decision. Do not assume a vector database is needed; measure baseline matching/search before adding one. Optional MCP access belongs to a separate bounded interface design.
- [ ] **UX-08 — Expanded research analytics.** After FIX-09, consider all-county/per-capita regional views, maps, institution hierarchy and similar/reposted-job tracking (`is_repost_of`). Define source population vintage, denominator, duplication/window and historical observations before calling any curve a hiring trend.

## Deferred engineering and source expansion

- [ ] **LATER-01 — Performance and storage simplification.** Measure before optimizing PHP JSON facets/count fan-out; consolidate bucket counts or normalize `posting_values` only when justified by observed/archive load. Removing CSV interchange, renaming `webapp/`, shipping the whole archive or changing hosting are separate migrations with compatibility/rollback evidence. Keep the current split deployment until a concrete limitation warrants change.
- [ ] **LATER-02 — Provider and prompt cost optimization.** Validate explicit Gemini caching, cache-hit paths for all providers, batch support/pricing and optional quality-based model routing using current measured costs. FIX-10 supplies accounting first. Historical prices/model availability are not a current quote; update comparison config before paid experiments. Avoid optimizing a low-cost incremental workload before fixing reliability.
- [ ] **LATER-03 — Additional sources and document viewing.** Assess employer-site announcements against official-portal duplicates and a clear source/licensing policy; preserve original provenance. Multiple attachment links are already supported. An in-site DOC/DOCX/PDF viewer needs a separate rendering/security design and format coverage; retain external links in the meantime.
- [ ] **LATER-04 — Better source submission form.** Prepare a concrete proposal for posturi.gov.ro only after parser/field failure evidence is collected. This is a product proposal, not a coding task or authorization to contact anyone.

## Consolidation decisions

Completed work remains in the activity log and the preserved backlog rather than
mixed into this open queue. [The consolidation record](audits/2026-10-03-backlog-consolidation.md)
maps every previously open checkbox to its destination or superseded status.

- County normalization, locality storage, portable DOC extraction, `--limit`, retry/concurrency, feed routing, skins and v3 detail rendering already exist; they are not new feature gaps.
- October 3 intake/extraction recovery supersedes the old blanket “all providers dead”, “no scheduler” and “five-week-stale live database” blockers. OPS-02 still verifies current unattended operation; FIX-04 prevents recurrence.
- The old 79–80% missing-schema and specific missing-row counts are incident snapshots, not current tasks. FIX-05/DATA-03/OPS-03 measure the remaining work.
- The Django main body/schema renderer already uses `nh3`; the confirmed unsafe production path is PHP. FIX-01 audits all relevant PHP outputs; FIX-12 handles the retained developer UI separately.
- Credential-kind alias normalization already exists in `schema_models.py`; new invalid enum patterns belong to DATA-03 rather than reimplementing that alias.
- Active CI is still open: the prior workflow is archived under `_OBSOLETE/`. See FIX-07.

## Completion convention

Keep IDs stable. When an implementation passes its spec, record the change and
validation in `activity-log.md`, then mark the task done. Keep production rollout,
backfills and host verification open until independently evidenced. Add newly
found work under the existing ID when it is the same problem; use a new checkbox
only for genuinely separate scope. Never replace dated evidence with invented
current counts or silently discard a deferred idea.

# October 10 follow-up assignments

Status: first slices implemented locally 2026-10-10 (not deployed; UX-10 gated off;
FIX-05-STAGES policy still undecided) — see each file's "Implementation notes". The
[backlog](../../backlog.md) is authoritative.
These packages incorporate October 4–10 operational and product findings. Existing
FIX and October 6 product specs have October 10 addenda; read those before work.

| Package | Assignment | Prerequisites |
|---|---|---|
| FIX-05-STAGES | [Calendar-stage failures](01-calendar-stages.md) | Recorded stage-policy decision before behavior change |
| DATA-04 | [Role-scoped requirements and search](02-role-requirements.md) | Existing extraction provenance; coordinate FIX-11 |
| UX-13 | [Requirement evidence](03-requirement-evidence.md) | DATA-04 role scope; coordinate DATA-03 |
| UX-10 | [Eligibility preparation panel](04-eligibility-panel.md) | DATA-04, UX-13 |
| UX-11 | [Application preparation checklist](05-application-checklist.md) | DATA-04, UX-13, UX-09 |
| UX-12 | [Saved-job change notices](06-saved-job-changes.md) | FIX-03, DATA-04, UX-09; durable history first |

Recommended order: resolve stage policy independently; DATA-04, then UX-13,
then eligibility/checklists. Change notices require a history inventory first.
Implement one bounded assignment at a time. No agents, paid calls, host changes,
backfills or deployments are implied. Use existing PHP/Python/browser fixtures;
validate migrations and old-export compatibility for new data contracts.

## Coverage boundary

Detailed original specs cover FIX-01..12, not the entire backlog. October 6 specs
cover UX-01A, UX-09 and UX-04-FEEDS. This directory covers the six packages above.
Other DATA/OPS/LATER and UX packages retain backlog-level scope and require focused
acceptance designs before implementation. In particular UX-14 needs an email
subscription/provider/retention design, and table mode needs its own interaction
spec. Do not treat product-direction.md or ui-spec.md as equivalent assignments.

Desktop horizontal filters and a card grid already exist in compact mode; the
backlog retains rollout/acceptance work rather than requesting duplicate UI.

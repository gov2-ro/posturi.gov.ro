# Product direction — an application companion

Agreed 2026-10-03 after the project audit and feature discussion. The user asked
to aim to implement or address **all the capabilities below**, including the
additional suggestions. This is intended product scope, with phased delivery;
it is not a claim that the features exist or a commitment to delivery dates.

The [backlog](backlog.md) remains the task queue. The
[technical remediation specs](specs/2026-10-03-project-audit/README.md) cover the
reliability foundation, and [the initial audit](audits/2026-10-03-project-audit.md)
records the evidence. The older [UI spec](ui-spec.md) supplies broader context;
its deployment assumptions and historical counts need checking against source.

## Product goal

Help people **find suitable public-sector jobs, understand whether they can
apply, and keep track of their applications**. Discovery should lead naturally
to deciding, preparing documents and following a competition through changes.
The product should become an application companion, with research features as
a complementary surface.

## User-facing capabilities already in the backlog

| Capability | Intended outcome | Tracking |
|---|---|---|
| Clear deadlines and status | Distinguish confirmed submission dates, expiry estimates, closed competitions and cancellations. | FIX-02, FIX-03, FIX-05, UX-03 |
| Simpler filters | Remove overlapping controls, use understandable Romanian labels and explain inferred requirements. | UX-01, FIX-11 |
| Location discovery | Filter by city/commune within a county; follow with map exploration. | UX-02 |
| Employer discovery | Search employers and filter by actual institution type, including schools, hospitals and local authorities. | DATA-05 |
| Multi-role announcements | Show and search each role's own requirements, occupation and salary context. | DATA-04 |
| Useful expired/cancelled pages | Retain meaningful announcement information and suggest similar open jobs. | UX-03 |
| Improved discovery | Offer new-today and closing-soon sections and profession/category pages. | UX-05 |
| Candidate matching and ongoing use | Start with a short profile form; follow with CV/Europass input, saved searches, alerts and application tracking. | UX-07 |
| Sharing and subscriptions | Improve feeds, calendar subscriptions and link previews while preserving useful filtered URLs. | UX-04, FIX-02 |
| Email notifications (later) | Subscribe to new announcements matching a filtered search; later notify about verified changes to followed competitions. | UX-14, UX-12 |
| Richer information | Make salary estimates clearer; add trustworthy research statistics, document previews and additional announcement sources. | DATA-06, FIX-09, UX-08, LATER-03 |
| Presentation options | Add dark mode and English translation alongside the existing visual skins. | UX-06 |

These are separate from internal refactoring and provider optimization. Better
data quality is a prerequisite wherever a feature makes an eligibility, deadline
or change claim.

## Additional enhancements accepted into the direction

### Save and hide jobs without an account — UX-09

Provide a browser-local shortlist and a reversible “not interested” action.
Readers can return to saved jobs and reduce repeated irrelevant results without
creating an account. Explain that preferences remain in that browser and may be
lost when browser data is cleared. Plan optional transfer/sync later under UX-07;
do not require an account for the first version.

### A compact “Can I apply?” panel — UX-10

Show required education, experience and licences, with **matches / missing /
unknown** against information the reader supplies. Separate every role in a
multi-role announcement. Missing source information stays unknown; the panel is
decision support, not an official eligibility certification. This is the focused
first step toward UX-07's broader ranked profile matching.

### An application checklist — UX-11

Turn required documents and competition stages into a checklist readers can
tick off. Link each source-derived requirement back to the official announcement;
make uncertain or incomplete extraction visible. Save progress locally first,
and distinguish document preparation from an actual submitted application.

### “What changed?” notices — UX-12

Highlight revised deadlines, cancellations and changed requirements for saved
jobs. Show the previous and current fact and when the change was observed. This
depends on FIX-03 refreshing source content and retaining revisions. An unchanged
listing or failed refresh must not imply that no changes occurred. Start with
notices on return visits; delivery through alerts follows UX-07's channel and
retention design.

### Evidence beside important requirements — UX-13

Let readers expand a requirement such as “minimum 3 years' experience” to see
the original sentence and its source. Preserve the distinction between scraped,
inferred and estimated values. If evidence cannot be verified, show that limit
rather than inventing a quote. This gives readers a practical way to inspect
automated extraction and supports the eligibility/checklist panels.

## Recommended delivery order

All capabilities remain in scope. This order expresses the initial recommendation,
not an instruction to abandon later features or implement everything together.

1. **Reliable discovery:** correct deadlines/status, simpler filters,
   locality/employer search and a shortlist without accounts. Complete the
   security and source-refresh foundation alongside these user-facing changes.
2. **Preparation and follow-through:** requirement evidence, application
   checklist, useful closed/cancelled pages and change notices. Improve sharing
   and calendar/feed subscriptions where they support this workflow.
3. **Personal relevance:** the compact eligibility panel and profile matching,
   followed by saved-search alerts, CV input and optional accounts/application
   tracking. Requirement accuracy and per-role matching must be dependable first.
4. **Broader reach and insight:** category hubs, maps, richer salary explanations,
   research analytics, document previews, additional sources, dark mode and
   English translation. Independent useful improvements can ship earlier when
   their prerequisites are ready.

## Design and completion principles

- Keep unknown information visible; do not treat it as a satisfied requirement.
- Keep official source links accessible and salary estimates secondary to facts
  actually stated in the announcement.
- Distinguish a role, an announcement and a competition throughout matching and
  application progress.
- Begin personal features with minimal browser-local state. Specify privacy,
  retention and export/delete behavior before server-side CV/account storage.
- Use focused designs and acceptance criteria for each package; this direction
  records intent and dependencies, not detailed implementation specifications.
- Record actual delivery in the activity log and complete the corresponding
  backlog item only with validation. If a capability needs a different approach,
  record that decision explicitly rather than silently dropping it.

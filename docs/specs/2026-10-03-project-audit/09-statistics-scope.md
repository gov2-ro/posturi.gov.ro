# FIX-09 — Correct statistics scope

Priority: P2. Dependency: FIX-05 status contract (satisfied in code as of 2026-10-04). Audit finding: 7.

## Problem and scope

Public stats query the expiry-selected SQLite slice. A monthly grouping over
survivors cannot describe historical intake, and `employer_category` commonly
contains position/contract labels, despite the heading “Tip angajator”. Own
stats/employer analytics copy, aggregation export and tests. This is independent
of shipping every expired job body to the public host.

## Required behavior

- First make current-slice analytics honest: label the dataset and build date;
  distinguish all exported announcements, confirmed open, uncertain and closed
  under FIX-05. Label monthly publication distribution as belonging to the
  current export, or hide it until historical aggregates are available.
- Rename the existing employer-category chart to its actual source semantics.
  Do not manufacture institution-type analytics from contract labels. A new
  canonical institution classifier is a separate backlog item; display unknown
  where no reliable classification exists.
- Export compact, versioned historical aggregates directly from PostgreSQL for
  publication counts by Bucharest month and, where useful, county/employer. Define
  denominators, inclusion of cancelled/unknown rows, date window and missing
  values. Keep source IDs stable and missing dates explicit. Monthly publication
  intake is not a reconstruction of how many jobs were open on a past date;
  historical active-stock curves need separate observation data.
- Never mix all-history and current-slice figures under one unlabeled total.
  Changes caused by deleting expired rows from SQLite must not erase historical
  publication totals. If aggregate tables are missing on an old export, display
  “indisponibil” or clearly scoped current data rather than inferred history.
- Keep salary-announced and salary-estimated counts distinct; estimates from an
  unadopted draft are not salaries offered by the employer.

## Acceptance and tests

Use a fixture with older expired announcements omitted from the public slice.
Historical monthly totals stay fixed across two export dates; current-slice
totals change and their labels say so. Match aggregate totals against direct
PostgreSQL queries. Cover null dates, cancellations, timezone boundaries,
unknown institution types and legacy SQLite. Browser-check labels and charts.

## Rollout and completion

Ship accurate current-slice copy first. Deploy readers with graceful fallback,
then the aggregate producer. Include exported table definitions and example
reconciliation queries. Whole-archive export/performance work remains deferred.

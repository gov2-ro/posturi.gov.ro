# FIX-02 — Make deadline presentation consistent

Priority: P1. Dependencies: none; coordinate the final status vocabulary with
FIX-05. Audit findings: 2 and 5.

## Problem and scope

`partials/result_list.php` computes days from `apply_deadline` but prints
`expires_at`. On October 3 a row said “13 zile” and “10.11.2026”. The fallback
date is disclosed on detail pages but reads like a confirmed submission date in
list rows. Own PHP deadline presentation in helpers, results, detail and feeds;
keep JSON's existing `expires_at` field for compatibility.

## Required behavior

- Resolve the displayed date once. Countdown, printed date, `<time datetime>`,
  sort, detail summary and calendar reference that same application-date value.
  Retain announcement expiry separately, labelled as expiry if shown.
- Preserve `deadline_source` values `concurs`, `anunt`, `expirare` and unknown.
  For `expirare`, show a short visible qualification such as “Data expirării;
  termenul de înscriere nu este confirmat”. Never call it a confirmed
  “Înscrieri până la”. Do not use tooltip-only disclosure for this distinction.
- For no date, render an explicit unknown state without a fabricated countdown.
  Do not gate a known application date on `expires_at` being non-null.
- Compute day boundaries in Europe/Bucharest on every PHP host. A deadline
  recorded with an exact time must not later be described as open until 23:59
  merely because another surface only has date precision; preserve available
  precision and disclose date-only limits.
- Keep JSON source provenance and make fallback uncertainty visible in Atom and
  iCal descriptions. All-day iCal events must have an exclusive end date of the
  next day, not the same day as `DTSTART`; validate this while touching the feed.
  Keep UIDs stable and declare feed caps/documented subscription limitations.

## Acceptance and tests

Use a fixed clock and fixtures with application deadline earlier than expiry,
same-day deadline, past deadline, missing expiry, expiry fallback and no dates.
Assert visible text and machine dates agree across list/detail/feed surfaces.
Cover midnight and both Bucharest UTC offsets. Validate generated iCal with a
calendar parser and check one-day event duration. Preserve stable URLs, feed
UIDs, and `expires_at`/`apply_deadline` JSON compatibility. A rendered regression
test must catch the original countdown/date mismatch; source-string tests alone
did not catch it in the existing suite.

## Rollout and completion

This is a code-only presentation correction. It does not claim that fallback
dates became accurate or that v4 is deployed. Review desktop, 320/375px mobile
and HTMX results. Record fallback wording and remaining uncertainty; FIX-05 owns
selection semantics and production deadline coverage.

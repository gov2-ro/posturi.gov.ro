# Brief: posting anomalies (ANOMALY-SURVEY)

Date: 2026-11-22. Author: Fable session, 15-minute survey. Builder: Sonnet.

## Goal

Find the postings that are unusual, by intent or by error. Separate three classes:

- **A. Our data defects.** The pipeline made the value. Fix the pipeline; do not show a flag.
- **B. Rule anomalies.** One posting breaks a rule that a reader can check.
- **C. Statistical anomalies.** A posting or an employer is far from its peers.

Today the app has six flags (`infer_postings._infer_anomaly_flags`): `short_deadline`,
`missing_contact`, `contact_in_attachment`, `gender_criteria`, `no_body`, `frequent_repost`.

## Baseline (measured)

Source: `tmp/261118/grid153/posturi-after2.sqlite`. It holds 9,756 postings, published
2024-07-23 to 2026-09-09. This is the September copy: the v5 columns are empty.
**Measure again on the VPS export before you decide a threshold.**

| # | Check | Count | Class |
|---|---|---|---|
| 1 | `nr_posturi` = 339395 (a sentinel or parse error) | ≥ 8; 50 rows > 20 | A |
| 2 | `calendar_events.data` year outside 2024–2026 (1985…2019, 2027, 2028) | ~75 | A |
| 3 | `calendar_events.eveniment` is `–`, `•`, `-` or empty | 1,713 | A |
| 4 | `inf_salary_min` < 1000 (hourly rate 60–70 lei read as monthly pay) | 10 of 16 | A |
| 5 | Employer name variants (same name after diacritics fold) | 333 keys of 5,901 | A |
| 6 | Deadline on the publication day (0 days) | 392 | A? |
| 7 | Deadline before publication | 29 | A or B |
| 8 | `short_deadline` flag total (< 7 calendar days) | 531 | B |
| 9 | Written test before deadline / interview before written / results before interview | 3 / 9 / 26 | B |
| 10 | Written test 0–5 days after the deadline | 63 | B |
| 11 | Event on Saturday or Sunday | ~40 | B |
| 12 | Title says "debutant", experience ≥ 1 year | 90 | B (mixed titles) |
| 13 | Experience ≥ 7 years and > 3 × group mean + 3 (`occ_group`, n ≥ 30) | 26 | C |
| 14 | Experience ≥ 16 years (caretaker 19, storekeeper 20, guard 25) | 17 | A? or C |
| 15 | Employer with ≥ 8 postings and ≥ 50% `short_deadline` | 9 | C |
| 16 | Same employer + title ≥ 3 times | 47 groups | C |
| 17 | Employer publishes ≥ 10 postings on one day | 13 | C |
| 18 | Contact email on gmail/yahoo | 1,935 of 4,724 | not an anomaly |
| 19 | `expires_at` less than 5 days after publication | 88 | B |
| 20 | Role with ≥ 12 skills (max 51) | 78 of 11,209 | C |

Not measured: content hash duplicates returned 0 groups. `sal_min > sal_max`: 0.

## Assumptions (not verified)

- Row 6: the 0-day deadline is a parser artifact (the parser takes the publication date).
  If true, 74% of the `short_deadline` flags are false. **Verify this first**: read 10 pages.
- Row 14: the high values are LLM errors (age, or years until pension), not requirements.
- Row 18: gmail/yahoo is normal for schools (719 of 890) and town halls (567 of 1,349).
  It is a signal only for an employer kind where it is rare (universities: 14 of 189).
- The legal minimum for the application period is not confirmed. HG 1336/2022 (contract
  staff) gives 10 working days, as far as the author remembers. Confirm the text before use.
  Civil servants and temporary posts have other periods.

## Work

### Step 1 — survey script (read-only)

1. Write `ops/anomaly-survey.py`. Input: the path of an export SQLite. Output: one JSON
   report and one CSV of `posting_id, check, value, peer_value`.
2. Implement the rows of the table as separate functions, one check per function.
3. Add the checks below. They need more than one SQL line.
4. Add tests with small fixtures, one per check.
5. The VPS session runs the script on the live export. Report the counts next to the
   baseline above.

Checks to add:

- **Working-day window.** Count working days from publication to deadline. Use the holidays
  from `core-assets`. Report the distribution by `job_regime` (contract, civil servant).
- **Window across a holiday.** The window contains Christmas, Easter or 1–15 August, and
  it has fewer than N working days.
- **Repost chain.** Same employer + normalised title, next posting within 90 days. This
  shows a competition that failed or was cancelled. Use `_norm_title`. Merge the employer
  variants (row 5) first, or the chain breaks.
- **Edit after publication.** Use the `updates` column of the index CSV. Flag a change of
  deadline or of title after the first sight.
- **Narrow requirements** (possible tailored post). For each role, compare with the peers
  in the same `occ_group`: experience years (z-score), count of credentials, count of
  skills, a rare `isced_fields` value. Flag a role that is in the top 2% on two or more
  measures. Use only facts with `requirement_evidence.status = 'verified'`.
- **Declared pay against the grid.** `inf_salary_*` outside `sal_min..sal_max` by more than
  20%, after you exclude hourly rates (row 4) and pay below the minimum wage.
- **Rare contact domain.** Free-mail contact where fewer than 15% of the same
  `employer_kind` use one.
- **Old event dates.** A calendar event more than 30 days before publication.

### Step 2 — decision (Opus or owner, not Sonnet)

After the VPS counts arrive, decide for each check:

- class A → a backlog item for a pipeline fix;
- class B → a new flag in `_infer_anomaly_flags`, with a Romanian label;
- class C → an employer-level report or an admin filter, not a public flag.

A public flag about an employer is a claim. Show a class C result to the public only
after a person reads a sample.

## Stop and ask

- Before you change `_infer_anomaly_flags`, `ops/check-export.py` thresholds or a parser.
- Before you rename or remove an existing flag (filter URLs depend on the names).
- When a check needs a column that the export does not hold.

## Report

For each check: the count, the share, 5 sample URLs, and the false positives you saw in the
sample. Say what you measured and what you assumed.

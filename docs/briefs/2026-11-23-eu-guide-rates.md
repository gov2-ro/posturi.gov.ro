# Brief — spike: hourly rate caps in the applicant guides (EU-GUIDE-RATES)

Date: 2026-11-23. Author: Opus. Builder: Sonnet. Owner decision: D4 of `docs/audits/2026-11-23-eu-rules-pcidif.md` (yes, 2026-11-23).

## Question

Do the applicant guides of the programmes that fund **public employers** cap the hourly rate of a project-hired post? If yes: by role, by level of studies, by years of experience, gross or net, and in lei or euro?

If a guide caps the rate, SALARY-EU-DETAIL can show „maxim N lei/oră conform ghidului” for a post with no stated pay. The audit of 2026-11-18 (§ 3) found no cap in the acts; Art. 16 alin. (10^2) Legea 153/2017 points to the guide.

## Read first

- `docs/audits/2026-11-18-salary-eu-projects.md` § 3 and § 4.
- `docs/audits/2026-11-23-eu-rules-pcidif.md` § 1 (the Action 2.1 „Limita max €/h” lead).
- `docs/audits/2026-11-20-salary-pids.md` (what was already tried for PIDS; adrcentru.ro blocks scripts).

## Scope, in order

1. Find the most common SMIS codes and programmes among project hires in `data/_dev/full-2026-11-23.sqlite` (`project_hire = 1`; pattern `SMIS[^0-9]{0,25}(\d{5,6})`, case-sensitive). Top 10 codes, with the programme that the posting names.
2. For the programmes that cover most of these postings (expected: PIDS / PoIDS 2021–2027; PEO; PCIDIF Actions 2.2.x and 3.1), get the applicant guide (general and specific conditions) from mfe.gov.ro, oipsi.gov.ro or the regional ADR site. Use a browser where a site blocks scripts. Keep each file in `data/eu-guides/` (not in git); record the URL and the date in the audit.
3. In each guide, find any cap on salary cost: per hour, per month, per role, or as a share of the budget. Quote the text and the page.
4. Compare a found cap with the stated rates in the postings of the same programme (`proj_pay_amount`, `proj_pay_unit`, `proj_pay_basis`). Report how many postings sit at the cap.

## Out of scope; stop and ask

- No code and no change to the webapp or the pipeline.
- No call to an LLM over the guides.
- Stop after three programmes if none has a cap, and report.

## Output

`docs/audits/2026-11-23-eu-guide-rates.md`: per programme, the guide, the cap or „no cap found”, the quote, and the posting comparison. Mark each claim measured, assumed or unverified. Update EU-GUIDE-RATES in `docs/backlog.md` and add an entry to `docs/activity-log.md`.

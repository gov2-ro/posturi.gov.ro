# Audit — PCIDIF recruitment rules for EU project posts (EU-RULES-PCIDIF)

Date: 2026-11-23. Author: Opus. Source: `docs/archive-reference/Reguli Recrutare Proiecte Europene PCIDIF.docx` (commit `51ffafd`).
Data: `data/_dev/full-2026-11-23.sqlite` (built 2026-11-23 09:52 UTC on gov2-1, code `4d9938c`, 16,875 postings, all statuses). Scripts: session scratch, not kept; the patterns are in § 4.

## Summary

1. **The document is a secondary source.** It is an AI research report written for one reader. It answers one question: may a posting ask for 7 years of experience (ePatrimoniu, SMIS 320807)? It cites consultancy summaries, Reddit and press next to acts. Use it as a list of leads, not as law. One of its leads is out of date: it names HG 325/2018 for posts outside the organigramă. HG 234/2023 repealed HG 325/2018 (audit of 2026-11-18, § 3). No posting in the data cites HG 325/2018 (0 of 1,033 project hires).
2. **It fixes no pay.** It confirms the audit of 2026-11-18: no act sets a lei amount for a project-hired post. The pay is the hourly rate of the funding request. It adds one fact: an applicant guide can cap the rate by level of studies and years of experience („Limita max €/h”, PCIDIF Action 2.1, for SMEs). So the guides **can** hold rate caps. The guides for public bodies are not read yet.
3. **It fixes no roles.** It names the minimum team of one call (a coordinator and two technical experts, Action 2.1, SMEs). This does not apply to public employers.
4. **The main result is negative, and it is useful.** No act caps the years of experience for a post filled by contest (HG 1336/2022) or by an internal procedure (Art. 16 alin. (10^6) Legea 153/2017). The ban on restrictive experience is a rule of public procurement (Legea 98/2016, ANAP Instrucțiunea 1/2017, OUG 66/2011). It does not apply to employment. So the site must not call a high experience requirement an error or a breach. Checks R13 and R14 stay `unusual`.
5. **The rule that does apply is soft.** The EU horizontal principles ask for equal opportunities, non-discrimination and accessible information in the job notices. Art. 16 alin. (10^6) Legea 153/2017 asks for a transparent, non-discriminatory internal procedure. Neither text gives a measurable limit. They support signals, not verdicts.
6. **The data shows two preference criteria in one template.** 244 of 1,033 project hires (24 %) say „Constituie avantaj domiciliul în UAT”. 224 (22 %) say that prior work in the pilot project (MySMIS 122607) is an advantage. Both come from the PIDS template (SMIS 339395). No act that we read sets these criteria, and the posting does not say how the advantage is scored. These are candidate anomaly signals (§ 5).

## 1. What the document says, and how far to trust it

| Topic | Claim | Basis in the document | Status for us |
|---|---|---|---|
| Recruitment act | The ePatrimoniu post is filled by contest under HG 1336/2022, not by procurement. | the UMP notice | Agrees with the data: 659 of 1,033 project hires (64 %) cite HG 1336/2022. |
| Posts outside the organigramă | HG 325/2018 | a consultancy page | **Out of date.** HG 234/2023 is in force (audit 2026-11-18). |
| Experience cap | None for employment. Procurement bans restrictive requirements. | Legea 98/2016, ANAP, CNSC decisions, ADR case books | Plausible and consistent with HG 1336/2022, which lets the employer set the experience. We did not read the ANAP guide. |
| Horizontal principles | Notices must give equal chances and accessible information. | regional guides (ADR Nord-Est), MIPE | Consistent with Art. 16 alin. (10^6). No numeric rule. |
| Hourly rate | Annual gross cost ÷ 1,720 h for a full-time post. | Todos summary of HG 873/2022 | The 1,720 h divisor is a rule for **reimbursement** of staff cost. It is not a work schedule. Not checked in the act. |
| Rate caps | Action 2.1 guide caps € per hour by studies and experience. | MIPE consultation table, June 2026 | Lead. Not read. Applies to SMEs. |
| Timesheets | The hours reported must match the work schedule and the norm. Monthly report per expert (Anexa 13). | PoCIDIF Beneficiary Manual, Aug 2025 | Lead. Confirms Art. 16 alin. (10^4) (maximum hours, cumulation). |
| Transparency | The managing authority publishes the list of operations at least three times a year. | Reg. (UE) 2021/1060 | Lead for EU-PROJECT-SICAP. Format and URL not checked. |
| Appeals | 1 working day after each result. | HG 1336/2022 | Agrees with art. 53, which the employer assistant already uses. |

## 2. Measured on the export of 2026-11-23

| Measure | Project hires (`project_hire = 1`) | Other postings |
|---|---:|---:|
| Postings | 1,033 | 15,842 |
| State an SMIS code (`SMIS … NNNNN[N]`) | 591 (57 %) | 40 (38 of them flagged `fonduri_europene`) |
| Distinct SMIS codes | 95 (21 used by more than one employer) | |
| Top codes | 339395 (PIDS, 285), 122607 (pilot project, 246) | |
| Cite HG 1336/2022 | 659 (64 %) | |
| Cite HG 234/2023 | 46 | |
| Cite Art. 16 Legea 153/2017 | 34 | |
| Mention an internal procedure | 65 | |
| Cite HG 325/2018 | 0 | |
| Window publication → deadline under 10 working days | 151 of 1,033 (14.6 %) | 1,378 of 15,826 (8.7 %) |
| … of which cite HG 1336/2022 | 90 of 659 (13.7 %) | |
| … of which do not cite it | 61 of 374 (16.3 %) | |
| „Avantaj: domiciliul în UAT” | 244 (24 %) | |
| „Avantaj: activitatea în proiectul (pilot)” | 224 (22 %) | |
| Experience in EU-funded projects (required or an advantage) | 16 (1.5 %) | 7 |
| English at an advanced level | 12 (1.2 %) | 55 (0.3 %) |
| 7 or more years of experience | 34 (3.3 %) | 997 (6.3 %) |

Notes:
- The working-day count ignores public holidays. It is a rough split, not the R08 check.
- The project posts that do not cite HG 1336/2022 do not have shorter windows than those that cite it (16.3 % against 13.7 %). So the act that the posting cites does not explain the short windows.
- „Domiciliul în România” is a general condition of HG 1336/2022 art. 15. The domicile counts above exclude it: they match only „avantaj … domiciliu”.
- Experience requirements are **lower** in project posts (7+ years: 3.3 % against 6.3 %). The ePatrimoniu case is not typical.

## 3. What changes for each item

### SALARY-EU-DETAIL (done) — no change

- The rule „hide the grid figure, show the stated pay” stays. The document confirms that no lei maximum exists.
- **Do not** turn an hourly rate into a month with 1,720 ÷ 12 = 143.3 h. That divisor is for reimbursement. Today the code makes a month figure only when the posting states the hours (`helpers.php:1286`). Keep it. Measured: 10 of 29 stated pays are hourly with no hours; they keep no month figure.
- New, optional: show the SMIS code on the detail page as a fact („Proiect cod SMIS 339395”). 591 project hires state one. It is the key that links a posting to a project. Not a pay figure. Owner decides (§ 6, D1).

### SALARY-EU-ESTIMATOR (open) — two additions to the future brief

- Case 1 (the raise up to 40 %) uses HG 234/2023 Anexa 1. The hours on the project and the **value of the project** pick the percentage. The value can come from the list of operations by SMIS code (EU-PROJECT-SICAP). So the estimator can ask for an SMIS code and fill the value band. Wait for EU-PROJECT-SICAP.
- Add one sentence: the hours on a project must match the timesheet and the norm (Art. 16 alin. (10^4); Beneficiary Manual). A person cannot report project hours that overlap the main schedule.
- The document gives no reason to raise the priority. Case 1 still does not appear in postings.

### Employer assistant („Asistent redactare anunțuri angajare”) — a project mode

v1 covers contract staff under HG 1336/2022. Project posts are 6 % of all postings, and 64 % of them follow HG 1336/2022. So the assistant serves them already. A project mode adds:

1. Fields: programme, SMIS code, project end date (the contract is fixed-term to that date), pay as rate + unit + gross/net + hours per month. These are the fields of D3 (`proj_pay_*`). A posting made with the assistant then gives the pay that the pipeline misses today (it holds 29 of about 1,000).
2. The legal basis line: Art. 16 alin. (10) Legea 153/2017 and HG 234/2023, and the procedure (HG 1336/2022 or the internal procedure of the employer).
3. Soft hints, not blocks: an „avantaj” without a score in the selection grid; a requirement of experience in a named project; years of experience well above the median of the occupation (the R13 group mean). Each hint says that the law allows it, and that it narrows the field of candidates.
4. A reminder of the horizontal principles: equal chances, accessible information.

Owner decides (§ 6, D2). Sonnet builds from a brief.

## 4. Patterns used

- SMIS: `SMIS[^0-9]{0,25}(\d{5,6})` (case-sensitive; `LIKE '%SMIS%'` also matches „tranSMIS”).
- Domicile advantage: `avantaj[^.\n;]{0,15}domicili` (case-insensitive).
- Prior project advantage: `avantaj[^.\n;]{0,80}(proiect|MySMIS)`.
- Experience in EU projects: `experiență … proiecte … fonduri (europene|nerambursabile|structurale)`, within one sentence.
- 7+ years: `(minim(um)?|cel puțin)\s*(7|8|9|1[0-9])\s*ani` (also matches age or term phrases; a rough count).

## 5. Limiting conditions: candidate checks for the anomaly register

These are proposals for `ops/anomaly-checks.json`. Kind `unusual` for all: each is legal as far as we know. The owner decides; each enters as `hidden` or `listed`, never `published` (ANOMALY-PUBLIC spec).

| Id (proposed) | Signal | Measured (export 2026-11-23) | Comparison shown |
|---|---|---:|---|
| R21 | „Avantaj: domiciliul în localitate” | 244 project, a few others (not counted) | „criteriu de preferință; anunțul nu spune cum se punctează” |
| R22 | „Avantaj: experiență într-un proiect anume” (named project or SMIS code) | 224 | same |
| R23 | Experience in EU-funded projects (required or an advantage) | 23 | share of such requirements among postings of the same occupation |
| R24 | Several cumulative conditions above the median of the occupation (studies + years + language level) | not measured | the median of the occupation; extends E05 |

Other limiting conditions that the scan looked for, all rare: a maximum age (18 postings, 0 project), a required sex (6, 0 project). These are discrimination grounds in law (OG 137/2000, Codul muncii art. 5) for most posts. They need a reading of each case first; some are legal (military, a few care posts). Proposal: R25 „vârstă maximă sau sex cerut”, kind `error_posting` only after a person reviews each hit.

## 6. Decisions for the owner

*Owner, 2026-11-23: yes to D1–D4, as recommended.*

- **D1.** Show the SMIS code on the detail page (a fact line, no link yet). Recommendation: yes, after EU-PROJECT-SICAP decides the link target.
- **D2.** A project mode in the employer assistant (§ 3). Recommendation: yes, low priority; after UX-EMP-02, so that the pipeline can read the pay fields.
- **D3.** Add R21–R23 to the register as `listed`, and R25 as `hidden` until a person reviews each hit. Recommendation: yes, in ANOMALY-PUBLIC phase 2.
- **D4.** Read the applicant guides for the public-body calls (PCIDIF Action 2.2.x and 3.1; PoIDS for PIDS) for hourly rate caps. If a guide caps the rate by role, SALARY-EU-DETAIL can show „maxim N lei/oră conform ghidului” for posts without a stated pay. Recommendation: a Sonnet spike, browser download (some ADR sites block scripts).

## Verified limits (do not investigate again)

- No act caps the years of experience for a post filled under HG 1336/2022 or Art. 16 alin. (10^6). An experience check is a signal of rarity, never of illegality.
- The procurement rules (Legea 98/2016, ANAP, CNSC, OUG 66/2011 corrections) do not apply to hiring by contest. A candidate cannot appeal a requirement through them.
- The 1,720 h divisor is a cost rule. It does not convert an hourly pay to a month.

## Measured and assumed

- **Measured:** the counts of § 2 on the local copy of the VPS export of 2026-11-23 (body text only; attachments not read).
- **Assumed:** a regex hit is a real requirement (spot-checked 7 domicile hits: 5 advantage clauses, 2 general-condition hits, which the final pattern excludes); the working-day split ignores holidays.
- **Not checked:** every act that the document names except those already read in the audit of 2026-11-18. The applicant guides, the Beneficiary Manual and the list of operations were not read.

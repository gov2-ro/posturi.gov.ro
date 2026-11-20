# Brief — Pay of the PIDS project teams (SALARY-PIDS), spike

Date: 2026-11-20. Author: Opus. Builder: Sonnet. Backlog: SALARY-PIDS (under SALARY-R2). ADR: `docs/decisions/0008-salary-two-baselines.md`.

## Why

Many postings with no salary figure are hires for one EU project. The project is „Furnizare de servicii integrate în comunitățile rurale – facilitarea accesului persoanelor vulnerabile la servicii de bază eficiente și de calitate” (code `PIDS/586/P04/…`). The labour ministry leads it with the education and health ministries and a national agency. Town halls hire a local team for it.

Local copy of 9 Sep 2026 (`jobs_occupation.canonical`; a posting counts as PIDS when its text matches `PIDS|servicii integrate`):

| Occupation | Postings | PIDS |
|---|---|---|
| Asistent medical comunitar | 293 | 194 |
| Consilier școlar | 216 | 175 |
| Tehnician asistență socială | 41 | 20 |
| Manager proiect | 29 | 18 |
| Mediator sanitar | 18 | 7 |

No posting of these states its pay. The grids have no row for Manager proiect, Mediator sanitar or Asistent medical comunitar. If the project documents fix the pay of each team role, one source covers all of these postings.

## Question

Does a public project document fix the gross pay of each team role? If it does: which amount, for which role, for which period, and is it the same in every commune?

## Steps

1. **Find the documents.** Look for the applicant guide (*ghidul solicitantului*) and its annexes, the financing contract model, and the budget or standard-cost methodology. Likely places: mfe.gov.ro, the PEO 2021–2027 pages, mmuncii.ro, and the attachments of the PIDS postings (`attachment_text`, `other_links`). Store each file in `tmp/261120/pids/` with its URL and the download date.
2. **Read the pay rules.** For each role of the local team, record: the role name, the gross monthly pay or the hourly rate, the work time (full time or 0.5), the legal basis (for example HG 325/2018, Legea 153/2017 art. 16, or a standard cost), and the period it covers.
3. **Check the postings.** On the local copy, list the PIDS postings by role. Count the postings whose text states the funding source with a different wording. Count the postings of the same occupations that are not PIDS.
4. **Measure on the VPS.** Ask the VPS session (`posturi-vps-261118`, find it with ListAgents) to run the read-only count of step 3 on the live data. The owner approves Postgres reads in that session.
5. **Report.** Write `docs/audits/2026-11-20-salary-pids.md`: the documents and their URLs, the pay table, the VPS counts, and a proposal. The proposal says how the figure enters the precedence of ADR 0008 (a new `baza`? where in the order?), how a posting is detected as PIDS, and the label on the site. Give the number of postings that gain a figure.

Stop after the report. Opus and the owner decide what gets built.

## Rules

- Spike only. Do not change a grid file, an ADR, `estimate-salaries.py` or a dictionary row.
- Use a public source only. Quote it. If the pay comes from a press article or a single posting, say so.
- If no document fixes the pay, say that, list where you looked, and stop.

## Done when

- The report holds the answers of steps 1–5, what you measured and what you assumed.
- The backlog item and the activity log are updated.

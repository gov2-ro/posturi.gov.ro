# Brief — Spike: pay of EU-funded and PNRR posts (SALARY-EU-SPIKE)

Date: 2026-11-18. Author: Opus. Builder: Sonnet. Backlog: SALARY-EU-SPIKE.
Type: spike. Output is a report, not code for the site.

## Question

The site estimates pay from the salary grid of Legea 153/2017. A post that an EU or PNRR project
pays is different, in two cases:

1. **A public employee who also works on a project.** The base pay comes from the grid. Art. 16
   alin. (1) of Legea 153/2017 allows an increase of up to 40 %, by the time spent on the project
   (`parametri.json` → `spor_proiecte_fonduri_europene_pct`).
2. **A post hired for the project** (experts, fixed-term, often part-time or hourly). The project
   budget pays it. A government decision sets maximum pay levels. Opus believes it is HG 325/2018;
   **this is not verified** — find and cite the act in force.

Today the site gives such a post a grid figure when the title matches a grid function. That
figure can be wrong in case 2. Answer: how many postings are affected, which case they are, what
the posting texts say about pay, and what the law says.

## Baseline (Opus, 2026-11-18)

Export `tmp/261118/grid153/posturi-after2.sqlite` (9,756 postings, all statuses):

| `funding_kind` | postings | with grid figure (`sal_min`) | with stated pay (`inf_salary_min`) |
|---|---:|---:|---:|
| `fonduri_europene` | 452 | 339 | 2 |
| `pnrr` | 20 | 16 | 0 |

`v4_funding_source` is set on only 13 postings in that export, so `funding_kind` (the text
classifier, `classify.eu_funding()`) is the usable signal there. Sample titles with a grid figure:
„Consilier școlar” (4,543), „Asistent social” (4,813), „Asistent medical comunitar” (5,250),
„Psiholog clinician” (7,241), „Cercetător postdoctoral” (4,750).

## Tasks

1. **Counts on the active postings** of the newest export that you can read. Name the file and
   its date. If a newer VPS export is needed, stop and ask Opus: the VPS is reached only through
   a remote session (no SSH from the Mac).
   - By `funding_kind`, `v4_funding_source` and `v4_funding_programme`: postings, with grid figure,
     with stated pay, with no figure.
   - Agreement of `funding_kind` and `v4_funding_source` where both are set.
2. **Classify a sample of 60** EU/PNRR postings (stratified: 30 with a grid figure, 20 without,
   10 with stated pay or with a pay figure in the body). Read `body_markdown` and the attachment
   text. For each: case 1, case 2 or unclear; does the text state the pay (amount, gross or net,
   unit: month, hour or day; hours per month); is the post full-time; is it fixed-term. Write a
   CSV `docs/audits/2026-11-18-salary-eu-sample.csv` with the posting id, the title, the case,
   the quoted pay sentence and your notes.
3. **The law.** Find the act that sets the maximum pay of posts paid from EU non-reimbursable
   funds (check HG 325/2018 and any later amendment) on legislatie.just.ro. Record: the act and
   its id, the categories and their maximum amounts, whether the amounts are per hour or per
   month, and the date of the consolidated text. Check Art. 16 of Legea 153/2017 in
   `docs/salarii/` (the consolidated text that the site read on 2026-11-18): the percentage, the
   conditions, and whether it applies to the base pay. Quote the article numbers.
4. **The grid figure on case-2 postings.** For the sample, compare the grid figure with the stated
   pay where both exist.

## Report

`docs/audits/2026-11-18-salary-eu-projects.md`: the counts, the sample result (case shares with
the 95 % interval), the law (acts, articles, amounts, with links), the gap between the grid figure
and stated pay, and a recommendation for each of these three decisions. Opus decides; the owner confirms.

- **D1 — posting page:** for a case-2 post, hide the grid figure and show „Post finanțat din
  fonduri europene: salariul depinde de bugetul proiectului”, plus the stated pay when there is one?
- **D2 — estimator:** an option „Lucrez și într-un proiect cu fonduri europene” that shows the range
  from base pay to base + Art. 16 percentage?
- **D3 — pipeline:** does PIPELINE-PROJECT-PAY need a new extraction field (the pay unit and the
  hours), or does `baseSalary.unitText` cover it?

Say what you measured and what you assumed.

## Files

You can write: the two audit files above, `docs/backlog.md` (the SALARY-EU-* items only),
`docs/activity-log.md`. Read anything. Do not change code, the export, ADRs or the site.
Network: legislatie.just.ro and other official Romanian sources only. Do not send posting data
to an outside service.

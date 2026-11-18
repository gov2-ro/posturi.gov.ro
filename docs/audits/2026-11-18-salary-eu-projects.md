# Audit — pay of EU-funded and PNRR posts (SALARY-EU-SPIKE)

Date: 2026-11-19 (work done on the export of 2026-11-18). Builder: Sonnet. Brief: `docs/briefs/2026-11-18-salary-eu-projects-spike.md`.
Sample file: `docs/audits/2026-11-18-salary-eu-sample.csv` (60 rows).

## Summary

1. **Almost every EU/PNRR post is a post hired for the project (case 2).** In the sample, 59 of 60 are case 2; 1 is unclear; 0 are case 1. Case 1 (an employee who also works on a project) is an internal nomination by administrative act (Art. 16 alin. (3) Legea 153/2017). A job posting does not announce it.
2. **The act that sets the rules is not HG 325/2018.** HG 325/2018 is repealed (HG 234/2023, art. 4). The act in force is HG 234/2023, amended by HG 1.009/2025 and HG 357/2026. **No act sets a maximum pay in lei for a project-hired post.** The pay is the hourly rate of the funding request and of the applicant guide (Art. 16 alin. (10^2) Legea 153/2017; art. 2 of the Regulation of HG 234/2023).
3. **The grid figure is wrong for these posts.** Where the posting states a net hourly rate, the rate is 1.9 to 3.9 times the net hourly pay of the grid figure (median 3.0, 8 postings). 65 % of the posts are part-time (95 % interval 52–76 %); the grid figure is for a full-time post.
4. **The pipeline misses the stated pay.** About 34 of 472 postings (7 %) state a pay in the body or an attachment. The export holds 2 (`inf_salary_min`).

## What "active" means, and the file used

- The webapp calls a posting **active** when `application_status IN ('confirmed_open','unconfirmed','unknown')` (`OPEN_STATUS_SQL`, `webapp-php/helpers.php`). The status comes from `apply_deadline`, resolved at export time (v4 calendar, then the scraped date, then the expiry date).
- File: `tmp/261118/grid153/posturi-after2.sqlite`. It was built on 2026-11-18 (`build_meta`), but the newest `last_seen_at` is **2026-09-09**. The pipeline did not scrape after that date. So the export computes the status against the build day, and only 61 of 9,756 rows are open (5 with an EU/PNRR flag). That count is useless.
- **Assumption:** "active" = active on the scrape date of 2026-09-09, that is `apply_deadline >= '2026-09-09'` or no deadline. This gives 1,882 postings (ADR 0008 quotes 1,822 for the same date; 1,822 is the count with a deadline, +60 with none).
- A newer VPS export is **not** essential for this spike: the sample and the law do not depend on the date. It is needed only to give the live count of D1 (how many posts the change touches today). Counts on the live site will be near the all-status shares below.

## 1. Counts

### All statuses (9,756 postings)

| `funding_kind` | postings | grid figure (`sal_min`) | stated pay (`inf_salary_min`) | no figure |
|---|---:|---:|---:|---:|
| `fonduri_europene` | 452 | 339 | 2 | 113 |
| `pnrr` | 20 | 16 | 0 | 4 |
| total | 472 | 355 | 2 | 117 |

### Active on 2026-09-09 (assumption above)

| `funding_kind` | postings | grid figure | stated pay | no figure |
|---|---:|---:|---:|---:|
| `fonduri_europene` | 119 | 92 | 2 | 27 |
| `pnrr` | 3 | 1 | 0 | 2 |
| (none) | 1,760 | 1,475 | 11 | 278 |

Of 1,882 active postings, 122 (6.5 %) have an EU/PNRR flag; 93 of them show a grid figure. Under the open-status definition of the export: 5 postings (4 with a figure).

### `v4_funding_source` and `v4_funding_programme`

- `v4_funding_source`: 13 rows set. 2 = `fonduri_europene` (both have `funding_kind = fonduri_europene`, both have a grid figure, 0 stated pay). 11 = `nespecificat` (`funding_kind` is empty for all 11; 10 have a grid figure).
- `v4_funding_programme`: empty on all 9,756 rows.
- `schema_prompt_version = 'v4'` on 0 rows of this export, which explains it. Agreement: 2 of 2 where both say EU; 11 of 11 where v4 says nothing. The sample is too small to measure more. `funding_kind` (text classifier) is the only usable signal.

### Stated pay in the text (my count)

- 34 of 472 postings state a pay (rate or amount) in the body or an attachment (pattern: "N lei/oră", "tarif orar", "salariu brut/net N"). 14 of them have a grid figure. The export holds 2. A stricter check on a sub-set: 21 in the body, 9 only in an attachment.
- Attachment text comes from `data/downloads/` (470 of 472 postings have a downloaded file; 392 gave text of more than 200 characters; converted with `pandoc` and `textutil`; PDFs through `pdftotext`, which is installed). Scanned PDFs without text are not read.

## 2. Sample of 60 (CSV)

**Design.** Stratified, random with seed 20261118: 30 from "grid figure, no stated pay" (338 postings), 20 from "no figure" (94), 10 from "stated pay" (40; my pattern, 1 of them has only a reference to the budget annex). I read the key sentences of body and attachments with a pattern search, then checked each case by hand. The `case` column in the CSV has these values: `2` (hired for the project), `1` (existing staff), `neclar`.

**Result.** 95 % intervals: Wilson on the 60 postings (unweighted) and, in brackets, a stratified estimate for the 472 postings (normal approximation, smoothed variance).

| Measure | k of 60 | Wilson 95 % | Stratified, 472 |
|---|---:|---|---|
| Case 2 (hired for the project) | 59 | 91.1–99.7 % | 97.6 % (92.1–100) |
| Case 1 (existing staff) | 0 | 0–6.0 % | 0 % (0–3.4) |
| Unclear | 1 | 0.3–8.9 % | 2.4 % (0–7.9) |
| Part-time (stated or implied by hours) | 39 | 52.4–75.8 % | 60.1 % (47.3–72.8) |
| Full-time (stated) | 14 | 14.4–35.4 % | 28.9 % (16.8–41.1) |
| Full-time not stated, or mixed | 7 | 5.8–22.2 % | 11.0 % (2.6–19.4) |
| Fixed-term (`perioadă determinată` or the project period) | 55 | 81.9–96.4 % | 91.0 % (82.9–99.1) |
| Posting states the pay amount | 9 | 8.1–26.1 % | 7.6 % (4.0–11.3) |

Notes:
- The one unclear row (9785, "Mediator școlar", Râmnicelu) has 1,057 characters of text. The only project mention is an "avantaj" (experience in a pilot project). It is probably a **false positive of `funding_kind`**. Posting 11873 (outside the sample) is another: the project appears in the duties list. Estimate: about 1–2 % of the flagged postings.
- 11 of the 14 full-time posts are in the grid stratum. In the 60, 16 of the 30 grid-figure posts are part-time (hours of 0.5 norm, 4 h/day, 42 or 84 h/month): the grid figure then shows the full-time pay of a half-time job.
- Most of the posts come from a few programmes with a repeated text: "Echipa Comunitară Integrată" (ECI, PIDS/586/PO4/339395; consilier școlar, mediator școlar, asistent social, asistent medical comunitar: 0.5 norm, fixed-term to 2029) and home-care projects of PoIDS (managers, assistants, nurses: 42–84 h/month). So the 472 are not independent drafts: a few templates give most postings. Several stated-pay postings share one template (for example Frumușica: "84 ore/lună, 50–60 lei/oră net").
- At least four posts in the sample are cancelled or amended announcements ("anunț anulat/rectificat").
- The text says nothing about case 1 because it does not hire. **Assumption:** a project-team nomination of an existing employee is not published on posturi.gov.ro. Not verified outside the 472 texts.

## 3. The law

All texts read on legislatie.just.ro on 2026-11-19. The statutory texts below are quoted from the pages; my reading of them is not legal advice. Anything marked **unverified** I did not find in an official source.

### Legea-cadru 153/2017, Art. 16 (consolidated text in `docs/salarii/lege-153/legea-153-2017-id298840-2026-11-18.html`, id 298840)

- **Alin. (1)** (as amended by Legea 141/2025, in force 2025-08-01): staff of public institutions "nominalizat în echipele de proiecte finanțate din fonduri europene nerambursabile și/sau fonduri externe rambursabile", and staff in the PNRR, "beneficiază de majorarea salariilor de bază, soldelor de funcție/salariilor de funcție, indemnizațiilor de încadrare cu **până la 40%**, indiferent de numărul de proiecte în care este implicat. Această majorare se aplică proporțional cu timpul efectiv alocat activităților pentru fiecare proiect." So: **it applies to the base pay**, up to 40 %, in proportion to the time spent on each project.
- **Alin. (1^2)** (Legea 141/2025): no raise after the project term is exceeded. **Art. LXI Legea 141/2025**: for projects already past their term on 2025-08-01 the raise stops from that date.
- **Alin. (2)**: mayors, deputy mayors and county council presidents/vice-presidents: indemnity up to +40 %.
- **Alin. (2^1)**: in local administration the total pay (base + alin. (1) raise) cannot exceed the indemnity of the vice-mayor / vice-president, raised under alin. (2), "în limita bugetului aprobat cu această destinație".
- **Alin. (3)–(6)**: the head of the institution nominates the team by administrative act; the timesheet (pontaj) proves the time.
- **Alin. (7)**: the raise applies only during the project implementation period of the funding contract.
- **Alin. (8)**: not for staff under Art. 17 alin. (1)–(2^1) and for officials, except mayors and county leaders.
- **Alin. (9)**: salary costs can be reimbursed by the funder, per project in proportion to the time on the timesheet.
- **Alin. (10)**: "poate fi încadrat personal cu contract individual de muncă pe durată determinată, pe posturi în afara organigramei" (this is case 2).
- **Alin. (10^1)**: the hire is for activities in the project only, within the funding contract and the allocated sums.
- **Alin. (10^2)**: "Personalului prevăzut la alin. (10) i se aplică **tarifele orare** prevăzute de cererile de finanțare și Ghidul solicitantului – condiții generale și specifice aplicabile, cu aprobarea ordonatorului de credite". **This is the rule for case 2. It sets no lei maximum.**
- **Alin. (10^3)**: the salary costs come from the budget line of the project, "în limita bugetului aprobat".
- **Alin. (10^4)**: the head ensures the maximum hours and the rules on cumulation of functions.
- **Alin. (10^5)**: a framework regulation approved by government decision sets the criteria of the raise and the conditions for posts outside the organigramă.
- **Alin. (10^6)**: an internal recruitment procedure, transparent and non-discriminatory.
- 2025 freeze: OUG 156/2024 art. I alin. (4) held the funds for the alin. (1) raise at the level of November 2024; OUG 21/2025 art. VI lifts that limit for costs that the project budget reimburses. A 2026 equivalent: **unverified** (I found none in the consolidated text; Legea 141/2025 art. XVII freezes allowances at the December 2025 level, which may apply to the raise as well: **unverified**).

`parametri.json` (`spor_proiecte_fonduri_europene_pct` = 40, "Art. 16 alin. (1)") matches the text.

### The regulation: HG 234/2023, id 265711; Regulation (consolidated) id 265712

- **HG 325/2018** (id 200640, MO 409/2018-05-14) was **repealed by art. 4 of HG 234/2023** ("La data intrării în vigoare … se abrogă Hotărârea Guvernului nr. 325/2018"). It is not the act in force.
- **HG 234/2023** of 2023-03-15, MO 219 of 2023-03-16: approves the Regulament-cadru. Amended by **HG 1.009/2025** (MO 1076, 2025-11-21; adds Anexa 1 new values, in force 2025-11-21) and **HG 357/2026** (MO 358, 2026-04-30; in force 2026-05-01; adds art. 4 alin. (1^1), (1^2) and Anexa 1^1 for cohesion-policy projects). The consolidated Regulation (id 265712) shows these two as the last changes. Date of the consolidated text I read: includes changes to 2026-05-01. The page does not give its own "as of" date (assumption: it is the latest).
- **Art. 1–3 of the Regulation (posts outside the organigramă):** allowed above the maximum number of posts, within the posts of the funding contract, when (a) a justification note proves the existing staff cannot take the tasks, (b) a funding contract exists, (c) all salary costs are in the approved budget. Art. 2: the administrative act states "nivelul de salarizare al fiecărui post, conform **tarifelor orare din ghidul solicitantului** … astfel cum au fost aprobate în cererile de finanțare … în limita bugetului aprobat". Art. 3: a fixed-term individual employment contract for the project period.
- **No category and no maximum amount in lei exists in these acts.** I looked for amounts per category in HG 325/2018 (text read: art. 1–2 and the annex heading only) and in the Regulation of HG 234/2023: none for case 2. The limit of a project-hired post is the rate in the approved funding request, within the guide of the programme. **Unverified:** the applicant guides themselves (for example of PoIDS, POCU, PNRR) may cap hourly rates. I did not read any guide: they are not on legislatie.just.ro. Sample hourly rates: 29–165 lei/oră (net where stated).
- **Art. 4–5 and Anexa 1 / 1^1 (case 1):** the percentage depends on the hours per month on the project(s), the cumulated project value and, for cohesion projects from 2026-05-01, the monthly indicator results. Anexa 1 (as amended by HG 1.009/2025): hours up to 20 → 5 % in all value bands; 21–40 → 5/5/10/10 %; 41–60 → 10/10/15/20 %; 61–80 → 15/20/25/30 %; over 80 → 25/30/35/40 % (value bands: under 1 M; 1–2.5 M; 2.5–5 M; over 5 M lei). Art. 5: the total raise over several teams is capped at 40 %. The percentage of Art. 4 alin. (1^2) is the mean of the Anexa 1 value (weight 50 %) and the Anexa 1^1 indicator value (50 %). Anexa 2: mayors, 5–40 % by the number and value of projects.
- **Mentioned in the postings:** many announcements cite "HG 234/2023 … procentul de majorare salarială pentru persoanele prevăzute la art. 16 din Legea nr. 153/2017" in their bibliography, and HG 1336/2022 (recruitment of contractual staff). This confirms that the announcing institutions apply the acts above.

Links: Legea 153/2017 <https://legislatie.just.ro/Public/DetaliiDocument/298840>; HG 234/2023 <https://legislatie.just.ro/Public/DetaliiDocument/265711>; Regulation <https://legislatie.just.ro/Public/DetaliiDocument/265712>; HG 1.009/2025 <https://legislatie.just.ro/Public/DetaliiDocument/304641>; HG 325/2018 (repealed) <https://legislatie.just.ro/Public/DetaliiDocument/200640>. HG 357/2026: seen through the notes of the Regulation; I did not fetch its own page.

## 4. The grid figure against the stated pay

Rule used: net hourly pay of the grid figure = `net_from_gross(sal_min)` of `helpers.php` (minimum wage 4,325) ÷ 168 h. The stated rate is "lei/oră". **Assumption:** 168 h = a full-time month. Gross or net: where the posting says "net" the comparison is net to net. In three postings the posting does not say (8071, 9032, 10924); they are left out of the net-only median.

| Posting | grid gross | grid net / h | stated | stated rate / grid net per h |
|---|---:|---:|---|---:|
| 12148 asistent social | 4,813 | 17.1 | 50 lei/oră net, 84 h | 2.9 |
| 12153 psiholog | 5,679 | 19.9 | 60 net, 84 h | 3.0 |
| 12169 asistent medical | 5,250 | 18.6 | 50 net, 84 h | 2.7 |
| 12182, 12187 kinetoterapeut | 5,390 | 19.0 | 60 net, 84 h | 3.2 |
| 12192 îngrijitor | 4,325 | 15.6 | 40 net, 84 h | 2.6 |
| 13067 asistent medical | 5,054 | 17.9 | 70 net, 42 h | 3.9 |
| 13067 îngrijitor | 4,325 | 15.6 | 29 net, 168 h | 1.9 |
| 8071, 9032, 10924 (unit not stated) | 4,325–4,543 | 15.6–16.3 | 70–160 lei/oră | 4.5–9.8 |

Net-only (8 postings): median 3.0, range 1.9–3.9. Monthly: 84 h × 50 lei net = 4,200 lei net for a half-time job, above the grid net of the full-time job (3,117). In the one posting with a gross monthly pay (11867, Autoritatea pentru Digitalizarea României, full-time, "nivelul maxim de salarizare brut lunar": 27,200 and 13,495 lei) the grid figure is absent. So the grid is not just a little off: it is the wrong kind of figure for case 2. A part-time hourly post and a full-time grid month are different things. The stated rates also differ by programme (29 to 165 lei/oră), which the grid cannot reproduce. Limit: 11 comparisons from 8 postings, most from two templates; this is a range, not a model.

## 5. Recommendations (Opus decides; the owner confirms)

**D1 — posting page: yes.** For a post flagged EU/PNRR, hide the grid figure and show „Post finanțat din fonduri europene: salariul depinde de bugetul proiectului”. Show the stated pay when there is one, with its unit (lei/oră or lei/lună), gross or net, and the hours per month. Reasons: 98 % of the flagged posts are case 2 (92–100 %); the grid gives the wrong kind of figure (part-time and hourly pay against a full-time month, 2–4 times lower in the net hourly comparison). Two cautions. (a) About 1–2 % of the flags are false (9785, 11873): the rule may hide a good figure. Option: hide only when `funding_kind` is set **and** the text says „în afara organigramei” or a fixed-term contract for the project; check this on the 472. (b) The list, the sort order, the salary chart and the filter use the same figure (ADR 0008 rule 2, 7): a post without a figure goes to `fara-estimare`. This moves about 355 of 9,756 postings (all statuses), 93 of 1,882 on the scrape date. Record the counts per bucket before and after, as ADR 0008 rule 7 asks.

**D2 — estimator: yes, low priority.** Option „Lucrez și într-un proiect cu fonduri europene”: range from the base pay to base × 1.40, with Art. 16 alin. (1) cited, the text "până la 40 %, proporțional cu timpul alocat", and the table of HG 234/2023 Anexa 1 as the next step (5–40 % by hours and project value; from 2026-05-01 the cohesion projects have the 50/50 value-and-indicator rule). In local administration, cap at the vice-mayor level (Art. 16 alin. (2^1)); the estimator already has that cap (`salary_cap`). Reason for low priority: case 1 never appears in the postings (0 of 60; upper limit 6 %), so the option serves a reader who asks about his own pay, not the posting pages. Say that the raise is not a right: it depends on the project budget and the timesheet.

**D3 — pipeline: a new field is needed.** `baseSalary.unitText` covers the unit (HOUR, MONTH) only. Project posts state: the rate, the unit, **gross or net** (net in 8 of 11 stated rates; 3 not stated), **the hours per month** (42, 63, 84, 168), and the period. The monthly pay is rate × hours, so without the hours a reader cannot read the figure; without gross/net the rate is ambiguous by a factor of 1.7. Propose (for the v5 prompt or a deterministic pass): `pay_amount`, `pay_unit` (`hour` | `month`), `pay_basis` (`gross` | `net` | `unspecified`), `hours_per_month`. Most texts are regular ("84 ore/lună, 50 lei/oră net"), so a deterministic regex on body and attachment text is likely enough for the two big templates; the LLM stays for the rest. Today `inf_salary_min` holds 2 of the 34 stated pays: the extraction misses hourly rates and the attachment-only pay (about 9 of 30); it also missed 11867 (monthly, in the body). The estimator brief and the detail page must show the unit, or „70 lei” will be read as a monthly pay.

## Measured and assumed

- **Measured:** the counts in § 1 (SQL on the export); the case, hours and fixed-term values of 60 postings (pattern search + reading of the key sentences; 7 labels changed by hand from "unclear" to case 2 after the first pass); the intervals in § 2; the rates and grid figures in § 4; the text of the acts in § 3 (read in full for Art. 16, Art. 1–7 and the annexes of the Regulation).
- **Assumed:** "active" = active on 2026-09-09; 168 h = full time; the consolidated texts on legislatie.just.ro are the latest; the stratum "stated pay" was built with my own pattern (a few unstated or blank tables fell in it; 1 in the 10 sampled); the 60 are not independent (few templates), so the intervals are narrower than the real uncertainty.
- **Unverified:** the applicant guides' rate caps; a 2026 freeze on the Art. 16 raise; HG 357/2026 read through the consolidated Regulation only; the claim that case 1 is not published on posturi.gov.ro.
- **Not done:** no VPS access; no attachment OCR; no reading of the programme guides.

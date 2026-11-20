# 0008 — Salary: two baselines, the law in force first

Date: 2026-11-18. Status: **proposed**. The owner chose rules 1–5 on 2026-11-18, after spikes S1 and S2 (`docs/audits/2026-11-18-salary-s1-current-law.md`, `docs/audits/2026-11-18-salary-s2-transparency-lists.md`). The owner accepted rule 7 on 2026-11-18 and rule 8 on 2026-11-19. The owner changed rules 2 and 4 on 2026-11-20. Rule 6 is not yet confirmed.

## Context

The site estimates the gross pay of each posting from the **July 2026 draft salary law** (`salary_grid.py`, variant `2026-07-17`, VR 4,100, gradation 0). On 2026-11-18 the owner confirmed that the draft **is not adopted**. Legea 153/2017 is still the law in force. Press reports say that the new law takes effect in 2027. So the main figure of the site describes pay that nobody gets today.

Pay under Legea 153/2017 is not one number from an annex:

- The annexes give the target values for 2022. Successive ordinances froze the pay at the „salariul de bază aflat în plată”.
- In local administration, each council (HCL) sets the pay. The law sets only the maximum.
- Each institution must publish its pay by function by 31 March and 30 September (Art. 33). That publication is the most exact source, but its format differs from one institution to the next.

The owner also asked about salariile.ro/compara. It has no model to copy. Each occupation shows one documented reference value: an eJobs survey average, an INS aggregate as context, or a range of steps from the 153/2017 grids. We take the pattern (a value with its source, a range, a page per occupation, "A vs B"). We do not take the method.

## Decision

1. **Two baselines.** Each estimate records `baza` (`lege_in_vigoare` or `proiect`), `versiune` and `status_juridic`. `lege_in_vigoare` is Legea 153/2017 with the 2026 pay rules. `proiect` is a variant of the draft law (today `2026-07-17`).
2. **The law in force comes first.** The list row, the sort order, the salary chart and the filter use `lege_in_vigoare`. The detail page and the `/salarii/` hub also show `proiect` as a side note, labelled „Dacă proiectul noii legi ar fi adoptat: …”. *(Changed 2026-11-20 by the owner: nothing guarantees that the draft passes, so the draft figure is context, not a forecast.)*
3. **Local administration shows a range.** A council (HCL) sets the pay of a local post, up to the cap of Art. 11 alin. (4) (the vice-mayor indemnity: Anexa IX lit. C coefficient × 2,415 lei). The site shows a range: the lower end is the value of the law (method (b) of S1, with the factor of the brief of SALARY-GRID-153: S1 used 1.05, but OUG 53/2024 adds 10%), the upper end is the cap. S1 measured 45 local values: 25–93% of the cap, median 50%, none ≥ 95%. So the cap alone would overstate the pay, and the lower end alone would understate it by a median 21%. *(Changed 2026-11-18 by the owner, after S1. The first version showed only „maxim legal”.)*
4. **The order of precedence for one posting:** (a) the pay that the posting states; (b) the grid of the law in force; (c) the draft grid, only when (b) has no row. Each figure carries its source, and the page names it. A figure of (c) is a last resort for search: it keeps the posting in the filter and the sort order. The page labels it „estimare aproximativă” and names the draft in a tooltip or a note. The site does not adjust it. *(Owner, 2026-11-20. Measured on the local copy of 9 Sep 2026, not on the VPS data: 8,256 postings with both figures: the draft is a median 14% over the law; 10th–90th percentile +4% to +37%. In that copy, (c) gives the figure of 112 postings. Measure again on the VPS data.)* The transparency lists of Art. 33 are not a source for now: S2 found that a generic parser does not work (14 layouts in 14 files), and the owner dropped the manual entry for now (2026-11-18).
5. **Context is not an estimate.** The INS figures (TEMPO FOM106E, FOM106G, FOM121A) and the eJobs/Paylab figures go next to an estimate, with source and period. They never give the figure of a posting, the sort order or the filter.
6. **The switch rule.** When the new law is in the Monitorul Oficial, its grid becomes `lege_in_vigoare`. The 153/2017 grid becomes a previous version and stays in `data/salarii/`, so that old figures stay traceable.
7. **The filter keeps its keys.** The values of `salary_bucket` do not change (`net-sub-3000` … `fara-estimare`, ADR 0001). Their meaning becomes "net pay under the figure of rule 4". A saved search or an email subscription can therefore get other postings once, on the day of the switch. The activity log records the counts per value before and after. *(Accepted by the owner, 2026-11-18. On the snapshot of 9 Sep, 528 of 1,822 active postings change their value.)*
8. **INS data is derived, not copied.** `ops/build-ins-context.py` reads `../tempo-ins-dump` and writes one small table, `data/salarii/ins-context.csv` (a few hundred rows, each with its matrix and period). The project does not copy the TEMPO files (`core-assets/docs/projects.md`). *(Accepted by the owner, 2026-11-19, with the estimator wizard: the INS context comes in now, as one line on the estimator result.)*

## Consequences

- Outside local administration the figures change little: for hospitals, the pay lists are +2.8% over the draft estimate (S2) and +2% over method (b) (S1). In local administration a figure becomes a wide range. This is the correct result. The draft figures stay visible as a projection.
- `salary_estimate` (JSONB) keeps the primary figure at the top level, in the format of today, with `baza`. The draft figure goes under the key `proiect`. So `sal_min`, `sal_max`, `SALARY_EXPR`, the filter and the detail page continue to read the top level, and a row in the old format (no `baza`) reads as a draft figure.
- Health pay depends on the type of unit (OUG 19/2024: a.1 clinical … a.4 other, 1.5–2% apart). The estimator classifies the hospital from the employer's name (owner, 2026-11-18).
- `salary_grid.py` stays the single source of truth for the selector vocabulary of both baselines.
- Alternative not taken: keep the draft as the main figure, with a stronger note. Cost: the main figure stays wrong for every reader until 2027, and the note does not fix the sort order or the filter.
- Alternative not taken: only the law in force, and add the draft when it is adopted. Cost: readers lose the projection that the press discusses now, and we must rebuild the draft path later.
- Alternative not taken (2026-11-20): adjust a draft figure of rule 4(c) by the median gap (−14%). Cost: the site shows a number that no act publishes, for 112 postings (9 Sep copy).
- Alternative not taken (2026-11-20): no figure when the law has no row. Cost: 112 postings (9 Sep copy) drop out of the salary filter, and the reader cannot find them by pay.
- Alternative not taken: new filter keys for the new meaning. Cost: each saved search and each subscription must be migrated (ADR 0001, rule 4) for a change that the reader wants anyway.

# Spike S2 — Transparency lists (Legea 153/2017, Art. 33): yield and cost

Date: 2026-11-18. Brief: `docs/briefs/2026-11-18-salary-s2-transparency-lists.md`. ADR: `docs/decisions/0008-salary-two-baselines.md` (rule 4b). The draft law is not adopted; Legea 153/2017 is in force.
Files: downloads in `tmp/261118/s2/files/`, scripts in `tmp/261118/s2/scripts/`, parsed rows in `tmp/261118/s2/parsed/`. Names of people: none copied.

## Summary

- **Measured:** 16 of the top 30 employers publish a list that we found (13 for September 2026, 3 for March 2026 only). These 16 hold 168 of 1,822 active postings (9.2%).
- **Parsed:** a prototype parser read 7 of these employers (87 postings, 4.8%). No generic parser worked on PDF text. Each of the 14 files that we downloaded had its own layout.
- **Matched:** 39 of the 87 postings matched a list row (2.1% of all active postings). Matching is a lower bound (see § 4).
- **Gap:** for 36 matched postings, the median list pay is +2.8% against the draft estimate (quartiles −7% / +13%).
- **Assumption, not measured:** the top 30 are almost all hospitals. Hospitals publish well. Town halls (426 postings) and schools (324 postings) were not sampled. Do not extrapolate the 53% find rate to them.
- **80% of postings cannot be reached with this source** (see § 5).

## 1. Definition of "active" and the data source

- Source: `data/_dev/dev.sqlite` (the brief's `webapp-php/posturi.sqlite` has no `apply_deadline` column and is from 9 Sep).
- **The export is not from 18 Oct in content.** `max(last_seen_at)` and `max(published_at)` are 2026-09-09. The export only flags status at 18 Oct. With `apply_deadline >= 2026-10-18` only **26** postings are active (46 with `expires_at`). That is too few for this spike.
- **Definition used:** `coalesce(apply_deadline, expires_at, '') >= '2026-09-09'` (the day of the last scrape). Result: **1,822 active postings, 1,078 employers.** 1,796 of them carry `application_status = closed` at 18 Oct. This does not matter for the yield question.
- Employer websites are not in the `employers` table (only `id, name, slug`). I took the domain from `contact_email` of the postings, and searched for the rest. The WebSearch tool found almost no list. Crawling the site with `curl` worked.

SQL of step 1:

```sql
with a as (select * from job_postings
           where coalesce(apply_deadline, expires_at, '') >= '2026-09-09'),
     t as (select count(*) n from a)
select employer_id, employer_name, count(*) c,
       round(100.0*count(*)/(select n from t),1) pct
from a group by employer_id order by c desc limit 30;
```

Concentration (measured): top 10 employers hold 7.7%, top 30 hold 15.1% (276 postings), top 100 hold 30.8%, top 300 hold 55.3%. 741 of 1,078 employers have one active posting. 80% of postings need the top **714** employers.
By `employer_kind`: spital 639 postings (229 employers), primarie 426 (310), scoala 324 (273), asistenta-sociala 98 (43).

## 2. The 30 employers and their lists

Time: "auto" = found by the crawl script (`scripts/crawl.py`, at most 9 pages per site, about 1–2 minutes). "Not found" means: not found by the crawl and one manual look. I did not spend the full 10 minutes on each. A list may exist where I did not find it.

| # | Employer (postings) | Latest list found | Page / file URL (site, then file) | Format | Time |
|---|---|---|---|---|---|
| 1 | SCJU Bihor, Oradea (34) | Sept 2026 | spitaljudetean-oradea.ro/situatia-drepturilor-salariale/ → `wp-content/uploads/2026/10/TRANSPARENTA-SALARIALA-SEPT.2026.pdf` | PDF text, 41 pp, one row per employee | auto |
| 2 | Min. Mediului (16) | Mar 2026 (Sept not on page) | mmediu.ro/informatii-de-interes-public/buget/situatia-drepturilor-salariale/ → `storage/2026/03/Lista-salarii-31.03.2026.pdf` | **Scanned PDF** (image only, 4 pp) | manual 3 min |
| 3 | SJU Alexandria (15) | Not found | `/stat-de-functii/` has only the staff structure; an old notice (2018) links `venituri_salariale.pdf` | — | not found |
| 4 | SCJU Pius Brînzeu, Timișoara (15) | Sept 2026 | hosptm.ro/venituri-nete-salariale/ → `files/venituri/2026/Lista funcțiilor și salariilor de bază-SEPTEMBRIE-2026.pdf` | PDF text, 85 pp, one row per employee | auto |
| 5 | SC Copii Gomoiu, București (13) | Mar 2026 | spitalgomoiu.ro/infointpub/situatia-drepturilor-salariale/ (paginated) → `wp-content/uploads/2024/10/SALARII-MARTIE.pdf` | **Scanned PDF** (8 pp) | auto |
| 6 | DAS Focșani (12) | Sept 2026 | das-focsani.ro → `wp-content/uploads/2026/09/DAS-FOCSANI-TRANSPARENTA-SALARII-30.09.2026.pdf` | PDF text, 2 pp | auto |
| 7 | DGASPC Neamț (10) | Sept 2026 | dgaspcneamt.ro → `wp-content/uploads/2026/09/MACHETA-Situatie-salarii-pt-site-30.09.2026.xls` | XLS | auto |
| 8 | SC Ortopedie Foișor (9) | Not found | foisor.ro crawled, no list | — | not found |
| 9 | SJU Vaslui (9) | Not found | sjuvaslui.ro has a „Venituri angajați” page; I could not find a file link in it | — | not found |
| 10 | SCU Copii Cluj (8) | Not found | spitcocluj.ro: only budgets and asset declarations | — | not found |
| 11 | SJU Târgu-Jiu (8) | "Sept 2026" (the file says August) | spitalgorj.ro/venituri-salariale/ → `wp-content/uploads/2026/10/TRANSPARENTA-SALARIALA-septembrie-2026.pdf` | PDF text, 334 pp | auto |
| 12 | SABIF (8) | Mar 2026 (Sept not published) | sabif.ro/financiar.php → `salarii/Salarii la 31.03.2026.XLS` | XLS | auto |
| 13 | SCU București (Floreasca) (8) | Not found | new portal `portal-web.urgentafloreasca.ro`: budgets only | — | not found |
| 14 | SC Colțea (8) | Mar 2025 (stale) | coltea.ro → `uploads/2025/06/TRANSPARENTA-SALARIALA-31.03.2025.xls` | XLS | auto |
| 15 | SCBI Cluj (7) | Not found | no list in crawl | — | not found |
| 16 | SO Hârșova (7) | Not found | „Transparenta” page holds only 2018 decisions | — | not found |
| 17 | SM Câmpulung Moldovenesc (7) | Sept 2026 | spitalcampulungmoldovenesc.ro → `uploads/2026/09/Lista-functii-site-septembrie-2026.xlsx` (+ same as PDF) | XLSX | auto |
| 18 | SM Câmpia Turzii (7) | Not found | spitalulcampiaturzii.ro crawled | — | not found |
| 19 | SM Dorohoi (7) | Sept 2026 | spitaldorohoi.ro → `financiar/transparenta veniturilor 30.09.2026.xls` | XLSX (named .xls) | auto |
| 20 | SM Carei (7) | Sept 2026 | spitalcarei.ro → `storage/transparenta-venituri-salariale-septembrie-2026.pdf` | PDF text, 39 pp | auto |
| 21 | Sp. Psihiatrie Pădureni-Grajduri (7) | Sept 2026 | spitalgrajduri.ro → `uploads/2026/10/Transparenta-salarii-Sept-2026.pdf` | **Scanned PDF** (7 pp) | auto |
| 22 | SJU Buzău (6) | "Aug 2026" | spitalulbuzau.ro → `docs/economic/venituri/2026/transparenta august 2026.xlsx` | XLSX, 81 columns | auto |
| 23 | SO Beclean (6) | "31.08.2026" | spitalbeclean.ro → `uploads/2026/10/Venituri-la-data-de-30.09.2026.pdf` | PDF text, 12 pp | auto |
| 24 | SCJU Suceava (6) | Not found | `/venituri/` page holds no list | — | not found |
| 25 | SC Obstetrică Brașov (6) | Sept 2026 | maternitatea.ro → `uploads/2026/10/TRANSPARENTA_30.09.2026.pdf` | **Scanned PDF** (16 pp) | auto |
| 26 | SJU Târgoviște (6) | Not found | spitaldb.ro: no list | — | not found |
| 27 | SCBIT Babeș, București (6) | Not found | spitalulbabes.ro: budgets only | — | not found |
| 28 | Grădinița PP 1 Beiuș (6) | Not searched | no site known | — | not searched |
| 29 | Inst. Urologie și Transplant Renal Cluj (6) | Sept 2026 | renaltransplant.ro → `uploads/2026/10/Venituri-salariale-la-30.09.2026.pdf` | PDF text, 7 pp | auto |
| 30 | SJU Miercurea Ciuc (6) | Not found | spitalmciuc.ro: no list | — | not found |

Count: **16 found** (13 with a September 2026 list, 3 with March 2026 only), 14 not found or not searched. Rows 3, 8–10, 13–16, 18, 24, 26–28, 30 are the 14.
Postings of the 16 found employers: **168** (9.2% of 1,822). The top 30 hold 276.

Formats of the 16 found employers (measured): PDF text 7, scanned PDF 4, spreadsheet 5 (XLS 3, XLSX 2 — two of them with a wrong file extension).

## 3. Parse a sample

I downloaded 15 files (each site 1–3 files) and tried a generic parser (`scripts/parse.py`: it finds the header row or column by the words „funcți*”, „salariu de bază”, „gradație”). Fields that I could read: function, gradation, base pay. „Nivel studii” exists in only some lists. Spors exist in all, in 10–50 different columns.

| File | Format | Generic parse | After help | Rows |
|---|---|---|---|---|
| SABIF Mar 2026 | XLS | works | — | 1,187 |
| Buzău Aug 2026 | XLSX | works | — | 1,701 |
| Câmpulung Sept 2026 | XLSX | works | — | 331 |
| Dorohoi Sept 2026 | XLSX named .xls | fails (extension; header „SBU L153”) | 2 small fixes | 606 |
| DGASPC Neamț Sept 2026 | XLS | fails (the pay header is merged; columns shift between rows) | own script | 458 |
| Brînzeu Sept 2026 | PDF text | fails | own regex, clean | 4,429 |
| Brînzeu Mar 2025 | PDF text | the Sept 2026 regex returns 0 rows: **layout changed** | not done | — |
| Oradea Sept 2026 | PDF text | fails | own regex, **about 20% of function names cut** (the column drifts between pages) | 4,218 |
| Târgu-Jiu, Carei, Beclean, Renal, Focșani | PDF text | fails (wrong columns, noise such as „2017”) | not done | — |
| Gomoiu, Grajduri, Maternitatea, MMAP | scanned PDF | no text at all | OCR only | — |

- Spreadsheet: 3 of 5 parse without help. The other 2 need 1 small fix each.
- PDF text: 0 of 7 parse without help. 2 of 7 parse after one hand-written regex each. The other 5 need a layout-specific parser.
- Scanned PDF: 4 of 12 PDFs. OCR test: `ocrmypdf -l ron+eng` on Grajduri (7 pp) took 11 s, but the table text is badly broken (columns merge, pay digits unreliable). I did not measure the accuracy of pay numbers. Treat as "needs manual entry".
- **Measured:** no two of the 14 layouts were the same. A generic parser is not realistic. Each employer needs its own column map.
- Many lists have one row per employee (Brînzeu: 4,429 rows for 145 function names). The pay depends on gradation and on the person, not only on function.

## 4. Match

Seven employers have a parsed list and active postings: Bihor, Brînzeu, SABIF, Buzău, Câmpulung, Dorohoi, DGASPC Neamț. Title normalisation: `ocupatii.parse_title()` plus own cleaning (remove „– 13 posturi”, „(PL)”, „(S)”, „minim … vechime”, abbreviations). Script: `scripts/match.py`.

| Employer | Postings | Exact | After normalisation | No match | Why no match |
|---|---|---|---|---|---|
| Bihor (Oradea) | 34 | 0 | 3 | 31 | **parser defect** (cut function names), not a real gap |
| Brînzeu | 15 | 0 | 13 | 2 | — |
| SABIF (Mar 2026) | 8 | 0 | 0 | 8 | list uses abbreviations („AS.MED.PR.”, „SOF AUTOSAN.I”); needs a dictionary per employer |
| Buzău | 6 | 2 | 2 | 2 | |
| Câmpulung | 7 | 1 | 4 | 2 | |
| Dorohoi | 7 | 4 | 0 | 3 | |
| DGASPC Neamț | 10 | 2 | 8 | 0 | |
| **Total** | **87** | **9** | **30** | **48** | |

- Matched: 39 of 87 (45%). Excluding Bihor and SABIF (two known defects): 36 of 45 (80%).
- Posting titles are noisy for hospitals („Asistent medical principal licențiat (S) – min. 5 ani vechime – 7 posturi”). 1 posting often names several posts („Psiholog și logoped”).
- Match is by function only. The list gives several pays for one function (gradations). I took the median of all rows of that function. A real match needs the grade and gradation of the posting, which most titles do not give.

## 5. Compare with the draft estimate

Assumption: `sal_min`/`sal_max` (variant 2026-07-17) are the gross base pay of the draft grid. I did not check `estimate-salaries.py` in detail.
Gap = (median list base pay − midpoint of `sal_min`, `sal_max`) / midpoint, for the 36 matched postings that have an estimate.

| Employer | n | Median gap |
|---|---|---|
| Bihor | 3 | +14.2% (unreliable: noisy parse) |
| Brînzeu | 12 | +3.1% |
| Buzău | 4 | +1.0% |
| Câmpulung | 5 | −3.7% |
| Dorohoi | 3 | +2.9% |
| DGASPC Neamț | 9 | +1.8% |
| **All** | **36** | **median +2.8%; quartiles −7.0% / +13.0%; mean absolute gap 10.6%** |

Reading: the median is small but the spread is large. Part of the spread is the gradation (the draft estimate uses gradation 0; the lists show mostly gradation 4–5). Do not read this as a validation of the draft grid. The sample is 36 postings of 6 employers.

## 6. Coverage and the cost of 80%

Measured for the top 30 (1,822 active postings):

| Step | Postings | Share of active |
|---|---|---|
| Top 30 employers | 276 | 15.1% |
| × list found (16 employers) | 168 | 9.2% |
| × parsed (7 employers, prototype) | 87 | 4.8% |
| × matched to a list row | 39 | 2.1% |
| Upper bound with perfect parsing, same 16 employers, matching 80% | about 134 | about 7.4% |

**80% of postings is out of reach with this source.** The arithmetic:
- 80% needs the top 714 employers (1,458 postings).
- If the find rate of the top 30 (53% of employers, 61% of their postings) held for all 714, then at most about 0.55–0.6 of those postings would have a list: **about 45–50% of the active postings is the ceiling**, before parse and match losses. With 80% matching, about 35–40%.
- The find rate is probably lower for the long tail. I did not sample it. The top 30 are hospitals. Town halls and schools: not measured.
- Local administration (426 postings): the council sets the pay (ADR 0008, rule 3). Their lists, if they exist, may hold the same function with many pays. Not measured.

Cost (measured and assumed):
- Discovery: about 1–2 minutes per employer with the crawl script, and about 40% of employers need a manual look (measured: 14 of 30 not found automatically; I did not manually search all 14 for 10 minutes). Assumed: 714 employers × 40% × 5 min ≈ 24 h of manual search.
- Parser: 14 layouts in 14 files. Per employer a column map or a regex: about 20–30 min each (assumed from this spike: the 3 custom parsers took more than 1 h in total with debugging). For 714 employers, with about 55% having a list: about 390 parsers ≈ 130–200 h.
- Scanned PDF (4 of 16 = 25%): manual entry or OCR with review. 25% of 390 = about 100 lists.
- Each period (March and September): the file URL changes every time (see § 7), so the discovery step repeats twice a year. The parser may break when the layout changes (Brînzeu changed between Mar 2025 and Sept 2026).
- Share needing manual help (measured, 16 found employers): 0 of 7 PDF-text lists parse without help; 2 of 5 spreadsheets need help; 4 of 16 need OCR or entry. **13 of 16 (81%) need manual help for parse.**

## 7. Change of URL and format between March and September

Seen in the listing pages of these sites (March and September files side by side):

- The **page** URL of the list is stable for all 16 sites (as far as I can see in the archive of the year).
- The **file** URL changes every period for all 16. No site uses a fixed file name. Names vary: Brînzeu `Transparenta-venituri-MARTIE-2025.pdf` → `Lista funcțiilor și salariilor de bază-SEPTEMBRIE-2026.pdf`; Oradea has typos in slugs („tansparenta”); Gomoiu reuses the `2024/10/` folder for a March 2026 file with no year in the name (`SALARII-MARTIE.pdf`); Grajduri names the March 2025 file `…martie-2024.pdf`.
- **Period labels are not reliable.** Târgu-Jiu: „Luna august 2026” and „februarie 2026”. Buzău: monthly labels. Beclean: file name 30.09, content 31.08. Carei: „31 SEPTEMBRIE 2026” (no such date). So the pipeline cannot trust the period from the name.
- **Format changes:** Dorohoi: .xls (2025-03), .xlsx (2025-09, 2026-03), then a real XLSX named .xls (2026-09). SABIF: XLS/XLSX mixed. Brînzeu: layout changed between Mar 2025 and Sept 2026 (my regex gives 0 rows on the 2025 file). Câmpulung: stable names, PDF and XLSX both.
- Late publication: SABIF, MMAP, Gomoiu had no September 2026 list on 2026-11-18 (the date is 18 Nov, the deadline 30 Sep). Assumed: not yet published, or not found.

## 8. Recommendation

**Do not build a general fetch-and-parse pipeline (Phase 5) now.** Measured reasons: 0 shared layouts in 14 files; 81% of found lists need manual help; the ceiling is about 35–50% of postings even with perfect work; it breaks twice a year.

**Do it by hand for a small top N, with a tight scope**, and only if the owner wants the figure for these postings:
- The top 10 employers hold 7.7% of postings; 6 of the 10 have a list. That is about 5% of postings.
- Per employer: one manual step each period — an operator downloads the file and a small per-employer map (`employer_id`, column names or regex, abbreviations) reads it. Output: a CSV `employer_id, function, grade, gradation, base_pay, period, source_url` in `data/salarii/`.
- Hospitals first (they publish one row per employee: use the median per function and grade, and show the range).
- Estimate: 10 employers × (30 min setup + 10 min each period) ≈ 5 h setup + 3 h a year. The gain is 5–7% of postings, with a precise local figure. It is not worth a pipeline.

**Alternative that may give more per hour (not measured):** use the top-down grid of the law in force (S1) for all postings, and treat the lists as a check on 5–10 hospitals. This also gives a data point for the gap in § 5.

**Verified limit (proposed entry):** a generic fetch-and-parse of Art. 33 lists does not work. Reason: 14 different layouts in 14 files; 4 of 16 are scans; the file URL changes every period; the period labels are wrong in 4 of 16.

### What I measured vs what I assumed

Measured: active count and definition; top-30 shares; the 16 found / 14 not found; formats; parse results per file; match counts; gaps; URL and format history from listing pages.
Assumed: the 14 "not found" employers may have a list I did not find (crawl limited to 9 pages + one manual look); the find rate for town halls, schools and the long tail; `sal_min`/`sal_max` = gross base pay of the draft; the time per parser; that SABIF/MMAP/Gomoiu have not yet published September 2026.
Not done: OCR accuracy; matching for the 4 PDF-text employers that have a list but no working parser (Târgu-Jiu, Carei, Beclean, Renal, Focșani — 5 employers, 37 postings); a sample of 20 more employers from the middle of the ranking (the pattern was clear at 30, so I did not extend).
Limits of the brief that I did not meet: the 3-file limit per site (I downloaded at most 2–3 files and fetched several pages per site, 1 sleep of 0.5–1.1 s before each request); the "10 minutes per employer" search time.

## Proposed activity-log entry

### 2026-11-18 — Spike S2: transparency lists (Art. 33, Legea 153/2017)

**Done**
- Measured the yield of the Art. 33 lists for the top 30 employers (1,822 active postings; definition: `coalesce(apply_deadline, expires_at) >= 2026-09-09`, because the `dev.sqlite` export holds data to 9 Sep; with 2026-10-18 only 26 postings are active).
- Found a list for 16 of 30 employers (13 September 2026, 3 March 2026), holding 168 postings (9.2%). Parsed 7 employers (87 postings, 4.8%) with prototype parsers. Matched 39 postings (2.1%). Median gap list vs draft estimate: +2.8% (n = 36, quartiles −7% / +13%).
- Report: `docs/audits/2026-11-18-salary-s2-transparency-lists.md`. Scripts and downloads: `tmp/261118/s2/` (not in git).

**Hard**
- No generic parser: 14 files had 14 layouts. 0 of 7 PDF-text lists parsed without help. 4 of 16 lists are scans. One XLSX had the extension .xls.
- The file URL changes every period. The period label is wrong in 4 of 16 lists. One layout changed between March 2025 and September 2026.
- The top 30 are almost all hospitals, which publish well. Town halls and schools were not measured.
- WebSearch found almost no list. Crawling the site with `curl` worked.

**Decided** (proposed; the owner decides)
- No Phase 5 pipeline. Rationale: the ceiling is about 35–50% of postings, and 81% of lists need manual help. Cost of the alternative (a pipeline): about 130–200 h of parser work plus repeated work twice a year, for a gain of at most the ceiling.
- If the owner wants the figure: by hand for the top 10 employers (about 5% of postings).

## Proposed backlog items

- [ ] Owner decision: build by hand the Art. 33 lists for the top 10 employers (about 5 h set-up, 3 h a year, gain about 5% of postings), or drop. Source: S2 report.
- [ ] If yes: define `data/salarii/transparenta-*.csv` (`employer_id, function, grade, gradation, base_pay, period, source_url`) and the per-employer map. Depends on ADR 0008 rule 4b.
- [ ] Sample 20 town halls and 20 schools for Art. 33 lists (S2 measured hospitals only). Time: about 2 h with `tmp/261118/s2/scripts/crawl.py`.
- [ ] Check what `sal_min`/`sal_max` mean (gross base pay or total) before the next gap comparison with lists.
- [ ] Fix: the `dev.sqlite` export carries data to 9 Sep, not 18 Oct (activity-log and ADR 0008 say 18 Oct). Check the export date.

### Verified limits (proposed)

- [-] **Generic fetch-and-parse of Art. 33 transparency lists.** Reason (S2, 2026-11-18): 14 files from 14 employers had 14 different layouts; 4 of 16 found lists are scanned images; the file URL changes every period; the period in the file name is wrong in 4 of 16; one employer changed layout between March 2025 and September 2026. 81% of the lists needed manual help. The ceiling is about 35–50% of active postings, because the top 30 are hospitals and the long tail is unmeasured. Do not investigate again unless a national open-data source for these lists appears.

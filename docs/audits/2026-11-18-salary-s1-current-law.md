# Spike S1 — Pay under the law in force (Legea 153/2017), 2026

Date: 2026-11-18. Author: Sonnet. Brief: `docs/briefs/2026-11-18-salary-s1-current-law.md`. ADR: `docs/decisions/0008-salary-two-baselines.md` (proposed).
Status: spike report. Active definition and S2 lists corrected on the coordinator message (see 2 and 5). No code, no file in `data/`, and no other doc changed. Downloads and the calculation script are in `tmp/261118/s1/` (not committed).

## 1. Summary

1. **The annex value is not the pay.** The annexes of Legea 153/2017 give the target values of 2022. Later acts changed them. For central, health and education posts, one multiplier or one later table gives the pay of 2026. For local administration, the annex gives no value at all (see 4.1).
2. **Recommended method (b):** `pay = value of the act in force × gradation multiplier (Art. 10), never below the minimum gross wage`.
   - Annex VIII (administration): value of 2022 × 1.05.
   - Annex II (health): the June 2024 column of Anexa 1 and Anexa 2 of OUG 19/2024.
   - Annex I (education): the June 2024 column of the annex of OUG 128/2023.
   - Floor: 4,050 lei (1 Jan – 30 Jun 2026). Press reports 4,325 lei from 1 Jul 2026 (not verified in the HG).
3. **Measured gap of method (b), 56 comparisons, 12 lists** (gap = (method − list) / list):

| Group | Comparisons | Median gap (a) | Median gap (b) | Rows within ±10% (b) |
|---|---|---|---|---|
| Local administration (4 UAT lists + 2 county councils) | 45 | −28% | **−21%** | 11 of 45 |
| The rest (2 hospitals, 1 agency, 1 institute, 1 university) | 11 | −23% | **−2%** | 8 of 11 |

   Method (b) is exact for the health tables in the two hospital lists (0% to −2% on 5 of 5 nurse, doctor and infirmieră rows), for the education table (0%), and within 10% for the central bodies. It is 14% to 28% too low for the Annex VIII workers in one hospital (muncitor, șofer). For local administration, method (b) is a floor, not an estimate: each council pays up to 2.2× the annex value.
4. **Local administration:** "maxim legal" (ADR 0008, rule 3) is a true upper bound but a poor estimate. The 45 local values are 25% to 93% of the cap (median 50%). No value reaches 95% of the cap. Only management posts reach it.
5. **Ground truth is incomplete.** No school list and no ministry list that we could parse. The two hospital lists come from spike S2. See 5.2.
6. **The draft law is out of scope.** OUG 7/2026 (24 Feb 2026) does not change base pay. No 2026 act raises base pay. The rule for 2026 is Legea 141/2025, art. XVI.

## 2. The functions (step 1)

**Source and definition.** The brief names `webapp-php/posturi.sqlite`. It is stale. The owner replaced it with `data/_dev/dev.sqlite` (9,756 rows in `job_postings`). The coordinator corrected the date of this file: it is a snapshot of **2026-09-09** (max `last_seen_at`), not of 2026-10-18.

- **Active** = `coalesce(apply_deadline, expires_at) >= '2026-09-09'`. Result: **1,822 postings** (1,819 have `sal_json`). Same definition as spike S2.
- The export has no grid-function column. The function comes from `posting_text.sal_json` -> `$.selector.{anexa, regim, functie_grila}`.
- SQL: `tmp/261118/s1/top_functions.sql`. Replace `:active_from` with the literal `'2026-09-09'`, and `p.expires_at` with `coalesce(p.apply_deadline, p.expires_at)`.

Top 15 functions, active (1,822 postings): Asistent medical 177 (Anexa II); Îngrijitor 142 (VIII); Muncitor calificat 91 (VIII); Medic specialist 73 (II); Șofer 68 (VIII); Infirmieră 64 (II); Muncitor necalificat 59 (VIII); Consilier 45 (VIII); Asistent social, funcționar public 44 (VIII); Referent de specialitate 34 (VIII); Administrator financiar 28 (I); Îngrijitoare, spălătoreasă 26 (II); Mediator școlar 24 (I); Inspector de specialitate/expert/inginer 21 (VIII); Economist 20 (VIII).

The report uses 14 functions (F1–F14). They cover: Anexa VIII funcționari publici (F1–F3), Anexa VIII personal contractual (F4–F8), Anexa II (F9–F12), Anexa I (F13–F14), and local posts in a comună (F1, F4, F5, F6, F8 in the lists of Vlădeni and Tulca). Îngrijitoare/spălătoreasă, Secretar, Referent de specialitate and Inspector are not repeated: they use the same tables as F3/F4.

## 3. The law text (step 2) and the 2026 rules (step 3)

### 3.1 Sources read

- Consolidated Legea 153/2017: <https://legislatie.just.ro/Public/DetaliiDocument/298840>. The page lists consolidated versions up to 30.09.2026. Its notes include Legea 141/2025 and no later act that touches base pay. The page does not say which version it shows (assumed: the latest).
- OUG 19/2024 (health, social assistance): <https://legislatie.just.ro/Public/DetaliiDocument/279777>.
- OUG 7/2026: <https://legislatie.just.ro/Public/DetaliiDocument/307573> (read in full for "salariu de bază": no change to base pay).

### 3.2 Rows used (annex, table, row, value in lei, gradation 0, "Anul 2022")

Gradation multipliers (Art. 10 alin. (4), compound): g1 1.075; g2 1.12875; g3 1.1851875; g4 1.2148172; g5 1.2451876. They match `data/salarii/parametri.json`.

| Fn | Annex / table / row | 2022 value (lei) | Coefficient | Source of later value |
|---|---|---|---|---|
| F1 | Anexa VIII cap. I lit. A pct. II lit. b, nr. 2, "consilier… grad profesional superior" S (teritorial; proxy, see 4.1) | 4,905 | 1.96 | ×1.05 |
| F2 | same row, "grad profesional principal" S | 4,561 | 1.82 | ×1.05 |
| F3 | same table, nr. 4, "Referent grad profesional superior" M | 4,087 | 1.63 | ×1.05 |
| F4 | Anexa VIII cap. II lit. A pct. III lit. b, nr. 2, "Consilier, expert, inspector de specialitate… gradul IA" S (teritorial; proxy) | 4,733 | 1.89 | ×1.05 |
| F5 | Anexa VIII cap. II lit. C lit. b, nr. 12, "Muncitor calificat I" | 3,850 | 1.54 | ×1.05, floor |
| F6 | same table, nr. 13, "Muncitor necalificat I" | 2,535 | 1.01 | floor |
| F7 | same table, nr. 9, "Îngrijitor" | 2,950 | 1.18 | floor |
| F8 | same table, nr. 11, "Șofer I" | 3,850 | 1.54 | ×1.05 |
| F9 | Anexa II cap. I pct. 2 lit. a.4 (unități sanitare, fără unități clinice), nr. 23 "Asistent medical principal PL" | 3,900 | 1.56 | OUG 19/2024 Anexa 1, a.4, nr. 23: 4,778 (Mar 2024), **5,460** (Jun 2024) |
| F10 | same table, nr. 3 "Medic specialist" S | 9,702 | 3.88 | OUG 19/2024 Anexa 1, a.4, nr. 3: 10,575 / **10,963** |
| F11 | Anexa II cap. I pct. 2 lit. c.4, nr. 1 "Infirmieră, agent DDD" M;G | 3,550 | 1.42 | OUG 19/2024 Anexa 1, c.4, nr. 1: 4,171 / **4,615** |
| F12 | Anexa II (social assistance) "Asistent social principal" S | not read | – | OUG 19/2024 Anexa 2, nr. 1: 5,131 / **5,473** |
| F13 | Anexa I cap. I lit. A pct. 6, nr. 1 "Administrator financiar grad I" S (didactic auxiliar) | 5,645 | 2.26 | OUG 128/2023, annex, pct. 6, nr. 1: 6,221 (Jan 2024) / **6,530** (Jun 2024) |
| F14 | same table, nr. 22/31 "Mediator școlar treapta I" M/G | 4,600 | 1.84 | OUG 128/2023, annex, pct. 6, nr. 31: 4,914 / **5,083** |

For funcționari publici de conducere and for conducere in general, the annex value already includes the gradation at maximum level (Art. 19 alin. (2); notes under each table). Gradation 5 does not apply to them.

### 3.3 Chain of acts (how a value of 2022 becomes the pay of 2026)

| # | Act, article | Rule | Link |
|---|---|---|---|
| 1 | Legea 153/2017, Art. 12 alin. (2) | From 2023, base pay = coefficient × minimum gross wage in payment. | consolidated text |
| 2 | OUG 168/2022, art. I alin. (1), (3) | 2023: base pay +10% over Dec 2022, "fără a se depăși valoarea nominală pentru anul 2022" (annex value). | cited in the consolidated notes of Art. 12 |
| 3 | OUG 45/2023 (introduces art. I alin. (1^1) of OUG 168/2022) | From 1 Jun 2023, pay of the Anexa VIII posts = annex value of 2022. | notes of Art. 12 |
| 4 | OUG 115/2023, art. I alin. (1) | From 1 Jan 2024, base pay +5% over Dec 2023. | notes of Art. 12 |
| 5 | OUG 115/2023, art. I alin. (5); OUG 5/2025, art. IX | New hires and promotions with no similar post in payment: annex value of 2022 + gradation + 5% (+ increases since 1 Jan 2024). Derogation from Art. 39 alin. (2). | notes of Art. 39 |
| 6 | OUG 128/2023, art. I and annex | Education: new tables from 1 Jan 2024 and 1 Jun 2024. | notes of Anexa I |
| 7 | OUG 19/2024, art. I alin. (1)–(2) and Anexa 1–2 | Health and social assistance: new tables from Mar 2024 and Jun 2024. Art. I alin. (4), (6): +20% in two tranches for staff outside Anexa II. | <https://legislatie.just.ro/Public/DetaliiDocument/279777> |
| 8 | OUG 53/2024, art. II (cited by 3 lists; text not read) | Local administration: increase of up to 10% from Jun 2024 for some posts. | lists of Bușteni, Tulca |
| 9 | OUG 156/2024, art. I alin. (1), (3) | 2025: base pay kept at the Nov 2024 level; same for local administration. | notes of Art. 11 |
| 10 | Legea 141/2025, art. XVI alin. (1), (3) | **2026:** base pay kept at the Dec 2025 level, if the person holds the same function in the same conditions. Alin. (3): the same for local administration. | notes of Art. 11 and Art. 12 |
| 11 | Legea 141/2025, art. XVII | 2026: sporuri and other elements kept at most at the Dec 2025 level. | notes of Art. 11 |
| 12 | Legea 141/2025, art. XV pct. 5 (Art. 18) | 2026: indemnizație de hrană 347 lei per month, for net pay up to 6,000 lei. | notes of Art. 18 |
| 13 | OUG 7/2026, art. LIV | 2026: doctor title allowance fixed at 500 lei. No change to base pay. | <https://legislatie.just.ro/Public/DetaliiDocument/307573> |

### 3.4 How a reader gets from the annex value to the pay of 2026

1. Take the 2022 value of the function and grade in the annex (gradation 0). For management posts the value includes gradation.
2. If the function sits in Anexa I (education) or Anexa II (health, social assistance), use the June 2024 column of the later act (OUG 128/2023 or OUG 19/2024). Do not use the annex of 2022.
3. For Anexa VIII, add 5% (OUG 115/2023). The Dec 2023 level of Anexa VIII equals the value of 2022 (OUG 45/2023).
4. Apply the gradation of Art. 10 (compound percentages).
5. The freeze of 2025 and 2026 keeps this level. It does not add any increase.
6. The pay cannot be lower than the minimum gross wage (4,050 lei until 30 Jun 2026).
7. In local administration, steps 1–5 give a minimum only. The council (HCL) sets the pay (Art. 11 alin. (1)). The limit is the vice-mayor indemnity (Art. 11 alin. (4)). Annex VIII has no lei values for local posts (see 4.1).

### 3.5 Where the rule is not clear

- **Which pay is "in payment".** The freeze applies to the person "in the same function and conditions". A new hire has no pay in payment. Row 5 gives the rule for new hires only for the posts without a similar post. Method (b) assumes that this is the common case. This was not verified for each employer.
- **The 15% at central bodies.** ONRC and the institute at `ifa-mg.ro` pay exactly 1.15 × the annex value (ONRC: 11,933 × 1.15 = 13,724; 12,459 × 1.15 = 14,328). We found no article that explains 15%. Assumption: +10% (2023) and +5% (2024) added to a Dec 2022 pay below the annex. Not verified.
- **OUG 53/2024 and the cap.** Three lists cite OUG 53/2024 art. II. We did not read it.
- **The minimum wage floor.** Lists of 31 Mar 2026 use 4,050 lei (Bușteni, Tulca; HG 1506/2024 as cited by Tulca). The press reports 4,325 lei from 1 Jul 2026. We did not find the HG. Lists of 30 Sep 2026 should use 4,325 lei. This affects F5–F8 only at gradation 0 and 1.
- **Clinical or other hospital.** OUG 19/2024 has four tables per function (a.1 clinical, a.2 pathology, a.3 emergency, a.4 other). The estimator needs the type of unit. F9–F11 use a.4 (the lowest). The values of a.1 are 1.5–2% higher.
- `parametri.json` cites old article numbers (CFP "Art. 14", ceiling "Art. 21 alin. (2)", 20%). The consolidated text has CFP in Art. 15, doctor title in Art. 14, ceiling in Art. 25 alin. (1) (30%). Report to the owner of `data/salarii/`.

## 4. Two methods (step 4) and the gap (step 6)

### 4.1 Local administration has no annex value

Anexa VIII has lei values for the central and the territorial administration. For local administration (cap. I lit. A pct. III and cap. II lit. A pct. IV) it has only the list of functions. The council sets the pay (Art. 11 alin. (1)). Method (a) and (b) for local posts therefore use the territorial table as a proxy. The proxy is a floor.

### 4.2 Values per function (gradation 0 and 5)

| Function | (a) g0 | (a) g5 | (b) g0 | (b) g5 |
|---|---|---|---|---|
| F1 consilier/inspector fp superior S | 4,905 | 6,108 | 5,150 | 6,413 |
| F2 consilier/inspector fp principal S | 4,561 | 5,679 | 4,789 | 5,963 |
| F3 referent fp superior M | 4,087 | 5,089 | 4,291 | 5,344 |
| F4 consilier/inspector IA contractual S | 4,733 | 5,893 | 4,970 | 6,188 |
| F5 muncitor calificat I | 3,850 | 4,794 | 4,050 (floor) | 5,034 |
| F6 muncitor necalificat I | 2,535 | 3,157 | 4,050 (floor) | 4,050 (floor) |
| F7 îngrijitor | 2,950 | 3,673 | 4,050 (floor) | 4,050 (floor) |
| F8 șofer I | 3,850 | 4,794 | 4,050 (floor) | 5,034 |
| F9 asistent medical PL principal | 3,900 | 4,856 | 5,460 | 6,799 |
| F10 medic specialist (a.4) | 9,702 | 12,081 | 10,963 | 13,651 |
| F11 infirmieră | 3,550 | 4,420 | 4,615 | 5,747 |
| F12 asistent social principal | – | – | 5,473 | 6,815 |
| F13 administrator financiar grad I | 5,645 | 7,029 | 6,530 | 8,131 |
| F14 mediator școlar treapta I | 4,600 | 5,728 | 5,083 | 6,329 |

### 4.3 The gap per function and list (step 6)

Gap = (method − list) / list. Negative = the method is below the list. "Local" = rows of primării and county councils. The calculation is in `tmp/261118/s1/gap.py` (inputs typed by hand from the lists in 5.1).

| Institution (list date) | Function | Gr | List (lei) | (a) lei | Gap (a) | (b) lei | Gap (b) |
|---|---|---|---|---|---|---|---|
| Targoviste (municipiu) 31.03.2026 | F1 consilier/inspector fp superior S | 0 | 9,620 | 4,905 | -49% | 5,150 | -46% |
| Targoviste (municipiu) 31.03.2026 | F2 consilier/inspector fp principal S | 0 | 8,325 | 4,561 | -45% | 4,789 | -42% |
| Targoviste (municipiu) 31.03.2026 | F4 consilier/inspector IA contractual S | 0 | 8,880 | 4,733 | -47% | 4,970 | -44% |
| Targoviste (municipiu) 31.03.2026 | F5 muncitor calificat I | 0 | 4,847 | 3,850 | -21% | 4,050 | -16% |
| Targoviste (municipiu) 31.03.2026 | F5 muncitor calificat I | 5 | 6,035 | 4,794 | -21% | 5,034 | -17% |
| Targoviste (municipiu) 31.03.2026 | F6 muncitor necalificat I | 0 | 5,587 | 2,535 | -55% | 4,050 | -28% |
| Targoviste (municipiu) 31.03.2026 | F6 muncitor necalificat I | 5 | 6,957 | 3,157 | -55% | 4,050 | -42% |
| Targoviste (municipiu) 31.03.2026 | F8 sofer I | 0 | 4,847 | 3,850 | -21% | 4,050 | -16% |
| Busteni (oras) 31.03.2026 | F1 consilier/inspector fp superior S | 0 | 8,094 | 4,905 | -39% | 5,150 | -36% |
| Busteni (oras) 31.03.2026 | F1 consilier/inspector fp superior S | 5 | 10,080 | 6,108 | -39% | 6,413 | -36% |
| Busteni (oras) 31.03.2026 | F2 consilier/inspector fp principal S | 0 | 6,364 | 4,561 | -28% | 4,789 | -25% |
| Busteni (oras) 31.03.2026 | F3 referent fp superior M | 0 | 4,780 | 4,087 | -14% | 4,291 | -10% |
| Busteni (oras) 31.03.2026 | F4 consilier/inspector IA contractual S | 0 | 5,807 | 4,733 | -18% | 4,970 | -14% |
| Busteni (oras) 31.03.2026 | F4 consilier/inspector IA contractual S | 5 | 7,234 | 5,893 | -19% | 6,188 | -14% |
| Busteni (oras) 31.03.2026 | F5 muncitor calificat I | 0 | 4,050 | 3,850 | -5% | 4,050 | +0% |
| Busteni (oras) 31.03.2026 | F5 muncitor calificat I | 5 | 4,567 | 4,794 | +5% | 5,034 | +10% |
| Busteni (oras) 31.03.2026 | F7 ingrijitor | 0 | 4,050 | 2,950 | -27% | 4,050 | +0% |
| Busteni (oras) 31.03.2026 | F7 ingrijitor | 5 | 4,321 | 3,673 | -15% | 4,050 | -6% |
| Busteni (oras) 31.03.2026 | F8 sofer I | 0 | 4,050 | 3,850 | -5% | 4,050 | +0% |
| Busteni (oras) 31.03.2026 | F8 sofer I | 5 | 4,823 | 4,794 | -1% | 5,034 | +4% |
| Busteni (oras) 31.03.2026 | F9 asistent medical PL principal | 0 | 5,460 | 3,900 | -29% | 5,460 | +0% |
| Busteni (oras) 31.03.2026 | F9 asistent medical PL principal | 5 | 6,801 | 4,856 | -29% | 6,799 | +0% |
| Busteni (oras) 31.03.2026 | F12 asistent social principal S | 0 | 5,473 | - | - | 5,473 | +0% |
| Vladeni (comuna) 31.03.2026 | F1 consilier/inspector fp superior S | 0 | 6,216 | 4,905 | -21% | 5,150 | -17% |
| Vladeni (comuna) 31.03.2026 | F2 consilier/inspector fp principal S | 0 | 5,952 | 4,561 | -23% | 4,789 | -20% |
| Vladeni (comuna) 31.03.2026 | F3 referent fp superior M | 0 | 5,608 | 4,087 | -27% | 4,291 | -23% |
| Vladeni (comuna) 31.03.2026 | F5 muncitor calificat I | 0 | 5,129 | 3,850 | -25% | 4,050 | -21% |
| Vladeni (comuna) 31.03.2026 | F5 muncitor calificat I | 5 | 6,386 | 4,794 | -25% | 5,034 | -21% |
| Tulca (comuna) 31.03.2026 | F1 consilier/inspector fp superior S | 5 | 6,722 | 6,108 | -9% | 6,413 | -5% |
| Tulca (comuna) 31.03.2026 | F4 consilier/inspector IA contractual S | 0 | 5,187 | 4,733 | -9% | 4,970 | -4% |
| Tulca (comuna) 31.03.2026 | F5 muncitor calificat I | 5 | 5,733 | 4,794 | -16% | 5,034 | -12% |
| Tulca (comuna) 31.03.2026 | F6 muncitor necalificat I | 5 | 5,041 | 3,157 | -37% | 4,050 | -20% |
| Tulca (comuna) 31.03.2026 | F8 sofer I | 5 | 5,387 | 4,794 | -11% | 5,034 | -7% |
| CJ Timis 24.03.2026 | F1 consilier/inspector fp superior S | 5 | 13,366 | 6,108 | -54% | 6,413 | -52% |
| CJ Timis 24.03.2026 | F2 consilier/inspector fp principal S | 5 | 9,479 | 5,679 | -40% | 5,963 | -37% |
| CJ Bacau 31.03.2026 | F1 consilier/inspector fp superior S | 0 | 11,983 | 4,905 | -59% | 5,150 | -57% |
| CJ Bacau 31.03.2026 | F1 consilier/inspector fp superior S | 5 | 14,921 | 6,108 | -59% | 6,413 | -57% |
| CJ Bacau 31.03.2026 | F2 consilier/inspector fp principal S | 0 | 10,994 | 4,561 | -59% | 4,789 | -56% |
| CJ Bacau 31.03.2026 | F3 referent fp superior M | 0 | 7,498 | 4,087 | -45% | 4,291 | -43% |
| CJ Bacau 31.03.2026 | F4 consilier/inspector IA contractual S | 0 | 8,280 | 4,733 | -43% | 4,970 | -40% |
| CJ Bacau 31.03.2026 | F5 muncitor calificat I | 0 | 5,980 | 3,850 | -36% | 4,050 | -32% |
| CJ Bacau 31.03.2026 | F5 muncitor calificat I | 5 | 7,447 | 4,794 | -36% | 5,034 | -32% |
| CJ Bacau 31.03.2026 | F7 ingrijitor | 0 | 5,980 | 2,950 | -51% | 4,050 | -32% |
| CJ Bacau 31.03.2026 | F7 ingrijitor | 5 | 7,447 | 3,673 | -51% | 4,050 | -46% |
| CJ Bacau 31.03.2026 | F8 sofer I | 0 | 6,187 | 3,850 | -38% | 4,050 | -35% |
| ONRC (agentie nationala) 10.03.2026 | F5 muncitor calificat I | 5 | 5,514 | 4,794 | -13% | 5,034 | -9% |
| IFA-MG institut (31.03.2026) | F7 ingrijitor | 5 | 4,224 | 3,673 | -13% | 4,050 | -4% |
| UniBuc 31.03.2026 | F13 administrator financiar grad I S | 5 | 8,133 | 7,029 | -14% | 8,131 | +0% |
| SCJU Bihor Oradea (hospital, Sep 2026; S2 file) | F9 asistent medical PL principal | 5 | 6,816 | 4,856 | -29% | 6,799 | +0% |
| SCJU Bihor Oradea (hospital, Sep 2026; S2 file) | F10 medic specialist | 5 | 13,930 | 12,081 | -13% | 13,651 | -2% |
| SCJU Bihor Oradea (hospital, Sep 2026; S2 file) | F11 infirmiera | 5 | 5,747 | 4,420 | -23% | 5,747 | +0% |
| SCJU Pius Brinzeu Timisoara (hospital, Sep 2026; S2 file) | F9 asistent medical PL principal | 5 | 6,817 | 4,856 | -29% | 6,799 | +0% |
| SCJU Pius Brinzeu Timisoara (hospital, Sep 2026; S2 file) | F11 infirmiera | 5 | 5,747 | 4,420 | -23% | 5,747 | +0% |
| SCJU Pius Brinzeu Timisoara (hospital, Sep 2026; S2 file) | F5 muncitor calificat I | 5 | 6,515 | 4,794 | -26% | 5,034 | -23% |
| SCJU Pius Brinzeu Timisoara (hospital, Sep 2026; S2 file) | F6 muncitor necalificat I | 5 | 4,698 | 3,157 | -33% | 4,050 | -14% |
| SCJU Pius Brinzeu Timisoara (hospital, Sep 2026; S2 file) | F8 sofer I | 5 | 6,971 | 4,794 | -31% | 5,034 | -28% |

### 4.4 Results

| Group | n | Median gap (a) | Median gap (b) | Within ±10% (b) |
|---|---|---|---|---|
| Local administration | 45 | −28% | −21% | 11 |
| Rest (2 hospitals, ONRC, ifa-mg.ro institute, UniBuc) | 11 | −23% | −2% | 8 |

Per institution, median gap of (b): hospital Oradea 0%, hospital Timișoara −14% (Annex VIII workers), Bușteni 0%, Tulca −7%, Vlădeni −21%, Târgoviște −35%, CJ Bacău −41%, CJ Timiș −45%. The gap grows with the size and wealth of the UAT.

Observations, measured:

- **Health and social posts:** (b) matches the list on all 3 rows of Bușteni (asistent medical principal PL gradation 0: 5,460; gradation 5: 6,801 against 6,799; asistent social principal: 5,473). The June 2024 column of OUG 19/2024 is still the pay of 2026. Method (a) is 29% too low.
- **Hospitals (S2 files):** SCJU Bihor Oradea pays, at gradation 5, 6,816 lei for asistent medical principal, 13,930 for medic specialist and 5,747 for infirmieră. The clinical table a.1 of OUG 19/2024 gives exactly 6,817 (5,474 × 1.2452), 13,930 (11,187 × 1.2452) and 5,747 (4,615 × 1.2452). With the non-clinical table a.4 (used in the gap table) the gap is 0%, −2% and 0%. SCJU Pius Brînzeu Timișoara matches on asistent medical (6,817) and infirmieră (5,747). The same hospital pays muncitor calificat I 6,515 (gap −23%), muncitor necalificat 4,698 (−14%) and șofer I 6,971 (−28%). Annex VIII, cap. II lit. C does not explain these values. We did not find the rule. Assumption: the 20% rise of OUG 19/2024 art. I alin. (4) for non-Annex II staff, applied on a different base. Not verified.
- **Education:** UniBuc pays 8,133 lei for "administrator financiar" (37 employees, top step). (b) gives 8,131 (6,530 × 1.2452). Method (a) gives 7,029 (−14%). One row only; the list is a university, not a school.
- **Central bodies:** ONRC (muncitor calificat treapta I, gradation 5: 5,514) and the `ifa-mg.ro` institute (îngrijitor gradation 5: 4,224) pay 1.15 × the annex value. Method (b) is 9% and 4% below (the second figure also includes the floor).
- **Local administration, low-skill posts:** most councils pay at or near the floor: Bușteni pays 4,050 lei at gradation 0 for muncitor, îngrijitor and șofer. Târgoviște, Tulca and Bacău pay 4,847–6,187 lei at gradation 0.
- **Local administration, qualified posts:** the lists show 1.4× to 2.5× the annex value: Bușteni 8,094 (fp superior, g0), Târgoviște 9,620, CJ Bacău 11,983.
- **ANAF excluded.** DGRFP Timișoara (30.09.2026) pays 12,815 lei for "consilier superior", gradation 5. Method (b) gives 6,413 (+100% gap). The ANAF has a special regime. It is not in the table. See 5.1.

### 4.5 "Maxim legal" against the lists (local administration)

The maximum is the indemnity of the vice-mayor (Art. 11 alin. (4)). It is `coefficient (Anexa IX lit. C, poz. 17–28) × 2,415 lei`. 2,415 = 2,300 × 1.05 (OUG 115/2023 art. I alin. (2)). The lists confirm: Târgoviște 8 × 2,415 = 19,320; Bușteni 4.5 × 2,415 = 10,868; Tulca and Vlădeni 3 × 2,415 = 7,245; CJ Bacău and CJ Timiș 19,320.

| List | n | Min | Median | Max (value / maximum) |
|---|---|---|---|---|
| Târgoviște | 8 | 25% | 34% | 50% |
| Bușteni | 15 | 37% | 50% | 93% |
| Vlădeni | 5 | 71% | 82% | 88% |
| Tulca | 5 | 70% | 74% | 93% |
| CJ Timiș | 2 | 49% | 59% | 69% |
| CJ Bacău | 10 | 31% | 39% | 77% |
| All | 45 | 25% | 50% | 93% |

No council pays the maximum for a function that we compared. Only the management functions (secretar general, arhitect-șef, director executiv) reach it: Târgoviște 19,320; CJ Timiș 19,320; CJ Bacău 19,320. The maximum is also a poor upper bound for low-skill posts: for a muncitor, the cap is 1.3× (Tulca) to 4.8× (Târgoviște) the pay. **The rule "maxim legal" does not fit the lists as a single figure.** It fits as the top of a range.

## 5. Ground truth (step 5)

### 5.1 Lists used (all downloaded, in `tmp/261118/s1/lists/`)

| # | Institution | Type | Date | URL | Used |
|---|---|---|---|---|---|
| 1 | Municipiul Târgoviște | primărie, municipiu | 31.03.2026 | <https://pmtgv.ro/Lege153_2017/20260331TransparentaSalariala.pdf> | yes |
| 2 | Orașul Bușteni | primărie, oraș (≤10,000) | 31.03.2026 | <https://www2.orasul-busteni.ro/wp-content/uploads/2026/06/Transparenta_31.03.2026_639105448533956748.pdf> | yes |
| 3 | Comuna Vlădeni (Dâmbovița) | primărie, comună | 31.03.2026 | <https://www.primariavladeni.ro/wp-content/uploads/2026/03/transparenta-venituri-31.03.2026-postat-1.pdf> | yes. Several values are below the minimum wage, which suggests an outdated table |
| 4 | Comuna Tulca (Bihor) | primărie, comună (≤3,000) | 31.03.2026 | <https://docs.google.com/document/d/15S0jk6SeGuiOlOFbdlpAQqQad8T4uiEP/export?format=pdf> | yes |
| 5 | Consiliul Județean Timiș | county council | 24.03.2026 ("în plată în luna martie 2026") | <https://www.cjtimis.ro/wp-content/uploads/2020/07/2026-FUNCTII-PUBLICE.pdf> | yes, funcții publice only |
| 6 | Consiliul Județean Bacău | county council | 31.03.2026 | <https://www.csjbacau.ro/dm_cj/portalweb.nsf/C52BA7695B297877C2258DCB00374B50/%24FILE/Salarizare%20functii%20la%2031%20martie%202026c.pdf> | yes |
| 7 | Oficiul Național al Registrului Comerțului | national agency | stat de plată 10.03.2026 | <https://onrc.ro/documente/salarii/salarii_plata_martie2026.pdf> | yes (1 row) |
| 8 | Institute at ifa-mg.ro (name not in the file) | research institute | 31.03.2026 | <https://www.ifa-mg.ro/docs/salarii/Transparenta%20veniturilor%20salariale%20Martie%202026%20conf.%20art.%2033%20din%20Legea%20153%20din%202017%20.pdf> | yes (1 row) |
| 9 | Universitatea din București | university | 31.03.2026 | <https://unibuc.ro/wp-content/uploads/2026/05/Transparenta-veniturilor-la-31.03.2026.pdf> | yes (1 row) |
| 11 | SCJU Bihor Oradea | hospital (clinical) | Sep 2026 | `tmp/261118/s2/files/oradea_2026-09.pdf`, parsed in `tmp/261118/s2/parsed/` | yes (3 rows) |
| 12 | SCJU „Pius Brînzeu” Timișoara | hospital (clinical) | Sep 2026 | `tmp/261118/s2/files/hosptm_2026-09.pdf` | yes (5 rows) |
| 10 | DGRFP Timișoara (ANAF) | ministry-subordinate | 30.09.2026 | <https://static.anaf.ro/static/10/Timisoara/Timis/Transparenta_salariala_30.09.2026.pdf> | no: special pay regime |

Rejected: Spitalul Județean Sfântu Gheorghe (Google sheet; undated, based on a staff list of 2017, pay below the minimum wage); Gheorgheni (404). Lists of 2025 (Chitila, Ocna Mureș, Sărata) are older than the brief allows.

The brief asks for lists of institutions that have active postings. Lists 11 and 12 meet this (SCJU Bihor has 20 active postings in the old definition). The primării and county councils of lists 1–6 were chosen for size and for the format of the list, not because they have active postings. We did not check this.

### 5.2 Gaps in the ground truth

- **Hospitals:** two lists from S2, both clinical hospitals. Non-clinical units (table a.4) and municipal hospitals are not covered. We could not tell which table (a.1 or a.4) each hospital uses from the employer type. The hospital rows use a.4 (lowest); the gap would be 0% with a.1.
- **No school list.** UniBuc stands for the education table. A school list would show the 2024 uplifts of OUG 128/2023 art. II alin. (2) (+250 and +450 lei for nedidactic staff).
- **No ministry list parsed.** The list of the Ministry of Environment (`tmp/261118/s2/files/mmap_2026-03.pdf`) is a scanned image and has no text. ONRC and DGRFP are agencies.
- **Dates.** The primării, county councils and agencies date from March 2026. The hospitals (S2) and the ANAF list date from September 2026. The lists of 30 Sep 2026 are mostly not yet indexed.

## 6. Sporuri (step 7)

All articles of the consolidated Legea 153/2017. "In base pay" = included in the base salary.

| Spor / right | Article | Maximum | In base pay? |
|---|---|---|---|
| Title of doctor | Art. 14 alin. (1); OUG 7/2026 art. LIV | 50% of the minimum gross wage; in 2026 fixed at 500 lei | No |
| Control financiar preventiv (CFP) | Art. 15 | +10% of base pay | No (added to it; lists show "(include spor CFP)") |
| Projects with European funds | Art. 16 alin. (1) (changed by Legea 141/2025 art. XV) | up to 40% of base pay, in proportion to time; mayor and vice-mayor up to 40% (alin. (2)) | No |
| Management of European funds | Art. 17 alin. (1) | up to 40% | No |
| Indemnizație de hrană | Art. 18 alin. (1) | 347 lei per month in 2026, for net pay up to 6,000 lei | No |
| Night work | Art. 20 | 25% of base pay, at least 3 night hours | No; outside the cap of Art. 25 |
| Overtime | Art. 21 | compensation by free time, or pay | No |
| Disability | Art. 22 | 15% of base pay | No |
| Working conditions | Art. 23; Anexa VIII cap. I lit. B art. 1 | up to 300 lei per month (dangerous or harmful conditions), by regulation | No |
| Vouchere de vacanță | OUG 8/2009 as changed by Legea 141/2025 art. XLIV | 800 lei per year, for net pay up to 6,000 lei (as cited by 3 lists) | No |
| Gradation | Art. 10 | 7.5%, 5%, 5%, 2.5%, 2.5% (compound) | **Yes**: it is a base-pay step |
| Complexity | Anexa VIII notes (ministries, ANCPI) | +15% for named bodies | Yes, as a base-pay increase |
| Limit of all sporuri | Art. 25 alin. (1) | 30% of the sum of base pay, per ordonator (not per person) | – |

`parametri.json` shows the ceiling as 20% under "Art. 21 alin. (2)". The consolidated text says 30% under Art. 25 alin. (1).

## 7. Recommendation and what we measured against what we assumed

**Recommended method.** Method (b), in three layers:

1. Central, territorial and agency posts (Anexa VIII): `annex value of 2022 × 1.05 × gradation`, never below the minimum wage.
2. Health and social assistance (Anexa II) and education (Anexa I): the June 2024 table of OUG 19/2024 or OUG 128/2023, × gradation.
3. Local administration: show a range, with (b) as the lower end and the maximum of Art. 11 alin. (4) as the upper end. Use the transparency list of the employer when it exists (ADR 0008, rule 4). Our lists suggest a central estimate near 50% of the cap, but that is too coarse to publish.

**Measured:** the 56 gaps in 4.3; the validity of the June 2024 health tables for 2026 (5 of 5 hospital rows within 2%; 4 of 4 education and Bușteni rows within 1%); the cap values (6 of 6 lists match coefficient × 2,415).

**Assumed (not measured):** the consolidated page shows the latest version; the freeze rule for new hires (3.5); the +15% at ONRC and the institute; the minimum wage of 4,325 lei from 1 Jul 2026; that the territorial annex is a good proxy for the floor of local posts; that the six local lists are typical (they are not a sample: Târgoviște, Bușteni, Vlădeni, Tulca and two county councils).

**Counts:** 1,822 active postings; 12 lists, 11 compared; 14 functions; 56 comparisons (45 local, 11 other).

## 8. What `build-salary-grid.py` needs for method (b)

No workbook exists for the law in force. The draft came as an `.xlsx`. For 153/2017 the options are:

- **Recommended: parse the consolidated HTML.** `https://legislatie.just.ro/Public/DetaliiDocument/298840` has real `<table>` elements (176 tables, 3,824 rows) for Anexa I, II, VIII. `.../279777` (OUG 19/2024, 19 tables) holds the health tables. The OUG 128/2023 annex is inside the consolidated page (the tables with the "ianuarie 2024 / iunie 2024" columns). Download the HTML to `data/salarii/` with a date and a checksum. A table parser reads the rows. The structure is irregular (merged cells, "grad profesional" sub-rows), so the parser needs a manual check for about 300 rows.
- **Alternative: a hand-typed CSV** with the same columns as `grila-2026-07-17.csv` plus `baza=lege_in_vigoare`, `valoare_2022`, `valoare_dupa_2026`, `act`. About 300 rows for Anexa VIII (II.A, II.C, I.A), 3 × 54 rows for health (a.1, a.4, c.1, c.4), ~100 rows for education. It is slow and error prone.
- **New columns in the registry:** `act_sursa` (for example `OUG 19/2024 Anexa 1 a.4`), `tip_unitate` (a.1–a.4), `multiplicator_regula` (1.05 or 1), `plafon_local_coef` (Anexa IX lit. C).
- **New small table:** `plafon-local.csv` = type and size of UAT → coefficient (3.00 … 8.00) × 2,415. The bands of population exist already in `core-assets` (`uat.bands_for`).
- **New parameters in `parametri.json`:** `salariu_minim_brut` with dates (4,050 until 30 Jun 2026; 4,325 from 1 Jul 2026, to verify), `valoare_referinta_indemnizatii` 2,415, and the correct article numbers.

## 9. Open questions for the owner

1. **Local range or single figure?** Show "între X și maxim legal" (our recommendation), or one figure from method (b) labelled "minim estimat"? The measured gap of (b) is −21% median in local administration, and up to −57% in county councils.
2. **Source of tables.** Parse the consolidated HTML (recommended) or type the CSV by hand?
3. **Health unit type.** The hospital lists match table a.1 (clinical) exactly. The employer type in the export does not tell a.1 from a.4. Accept a.4 (lowest) for all hospitals, or add a classifier (name contains "Clinic")?
4. **Uplifts above method (b).** ONRC and the institute pay 1.15 × the annex; a hospital pays Annex VIII workers 14–28% above (b). Find the legal basis, or accept the gap?
5. **Minimum wage from 1 Jul 2026.** Confirm 4,325 lei (HG). It changes F5–F8 at low gradations.
6. **Missing lists.** School and ministry lists, and non-clinical hospital lists. Add them by hand, or write a crawler for Art. 33 lists (backlog)?
7. **Fresh export.** The dev export is a snapshot of 2026-09-09. Re-run step 1 on a fresh export?
8. **OUG 53/2024.** Read art. II (increase of up to 10% in local administration) before we write the rule for comune?
9. **ADR 0008 rule 3.** Change "maxim legal" to "range with maxim legal as the upper end"? This changes the ADR; the owner decides.

## Proposed activity-log entry

### 2026-11-18 — Spike S1: pay under Legea 153/2017

**Done.** Read the consolidated Legea 153/2017 (legislatie.just.ro, id 298840), OUG 19/2024 and OUG 7/2026. Built the chain of acts from the annex value of 2022 to the pay of 2026 (`docs/audits/2026-11-18-salary-s1-current-law.md`, 3.3). Collected 12 Art. 33 lists (6 local, 2 hospitals from S2, 4 other). Compared 14 functions in 56 rows with the annex value (a) and with the value after the 2026 rules (b). Median gap of (b): −2% for the 11 non-local rows (health and education tables exact), −21% for local administration (45 rows). 1,822 active postings in `data/_dev/dev.sqlite` (snapshot of 2026-09-09; active = coalesce(apply_deadline, expires_at) ≥ 2026-09-09).

**Hard.** Annex VIII has no lei values for local administration; the council sets the pay (Art. 11). Health and education tables of 2022 are obsolete (OUG 19/2024, OUG 128/2023). No school list and no parseable ministry list. ONRC and a research institute pay exactly 1.15 × the annex value, and one hospital pays workers 14–28% above (b); the legal basis is unknown. `parametri.json` cites old article numbers.

**Decided.** (Proposed, owner to confirm.) Method (b) for the central, health and education posts. For local administration, a range with the Art. 11 alin. (4) maximum as the upper end. Rationale: 45 local values are 25–93% of the cap (median 50%), none ≥ 95%. Cost of the alternative (the cap alone): the figure is 1.3× to 4.8× the pay of a low-skill post.

## Proposed backlog items

- [ ] S1-1 Owner decision: local pay as a range (ADR 0008 rule 3 change). Source: S1 report 4.5 and 9.
- [ ] S1-2 `build-salary-grid.py`: parse the consolidated HTML of Legea 153/2017 and OUG 19/2024 into `data/salarii/` (baza `lege_in_vigoare`). Add `act_sursa`, `tip_unitate`, `multiplicator_regula`.
- [ ] S1-3 New table `data/salarii/plafon-local.csv` (Anexa IX lit. C coefficient × 2,415) joined to the UAT population band.
- [ ] S1-4 Fix `data/salarii/parametri.json`: article numbers (CFP Art. 15, ceiling Art. 25 alin. (1) at 30%), minimum wage with dates, reference value 2,415.
- [ ] S1-5 Find the transparency lists still missing: 1 school, 1 ministry (OCR the scanned list of MMAP), non-clinical hospitals, lists of 30 Sep 2026. Re-run the gap table.
- [ ] S1-6 Read OUG 53/2024 art. II and the HG of the minimum wage 2026 (4,325 lei from 1 Jul 2026?).
- [ ] S1-7 Find the legal basis of the 1.15 multiplier at ONRC and `ifa-mg.ro` (Verified limits if none).
- [ ] S1-8 Re-run S1 step 1 on a fresh export (the dev export is a snapshot of 2026-09-09).

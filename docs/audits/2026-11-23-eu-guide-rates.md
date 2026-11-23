# Audit — hourly rate caps in the applicant guides (EU-GUIDE-RATES)

Date: 2026-11-23. Brief: `docs/briefs/2026-11-23-eu-guide-rates.md`. Spike only. No code changed.
Tags: **[M]** measured, **[A]** assumed, **[U]** unverified.

## 1. Result

1. **PoIDS (PIDS) and PEO: the general guides cap the pay.** [M] Both guides give one table of net lei per hour. The table covers **posts outside the organigram of a public body**. This is the case of a project hire.
2. **No cap by role title.** [M] The cap depends on the group of the expert and on the years of specific experience: 70 / 80 / 90 lei net per hour for managers and implementation experts. Support and administrative groups are lower.
3. **The two tables are identical.** [M] The 2023 PEO and PIDS general guides carry the same figures.
4. **PCIDIF: not found.** [M] The Beneficiary Manual has no hourly figure. The specific guides of Actions 2.2.x and 3.1 were not obtained. See section 5.
5. **The postings follow the cap.** [M] No PIDS or PEO posting states a net rate above 90. Eight of 21 sit exactly on a cap value (70, 80 or 90).
6. **Use for SALARY-EU-DETAIL.** A post with no stated pay can show "maxim 70–90 lei net/oră, conform Ghidului solicitantului (condiții generale)". It cannot show one figure: the role group and the experience of the future employee are not in the posting. [M]

## 2. Programmes and codes in the data [M]

Source: `data/_dev/full-2026-11-23.sqlite`, `project_hire = 1`, text of `posting_text.body_markdown` (the table holds no attachment text). Pattern `SMIS[^0-9]{0,25}(\d{5,6})`.

- 1,033 project hires. 591 (57 %) state an SMIS code.
- `v4_funding_programme` is empty for 969 of 1,033. It cannot name the programme. I used the project name and keywords in the text.
- Keyword count over the 1,033 texts: PIDS or "Incluziune și Demnitate" 541; PEO 24; POCU 14; PNRR 12; PCIDIF/PoCIDIF 4.

| # | SMIS | Postings | Project in the text | Programme |
|---|---|---|---|---|
| 1 | 339395 | 215 | SCI 2000, echipe comunitare integrate (Biertan, Marpod and others) | PoIDS (PIDS) |
| 2 | 122607 | 199 | "proiectul pilot … servicii comunitare integrate", cod MySMIS 122607 | Not stated. The code is the old pilot project. **[U]** I did not check the programme (2014–2020 POCU is likely). |
| 3 | 353396 | 16 | Home care for the elderly, Matca | PoIDS Action 6.1 [A] |
| 4 | 353349 | 11 | "Sprijin pentru Bunici", Valcău de Jos | PoIDS Action 6.1 [A] |
| 5 | 353382 | 10 | Home care, Corod | PoIDS Action 6.1 [A] |
| 6 | 352846 | 10 | Home care, Frumușica | PoIDS Action 6.1 [A] |
| 7 | 353855 | 8 | Home care, Rus; 2 postings say PIDS | PoIDS |
| 8 | 356963 | 7 | Tecuci, SDL Anton Cincu; "Programul Incluziune și Demnitate" in the text | PoIDS |
| 9 | 346255 | 6 | "Victoria susține seniorii" | PoIDS Action 6.1 [A] |
| 10 | 353515 | 6 | Vârfu Câmpului; text says "PIDS/POIDS 2021-2027" | PoIDS |
| 11 | 348599 | 5 | Măgura; "Programul Incluziune și Demnitate" | PoIDS |
| 12 | 352178 | 5 | Vulcan, therapy for children with disabilities | PoIDS [A] |

[A] = I inferred the programme from the project title and the code range (3xxxxx home-care calls). The text does not name it. So **PoIDS covers most of the coded postings**. PEO is next (24 keyword hits, no repeated code in the top 12).

## 3. Guides read

All downloaded on 2026-11-23 with `curl`. Files are in `data/eu-guides/` (ignored by git). Text was extracted with `pdftotext -layout`. No LLM read them.

| Programme | Guide | URL | Cap |
|---|---|---|---|
| PoIDS | Ghidul solicitantului – Condiții generale PIDS, March 2023, annex to Ordin MIPE 1245/2023 (60 pp.) | https://primariahereclean.ro/wp-content/uploads/2025/06/GS-CG_PIDS.pdf | **Yes** |
| PoIDS | Condiții specifice, Action 6.1 „Servicii de îngrijire la domiciliu pentru persoanele vârstnice”, 2025, annex to Ordin MIPE 622/2025 (43 pp.) | https://www.municipiulcarei.ro/sites/default/files/anunturi%202025/GS%20CS_PoIDS_6.1_Ingrijire%20la%20domiciliu_final.pdf | No own cap; refers to the general guide |
| PEO | Condiții generale PEO, March 2023, annex to Ordin MIPE 1244/2023 (51 pp.) | https://www.uab.ro/media/documente/GS_CG_PEO.pdf | **Yes**, same table |
| PCIDIF | Manualul Beneficiarului, ed. 1, Jan 2024 | https://ctfs.utcluj.ro/files/anunturi/2.%20PCIDIF_%20Manualul%20Beneficiarului%20editia%20ian%202024%20.pdf | **No cap found** (no hourly figure; searched "tarif", "/oră", "cost orar") |

The copies are hosted by a commune, a university and a municipality, not by mfe.gov.ro. I did not compare them with the MFE originals. **[U]** The PEO and PIDS general guides are dated March 2023. Later amendments (Ordin MIPE changes) may have changed the table. **[U]**

## 4. The cap

### 4.1 PoIDS, general conditions, section on personnel costs, PDF pages 38–39 (printed 38–39) [M]

Rules (p. 38):

> „Plafoanele de decontare reprezintă valori maximale, aferente salariilor nete (fără taxele, impozitele si contribuțiile angajatului și ale angajatorului, aferente salariilor);”

> „Taxele, impozitele si contributiile obligatorii aferente salariilor sunt eligibile, dar nu sunt incluse în plafon …”

> „În cazul beneficiarilor/partenerilor care sunt instituții și/sau autoritățile publice, plafoanele maxime de decontare prevăzute se aplică exclusiv personalului angajat pe posturi în afara organigramei, nominalizat în echipele de proiecte finanțate din fonduri europene;”

> „Pentru personalul din instituțiile și/sau autoritățile publice nominalizat în echipele de proiecte finanțate din fonduri europene, angajat în cadrul organigramei, se aplică prevederile Legii nr. 153/2017 …”

Table „Plafoane aplicabile cheltuielilor cu personalul”, „Plafon maxim de decontare (salariu net/ora), lei” (p. 39):

| Group | Experience (years) | Cap, lei net/h |
|---|---|---|
| Direct: manager de proiect, responsabil financiar, responsabil de achiziții publice, asistent manager, coordonator partener, „experți implementare activități proiect (naționali și internaționali) etc.” | <5 / 5–10 / >10 | 70 / 80 / 90 |
| Indirect, support: consilier juridic, expert suport IT, experți informare și publicitate, specialist resurse umane | <5 / 5–10 / >10 | 50 / 60 / 75 |
| Indirect, administrative and auxiliary | work seniority <3 / >3 | 35 / 45 |

Other rules on the same pages (p. 38): the pay is reimbursed per hour worked; the base is the 8-hour day and 21 working days a month; maximum 12 hours a day and 60 hours a week per expert. Experience means "experiența profesională specifică corelată cu cerințele pozițiilor necesare implementării unui proiect finanțat prin PIDS 2021-2027" (p. 39). [M] The PIDS guide has the 8-hour / 21-day base and the 12 h / 60 h limit too.

### 4.2 PEO, general conditions, printed pp. 30–31 (PDF pp. 30–31) [M]

Same rules and same table, word for word (70/80/90; 50/60/75; 35/45). The text states: „Decontarea cheltuielilor salariale se va determina în baza retribuţiei echivalente pe oră, luând ca punct de referinţă ziua de lucru de 8 ore şi o medie de 21 de zile lucrătoare pe lună” and „Limita de timp maximă … 12 ore/zi, 60 ore/săptămână”.

### 4.3 PoIDS Action 6.1 specific guide [M]

The guide holds no personnel cap. It sends to the general guide for ceilings (section 5.3, p. 30): „… vor respecta regulile și plafoanele stabilite prin Ghidul Solicitantului - Condiții Generale PoIDS 2021-2027”. It also recommends to state the number of hours in the cost description (p. 33). The specific guide fixes the indirect costs at a flat 15 % of direct staff cost. The guide has no table by role.

### 4.4 PCIDIF [M for what I read, U for the rest]

The Beneficiary Manual (Jan 2024) gives no hourly rate. It is a procedure manual and says it is "strict orientativ". The caps, if any, sit in the specific guides per action. I did not obtain the guides for Actions 2.2.x or 3.1 (section 5).

## 5. What I could not get

- **PoIDS Condiții specifice AP4 (SCI 2000, code 339395)**: not tried again. The ADR Centru copy answers HTTP 403 (also in the 2026-11-20 audit). A search did not find another host. This is the guide for the 215 most frequent postings. **[U]** whether it changes the pay rules; the Action 6.1 guide does not.
- **PCIDIF Action 2.2.x and 3.1 specific guides**: the ADR Centru copy (AP10, e-guvernare) answers HTTP 403. One search found no other host. The ADR Sud-Muntenia digitalisation guide I found is a regional programme guide, not PoCIDIF; I deleted it and did not read the cost section.
- **PCIDIF Action 2.1 guide** (the "Limita max €/h" lead): not read. It is for SMEs, so it is outside the scope.
- **mfe.gov.ro originals**: not reached. I used copies.
- **Amendments after March 2023**: not checked.
- **PNRR**: no applicant guide in this sense; not in the scope of the brief.

I stopped at three programmes (PoIDS, PEO, PCIDIF), as the brief says. The first two have a cap.

## 6. Comparison with the postings [M]

`proj_pay_unit = 'ora'`: 26 postings of the 1,033 project hires. 5 are university postings (Galați; Erasmus, ERIC and research projects) and are not in PoIDS or PEO. I left them out. That leaves **21 postings**.

| Group | Postings | Rate, lei/h | Reading |
|---|---|---|---|
| Frumușica (home care, SMIS 352846; PoIDS [A]) | 10 | 40–60 net | Below the lowest direct tier (70). Inside the support tier (50/60/75) for some roles; "îngrijitor" at 40 is above the administrative tier (35/45) only if >3 years. Roles are not mapped to groups. **[U]** |
| ANPDPD, consilier juridic (personal suport) | 1 | 60 net | On the support tier (5–10 years) **[A]**: "Consilier juridic" is named in the support group. |
| Mileanca, manager de proiect | 1 | 90 net | On the top direct cap. |
| Mileanca, responsabil financiar | 1 | 70 net | On the lowest direct cap. |
| Mogoșoaia, Ineu, Vulcan, Toldy | 4 | 70 (net or unstated) | On the lowest direct cap. |
| Budești (consilier școlar), Ocnele Mari (consilier educațional) | 2 | 80 (one "brut de bază", one "net") | On the middle cap. Budești says "salariu de bază brut de 80 lei/oră": a gross word beside a net cap. **[U]** |
| Carol I Iași, responsabil financiar (POEO) | 1 | 122.35 „inclusiv contribuțiile datorate de angajator” | Above 90 as written. Employer cost, not net. My arithmetic: 70 net ÷ 0.585 × 1.0225 = 122.4. So it equals the 70 net cap. This uses the 2026 rates (CAS 25 %, CASS 10 %, tax 10 %, CAM 2.25 %) and no personal deduction **[A]**. |
| Blăgești, îngrijitor | 1 | 17.70 net | Far below any tier. |

- **No PIDS or PEO posting states a net rate above 90.** [M]
- **8 of 21 sit exactly on a cap value** (70: 5; 80: 2; 90: 1; counting Iași as 70-equivalent makes 9). [M] This is a sign that the budgets use the cap, not proof. [A]
- Basis: 15 of the 21 say "net" in the quote; 6 do not say (Toldy, Vulcan, Budești, Costea Marinoiu, Iași, Blăgești). [M] Count from `proj_pay_basis`.
- Monthly pay: 3 postings (2,100–2,767 lei/month). Not comparable to an hourly cap without the hours. Not compared.

## 7. Use for SALARY-EU-DETAIL (proposal, Opus decides)

1. Show the cap only for postings that name PoIDS or PEO (or a code that maps to them) **and** have no stated pay. Text: „Plafon maxim conform Ghidului solicitantului (condiții generale): 70–90 lei net/oră pentru manager și experți de implementare, după experiență.” Do not show a monthly figure: the hours per month are in the posting only sometimes.
2. Do not show the cap for a post inside the organigram. The guide says Legea 153/2017 applies there.
3. For roles of the support or administrative group, the cap is lower (35–75). The roles in the postings (asistent medical, îngrijitor, psiholog) are not named in the table. **[U]** Do not map them without a source.
4. Treat the guide date (March 2023) as a limit. Check the current version at mfe.gov.ro before the label goes live. **[U]**
5. Next spike, if wanted: the AP4 (SCI 2000) and PCIDIF 2.2.x/3.1 guides from a browser session, since the ADR sites block scripts.

## 8. Assumed

- Programme of the home-care codes (rows marked [A] in section 2).
- Frumușica belongs to PoIDS (my keyword match missed it).
- The tax rates used in the Iași conversion.
- The copies of the guides equal the originals and are not amended.

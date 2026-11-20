# Spike — Asset declarations (ANI) in the monitorul.ai Kibana, as a pay source

Date: 2026-11-20. Author: Opus, with the owner. Backlog: SALARY-DECLARATII. ADR: 0008 (rule 3, council-set pay).

## Summary

The Kibana of monitorul.ai/integritate has a usable pay source. About 1.4 million asset declarations have their income table extracted by an LLM. The extracted declarations are those filed in **2022, 2023 and 2024**, so they give the pay for **2021, 2022 and 2023**. The backlog said that the data ends about 2022; that is wrong for this index.

Each declaration has a normalised job title (`functie`, 1,214 values) and a normalised institution (`institutie_id`, 27,677 values). So we can get pay by job title, by institution and by county.

The income lines are a plain object array, not a `nested` field. Elasticsearch cannot tie the category, the holder and the amount of one line together in a query or an aggregation. So the dump pulls the documents and filters the lines on our side.

## Access

- Host: `kibana.sicap.ai`, space `integritate`, Kibana 9.4.0. The owner's user has the role `integritate` (native realm).
- Data view: `integritate`, id `562a6f93-4049-4bf7-ab28-85e5a068e089`, index `integritate`. No time field.
- The Dev Tools console proxy (`/api/console/proxy`) is not available to this role.
- The internal search endpoint works from a signed-in page:
  `POST /s/integritate/internal/search/es` with the headers `kbn-xsrf: x`, `x-elastic-internal-origin: Kibana`, `elastic-api-version: 1`, and the body `{"params": {"index": "integritate", "body": <ES query>}}`. The result is in `rawResponse`.
- The cluster has API keys on. We did not test whether the role can make one.

## What is in the index

| Measure | Value |
|---|---|
| Documents, all | 14,038,488 |
| `tip` = `AVERE` (asset declaration) | 6,671,685 |
| `tip` = `INTERESE` | 6,603,876 |
| `AVERE` with extracted income | 1,435,993 |
| `AVERE` with an income line of category `salarii` | 1,402,282 |
| Extraction model | `google/gemma-4-26b-a4b-it` (one model for all) |

`AVERE` with extracted income, by filing year (`an`):

| `an` (filed) | Income year | Declarations | With income |
|---|---|---|---|
| 2021 | 2020 | 332,244 | 3,130 |
| 2022 | 2021 | 764,350 | 460,879 |
| 2023 | 2022 | 844,576 | 469,701 |
| 2024 | 2023 | 447,820 | 445,564 |

Before 2021, no declaration has extracted income. `an` is the year of `data_completare_iso`, and the declaration reports the income of the previous year.

`tip_institutie` of the declarations with income: `ALTUL` 627,461, `LOCAL` 387,312, `NATIONAL` 288,015, `JUDETEAN` 133,205.

### Fields that we use

| Field | Meaning |
|---|---|
| `aid` | Unique document id. Use it as the sort tiebreak. `declaration_id` is not unique. |
| `an`, `data_completare_iso` | Filing year and date. |
| `functie` | Job title, normalised (for example „Inspector superior”, „Șef serviciu”, „Politist local”). |
| `institutie`, `institutie_norm`, `institutie_id` | Institution. `institutie_id` is `NAME|COUNTY`. |
| `judet_norm`, `localitate`, `tip_institutie` | Place and level. |
| `extraction.venituri[]` | Income lines: `categorie`, `sursa`, `serviciul_prestat`, `beneficiar_tip`, `venit_anual.suma`, `venit_anual.valuta`. |

Values of `extraction.venituri.categorie`: `salarii`, `alte_surse`, `pensii`, `activitati_independente`, `activitati_agricole`, `cedarea_folosintei`, `investitii`, `premii_jocuri_noroc`, and a few typos. Values of `beneficiar_tip`: `titular`, `sot_sotie`, `copil`, and a few spelling variants (`Titular`, `soţ/soţie`). Currency: `RON` 1,388,203 lines, missing 145,362, then `EUR`, `USD`, `GBP`.

Do not dump `nume`, `nume_norm` or `beneficiar_nume`. We need no names.

## The rule for one pay figure

For each declaration, keep the income lines where:

1. `categorie` is `salarii`;
2. `beneficiar_tip` is `titular` (lower-case it first);
3. `valuta` is `RON` or missing, and `suma` is more than 0;
4. the normalised `sursa` contains the normalised `institutie_norm`, or the reverse.

Keep the declaration only when exactly one line passes. That line is the yearly pay of `functie` at `institutie_id` in the year `an - 1`.

Measured on the first 2,000 documents (sorted by `declaration_id`): 2,779 holder salary lines; 563 documents with more than one holder salary line; 1,590 documents (80 %) with a line whose source matches the institution.

## Sanity check

Filed in 2024 (pay of 2023), all levels, the rule above, the first 3,000 documents for each title:

| `functie` | n | p25 / year | Median / year | p75 / year | Median / month |
|---|---|---|---|---|---|
| Inspector superior | 2,530 | 57,906 | 72,873 | 83,871 | 6,073 |
| Consilier superior | 2,374 | 59,178 | 75,505 | 84,670 | 6,292 |
| Șef serviciu | 2,489 | 76,514 | 99,879 | 116,786 | 8,323 |
| Grefier | 1,335 | 68,329 | 85,205 | 99,759 | 7,100 |
| Profesor | 506 | 49,472 | 65,339 | 76,030 | 5,445 |
| Asistent medical | 263 | 53,248 | 64,441 | 72,892 | 5,370 |
| Politist local | 2,074 | 42,425 | 54,684 | 65,359 | 4,557 |
| Referent | 2,521 | 36,206 | 43,276 | 50,839 | 3,606 |

The order of the titles is plausible. The level is between the net and the gross pay of the grid. We did not check this against the grid.

## Limits

- **Net or gross is not known.** The ANI form asks for the income received. Some declarants write the net amount, some write the gross amount. A per-title median mixes both. Opus assumes most write the net amount; this is not measured.
- **A year is not 12 months of one post.** The figure includes bonuses, holiday pay and vouchers. A person who changed post or started in the year has a partial figure. Use medians, and drop values below a floor (for example the minimum wage × 6).
- **The newest pay is 2023.** The 2024 and 2025 raises are not in the data. Declarations after 2025 are not published (CCR decision).
- **Who declares.** Civil servants, managers, elected officials, police, magistrates. Few contract staff (îngrijitor, brancardier), so the data does not fill the gaps of SALARY-UNMATCHED for those jobs.
- **LLM extraction.** One model; 910 errors and 1,605 duplicates are marked in `extraction_meta.status`. Filter on `extraction_meta.status` = `ok`.

## Queries

All bodies go into `params.body` of the internal search (see Access).

Counts by filing year:

```json
{"size": 0, "query": {"bool": {"filter": [
   {"term": {"tip.keyword": "AVERE"}},
   {"exists": {"field": "extraction.venituri.venit_anual.suma"}}]}},
 "aggs": {"an": {"terms": {"field": "an", "size": 30}}}}
```

Institutions and titles (lists for the match to posturi):

```json
{"size": 0, "query": {"bool": {"filter": [
   {"term": {"tip.keyword": "AVERE"}},
   {"term": {"extraction.venituri.categorie.keyword": "salarii"}}]}},
 "aggs": {
   "inst": {"composite": {"size": 5000, "sources": [
     {"id": {"terms": {"field": "institutie_id.keyword"}}},
     {"tip": {"terms": {"field": "tip_institutie.keyword"}}}]}},
   "fn": {"terms": {"field": "functie.keyword", "size": 2000}}}}
```

Page the `composite` aggregation with `after`.

The dump, one page (2,000 documents in about 0.7 s, about 1 KB each). Repeat with `search_after` set to the `sort` of the last hit:

```json
{"size": 2000,
 "query": {"bool": {"filter": [
   {"term": {"tip.keyword": "AVERE"}},
   {"term": {"extraction_meta.status.keyword": "ok"}},
   {"term": {"extraction.venituri.categorie.keyword": "salarii"}}]}},
 "_source": ["aid", "an", "data_completare_iso", "functie", "institutie",
   "institutie_id", "institutie_norm", "judet_norm", "localitate",
   "tip_institutie", "source", "extraction.venituri.categorie",
   "extraction.venituri.sursa", "extraction.venituri.serviciul_prestat",
   "extraction.venituri.beneficiar_tip", "extraction.venituri.venit_anual"],
 "sort": [{"aid": "asc"}],
 "search_after": [<aid of the last hit>]}
```

A full dump is about 720 pages. The internal search returns `hits.total` as a number, not as `{"value": n}`.

## The dump scripts

The dump keeps **every** income line where `categorie` = `salarii` and `beneficiar_tip` = `titular`, one CSV row per line. It does not apply rule 4 (the institution match). A later step chooses the line, so we can change that rule without a new dump.

Why: the first run applied rule 4 in the browser. On the first 22,000 declarations, only 48 % passed, against 80 % in the first sample. The `sursa` text often has an address („PRIMARIA MUNICIPIULUI CRAIOVA, STR. TARGULUI, NR. 26” for `MUNICIPIUL CRAIOVA`). Police and army staff name the parent body („Ministerul Apararii Nationale” for `UNITATEA MILITARA 02159`). A substring match drops these good lines.

Columns: `aid, an, data_completare_iso, source, functie, institutie_id, institutie, institutie_norm, judet_norm, localitate, tip_institutie, n_lines, sursa, serviciul_prestat, suma, valuta`. `n_lines` is the number of holder salary lines in the declaration.

- `ops/integritate-dump.py` — for later runs. It needs ONE of: `KIBANA_API_KEY`; `KIBANA_USER` and `KIBANA_PASSWORD`; or `KIBANA_COOKIE` (the `Cookie` header of a signed-in tab). The owner sets the variable. The output goes to `data/integritate/salarii-raw.csv.gz` (out of git).
- `ops/integritate-dump.js` — the same dump in the DevTools console of a signed-in tab. It ends with a `.csv.gz` download. Use it when no credential for the script is at hand.

The sort is (`aid`, `source.keyword`). `aid` is unique only within one portal: `portal_vechi` and `portal_nou` reuse the same numbers for different declarations. So the key of a declaration is (`source`, `aid`).

## The first dump (2026-11-20)

Run with `ops/integritate-dump.js` in the owner's Chrome: 702 pages in 7.5 minutes (11:28–11:36 UTC). File: `data/integritate/integritate-salarii-raw-2026-11-20.csv.gz`, 92 MB (535 MB of CSV).

| Measure | Value |
|---|---|
| Declarations that match the query | 1,402,281 |
| Declarations read | 1,402,266 (15 skipped, see below) |
| Declarations with no holder salary line | 17,987 |
| Declarations in the file | 1,384,279 |
| Rows (holder salary lines) | 1,822,108 |
| Declarations with exactly 1 line | 1,096,231 (79.2 %) |
| … 2 lines / 3 lines / 4 or more | 199,941 (14.4 %) / 55,083 (4.0 %) / 33,024 |
| 1 line, and currency `RON`, `"null"` or empty | 1,093,892 |
| … by filing year 2022 / 2023 / 2024 | 366,055 / 350,725 / 325,981 |
| … with no `an` | 48,758 |

Currency of the rows: `RON` 1,671,268, the text `"null"` 140,419 (the LLM wrote the word), `EUR` 6,368, `USD` 2,166, `GBP` 360, and 912 rows with `"***** ***"` (redacted in the source).

This run sorted on `aid` only, so 15 declarations at page edges were skipped (0.001 %). The scripts now sort on (`aid`, `source.keyword`).

## The second dump, with a person key (2026-11-20)

The owner chose to add a scrambled person key (`person`). Each run makes a random salt, then `person` = the first 8 bytes of SHA-256(salt | sorted name tokens | `institutie_id`). The salt is never written. So `person` links one person at one institution inside one dump, and cannot be traced back to a name.

File: `data/integritate/integritate-salarii-raw-20261120T1229.csv.gz`, 110 MB, 1,402,281 declarations (none skipped), 1,822,126 rows, 9.7 minutes. Chrome blocks a second automatic download from a site; the owner must allow „multiple downloads” for `kibana.sicap.ai` once. The first dump (`…-2026-11-20.csv.gz`, no `person`) is superseded.

## Analysis (2026-11-20)

Scripts: `ops/integritate-select.py` (one pay line per declaration) and `ops/integritate-panel.py` (panel, net/gross, backtest). Output of the first: `data/integritate/selected.csv.gz`.

### One pay line per declaration

`ops/integritate-select.py` keeps, for each declaration, the **largest line that names the declaring institution**. The match removes the address (after the first comma) and the generic words, compares 6-letter stems, and accepts the parent body for police, army, ISU, prosecutors and customs. A type word in `sursa` that the institution name lacks („Scoala”, „Spital”, „SRL”) means another employer. 10 hand-picked cases pass.

| Result | Declarations | Share |
|---|---|---|
| `inst`: a line names the institution | 1,044,293 | 75.4 % |
| `single`: one line, institution not named (kept, flagged; not used below) | 143,322 | 10.4 % |
| No amount (the extractor found none; currency `"null"`, amount 0) | 148,618 | 10.7 % |
| Several lines, none names the institution | 48,061 | 3.5 % |

The largest line, not the sum: the other lines from the same employer are often holiday vouchers (1,450 lei) or back pay („diferențe salariale”).

Then one declaration per (`person`, `an`), the latest filing: 814,216 kept, 124,806 repeats dropped. Values under 12,000 lei a year are dropped as part-year.

### Panel: the same person, the same post, one year later

Growth = pay of the year / pay of the year before, for the same `person` with the same `functie`.

| Family | 2021→2022 median | ±5 % | 2022→2023 median | ±5 % | n (2022→2023) |
|---|---|---|---|---|---|
| All | 1.035 | 60 % | 1.124 | 29 % | 189,216 |
| Local administration | 1.033 | 60 % | 1.151 | 24 % | 40,091 |
| Central administration | 1.038 | 68 % | 1.111 | 26 % | 37,698 |
| County administration | 1.028 | 71 % | 1.090 | 41 % | 13,012 |
| MAI / MApN | 1.052 | 53 % | 1.180 | 19 % | 14,328 |
| Local police | 1.066 | 50 % | 1.168 | 20 % | 8,665 |
| Health | 1.000 | 66 % | 1.024 | 62 % | 8,032 |
| Education | 1.027 | 61 % | 1.145 | 18 % | 6,548 |
| Justice staff | 1.051 | 42 % | 1.159 | 20 % | 7,463 |
| Magistrates | 1.083 | 36 % | 1.126 | 24 % | 3,584 |
| Elected | 1.000 | 75 % | 1.193 | 28 % | 6,797 |

The families come from keywords on `functie` and `institutie_norm` (`family()` in the script). They are rough.

Against the chain of acts of S1 (§ 3.3):

- **2022 was frozen.** The data agrees: median 1.035, 60 % within ±5 %, health and elected officials exactly 1.000. The rest is gradations and changes inside a title.
- **2023: +10 % over Dec 2022, but not over the 2022 annex value** (OUG 168/2022). Central (1.111) and county (1.090) administration agree. Health (1.024) agrees with the cap: health pay already stood at the 2022 annex value.
- **Local administration** (1.151) also has the Anexa VIII jump of June 2023 (OUG 45/2023).
- **MAI, education, justice, elected:** the growth is larger than the S1 chain explains. Other acts apply that S1 did not read. Not checked.

So the realised growth follows the law by family. This supports the plan: take the 2023 level from the declarations, and the 2023→2026 change from the acts.

### Net or gross

- Inside a group (filing year + institution + title, n ≥ 5; 535,368 values), the ratio of each value to the group median has **one peak** at 1.0. There is no bump at 0.585 (net/gross) or at 1.71 (gross/net).
- In the panel, 0.2–1.2 % of the pairs sit near 0.585, and 1–5 % near 1.71. The bump near 1.71 is more likely promotions and back pay than a change of convention.

So the people in one group declare the same way. The level does not tell which way. The consilier superior median for 2023 (about 6,300 lei a month) sits between the 2026 grid base (4,905 gross, territorial) and the INS net average of the sector (6,900 net, May 2026). **The method does not need to know**, because a growth factor gives the same result on net and on gross. Only the label of the result needs it. The S2 lists (exact pay at named institutions in 2026) can settle the label.

### Backtest: how far an institution departs from its family

Prediction: group median for 2023 = group median for 2022 × the median growth of its family. Groups = institution + title, n ≥ 5 in both years. The factor comes from the same data, so this measures only how far an institution departs from its family. That is the error that a family-wide legal factor carries forward to 2026.

| Family | Factor | Groups | Median abs. error | p90 abs. error | Within 10 % | Bias |
|---|---|---|---|---|---|---|
| All | — | 7,384 | 6.0 % | 19.2 % | 69 % | +0.8 % |
| Local administration | 1.151 | 1,537 | 5.6 % | 15.1 % | 74 % | +1.6 % |
| Central administration | 1.111 | 1,258 | 4.5 % | 17.1 % | 75 % | +0.2 % |
| County administration | 1.090 | 582 | 4.8 % | 20.0 % | 74 % | +1.2 % |
| MAI / MApN | 1.180 | 679 | 6.1 % | 18.9 % | 68 % | +2.2 % |
| Health | 1.024 | 432 | 5.3 % | 18.8 % | 73 % | +0.7 % |
| Local police | 1.168 | 275 | 6.1 % | 17.2 % | 70 % | +0.7 % |
| Education | 1.145 | 67 | 4.9 % | 26.1 % | 64 % | −0.1 % |
| Justice staff | 1.159 | 320 | 11.4 % | 31.3 % | 43 % | −0.8 % |
| Magistrates | 1.126 | 139 | 11.1 % | 31.0 % | 45 % | +4.1 % |
| Projects (EU) | 1.165 | 154 | 10.2 % | 33.6 % | 49 % | +0.3 % |
| Elected | 1.193 | 95 | 10.7 % | 20.7 % | 27 % | +10.6 % |

For administration, health, police and MAI: a typical error of about 5–6 %, and 1 group in 10 off by more than 15–20 %. Justice, magistrates, projects and elected officials are about twice as noisy.

### What the method needs next

1. **The legal factor 2023→2026 per family.** For Anexa VIII and similar annexes it is ×1.15 from Dec 2023 (OUG 115/2023 and the 2024 raises; 2025 and 2026 frozen). For health and education it is the June 2024 table of OUG 19/2024 or OUG 128/2023 divided by the 2022 annex value, per title. MAI (Anexa VI) has no documented factor yet, so it gets no estimate.
2. **The timing gap.** A yearly figure for 2023 is an average of the year. Local administration rose in June 2023, so its 2023 average is below its Dec 2023 level, and ×1.15 on the average underestimates. Not measured.
3. **The label (net or gross),** from the S2 lists.
4. **When to show a figure:** n ≥ 5 in the group and a family with a documented factor. The band comes from the backtest error of the family.
5. **Leave out** elected officials (indemnities, not posts), and EU project staff (ADR 0008 has its own rule for them).

## The factor from 2023 to 2026 (2026-11-20)

A declared figure is the **average of 2023**. The pay of 2026 is frozen at the Dec 2025 level (Legea 141/2025). So the factor must take a 2023 yearly average to the 2026 level. Two sources give it.

### Source 1: INS, net average pay by sector

FOM106D (CAEN Rev.2, to 2025-08) and FOM106G (CAEN Rev.3, from 2025-01). In the 8 shared months the two series agree to 0.01 %. Lei net per month:

| Section | 2022 | 2023 | 2024 | 2025 (Jan–Aug) | 2026 (Jan–May) | **2026 / 2023** | Jan–May 2023 / 2023 |
|---|---|---|---|---|---|---|---|
| O: public administration and defence | 5,310 | 5,964 | 6,905 | 7,140 | 6,860 | **1.150** | 0.971 |
| P: education | 3,860 | 4,624 | 5,745 | 5,853 | 5,745 | **1.242** | 0.920 |
| Q: health and social care | 4,212 | 4,480 | 5,137 | 5,393 | 5,406 | **1.207** | 0.984 |

The 2026 months are flat (O: 6,816–6,900), so January–May stands for the year. The last column shows the raises inside 2023: January–May 2023 was 2.9 % under the 2023 average in section O, and 8 % in education.

### Source 2: the law (from the grid build)

`build-salary-grid.py` records the factor from the Dec 2023 level to 2026 for each annex:

- Anexa VIII, III, IV, I cap. II, V cap. VI: ×1.15 (OUG 115/2023 +5 %, OUG 53/2024 +10 %).
- Anexa V (magistrates) and V cap. VII: ×1.05 only.
- Health and education: the June 2024 tables of OUG 19/2024 and OUG 128/2023. The grid does not keep the values from before.
- Anexa VI (MAI, army): not in the grid (SALARY-ANEXA-VI). OUG 36/2025 capped the hazardous-conditions bonus of Anexa VI at 300 lei a month from 1 Jul 2025, which is a cut.

### Do the two agree?

For section O, yes:

- The law gives ×1.15 from Dec 2023.
- The 2023 average sits about 3 % under the Dec 2023 level. INS shows it (0.971 for January–May), and so does the panel (the June 2023 step of Anexa VIII).
- So the law alone predicts about ×1.18 on the 2023 average. INS measures ×1.150.
- The difference (about 3 %) is the size of the fall from 2025 to 2026 (−4 %). That fits the 2025 cuts (for example OUG 36/2025). Opus assumes this cause; it is not measured.

### The INS factor, tested one year back

The backtest above, rerun with the INS sector factor for 2022→2023 (O 1.123, P 1.198, Q 1.064) in place of a factor fitted on the panel. This is out of sample (`ops/integritate-panel.py --backtest`):

| Family | INS factor | Groups | Median abs. error | p90 abs. error | Within 10 % | Bias |
|---|---|---|---|---|---|---|
| All | — | 5,636 | 6.1 % | 19.4 % | 69 % | +1.0 % |
| Local administration | 1.123 | 1,537 | 5.2 % | 15.0 % | 76 % | −0.8 % |
| Central administration | 1.123 | 1,258 | 4.8 % | 16.8 % | 74 % | +1.2 % |
| MAI / MApN | 1.123 | 679 | 6.1 % | 17.5 % | 70 % | −2.7 % |
| County administration | 1.123 | 582 | 6.7 % | 19.7 % | 66 % | +4.2 % |
| Local police | 1.123 | 275 | 6.0 % | 17.3 % | 73 % | −3.2 % |
| Health | 1.064 | 432 | 6.9 % | 20.7 % | 68 % | +4.6 % |
| Education | 1.198 | 67 | 8.7 % | 24.7 % | 61 % | +4.5 % |
| Universities | 1.198 | 252 | 9.8 % | 26.9 % | 52 % | +8.9 % |
| Justice staff | 1.123 | 320 | 11.8 % | 30.2 % | 45 % | −3.9 % |
| Magistrates | 1.123 | 139 | 11.0 % | 30.7 % | 46 % | +3.9 % |

For section O, the INS factor is as good as a factor fitted on the declarations. For health and education it overshoots by about 4.5 %. Those INS sections include private units, and the people who declare are mostly managers (directors, inspectors, heads of section), not teachers or nurses.

### Factor by family (accepted by the owner, 2026-11-20)

| Family | Factor 2023 → 2026 | Source | Check | Status |
|---|---|---|---|---|
| Local, county, central administration | **1.150** | INS section O | law ×1.15 + 3 % gap − 2025 cuts | ready |
| Local police | **1.150** | INS section O | law: Anexa VIII | ready |
| MAI / MApN | **1.150** | INS section O | law: Anexa VI unknown; bonus cut in 2025 | ready, band from the backtest |
| Justice staff (grefieri, etc.) | 1.150 | INS section O | law: Anexa V cap. VI ×1.15 | backtest error 11.8 %: wide band, or no figure |
| Magistrates | — | — | law ×1.05 against INS ×1.15 | no figure: the sources disagree, and judges are not hired through postings |
| Health | (1.207) | INS section Q | overshoots +4.6 % | not ready: needs the legal ratio per title |
| Education, universities | (1.242) | INS section P | overshoots +4.5 % / +8.9 % | not ready: needs the legal ratio per title |
| Elected officials, EU projects | — | — | — | out of scope |

## Net or gross: checked against the published lists (2026-11-20)

S1 collected the Art. 33 lists of March 2026 (gross base pay per function) for some section O employers (`tmp/261118/s1/lists/`, `gap_table.md`). For the same employer and post, compare: (declared median for 2023 ÷ 12) × 1.150 against the list value. If the declarations are gross, the ratio is about 1.0 or more (bonuses, vouchers). If they are net, it is about 0.585 or a little more.

Posts: F1 = consilier/inspector superior, F2 = principal, F3 = referent superior. Filing year 2024. Groups with n ≥ 3.

| Employer | Post | n | Declared 2023, per month | × 1.150 | List gr. 0 | List gr. 5 | Ratio |
|---|---|---|---|---|---|---|---|
| Municipiul Târgoviște | F1 | 5 | 6,118 | 7,036 | 9,620 | — | 0.73 |
| CJ Timiș | F1 | 144 | 7,470 | 8,590 | — | 13,366 | 0.64 |
| CJ Timiș | F2 | 9 | 4,870 | 5,600 | — | 9,479 | 0.59 |
| CJ Bacău | F1 | 85 | 6,155 | 7,078 | 11,983 | 14,921 | 0.53 |
| CJ Bacău | F2 | 4 | 5,544 | 6,376 | 10,994 | — | 0.58 |
| CJ Bacău | F3 | 5 | 4,406 | 5,067 | 7,498 | — | 0.68 |

The ratio is mean(gr. 0, gr. 5) of the list where both exist. Median 0.62, range 0.53–0.73, 6 groups. Bușteni, Vlădeni (Dâmbovița) and Tulca have fewer than 3 declarants per post.

**Result: the declared amounts are net.** A gross figure 36 % under the base pay of the list (CJ Timiș, n = 144, against gradation 5) is not plausible. Back to gross (÷ 0.585): CJ Timiș F1 ≈ 14,700 against a base of 13,366 plus bonuses; CJ Bacău F1 ≈ 12,100 against 11,983–14,921. Both fit.

Limits: 6 groups at 3 employers, all local or county administration. The within-group test above shows that one group declares one way. It does not prove that every group in the country declares net. The 2026 food allowance (347 lei a month for net pay up to 6,000) is in the 2026 pay but not in the 2023 base of the factor. INS net includes it.

## Coverage spikes (2026-11-20, after the build)

The build (brief `docs/briefs/2026-11-20-salary-declared-pay.md`) matched 5 of 9,750 postings on the 9 Sep copy, although 1,093 postings have an employer with a group. Two ideas to raise the coverage were tested. Script: `tmp/261120/coverage/spike_a.py` (spike code, not in git).

### A. Wider groups (peer groups)

Peer key: for local administration, the unit type (COMUNA, ORAS, MUNICIPIUL, JUDET, SECTOR) + county + title; for any other employer, the kind of institution (the name without county and place tokens, for example „INSPECTORATUL TERITORIAL DE MUNCA”) + title, national. A peer group needs n ≥ 5 at ≥ 3 employers.

Accuracy, leave-one-out: for each employer group (n ≥ 5), the median of its peers at the other employers against its own median, same year:

| Peer key | Family | Groups | Median abs. error | p90 abs. error | Within 10 % |
|---|---|---|---|---|---|
| Kind of institution (national) | all | 2,719 | 4.4 % | 21.6 % | 73 % |
| | central administration | 718 | 2.0 % | 21.1 % | 77 % |
| | county administration | 478 | 2.5 % | 15.2 % | 84 % |
| | MAI / MApN | 1,469 | 5.7 % | 22.4 % | 69 % |
| Unit type + county (councils) | all | 1,102 | 11.3 % | 27.5 % | 44 % |
| | local administration | 963 | 11.0 % | 26.5 % | 45 % |
| | local police | 139 | 14.5 % | 39.1 % | 34 % |

- The kind of institution works: deconcentrated services pay on national scales.
- Councils of the same type in the same county do not work: each council sets its own pay (rule 3), and an error of 11 % is about twice the error of the method.

Coverage on the 9 Sep copy: 2,466 peer groups would add **77 postings** (70 council, 7 other). 2,364 postings (24 %) sit at a known unit, but their title matches no declared title.

Why: council postings and council declarants hold different jobs.

| Council postings (1,919), top titles | | Council declarants (47,139), top titles | |
|---|---|---|---|
| asistent medical comunitar | 181 | inspector | 6,384 |
| consilier școlar | 130 | consilier | 6,236 |
| mediator școlar | 85 | consilier superior | 5,201 |
| asistent social | 67 | inspector superior | 4,509 |
| șofer microbuz școlar | 57 | referent superior | 2,221 |
| șofer | 56 | referent | 2,141 |
| muncitor calificat | 40 | șef serviciu | 2,080 |

Among the 47,139 council declarants: 85 workers, 53 drivers, 7 carers, 70 social workers. Contract staff seldom file an asset declaration. No title rule can match postings to declarants who do not exist.

### B. The council's pay level, carried to contract staff

Idea: the declarations show how far a council pays its civil servants above the law. If the council pays contract staff the same way, that premium narrows the range of rule 3 for every council posting. Data with both groups at one council: the S1 lists (`tmp/261118/s1/gap_table.md`), 5 councils with both groups:

| Council | Civil servants ÷ law (median) | Workers ÷ law (median) | (workers − 1) ÷ (civil servants − 1) | Contract staff with a degree ÷ law |
|---|---|---|---|---|
| CJ Bacău | 2.31 | 1.48 | 0.37 | 1.67 |
| Târgoviște | 1.81 | 1.20 | 0.25 | 1.79 |
| Bușteni | 1.45 | 1.00 | 0.00 | 1.17 |
| Vlădeni (DB) | 1.24 | 1.27 | 1.13 | — |
| Tulca | 1.05 | 1.14 | 2.80 | 1.04 |

The share of the civil-servant premium that reaches workers goes from 0 to 2.8. The rank order of the councils is not the same for the two groups. The minimum wage compresses worker pay. Carrying the civil-servant premium to workers would overstate worker pay by 25–55 % at the high-paying councils. With 5 councils we cannot fit a rule, and the data shows no simple rule.

### Result

- Rule 9 stays as built: one employer and one title. It covers civil-servant and management posts, which are few among the postings.
- A national peer group by kind of institution is accurate (4.4 %), but adds 7 postings. Not worth a change to rule 9 now.
- Council peer groups and the council premium for contract staff: dead ends, for the reasons above.
- The declarations are strong for what they cover: 6,201 groups at 2,108 institutions, real net pay by employer and title. A page that shows them per institution would use them fully, and does not depend on the postings. That is a product decision.

## Open

- How posturi uses the figure (ADR 0008 rule 3): a third baseline („salarii declarate”), or a check of the grid estimate. This needs a decision.

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

## Open

- The rule that chooses the pay line of the declared post (rule 4): a better institution match (drop the address, „PRIMARIA X” = „X”, ministry for its units), and what to do with several lines.
- How posturi uses the figure (ADR 0008 rule 3): a third baseline („salarii declarate”), or a check of the grid estimate. This needs a decision.

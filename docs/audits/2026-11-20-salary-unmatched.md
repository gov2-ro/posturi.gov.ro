# Audit — postings with no salary figure (SALARY-UNMATCHED)

Date: 2026-11-20. Brief: `docs/briefs/2026-11-20-salary-unmatched.md`. Step 2 (report only; no fix is built).

## Data sources

| Label | Data | Run |
|---|---|---|
| **VPS** | live data, latest `updated_at` 2026-11-19 17:04 UTC | `ops/salary-unmatched.py` and `estimate-salaries.py --dry-run`, commit `1e975fe` |
| **Local** | copy of **9 Sep 2026** | same script, same commit |

Raw VPS JSON stays on the VPS (`/tmp/salary-unmatched-vps.json`). The VPS numbers below come from `tmp/261120/unmatched/vps-summary.md`.

## 1. Result

| | VPS (live, 19 Nov) | Local (9 Sep copy) |
|---|---|---|
| Postings | 16,663 | 9,756 |
| With an occupation | 16,640 | 9,750 |
| **No figure from either grid** | **2,625 (15.8%)** | 1,382 (14.2%) |
| Flagged outside Legea 153/2017 (heuristic) | 86 | 64 |

The brief's 1,388 is the local 1,382 plus the 6 postings without an occupation.

### Cause table

| Cause | VPS | Local | Top occupations (VPS count) |
|---|---|---|---|
| `fara-selector` (no `anexa`, no `functie`) | 2,396 | 1,254 | Medic specialist 123, Îngrijitor 105, Infirmier 90, Șofer 70, Brancardier 68, Consilier școlar 58, Medic 50, Tehnician asistență socială 44, Referent 41, Fochist 33 |
| `fara-rand-functie` (`anexa` set, `functie` and `cod` empty) | 229 | 128 | Electrician 34, Medic specialist 29, Medic rezident 10, Registrator medical 8, Medic 8, Electromecanic 7, Șef serviciu 7, Asistent medical 6, Îngrijitor 6, Șofer 5 |
| `filtru-gol` | 0 | 0 | — |
| `in-afara-grilei` (as primary cause) | 0 | 0 | — see (d) |
| `altul` | 0 | 0 | — |

`filtru-gol` is 0 by construction: `Grid.select()` falls back to the wider row set when a narrowing step matches nothing. `in-afara-grilei` is 0 as a primary cause because the first two causes take every posting; it is reported as a flag (86 on the VPS, 84 of them inside `fara-selector`).

**Every one of the 2,625 postings has an empty `functie`.** No posting fails because of a grid filter. The gap is in the occupation dictionary, not in the grid.

### Examples (VPS)

- fara-selector: #21001 Logoped (DAS municipiu); #21035 PAZNIC-1 POST, INGRIJITOR -0,5 POST (Școala Ploiești); #21005 mediator sanitar (Primăria Terebești); #21014 KINETOTERAPEUT, INSTRUCTOR…, INFIRMIER… (DGASPC); #18961 Muncitori Hidrometrici (ABA Siret). All: `anexa` null, `functie_grila` null.
- fara-rand-functie: #19402 Director secție fotbal (Comuna Corbu); #9103 Responsabil achiziții (Liceul Ineu); #5609 electrician, zugrav, telefonist, zidar (Spital Târgu); #12431 Director Sportiv (USAMV); #8813 ECONOMIST (UM 01101). All: Anexa VIII, `functie_grila` null, `cod` null.
- Local examples with the full selector: #14109 Medic specialist (Spitalul Blaj), #14113 brancardieri (Spitalul Câmpina), #6132 lucrator social (DGASPC Timiș), #5737 electrician (ABA Buzău-Ialomița).

## 2. Why is the selector empty? The mechanism

Measured on the local copy; read in `normalize-titles.py`. No LLM was run.

**The occupation pass did not skip these titles, and it did not fail.** It ran, and the model answered "none" (`match_confidence = none`, null `cod`, null `functie_grila`). Local: 905 occupations that postings use have confidence `none`. The pass then never retries them. `main()` skips every `title_norm` already in `jobs_occupation`, and a "none" answer is a row like any other. Only `--force` re-maps, and it re-maps all titles with an LLM call each.

Two causes produce the "none":

1. **The dictionary key is the whole parsed title, not the occupation.** `title_norm` is `parse_title().base_norm`. It strips the grade and the post count, not the specialty or the free text. "Medic specialist" has **54 separate occupation rows** for 55 postings, one per title string ("1 post medic specialist în specialitatea radiologie…", "…spec.chirugie plastica…"). For these long strings `Grid.candidates()` returns nothing above its 0.35 similarity threshold (checked: two such titles give an empty shortlist, one gives "tehnician de radiologie"). With an empty shortlist the model has nothing to copy and answers none. The clean title "Medic specialist" gets the exact row at score 1.0. In all, 1,088 occupation rows cover the 1,382 local postings with no figure.
2. **The shortlist comes from the draft grid only** (`--grid-version`, default draft). Occupations that only the law grid has get no candidate: Brancardier (draft 0 rows, law 4), Logoped (0 / 12), Kinetoterapeut (0 / 12), Psiholog (0 / 8). Brancardier alone is 9 occupation rows and 38 local postings, all "none".

So one fix to the **estimate side** covers future postings: when the selector has no `functie`, look the **canonical occupation name** up in the grids. The canonical name is already clean (the pass sets it, then `trade_label()` refines it). See § 4.

## 3. Proposed fixes and gains

Gains are **estimates** for the VPS, scaled from a local measurement. Method: for each local posting with no figure, set `functie` = the occupation `canonical` (plus the alias in the table), keep the rest of the selector, and run the real `estimate()` of both grids. Local gain 731 of 1,382 (53%). VPS estimate: 53% × 2,625 = **about 1,390**. The VPS must run the same script to confirm. Range: the local share may differ by 5 points, so read 1,250–1,500.

### (a) Fill the selector from the canonical name (no LLM)

Rule: look up `norm(canonical)` in the law grid, then the draft grid (`by_name` already indexes the synonyms). Write `functie_grila` and `anexa` of the matching row. Local gains by occupation (VPS scale is about 1.9×):

| Canonical | Local gain | Grid rows (draft / law) |
|---|---|---|
| Medic specialist | 64 | 1 / 4 (Anexa II) |
| Îngrijitor | 58 | 10 / 2 |
| Brancardier | 38 | 0 / 4 (law only) |
| Infirmier (alias „Infirmieră”) | 38 | 1 / 14 |
| Șofer | 34 | 15 / 7 |
| Electrician (alias „Muncitor calificat”) | 29 | 20 / 8 (see note) |
| Medic | 28 | 1 / 7 |
| Referent | 21 | 119 / 106 |
| Economist | 20 | 4 / 4 |
| Psiholog | 18 | 0 / 8 (law only) |
| Logoped | 16 | 0 / 12 (law only) |
| Muncitor calificat | 16 | 20 / 8 |
| Asistent medical | 15 | 6 / 58 |
| Expert | 14 | 56 / 46 |
| Muzeograf | 14 | found |

Total 731 locally, including a long tail. Notes:

- Every row named here was checked in **both** grids by `by_name` count, except where the table says "law only". A "law only" occupation gets a figure from the law grid and none from the draft (it then shows `baza = lege_in_vigoare` only).
- **Alias risk (owner decision).** Electrician, Fochist and Electromecanic have no row of their own. The alias „Muncitor calificat” is the grid function the dictionary already uses for „Muncitor calificat (electrician)” (cod `82.60128018.01.1`, Anexa VIII). Opus should confirm that one alias per trade is allowed. Without the alias the gain falls by about 70 locally (29 + fochist + electromecanic).
- **Medic specialist** with a speciality still maps to the one Anexa II row; the grid has no per-speciality row.
- The match is by exact name only. Nothing is inferred from similarity.

### (b) Multi-role titles

"electrician, zugrav, telefonist, zidar", "PAZNIC-1 POST, INGRIJITOR -0,5 POST", "KINETOTERAPEUT, INSTRUCTOR…, INFIRMIER…". Locally 563 of the 1,382 titles hold a comma or „și”, but many are one role with a specialty list ("medic specialist, studii S, …"), so 563 is an upper bound, not a gain. I did **not** measure the gain. A safe rule: take the **first** role only when all roles map to the same annex, and tell the user it is the first role (ADR 0008 asks for provenance). When roles span annexes, leave no figure. This is the DATA-04 topic (per-role occupation and salary); propose to defer it to DATA-04 and not mix it into this work. Estimated gain: unmeasured.

### (c) Titles with no grid row

Checked in both grids (`by_name` and substring):

| Title | Row exists? | Proposal |
|---|---|---|
| Medic rezident (VPS 10 + part of others) | **Yes**, „Medic rezident anul I … VI” in both grids. The year is not in the title. | Map to the name; `select()` already matches the prefix and returns the range over all years. |
| Consilier școlar (local 35) | No. Only „Inspector școlar”, a different job. | Leave without figure. Ask the owner. |
| Manager proiect (29) | No. „Manager”, „Manager public” exist, but a project manager is not a public manager. | Leave. |
| Tehnician asistență socială (27), Lucrător social (6), Asistent medical comunitar (7) | No | Leave. |
| Femeie de serviciu (10), Îngrijitor copii (6) | No row under that name. „Îngrijitor” exists. | Owner decision: same pay as Îngrijitor? |
| Director secție fotbal, Director sportiv, Responsabil achiziții, Maseur, Instalator, Sudor, Tractorist, Fochist | No row | Leave. |
| Antrenor, Instructor sportiv | Yes (law 5 and 3 rows) | Covered by (a) when the canonical name matches. |

The residual after (a): local 651 postings (1,382 − 731); VPS estimate about 1,235. These stay with no figure. This is the honest state: "no row in the law".

### (d) The 86 flagged outside the law

The flag is a heuristic: employer-name words (BNR, ASF, SA, SRL, companie, regie…) and title words (proiect, PNRR, FSE). 84 of the 86 are inside `fara-selector`. The regex is rough (`sa`, `s a` can match inside a name), so 86 is an upper bound. **Proposed gain: 0.** These postings are not under Legea 153/2017. Do not map them. Keep them out of the grid and show „fără salariu estimat”. A later step can show a better reason on the page. If the owner wants a count that is certain, read the employer type from the posting and not from a regex.

### Summary of gains (VPS, estimates)

| Fix | Gain | Basis |
|---|---|---|
| (a) fill from the canonical name | about 1,390 (range 1,250–1,500) | measured local 731/1,382, scaled |
| (b) first role of a multi-role title | unmeasured | defer to DATA-04 |
| (c) no row | 0 | no grid row |
| (d) outside the law | 0 | not under the law |
| Left after (a) | about 1,235 of 2,625 | |

No posting that has a figure today changes, because the rule runs only when `functie` is empty.

## 4. How the fix runs on the VPS

**Recommendation: a flag of `normalize-titles.py`, `--fill-selectors`, with no LLM, then `estimate-salaries.py`.**

- The flag selects occupations with an empty `functie_grila`, applies the name rule of (a), and updates `grid_selector` (`functie_grila`, `anexa`, `cod`) and `provider` = `rule-name-lookup`. It is idempotent: a second run changes nothing. It has `--dry-run`, like the other flags (`--relabel-trades`, `--merge-spellings`), which is the pattern in that file.
- Why in the dictionary, not as a fallback inside `estimate-salaries.py`: the mapping then lives in one visible place, with a `provider` mark. The occupation dictionary feeds other things (filters, display); a hidden fallback in the estimator would leave them with an empty selector. A fallback in the estimator is simpler (about 5 lines, no write) and would also cover future postings at once. The cost: the dictionary stays wrong and the rule is invisible.
- Future postings: a new title still goes through the LLM pass, and may still get "none". The same flag, run after the pass, repairs it. Add it to the pipeline step that runs the occupation pass. **Finding:** `ops/run-pipeline.sh` does not contain `normalize-titles` or `estimate-salaries` (grep is empty), so these two steps are run by hand or elsewhere. I did not find where. Ask the owner before the flag is wired in.
- Root-cause improvements (separate, optional): offer the law grid shortlist in the prompt (mechanism 2); key the dictionary on a cleaner title (mechanism 1). Both change the LLM pass and cost money; they are not needed for the gain above.
- Tests: `webapp/tests/test_salary_grid.py`, one per rule (exact name, alias, law-only name, no match leaves the selector alone, idempotence).
- Measurement on the VPS, as the brief says: `estimate-salaries.py --dry-run` before and after; no posting may lose its figure.

## 5. Assumptions

- The local gain share (53%) holds on the VPS. The VPS run of the gain measurement is not done; I do not have VPS access.
- `Grid.by_name` indexes the synonyms of a row, so a name found there means a real row. I did not read each row's annex for every occupation in the table.
- The alias for trades (Electrician → Muncitor calificat) follows what the dictionary already does for „Muncitor calificat (electrician)”. The owner has not confirmed it.
- The mechanism is measured on the local copy. The VPS dictionary may hold other "none" causes (the VPS has 1.9× the postings).

## 6. Stop and ask

- Fix (a) with the alias rule: does it change a grid file, ADR 0008, or the precedence of figures? No. Does it change the figure of a posting that has one? No.
- `altul` is 0, well under 200.
- Open decisions for Opus and the owner: the trade alias, „Femeie de serviciu” as „Îngrijitor”, the first-role rule for (b), and where to run the flag in the pipeline.

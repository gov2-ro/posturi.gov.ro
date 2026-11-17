# Audit — trade in the occupation, one name per occupation, domain from COR

Date: 2026-11-17. Brief: `docs/briefs/2026-11-17-pipeline-domain-trade.md`. Author: Sonnet. Review: Opus.

## Setup

- Local database: PostgreSQL 14 on `localhost:5432`, database `posturi_dev` (`DATABASE_URL` default of the scripts). Not production. No SSH.
- The corpus is the September snapshot: 9,756 postings, 3,767 occupation rows. It has 46 postings with `expires_at >= 2026-10-17`. For "active" the report uses `data/_dev/posturi-new.sqlite` (the 17 Oct export, 2,461 active postings) and says so each time.
- The group of a posting (`occ_group`) is derived as the export does: `isco_group`, else the majority code of the label, else `classify.occupation_fallback()`.
- Scratch scripts and raw output: `/private/tmp/claude-501/-Users-pax-devbox-gov2-posturi-gov-ro/49a71b01-0413-41ca-8b4f-812d90305b55/scratchpad/brief2/`.
- PHP suites and Playwright were not run (another agent works in `webapp-php/`). Opus runs them.

## Part A1 — the trade

### Cause (measured)

There are two causes, not one.

1. **The dash.** `parse_title()` drops the text after " – " unless it is a grade. "Muncitor calificat IV – fochist" gave the base "Muncitor calificat". With the final rule this changes the key of 60 of 9,756 postings (25 distinct bases; the first, looser rule gave 68 and 33).
2. **The model.** The brackets and the plain suffix already stayed in the base: "Muncitor calificat (electrician)" has the base "Muncitor calificat electrician". The model still answered "Muncitor calificat" for most of them. 148 of the dictionary rows that start with "muncitor calificat " had a generic canonical. So a change of `parse_title()` alone does not fix the label.

The sample of 25 real titles (seed 1; the full output is in the scratchpad) shows both: `'Muncitor calificat – agent de pază și ordine'` → base `'Muncitor calificat'` (dash); `'Muncitor calificat (instalator) (1)'` → base `'Muncitor calificat instalator 1'` (kept, but the cached label was generic).

### Change

- `ocupatii.py`: `parse_title()` keeps the first tail after a dash when the head is generic (`GENERIC_HEADS`: muncitor calificat, muncitor, inginer, tehnician, plus a grade) and the tail names a trade. A tail names a trade when its words start the name of a COR occupation (`Cor.candidates()`, not a hand list). A department never matches: no COR name starts with "serviciul", "compartiment", "biroul". The new function `trade_in()` does the check; the new `trade_label()` gives the label.
- `normalize-titles.py`: `write_occupation()` replaces a generic canonical with the trade (`trade_label()`), and the new option `--relabel-trades` does the same for the rows that exist, with no LLM call (`--dry-run` works).
- "Muncitor calificat" and "Muncitor" give the trade alone ("Electrician"). "Inginer" and "Tehnician" keep the head ("Inginer construcții"), because COR writes the head in the name.
- Titles with more than one post ("fochist, muncitor calificat întreținere, șofer"): the first is kept. Of the 60 titles changed by the dash, 11 name more than one post. In the whole corpus 53 titles have a generic head, a comma and another post (a regular expression, not exact). The label rule keeps the first trade in the titles without a dash too.

### Commands and results

| Command | Result |
|---|---|
| `python -m doctest ocupatii.py` | passes |
| `python normalize-titles.py --dry-run` | 12 titles to map, covering 13 postings |
| `python normalize-titles.py --workers 4 --no-run-log` | 12/12 mapped, USD 0.0026, DeepSeek `deepseek-v4-flash`, 12 calls |
| `python normalize-titles.py --relabel-trades --dry-run`, then without `--dry-run` | 45 rows, then 3 more rows after a fix of "IV-I": 48 rows relabelled, no LLM |
| `cd webapp && ../.venv/bin/python -m pytest -q` | 1481 passed, 13 skipped, 1 failed. The failure is `test_salary_facet_sql.py::...[pages/list.php]` and reads the file of the other agent. |

The cost limit (USD 2 or 2,000 titles) was not reached: 12 titles, USD 0.0026. The 12 titles are the keys that did not exist in the dictionary. `--force` was not needed.

### Before and after

All postings (local PostgreSQL, 9,756):

| Measure | Before | After |
|---|---|---|
| "Muncitor calificat" + "Muncitor" in COR 7 | 622 / 857 = 72.6 % | 474 / 801 = 59.2 % |
| "Inginer" in COR 2 | 73 / 4131 = 1.8 % | 73 / 4131 = 1.8 % |
| "Tehnician" in COR 3 | 16 / 1191 = 1.3 % | 16 / 1192 = 1.3 % |
| Distinct occupations | 821 | 836 |

(The COR 7 denominator also moves: some labels change their derived group when the label changes.)

Active postings, simulated on the 17 Oct export (2,461 rows; each row relabelled with the Postgres dictionary and `trade_label()`; the export itself is not changed):

| Measure | Before | After |
|---|---|---|
| "Muncitor calificat" + "Muncitor" in COR 7 | 128 / 199 = 64.3 % | 87 / 199 = 43.7 % |
| "Inginer" in COR 2 | 35 / 1162 | 32 / 1162 |
| "Tehnician" in COR 3 | 3 / 267 | 3 / 267 |
| Distinct occupations | 343 | 353 |

Local "active" (`expires_at >= 2026-10-17`, 46 rows) is too small to use: COR 7 3 → 1 of 3.

The share of "Inginer" and "Tehnician" does not move. Their loss is not in the dash: the dash case gave 1 title for "Tehnician" and none for "Inginer". The brief's "9 of 42 engineers" is not visible in this snapshot (2 of 22 active in COR 2 are "Inginer"). Left as it is.

What remains generic (59 rows of the 17 Oct export, 87 postings): the bare "Muncitor calificat" (19 + 6 with a grade), titles with a department or a free sentence ("muncitor calificat – compartimentul reparații…"), and words that are not the start of a COR name ("bucătărie", "întreținere").

### Grade and grid row

- `parse_title()`: grade token and study hint are equal before and after for all 9,756 titles (0 differences). The doctests and the new tests pass.
- Grid row of the changed postings (final state, after A1 and A2): 25 postings change the grid selector, 6 change study level. The cause is not the grade. A posting that moves to a new dictionary key takes the grid selector of that key. A2 changes no grid selector. Each change is in `scratchpad/brief2/grid_changes.tsv`. Reading of the grid (Sonnet, after the review of Opus):
  - The last segment of an Anexa VIII code is the grade row of the function: `82.60128018.01.1` to `.4` are "Muncitor calificat" gradul I to IV. `salary_grid.select()` picks rows **by function name first and by code second**, and the grade of the title narrows the rows (`estimate-salaries.py`: "the posting's own grade always wins"). So the segment matters only for a posting with no grade in its title.
  - 9 "bucătar" postings go from `…018.01.1` to a null `cod`. Cause: the grid file has a row "Bucătar calificat" (sheet `VIII CII C`, source row 25) with **no code in the source** (line 1925 of `grila-2026-07-17.csv`). The model copied that row exactly, as the prompt says; the existing dictionary row "muncitor calificat bucatar" (id 126) is faithful to the grid. The selector keeps `functie_grila = "Bucătar calificat"`, and `select()` finds the row by name. **No posting loses its estimate** (checked with `build_selector()` and `grid.estimate()` on all 150 postings with a changed occupation row: 0 lost). The estimate of these 9 changes from a range (for example 4,488 to 5,879 lei) to one value, 5,250 lei (coefficient 1.2803 of that row). A new model call would see the same shortlist and give the same row. I did not re-run it.
  - 5 postings go `…018.01.1` to `…018.01.4` (zugrav, agent curățenie, îngrijitor spații verzi, liftier, electromecanic auto). All five titles say "IV". The new row is the grade the title names. This is a correction. The estimate does not change (the title grade already chose the row).
  - 4 "Muncitor" postings (`Muncitor I – Electrician` ×2, `Muncitor I – lacatus`, `Muncitor – mecanizator`) go from `82.60128019.01.1` ("Muncitor necalificat", study generală) to `82.60128018.01.1` ("Muncitor calificat", study profesională). A trade (electrician, lăcătuș, mecanizator) is a skilled job. This is a correction. Their estimate range goes up (for example 4,580 to 5,399 becomes 4,988 to 5,879 lei).
  - 1 posting `Muncitor IV – Bucatar` goes from `…019.01.1` (necalificat) to `82.60128014.02.1` ("Bucătar; lenjereasă"), study profesională. A correction (a cook is a skilled trade). The estimate range widens (4,100 to 5,399 becomes 4,711 to 6,417 lei).
  - 1 posting `Muncitor calificat tr. I – lacatus mecanic` goes from `…018.01.1` to `82.40302012.01.1` ("Muncitor calificat", sheet C, "treaptă I"). The title says "tr. I", which is the grade word of sheet C. Plausible. The estimate does not change.
  - 5 postings keep the code but change another field of the selector (`functie_grila` or `nivel_administrativ`). One of them ("femeie de serviciu") changes study level from profesională to generală, which is right for a cleaner.
  - Final: **estimate lost for 0 postings; estimate value changed for 14** (the 9 bucătar, 4 muncitor, 1 `Muncitor IV – Bucatar`).
- `canonical` changed for 144 postings after A1 (150 with any change in the occupation row); 279 of 283 changed postings after A1 and A2.

## Part A2 — one name per occupation (approved by Opus and applied 2026-11-17)

### Measured

- 449 COR codes carry labels. **134 codes have more than one label** (4,594 postings). 3,531 postings and 403 labels have no COR code.
- Most of the 134 are not spelling variants. Examples: code 221201 has "Medic specialist" (274) and 22 specialty labels ("Medic specialist neurologie" …); 515104 has "Administrator" (99) and "Administrator patrimoniu" (77); 515301 has "Îngrijitor" (529) and "Îngrijitor clădiri" (21). A rule "one label for each code" would erase the specialty and the qualifier.
- The same label sits under several codes: 56 labels, 5,723 postings (for example "Îngrijitor" under 515301, 532104, 531204, 531101 and no code). This is model noise in the code, not a naming problem.

### Proposal

A **spelling rule on the label, not a code rule**. Fold each label to a key; labels with one key are one occupation; the most frequent spelling wins (ties: the longer one, then alphabetical).

Key = `ocupatii.norm()` (case, diacritics, hyphens, punctuation) minus the words `de la în din pentru a al cu`, and the feminine "-iera" → "-ier" ("Infirmieră" → "Infirmier").

Result on the dictionary: 836 labels → 801 keys. 33 keys have more than one spelling; **35 labels removed; 131 postings change label**. 7 of the 33 merges join labels that have different COR codes (marked `X`). A missing code does not matter: the key reads the label only.

Merges (spellings with their postings; the winner is first):

```
Infirmier (240) ; Infirmieră (14)                                  X 532103/532104
Administrator patrimoniu (142) ; Administrator de patrimoniu (5)   X 263111/431203/515104
Secretar șef (46) ; Secretar-șef (3)                               X 111217/235909
Contabil-șef (37) ; Contabil șef (8)
Muncitor întreținere (21) ; Muncitor de întreținere (20)           X 818204/818206
Tehnician asistență socială (35) ; … în asistență socială (5) ; … de asistență socială (1)
Îngrijitor copii (22) ; Îngrijitor de copii (17)
Supraveghetor de noapte (18) ; Supraveghetor noapte (11)           X 532907/962911
Manager proiect (23) ; Manager de proiect (6)                      X 242101/242112
Instructor de educație (15) ; Instructor educație (1)              X 223003/223005
Asistent medical de radiologie (8) ; Asistent medical radiologie (6)
Membru în Consiliul de Administrație (8) ; … consiliul de administrație (4) ; … Consiliul de administrație (1)
Medic specialist medicină de familie (9) ; … medicina de familie (2)
Asistent medical de laborator (5) ; Asistent medical laborator (5)
Instructor de ergoterapie (4) ; Instructor ergoterapie (3)
Moașă (3) ; Moasă (2)
Medic șef secție (3) ; Medic șef de secție (1)
Expert consultant (3) ; Expert-consultant (1)
… and 15 more with 1 to 4 postings (list: scratchpad/brief2/a2b.txt)
```

The merges across two codes (X) need an explicit decision from Opus. My recommendation: accept all 7; each pair is one occupation under two codes of the model.

Not caught by the rule, and left for a decision: "Asistent medical" / "Asistent medical generalist" (code 222101, 200 + 238), "Îngrijitor" / "Îngrijitor clădiri". They differ by a word, not by a spelling. Merging them loses information, or hides a coding error. I propose not to merge them.

### Applied

- `ocupatii.spelling_key()` and `spelling_winners()`; `normalize-titles.py --merge-spellings` (honours `--dry-run`, no LLM) for the rows that exist; `write_occupation()` gives a new row the winning spelling. The webapp is unchanged. Only `canonical` changes: COR code, grid selector and study level stay (grid diff above: A2 changed no selector).
- `--merge-spellings --dry-run`: 35 labels, 131 postings. Applied: same numbers. A second dry run: 0. Opus's decisions: all 7 merges across two COR codes accepted; labels that differ by a word stay apart.
- Side effect, measured: 141 postings change `occ_group` after A1 and A2 together (the export derives it from the majority code of the label, and from a title fallback). Most are A1 ("Muncitor calificat" → "Electrician": group 7 → 741).
- Tests (`webapp/tests/test_ocupatii.py`): two spellings give one label; the most frequent wins; a tie goes to the longer label; a word difference gives two labels. Mutations: stop words emptied → 3 fail; key keeps only 2 words → "differ by a word" fails; winner = least frequent → "two spellings" fails.
- Not applied to the 4 PHP-side lists that name occupations (`inc/themes.php` chart groups use "Infirmieră" and "Infirmier" as two values). After the merge the label is "Infirmier" only. That list is in webapp-php, which I did not touch: Opus or the brief-1 agent should drop "Infirmieră" there.

## Part A3 — COR group and specialty in the archive

- `export-to-sqlite.py`: `archived_postings` has `occ_group` and `med_specialty`. Same derivation as `job_postings`: `isco_group`, else the majority code of the label, else `classify.occupation_fallback(canonical, title)`; `med_specialty` is `classify.medical_specialties(title)` as JSON.
- Command: `python export-to-sqlite.py --active-only --min-rows 1 --out <scratchpad>/export.sqlite`. Result: `job_postings` 5 rows (the local data is old), `archived_postings` **3,064 rows**, of which **3,002 (98.0 %) have `occ_group`** and **187 (6.1 %) have `med_specialty`**. `job_postings` 5 of 5 have a group.
- Test: `webapp/tests/test_archive_export.py::test_the_archive_carries_the_cor_group_and_the_specialty`.

## Tests added and mutations

New: `webapp/tests/test_ocupatii.py` (15 cases) and one test in `test_archive_export.py`. Each check failed against a named mutation:

| Mutation | Failing check |
|---|---|
| dash trade switched off (`trade = ""`) | the 4 trade cases, "several posts keeps the first", "replaces only a generic label" |
| grade tail not kept after a dash | "a dash with a grade still keeps the grade" |
| `trade_label` ignores whether the label is generic | the doctest and "replaces only a generic label" |
| archive `occ_group` always empty | the archive test |
| department guard removed | **no test fails**. The guard was dead code (no COR name starts with a department word), so I deleted it. The test "a dash with a department still drops the department" still passes and holds the behaviour. |

Not run: PHP suites, Playwright (another agent in `webapp-php/`). Python: `webapp` suite as above.

## Part B — spike: the domain from the COR code (measure only)

Nothing in the pipeline, export or webapp changed for Part B. The table and the numbers come from `scratchpad/brief2/domain_map.py` and `partb.py` (output `partb.txt`).

### Rules

Code of a posting: the first 4 digits of the 6-digit COR code; else `occ_isco_group` (4 digits); else `occ_group` (1 to 4 digits). The longest prefix wins.

| Prefix | Domain | Note |
|---|---|---|
| 0 | ordine | armed forces |
| 1 | administratie | managers |
| 1211, 1346 | financiar | |
| 1330 | it | |
| 1341, 1345 | educatie | |
| 1342 | sanatate | |
| 1343, 1344 | social | |
| 2 | ingineri | science and engineering |
| 22 | sanatate | doctors, nurses, dentists, pharmacists, physio |
| 23 | educatie | |
| 24 | administratie | |
| 241, 2631 | financiar | 2631 = economists ("administrator financiar") |
| 25 | it | |
| 261, 3411 | juridic | |
| 262, 264, 265, 2166 | cultura | |
| 263 (except 2631) | social | social workers, psychologists, sociologists |
| 3 | tehnicieni | |
| 32, 3344 | sanatate | nursing and medical associates, medical registrar |
| 33 | administratie | |
| 331 | financiar | |
| 3355 | ordine | police inspectors |
| 341 | social | |
| 342 | altele | sport |
| 343, 3521 | cultura | |
| 3434 | paza | chefs |
| 35 | it | |
| 4 | administratie | |
| 4311, 4312, 4313 | financiar | |
| 4411 | cultura | library clerks |
| 5 | paza | services, sales, care |
| 5311 | educatie | childcare |
| 5312, 5322 | social | maternal assistant, home care |
| 5321 | sanatate | infirmier |
| 5411, 5412, 5413, 5419 | ordine | 5414 (security guard) stays in `paza` |
| 6, 7 | meseriasi | 6 = skilled agriculture (new: today it is not covered) |
| 8 | soferi | |
| 9 | necalificati | |

Judgement calls that Opus should confirm: 5311 → educatie, 5321 → sanatate, 5322 and 5312 → social, 342 → altele, 6 → meseriasi, 3434 → paza, 2166 → cultura. The COR file is not pure ISCO-08: "infirmier/infirmieră" and "băieș" and "gipsar" share 5321.

### Which postings have no usable code

| Kind of code | 17 Oct active (2,461) | All (9,756) |
|---|---|---|
| 6-digit COR code | 1,522 | 6,219 |
| no 6-digit code, 4-digit ISCO | 402 | 1,566 |
| group of 3 digits | 224 | 749 |
| group of 2 digits | 21 | 92 |
| **group of 1 digit** | **243** | **926** |
| **no group at all** | **49** | **204** |

Postings that need the LLM family as a fallback = no code, or a code too short to decide (a longer rule leads to another domain, for example "2", "22", "3", "5", "26"; the groups 6, 7, 8, 9 are decided by one digit): **119 of 2,461 active (4.8 %; 49 with no code, 70 too short) and 511 of 9,756 in all (5.2 %; 204 + 307)**.

### Matrix: today × proposed

"Today" is `inf_profession_family`, with the family `tehnic` split by the first digit of `occ_group` as the landing page does (7 meseriasi, 5 paza, 8 soferi, 2 ingineri, 3 tehnicieni, 9 necalificati; any other digit = `tehnic-nealocat`). A posting that needs the fallback keeps today's value (shown "(llm family) x" in the raw output).

Summary (all 9,756): 6,605 unchanged, **3,151 moved (32.3 %)**. Active (17 Oct, 2,461): 1,870 unchanged, **591 moved (24.0 %)**. The full matrices are in `scratchpad/brief2/partb.txt`. Row totals (all 9,756), today → proposed:

| Domain | Today | Proposed |
|---|---|---|
| sanatate | 2,091 | 2,365 |
| administratie | 2,548 | 1,453 |
| paza | 825 | 1,219 |
| financiar | 425 | 918 |
| meseriasi | 780 | 807 |
| soferi | 504 | 529 |
| educatie | 172 | 512 |
| social | 448 | 506 |
| necalificati | 362 | 429 |
| ingineri | 185 | 267 |
| cultura | 170 | 204 |
| it | 69 | 153 |
| juridic | 23 | 80 |
| tehnicieni | 109 | 65 |
| ordine | 52 | 32 |
| altele | 946 | 13 |
| tehnic-nealocat | 47 | 0 |
| needs the LLM family | — | 204 (no code) |

The `tehnic` split is stable: meseriasi 780 → 780 (the 780 postings of today's meseriasi are all in 7 → meseriasi), soferi and necalificati the same. Everything else moves. The family "altele" (946 today) nearly disappears: its postings go to `sanatate` (211), `administratie` (197), `paza` (136), `ingineri` (82) and others.

### The 30 largest moves (all postings; occupation, count, from → to)

| n | Occupation | From → to |
|---|---|---|
| 204 | Consilier școlar | administratie → educatie |
| 173 | Referent | administratie → financiar |
| 145 | Administrator financiar | administratie → financiar |
| 141 | Mediator școlar | social → educatie |
| 134 | Consilier | administratie → financiar |
| 132 | Administrator patrimoniu | administratie → paza |
| 120 | Administrator | administratie → paza |
| 79 | Șef serviciu | altele → administratie |
| 76 | Îngrijitor | altele → sanatate |
| 62 | Registrator medical | administratie → sanatate |
| 59 | Bucătar | altele → paza |
| 47 | Casier | administratie → paza |
| 46 | Psiholog | sanatate → social |
| 44 | Consilier juridic | administratie → juridic |
| 41 | Cercetător științific | altele → ingineri |
| 35 | Îngrijitor la domiciliu | paza → social |
| 31 | Magaziner | altele → administratie |
| 31 | Tehnician asistență socială | tehnicieni → social |
| 29 | Asistent medical | altele → sanatate |
| 28 | Îngrijitor | altele → paza |
| 27 | Îngrijitor | administratie → sanatate |
| 26 | Îngrijitor | paza → social |
| 23 | Medic specialist | altele → sanatate |
| 22 | Șef centru | altele → it |
| 21 | Șef serviciu | ordine → administratie |
| 21 | Director medical | administratie → sanatate |
| 20 | Îngrijitor copii | paza → educatie |
| 19 | Îngrijitor bătrâni la domiciliu | paza → social |
| 18 | Logoped | educatie → sanatate |
| 18 | Secretar școală | administratie → educatie |

Active (17 Oct) shows the same order: Referent 34 (administratie → financiar), Registrator medical 28 (→ sanatate), Administrator 27 (→ paza), Mediator școlar 26 (→ educatie).

### What the numbers say (assumption flagged)

- Many moves are not a better domain but a **code that the model gave to a label**: "Administrator" and "Administrator patrimoniu" carry COR 515104 (a caretaker, domain paza); "Referent" and "Consilier" carry 331309 (a financial associate). A domain from the COR code is only as good as the code. Opus should decide whether the domain should read the code of the label or the code per posting, and whether the dictionary codes of these generic labels (Referent, Consilier, Administrator, Șef serviciu) are fixed first. I did not check which side is right for each label; I only measured the moves.
- "Îngrijitor" has 5 families today and 5 codes. The proposal splits it by code (sanatate / paza / social / educatie). It is the same noise as in A2.
- Only 5 to 6 % of the postings need the LLM family.

### What a change of the `family` vocabulary breaks (read from the code, nothing run)

- **Saved searches** (ADR 0001): the search key stores `family[]=tehnic`. A saved key with a value that disappears matches nothing. Needs an alias table (old value → new values), or the old values stay valid.
- **Email alerts** (ADR 0003): the same filter strings, kept on the server. Same alias problem, and no way to ask the reader.
- **Feeds** (`webapp-php/feeds/*`): a feed URL with `family[]=` is a reader subscription. The feed `id` must not change (reader key). `feeds/jobs.json.php` also prints `profession_family` from `inferred`.
- **Old URLs** `family[]=tehnic` (bookmarks, links from outside, the landing page of today): a redirect or an alias.
- **Landing items**: `HOME_DOMAINS` in `webapp-php/helpers.php` builds 6 of the 16 items from `family=tehnic` plus `cor[]`; the counts are checked in `tests/home_test.php`. A domain filter replaces both parameters.
- **Themed pages**: `inc/themes.php` uses `base => family[...]` and `history => family` (the archive filter reads `archived_postings.inf_profession_family`). `home_weekly_postings()` filters by family in both tables. The theme tests (`theme_test.php`, `fixtures/theme_db.php`, `chips_db.php`) and `webapp-php/tests/browser/chips.spec.js` use the values.
- **The filter list** "Domeniu profesional" (`pages/list.php`) shows the family values; `pages/archived.php` finds "similar open jobs" by `inf_profession_family`; `webapp-php/db.php` builds a synthetic `inf_profession_family` for the archive.
- **Pipeline**: `export-to-sqlite.py` (`inf_profession_family` column, index `idx_jp_family`, the archive column), `webapp/apps/jobs/management/commands/infer_postings.py` and `quality_check.py` (`FAMILIES`, `_match_family`, `PROFESSION_FAMILIES`), `schema_models.py`, `normalize-titles.py` (`context_for()` maps the family to the grid annex: sănătate II, educație I, cultură III, juridic V, ordine publică VI, else VIII — this one changes the **salary grid choice**), `ops/check-export.py` (metrics `family_altele_pct`, `family_top_pct`, thresholds on the share of "altele" and on the largest family; they would need new baselines).
- **Docs**: `docs/ui-spec.md`, `docs/pipeline-quality-checks.md`, `docs/metadata-schema-v3.md`.
- A new field next to `inf_profession_family` (not a new vocabulary) breaks none of these. Opus can weigh that for the ADR.

## Left undone

- Part A2 is not applied (the brief says to stop).
- PHP suites and Playwright were not run.
- No deploy, no commit, no production access.

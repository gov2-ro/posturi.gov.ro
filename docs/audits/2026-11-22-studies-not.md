# DATA-STUDIES-NOT — counts, VPS commands and assumptions

Date: 2026-11-22. Builder: Sonnet. Brief: `docs/briefs/2026-11-22-studies-not.md`.

## Local counts (step 1)

Source: `data/_dev/dev.sqlite` of 18 Oct (9,756 postings, 220 `doctorat` roles). The Postgres copy of 9 Sep and these exports are older than the live data. Treat the numbers as a baseline.

| Measure | Count |
|---|---|
| Postings removed by `studies_level_not[]=licenta` today (a role at licență) | 4,394 |
| Postings removed with decision 1 (a role at licență, master or doctorat) | 4,842 (+448) |
| Postings whose title starts with „medic”, by role level | licență 572, doctorat 39, none 23, generală 9, master 2, postliceală 1 |
| Of these: passing the exclusion today / after decision 1 | 72 / 31 |
| Postings with no level in any role, by COR major group (`occ_group`) | none 29, 1: 68, **2: 346**, 3: 282, 4: 17, 5: 529, 6: 4, 7: 286, 8: 122, 9: 144 |
| Postings that decision 4 fills (role with no level, group 2 by `occ_cor_code`, ISCO group as fallback) | **252 postings, 256 roles** |
| The same by `occ_group` (includes the group read from the title) | 350 postings (not used) |
| Step 4 rule: `doctorat` roles that become `licenta` (medical title, no „doctorat” / „studii doctorale” in the body) | **47 roles in 47 postings** (limit 200) |
| Medical titles that keep `doctorat` because the text asks for it | 3 |
| Non-medical titles changed | 0 |
| Postings with a medical title and level `generala` (the warning) | 9 |

Notes:
- The text for the doctorate check is the body only locally (`posting_text`). The export also reads the attachment text. The VPS count can be lower than 47.
- The medical-title pattern is `doctor-medic`, `doctor în medicină`, or the word „medic” / „medici” anywhere in the title. The first pattern (title starts with „medic”) changed 35 roles and missed titles like „2 posturi medici specialisti/primari”. The word pattern changed 47.
- „Asistent medical” and „director medical” do not match (the word is „medical”).
- Of the 9 `generala` postings, one title is „Asistent cercetare în medicină generală, cod COR 221110, medic oncolog”. The warning lists it. Review it by hand.

## What changed

1. `build_filters()`: `studies_level_not` expands to the level and every level above it. Order: generală < profesională < liceală < postliceală < licență < master < doctorat. `profesionala` is in the order because the extraction writes it (`schema_models.StudyLevel`); the brief did not list it. The other `_not` filters and the URL key do not change.
2. Chip: „Fără studii: Licență” becomes „Fără licență și peste” (doctorat: „Fără doctorat”). The control of the level has the same words in its `title` and its screen-reader text. The visible text of the control stays „fără”.
3. `posting_roles.py`: the medical-title rule, and the inferred level. New nullable column `study_level_source` (`text`, `inferat-ocupatie`, NULL). The compat view of an old export reads it as NULL.
4. `export-to-sqlite.py`: the warning for a medical title with level `generala` is in `verify()`. `ops/` is outside this work, so `ops/check-export.py` does not show it yet.
5. The „Pot aplica?” panel ignores a level with source `inferat-ocupatie`. The panel compares requirements that the posting states.

## For the VPS session

Run these on the VPS after the export of the new code. All are read-only.

Export column check (after `export-to-sqlite.py --active-only`; the path is the live export):

```
sqlite3 -readonly "$DB" "SELECT study_level_source, COUNT(*) FROM posting_roles GROUP BY 1;"
```

Postings removed by the exclusion, before (old export) and after (new export):

```
sqlite3 -readonly "$DB" "SELECT COUNT(DISTINCT posting_id) FROM posting_roles WHERE study_level='licenta';"
sqlite3 -readonly "$DB" "SELECT COUNT(DISTINCT posting_id) FROM posting_roles WHERE study_level IN ('licenta','master','doctorat');"
```

Doctor postings by level, and how many pass the exclusion:

```
sqlite3 -readonly "$DB" "SELECT IFNULL(r.study_level,'none'), COUNT(DISTINCT j.id) FROM job_postings j JOIN posting_roles r ON r.posting_id=j.id WHERE lower(j.title) LIKE 'medic%' GROUP BY 1;"
sqlite3 -readonly "$DB" "SELECT COUNT(*) FROM job_postings j WHERE lower(title) LIKE 'medic%' AND NOT EXISTS (SELECT 1 FROM posting_roles r WHERE r.posting_id=j.id AND r.study_level IN ('licenta','master','doctorat'));"
```

Postings with no level, by COR major group (run on the OLD export, or filter `study_level_source IS NULL` on the new one):

```
sqlite3 -readonly "$DB" "SELECT substr(COALESCE(NULLIF(j.occ_cor_code,''), j.occ_isco_group),1,1) g, COUNT(DISTINCT j.id) FROM job_postings j WHERE NOT EXISTS (SELECT 1 FROM posting_roles r WHERE r.posting_id=j.id AND r.study_level IS NOT NULL) GROUP BY 1 ORDER BY 1;"
```

On the new export, the postings that decision 4 filled, and the roles that step 4 changed (compare `doctorat` before and after):

```
sqlite3 -readonly "$DB" "SELECT COUNT(DISTINCT posting_id), COUNT(*) FROM posting_roles WHERE study_level_source='inferat-ocupatie';"
sqlite3 -readonly "$DB" "SELECT COUNT(*) FROM posting_roles WHERE study_level='doctorat';"
```

The old export gives the `doctorat` count before the change. The difference is the number of roles that step 4 changed. Stop and report if it is above 200.

The warning for `generala` with a medical title appears on stderr at the end of every `export-to-sqlite.py` run (the verify step), or run:

```
sqlite3 -readonly "$DB" "SELECT DISTINCT j.id, COALESCE(r.title,j.title) FROM posting_roles r JOIN job_postings j ON j.id=r.posting_id WHERE r.study_level='generala' AND (lower(COALESCE(r.title,j.title)) LIKE '%medic%');"
```

Result check on the live list: `/?studies_level_not[]=licenta&sort=salary` shows no medical doctor in the first page.

## Assumptions

- The inferred level uses `occ_cor_code`, with `occ_isco_group` as fallback. `occ_group` (the filter „Grupă ocupațională”) also holds a group read from the title. It would fill 350 postings instead of 252. The brief says „COR code”, so the stricter source is used.
- Decision 4 also affects the positive filter „Nivel de studii: licență” and its counts: a group-2 posting with no stated level now matches. The facet help text says „Nivelul cerut de anunț”. Opus can decide whether the text must say „sau dedus din ocupație”.
- The facet counts of the level group ignore the exclusion of the level group itself (the same rule as every group). Every other group follows the ordered exclusion.
- `ROLE_FORMAT` stays 1. The new column is additive.
- A role with no title takes the posting title for the medical rule.
- The extraction is not read again. The rule works on the stored value at export time. A medical title that the text really asks a doctorate for keeps `doctorat`.
- The Playwright spec uses the shared fixture, which has no `doctorat` posting. The `doctorat` label is covered by the PHP test.
- `tests/theme_test.php` fails one assertion (the sprite file equals the output of `build-sprite.php`). The failure is the same before this work and is not related to it. `tests/test_salary_facet_sql.py` fails as known.

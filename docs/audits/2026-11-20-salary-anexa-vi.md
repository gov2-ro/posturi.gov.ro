# Audit — Anexa VI: rank pay, matcher bug and the law in force (SALARY-ANEXA-VI)

Date: 2026-11-22. Author: Sonnet. Brief: `docs/briefs/2026-11-20-salary-anexa-vi.md`. The first run stopped at the rank mapping (see "Stop condition"). The owner decided on 2026-11-22 (decisions 6–8, ADR 0008 rule 10). The second run built steps 2, 4, 6 and 7: see "Second run" at the end.

## Stop condition: the rank names do not map one to one

The brief says: stop if the rank names of the post rows do not map one to one to the rank table. Measured on `VI_Armata` (post rows 10–68, rank rows 74–95):

| Post row | Problem |
|---|---|
| 24–27 "agent - agent șef principal de poliție/penitenciare" | The label names a **range** of ranks (0,52 to 0,61). It is not one rank. |
| 33–35 "plutonier adjutant șef" | The rank table has "Plutonier adjutant principal" (0,61), not "șef". No name match. |
| 23 "subinspector … debutant" | Maps after the "debutant" strip: "Subinspector de poliție" (0,63). Not a problem. |
| 10–12 heads of institutions | No rank in the name. Brief: no rank pay. |

All other post rows map to one rank. Decision 2 of the brief covers "no match" (no rank pay and a warning). It does not cover a range. The owner must decide: (a) a range gives `lei_min` with the lowest and `lei_max` with the highest rank coefficient; (b) "șef" maps to "principal"; (c) both rows get no rank pay.

## Step 1: grid (done)

`build-salary-grid.py` now writes `data/salarii/solda-grad-2026-07-17.csv`: 22 ranks (Mareșal 1,10 to Soldat 0,40). The rows of `grila-2026-07-17.csv` and `functii-2026-07-17.csv` are byte-identical after the rebuild.

## Step 3: matcher bug (done)

Cause: the build splits a label on "/". "…agent de poliţie/penitenciare debutant" becomes two synonyms: "…agent de poliţie" and "penitenciare debutant". No synonym equals the full label, so `select()` found no row. The word "debutant" is not the cause.

Fix: `Grid.by_label` indexes the normalised full label of a row, only when no synonym has that key. `select()` reads it as a last resort, after the name and alternative-name lookups and before the code lookup.

Dry run of `estimate-salaries.py --dry-run`, local copy of 9 Sep 2026 (9,750 postings), before and after: **all counts identical** (estimated 8,368; baza lege_in_vigoare 8,251, proiect 1,494, declaratii 5; confidence exact 3,298, interval 5,070, necunoscut 1,382; the per-annex table; the bucket changes). Only the 12 sample lines differ: the SQL returns rows in a different order between runs. The local copy has 0 postings in Anexa VI, so no count can move.

For the VPS session (from the repository root, after the code deploy):

```
python build-salary-grid.py            # writes solda-grad-2026-07-17.csv; check that git diff of grila-2026-07-17.csv is empty
python estimate-salaries.py --dry-run > /tmp/after.txt
git stash   # or check out the commit before e2c5a81 for the "before" run
python estimate-salaries.py --dry-run > /tmp/before.txt
diff /tmp/before.txt /tmp/after.txt    # expect only the 12 sample lines
```

## Step 5: spike, the law in force (no build)

Source: `docs/salarii/lege-153/legea-153-2017-id298840-2026-11-18.html`, Anexa VI.

**1. Lei or coefficients, and the reference value.** Anexa VI holds both: lei values for "anul 2022" and coefficients (reference value 2,500 lei: soldat 2.500 = 1,00). Cap. I (post pay) and Art. 7 (rank pay, lei) are separate tables. The law-in-force reference value for 2026 does not exist as one number: the pay is the 2022 lei value times the acts below. The 2,500 base is the 2022 value; 4,100 and 4,000 belong to the drafts.

**2. Acts after 2022.**
- OUG 168/2022 art. I and OUG 54/57/2023: from June 2023, the post pay (Anexa VI) is set at the 2022 level of the annex.
- OUG 115/2023 art. I (1): +5% on "soldele de funcție" from 1 Jan 2024. This is the factor 1,05 (the equivalent of 1,15 for Anexa VIII).
- OUG 53/2024 (the +10% in two steps) names the annexes III, IV, VII, VIII, I cap. II and V cap. VI. It does **not** name Anexa VI. So Anexa VI gets 1,05, not 1,15.
- 2025 and 2026: the pay is frozen at the level of Nov 2024 / Dec 2025.
- **Rank pay is a separate rule.** Art. 7 (2) lists 2022 lei values (agent de poliție 250). Art. 7 (3) says the rank pay follows the minimum wage by Government decision. OUG 26/2024 art. XI sets the updated values from 25 Mar 2024: agent de poliție/penitenciare **1.980 lei** (all ranks listed; soldat 1.584). The 2022 table is no longer the amount paid. 1.980 = 0,6 × 3.300 (minimum wage of 2024). I did not find a later update for the minimum wage of 2026 (4.325); no Government decision is in the file.

**3. Figure for agent de poliție debutant, no seniority, PL, post + rank, law in force.** Cap. I row 22 (debutant agent and sergeant, PL): 3.750 lei (2022). The law has one row for "maistru militar cls. a V-a / sergent / agent de poliție debutant". The draft has a separate row (1,49).
- Post pay: ceil(3.750 × 1,05) = 3.938 lei.
- Rank pay: 1.980 lei (Art. XI OUG 26/2024).
- **Total about 5.918 lei gross**, before bonuses.
- Doubt: a debutant agent may hold no rank yet or the rank "agent" (the draft maps debutant to "Agent de poliție"). I assumed the rank "agent de poliție". I did not confirm that the 1,05 applies to this row (the text of OUG 115/2023 says "soldele de funcție aflate în plată").

**4. Public figure to check.** Not confirmed. A web search found only secondary sources: the union FSNPP (cited by playtech.ro, 2026) puts the debutant "salariu de funcție" at 4.075 lei gross, and press estimates of 4.000–4.500 lei net. 4.075 differs from my 3.938 by 137 lei: either another act raised the post pay, or the union figure is another row. No recruitment notice of MAI or IGPF that states the pay was found (the IGPF notice in the results covers places and dates only). The check needs a primary source: an IGPF or ANP job notice with "salariu de bază", or an état de plată.

**Rule for ADR 0008?** Yes, probably. The rank pay of the law in force is not a function of the annex: it needs its own table (OUG 26/2024 art. XI) and a rule for the minimum-wage update. Per the brief, this is a stop condition for building the law rows. Opus decides.

## What I assumed

- The "debutant" matcher fix may change other results where a label had no row before. The dry run shows no change on the local copy; the VPS run confirms.
- The rank "agent de poliție" for the debutant in the law-in-force figure (above).
- I read the Anexa VI table from the text of the HTML. The 2022 values agree with the draft Cap. I structure.

## Second run (2026-11-22): the build

**Rules built** (`salary_grid.py`): `post_names()` reads a label ("agent de poliţie/penitenciare debutant" gives "agent de poliţie debutant"; "principal/șef" gives both; "cls." is "clasa"). `RankTable.match()` finds the ranks: a range ("agent - agent șef principal") gives its two ranks; "plutonier adjutant șef" takes "principal" with a warning when the table has no "șef" (the law table has it, so no warning there); a head of institution has no rank (post pay only, warning). `Grid.row_parts()` gives the parts. The ranks come from the label of the selector when it has the prefix "Funcţii corespunzătoare gradului de", else from the label of the row.

**Law rows.** `build-salary-grid.py --source lege-153` reads table 97 (Anexa VI Cap. I): 40 rows, stored as the 2022 lei value with `multiplicator_regula` 1.05, the convention of Anexa V. The pay is ceil(3,750 × 1.05) = 3,938. The synonyms of these rows come from `post_names()`, so the draft label finds its law row. `solda-grad-lege153-2026.csv` holds the 27 ranks of OUG 26/2024 art. XI, typed from the consolidated act (legislatie.just.ro, id 298840, file `legea-153-2017-id298840-2026-11-18.html`, downloaded 2026-11-18). The values were extracted from the text of that file by a script and checked by count (27) and by sample (agent 1,980; soldat 1,584).

**Figures** (gradation 0): agent de poliție debutant, PL: draft 8,241 (6,109 + 2,132); law 5,918 (3,938 + 1,980). Locotenent: draft 10,086–11,480; law 6,927–7,199. A range of ranks: law grade part 1,980–2,277.

**No other annex changes.**
- `grila-lege153-2026.csv` before and after: 40 lines added, 0 removed, all 40 are Anexa VI. `grila-2026-07-17.csv` and `functii-2026-07-17.csv` are identical.
- `estimate-salaries.py --dry-run` on the local copy (9 Sep, 9,750 postings), before and after the build: all counts identical (the first 21 lines, with the per-annex table). Only the sample lines can differ. The local copy has 0 Anexa VI postings.
- `row_parts()` returns None for every row that is not Anexa VI; `as_dict()` adds the four new keys only when they exist, so the payload of other annexes is unchanged.

**Page.** `/estimator-salariu/` shows, for Anexa VI, "Soldă de funcție", "Soldă de grad" and "Total brut" in each tile (law first, draft second, as ADR 0008 rule 2 says) and one line about bonuses. The export adds four nullable columns to `salary_grid_pay`. Screenshots at 375 px and 1280 px were taken from the PHP fixture server (README) with Chrome; no horizontal overflow. They are in the scratchpad of the run, not in git.

**Assumed (second run).**
- The gradation does not change the law rank pay (a fixed amount). The draft applies it to both parts (brief decision 3).
- The minimum wage is the floor of the total for Anexa VI, not of the post pay: 3,938 < 4,325 would otherwise raise the post pay and give 6,305 for the agent. Soldat: 2,625 + 1,584 = 4,209, so the floor applies.
- The law row for the debutant agent also names "maistru militar cls. a V-a" and "sergent". The page shows the range of ranks of the row (1,881–1,980); the estimate with the draft label gives the rank of the agent (1,980) exactly.
- The known gap of rule 10 stays: 3,938 against the union figure of 4,075.
- The test fixture of the PHP suite moved its draft-only row (Soldat) from Anexa VI to Anexa IX, because Anexa VI now has law rows. Four assertions of `salary_estimator_test.php` changed with it.

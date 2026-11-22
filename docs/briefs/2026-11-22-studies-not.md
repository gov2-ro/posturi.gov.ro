# Brief — „Fără licență” must also remove the levels above (DATA-STUDIES-NOT)

Date: 2026-11-22. Author: Opus. Builder: Sonnet. Backlog: DATA-STUDIES-NOT.

## Why

The owner opened `/?studies_level_not[]=licenta&sort=salary` and saw medical doctors. A reader who excludes licență means "I have no degree". Today the exclusion removes only the postings at licență: master, doctorat and the postings with no level stay.

Local export of 18 Oct (`data/_dev/dev.sqlite`), postings whose title starts with „medic”, by `posting_roles.study_level`: licență 565, doctorat 39, none 23, generală 9, master 2, postliceală 1. So 74 doctor postings pass the filter. Three causes:

1. The exclusion of a level does not remove the levels above it.
2. The extraction reads „doctor-medic” (the title of the medical degree, a diplomă de licență) as `doctorat`, and some doctor postings as `generala`.
3. Some postings have no level, although the occupation needs a degree.

## Decisions (owner, 2026-11-22)

1. **Ordered exclusion.** For `studies_level` only, excluding a level also excludes every level above it. Order: generala < liceala < postliceala < licenta < master < doctorat (`STUDIES_LABELS`). The URL key `studies_level_not[]` does not change. The other `_not` filters do not change.
2. **The chip says it.** An active exclusion reads „Fără licență și peste” (for doctorat: „Fără doctorat”). The facet's exclude control uses the same words.
3. **Unknown level stays.** A posting with no level is not removed by an exclusion. Its row shows „nivel necunoscut” only if the row already shows the level; do not add a new row element.
4. **Inferred minimum from the occupation.** When the posting states no level and its COR code is in major group 2 (ISCO skill level 4), the role gets `licenta` as an inferred level. Groups 3–9: no inference. The inferred level is marked as inferred, not as read from the text.
5. **No LLM re-run.** The fix of cause 2 is a rule on the stored extraction, not a new extraction.

## Read first

- `webapp-php/helpers.php`: `build_filters()` (the studies clause near line 2477, the exclusions loop `NEGATABLE_PARAMS` near line 2656), `STUDIES_LABELS`, the chip labels near line 1906.
- `webapp-php/pages/eligibility_tool.php` (it already sends every level above the reader's).
- `webapp-php/pages/list.php` near line 1139 (the include/exclude toggle).
- `posting_roles.py` (where `study_level` comes from: `edu.minimum_level`, or `inf_studies_required` for an implicit role) and `export-to-sqlite.py`.
- The inference pass that writes `inf_studies_required`: find it, and check whether it already uses the occupation.
- ADR 0001 (filter keys), `docs/specs/2026-10-10-follow-ups/02-role-requirements.md` (DATA-04: the level is per role).

## Where the data is

The local Postgres is a copy of 9 Sep 2026; the local exports are older. **The live data is on the VPS.** Build and test on the Mac. For the VPS numbers, write the exact read-only commands or SQL into the report under „For the VPS session”; Opus relays them. Never edit a VPS row by hand: a data fix is a committed script or flag.

## Steps

1. **Measure (local first, VPS commands in the report).** On the newest local export, count: postings removed by `studies_level_not[]=licenta` today and with decision 1; doctor postings by level; postings with no level by COR major group; postings that decision 4 would fill; postings that the rule of step 4 would change.
2. **Ordered exclusion.** In `build_filters()`, expand a `studies_level_not` value to that level and every level above it, then build the exclusion as today. Facet counts follow the same rule. Unknown level stays (decision 3).
3. **Chip and control label** (decision 2). Romanian copy: short, one term per concept.
4. **Extraction rule.** Where the export reads the level, map `doctorat` to `licenta` when the role or title is a medical degree title („doctor-medic”, „doctor în medicină”, „medic dentist”, „medic”) and the text has no „doctorat” / „studii doctorale”. Add a `check-export` warning that lists postings with a medical title and level `generala`. Do not change `generala` automatically: report the list.
5. **Inferred level** (decision 4). Put it where the role row is built (`posting_roles.py`), with a source mark (for example `study_level_source`: `text` / `inferat-ocupatie`). If a new column is needed, add it as nullable, so that an old export still works.
6. **Tests.** PHP: the ordered exclusion (licenta removes master and doctorat; unknown stays), the chip label. Python: the medical-title rule, the occupation inference (group 2 yes, group 3 no, a stated level wins). Playwright: the list with the exclusion at 375 and 1280 px.

## Done when

- The tests pass, and the full test suites pass.
- The report `docs/audits/2026-11-22-studies-not.md` holds the counts of step 1 (local), the VPS commands, and what you assumed.
- The activity log and the backlog are updated.

## Stop and ask

- If decision 1 would change another `_not` filter or a filter key.
- If the inferred level would change a level that the text states.
- If step 4 changes more than 200 postings, or touches a non-medical title.
- If the change needs a new DS component or token.

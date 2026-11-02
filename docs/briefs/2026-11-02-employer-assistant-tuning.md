# Brief — UX-EMP-03 Tune the employer assistant

Owner: Sonnet builds; Opus reviews. Date: 2026-11-02. Baseline: `4df6855`.
Read first: [ADR 0002](../decisions/0002-employer-posting-payload.md), the [UX-EMP-01 brief](2026-11-02-employer-posting-builder.md), `webapp-php/pages/employer_builder.php`, `webapp-php/static/employer-builder.js`.

## Goal

pax reviewed the live builder and asked for eight changes (2026-11-02). Each change is below, with the rule to implement. Do not change the payload format (ADR 0002). Do not change the storage keys or their versions.

## Constraints

The constraints of the UX-EMP-01 brief stay. Also:
- The browser test suites of this repo and of another session share port 8823. Before you run Playwright, check that the port is free (`lsof -nP -iTCP:8823 -sTCP:LISTEN`). If it is not free, wait.
- A draft saved by the current version must open without loss after your change. Add a test with a stored draft of the current shape.

## Work

### 1. New name

The page name is **„Asistent redactare anunțuri angajare”** (pax's wording). Use it in `$page_title`, the `<h1>`, the meta description and the three links (footer, `/angajatori/`, the employer page). The link text is „Asistent redactare anunțuri angajare”. The URL stays `/pentru-angajatori/anunt/`. The `generator` string of the payload stays as it is.

### 2. Sidebar

At `lg` and wider, use two columns: the form on the left and a sticky sidebar on the right (`position: sticky; top: …`, its own scroll when it is taller than the window). The sidebar holds, in this order:
1. „Cât de complet este anunțul” (the meter, item 3);
2. „De completat” (the missing items, each a link to its section);
3. the art. 18(2) list;
4. the two download buttons and the PDF notice.

The drafts list stays above the form. The preview stays below the form, in full width.

Below `lg`, the page keeps one column. A sticky bar at the bottom of the window shows „Anunț complet în proporție de 83%” and a link „Vezi ce lipsește” to the meter. The bar must not cover the last form field: add bottom padding equal to its height. Check 320 and 375 px for horizontal overflow.

### 3. Percent, not points

Show the score as a percent: the big number „83%”, and under it the label „complet”. The weights of `classify.QUALITY_CHECKS` add up to 100, so the number does not change. Add a test that asserts the sum is 100, in Python and in JS, so that a later change of weights cannot make the percent wrong. Set `aria-valuetext="83%"` on the meter.

### 4. Defaults in a new draft

A new draft starts with these values, unless the institution profile has its own default:

| Field | Default |
|---|---|
| `contract.post_status` | `vacant` |
| `contract.duration` | `nedeterminata` |
| `contract.schedule` | `norma_intreaga` |
| `documents.standard` | the codes of HG 1336/2022 art. 35(1) a)–g) and i): `formular_inscriere`, `act_identitate`, `certificat_casatorie`, `acte_studii`, `carnet_munca_adeverinta_vechime`, `cazier_judiciar`, `adeverinta_medicala`, `cv` |

- `certificat_integritate` (art. 35(1) h) is checked by default only when the institution category from org.gov2.ro (item 7) is „Educație”, „Sănătate” or „Asistență socială și muncă”. Show the hint „Obligatoriu în învățământ, sănătate, protecție socială și în posturile cu persoane vulnerabile (art. 35 alin. 1 lit. h)”.
- `declaratie_conformitate` and `dosar_plic` are not in art. 35. They stay unchecked.
- Defaults apply only to a new draft. Do not change an existing draft.
- A default counts as a filled value for the meter.

Later (not now): templates with the most common choices for each kind of post. Record this as backlog text in your report.

### 5. Conditional fields

Rule: a field that applies only to one choice is shown only when that choice is made. A hidden field keeps its stored value in the draft, so that switching back restores it. A hidden field is left out of the payload, the preview, the .docx and the meter.

| Field | Shown when |
|---|---|
| Durata în luni | `contract.duration` = `determinata` |
| Ore pe săptămână | `contract.schedule` = `norma_partiala` |
| Ani de experiență, În specialitate | „Fără experiență” is not checked |
| Localități în care se lucrează (`work_localities`) | the new checkbox „Postul presupune lucru în mai multe localități” is checked. The checkbox state goes in `draft.meta`. |
| the address for submission | channel `sediu` or `posta` is checked |
| the e-mail for submission | channel `email` is checked |
| the link for submission | channel `online` is checked |
| the fee amount | the fee is set to „da” |

Read section 8 of the page. If the fields there do not match this table, follow the rule, and report each field that you made conditional.

About `work_localities`: pax asked whether the field is ever used. It is a v5 field. The local database has no v5 extractions, so nobody has measured it yet. The checkbox keeps the field out of the way for the common case. The measurement is a backlog item (item 9).

### 6. Field of study: a list, and free text

`education.fields_of_study` is a list of `{label_ro, isced_field}`. Replace the textarea with a chip input:
- The input has a `<datalist>` of suggestions. Picking a suggestion adds a chip with `label_ro` and `isced_field`.
- Free text adds a chip with `isced_field: null`. Enter or a comma adds the chip. Each chip has a remove button.
- Store the suggestions in `webapp-php/static/domenii-studii.js`: `{label_ro, isced_field}`, sorted by label, at most 120 entries.

Build the list from the extracted data. These are the 45 most frequent labels in the local v3/v4 extractions (Postgres `posturi_dev`, `jobs_jobpostingschemavariant`, 848 extractions, 1,620 labels, 319 distinct):

```sql
select lower(f->>'label_ro'), f->>'isced_field', count(*)
from jobs_jobpostingschemavariant v,
     jsonb_array_elements(case when jsonb_typeof(v.schema_json->'education'->'fields_of_study')='array'
                          then v.schema_json->'education'->'fields_of_study' else '[]' end) f
where v.prompt_version in ('v3','v4') group by 1,2 order by 3 desc;
```

- Merge variants into one label: „economie”, „economic”, „economice” and „științe economice” become „științe economice”. „asistență medicală generală” and „asistent medical generalist” become „asistență medicală generală”.
- Drop labels that are an occupation, not a field of study („bucătar”, „electrician”). Drop labels that are too general to help („tehnic”, „administrativ”).
- Keep the labels with at least 3 uses.
- Write the merge and drop decisions in the header comment of the file.

Later (not now): the official list of the university fields of study (nomenclatorul domeniilor și programelor de studii). Record this as backlog text.

### 7. Institution autocomplete from org.gov2.ro

The source is the org.gov2.ro data: `~/devbox/gov2/institutii-publice/data/consolidated/institutii.csv`. It has 25,154 rows. It is consolidated 2026-10-27 and licensed under EUPL v1.2.

**Snapshot script** `ops/build-institutii-snapshot.py` (stdlib only):
- Input: the CSV path (`--from`).
- Output: `webapp-php/static/institutii/<judet-slug>.json`, one file for each of the 42 counties, plus `index.json` (`{source, licence, consolidated_at, built_at, counts}`).
- Each row: `{id, name, cif, locality, address, phone, email, category}`. Use `uat` for the locality.
- Map the CSV `county` value through `webapp/apps/jobs/judete.py` `normalize_judet()`. Count the rows that do not map, and print them. Do not drop them silently.
- Leave out rows with no name. Keep the CSV order.
- Commit the generated files. A rebuild is the "sync with org.gov2.ro" until the org.gov2.ro work (item 8) changes the source.
- Report the total size of the files, raw and gzipped.

**UI.** In section 1, put the county select first, then the name.
- When a county is chosen, load `static/institutii/<slug>.json` once, on demand.
- The name field suggests matches as the user types: fold diacritics and case, match all words, show at most 8. Use a DS combobox if `COMPONENTS.md` has one. Otherwise build an accessible listbox (`role="combobox"`, arrow keys, Enter, Escape).
- Picking an institution fills name, CUI, address, locality, phone and e-mail. It also keeps `category` for item 4. Every filled field stays editable.
- Free text is always allowed. The autocomplete never blocks typing.
- Under the field: „Date despre instituții: org.gov2.ro (EUPL), actualizate la <consolidated_at>.”
- A link „Instituția lipsește sau are date greșite?” opens a new GitHub issue in `gov2-ro/institutii-publice`. Prefill the title and body from the typed values (`/issues/new?title=…&body=…`). Nothing goes to a server from the page itself.
- The suggestions never send the typed text anywhere. Loading a county file is the only request.

### 8. Not in this brief: matching employers with org.gov2.ro

pax asked to match the ~5,900 posturi employers with org.gov2.ro, and to propose the employers that have no match as new institutions. That work is in the org.gov2.ro repo: [institutii-publice brief](../../../institutii-publice/docs/briefs/posturi-employers-source.md). The posturi part is one export script, and it belongs to that brief. Do not build it here.

### 9. Backlog and activity text

Put the text in your report. Do not edit `docs/backlog.md` or `docs/activity-log.md`. Include:
- templates with common choices (item 4);
- the official list of fields of study (item 6);
- measuring how often `work_localities` is filled in v5 extractions on the VPS (item 5).

## Verification (report each result)

1. Playwright at 320, 375 and 1280 px:
   - the sidebar is sticky at 1280; the bottom bar shows at 375; there is no horizontal overflow;
   - a new draft has the defaults; an old draft opens unchanged;
   - each conditional field hides and shows, and a hidden value is absent from the payload;
   - chips: a suggestion adds a chip with an `isced_field`, free text adds a chip with `null`, and a chip can be removed;
   - autocomplete: choose Alba, type „ciugud”, pick a suggestion; the section fills; the category ticks `certificat_integritate` only for the three categories.
2. The existing `employer_builder.spec.js` and `test_employer_payload.py` still pass. Update a test only where this brief changes the behaviour, and name each change.
3. The weight-sum test (item 3) passes in Python and in JS.
4. The snapshot script: the row count, the unmapped counties, and the file sizes.
5. All PHP suites pass. `check-skins.php` reports no new problems. Run `npm run css` and commit `static/app.css`.

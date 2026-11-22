# Brief — Draft figure as a last resort, and the limits on `/despre/` (SALARY-LABELS-LIMITS)

Date: 2026-11-20. Author: Opus. Builder: Sonnet. Backlog: SALARY-LABELS-LIMITS (under SALARY-R2); it closes DATA-SALARY-STALE-COPY. ADR: `docs/decisions/0008-salary-two-baselines.md`, rules 2 and 4 (changed by the owner on 2026-11-20).

## Why

Nothing guarantees that the draft salary law passes. The draft figure stays only for two jobs:

- as the last resort for search, when a posting has no stated salary and no figure under the law in force (ADR 0008 rule 4(c): 112 postings in the local copy of 9 Sep 2026; the VPS number is different);
- as a side note on the posting page.

The site does not say this today. The detail page says „Conform legii salarizării (propunere)” next to a figure of the law in force. `/despre/` does not list what the estimate cannot know.

## Where the data is

The local Postgres is a copy of 9 Sep 2026. The numbers above come from that copy. **The live data is on the VPS** (`gov2-1`, checkout `/home/pax/g2-dev/posturi.gov.ro`). The Mac has no SSH to it: reach it through the owner's Remote Control session (`posturi-vps`; find it with ListAgents).

- Build and test the code on the Mac, against the local copy.
- Measure on the VPS: ask the VPS session to run the read-only commands (`estimate-salaries.py --dry-run`, the classify script). The VPS session blocks Postgres reads until the owner approves them **in that session**. Approval here does not carry over.
- Each fix to the data (for example, the `grid_selector` of an occupation) is a committed, re-runnable script or a `--flag` of an existing script. It runs on the VPS after a code deploy, then `estimate-salaries.py` runs there. Never edit a VPS row by hand.
- The Mac deploys code only (`./deploy-php.sh --code-only`). The owner runs the deploy. The data reaches the site with the next VPS pipeline run.

## Read first

- ADR 0008 (all rules, and the consequences of 2026-11-20).
- The backlog item DATA-SALARY-STALE-COPY: it lists the texts that are wrong today.
- `export-to-sqlite.py` → `_salary_columns()`.
- `webapp-php/pages/detail.php` (the salary blocks, near lines 540–680), `webapp-php/pages/about.php` (section `salarii`, lines 64–113), `list_salary()` and `SALARY_EXPR` in `webapp-php/helpers.php`, `webapp-php/pages/stats.php` near line 223, the feeds.
- The palette rule in `CLAUDE.md`, and `COMPONENTS.md` of the DS.

## Steps

1. **Export.** `_salary_columns()` adds three columns: `sal_baza` (`lege_in_vigoare` or `proiect`; empty for an old row, which counts as `proiect`), `sal_draft_min` and `sal_draft_max` (the `proiect` block). The sort order, the filter and `SALARY_EXPR` do not change.
2. **Last-resort label.** When `sal_baza` is `proiect`, each place that shows the figure says „estimare aproximativă”: the list row, the grid card, the table, the detail page and the feeds. The tooltip, or the note on the detail page, says: „Legea în vigoare nu are un rând pentru această funcție. Cifra vine din proiectul noii legi a salarizării, care nu este adoptat.”
3. **Side note on the detail page.** When `sal_baza` is `lege_in_vigoare` and a draft figure exists, add one muted line under the figure: „Dacă proiectul noii legi ar fi adoptat: ~X lei net (brut Y lei).” Link it to `/estimator-salariu/`.
4. **Wrong texts.** Fix each text of DATA-SALARY-STALE-COPY, except the `/despre/` paragraph that it marks as owner text. Step 5 replaces that paragraph. The detail line `data-pay-law` says „Estimare după Legea 153/2017” for a law figure, and „estimare aproximativă, după proiectul de lege” for a draft figure.
5. **`/despre/`.** Replace the paragraph „Proiectul de lege” with the text in § Proposed text. Add the anchor `#ce-nu-stim`. **The owner reviews the text before the deploy.** Use the text as it is. If a sentence does not fit the page, mark it in the report; do not rewrite it. {X} is the median gap: ask the VPS session for it on the live data (14% on the 9 Sep copy).
6. **Numbers.** The numbers in the text come from the export, at build time (`about.php` reads them, or the export writes them to a meta table). Do not type them into the page. If this is more than a small change, use the numbers as text, and add a backlog item.
7. **Tests.** The Playwright specs that touch the detail page, the list and `/despre/` pass at 320, 375 and 1280 px. Add one check for each of steps 2 and 3.

## Proposed text (owner reviews)

*Version 2, 2026-11-22: Opus edits after the declared-pay work. {X} is the median gap on the live VPS data (14% on the 9 Sep copy). {Dc} counts `baza = declaratii`. Changed: the project-hire sentence, the declared-pay count and its explanation.*

> **Proiectul de lege.** Un proiect de lege din iulie 2026 propune alte salarii. Proiectul nu este adoptat și poate să nu fie adoptat niciodată. Site-ul folosește cifra din proiect numai în două cazuri. Primul: când legea în vigoare nu are un rând pentru funcție. Atunci cifra din proiect ține anunțul în filtrul „Salariu” și apare ca „estimare aproximativă”. Al doilea: pe pagina anunțului, ca notă. Acolo unde există ambele cifre, cifra din proiect este în medie cu {X}% mai mare.
>
> **Ce nu putem ști** {#ce-nu-stim}
>
> Unele lucruri nu apar în nicio sursă publică pentru un anunț. De aceea estimarea nu le include:
>
> - **Sporurile.** Angajatorul le acordă după regulamentul lui și după condițiile locului de muncă. Legea limitează totalul sporurilor la 30% pentru toată instituția, nu pentru o persoană.
> - **Salariul stabilit de consiliul local.** În primării și în serviciile lor, consiliul stabilește salariul. Legea dă numai plafonul. De aceea site-ul arată un interval.
> - **Salariul colegilor.** Un angajat nou poate primi salariul colegilor care au aceeași funcție. Unele instituții plătesc peste tabelul legii.
> - **Persoana angajată.** Vechimea, persoanele în întreținere și scutirile de impozit schimbă salariul. Site-ul calculează la gradația 0, fără persoane în întreținere. Estimatorul de salariu are o opțiune pentru vechime.
> - **Armata, poliția și serviciile de informații.** Instituția stabilește coeficientul fiecărei funcții, între limitele legii. Multe dintre aceste ordine nu sunt publice.
> - **Angajatorii care nu aplică Legea 153/2017.** Companiile de stat, BNR și ASF au alte reguli. Pentru posturile dintr-un proiect, cifra este a funcției celei mai apropiate din grilă.
> - **Potrivirea titlului.** Site-ul alege funcția din grilă după titlul anunțului. Un titlu general, de exemplu „Referent”, poate corespunde mai multor funcții.
>
> **Acoperirea.** Din {N} anunțuri, {A} au salariul anunțat, {Dc} au salariul din declarațiile de avere ale colegilor, {L} au o estimare după legea în vigoare, {D} au o estimare aproximativă după proiect, iar {F} nu au nicio estimare. Cifra din declarații este salariul net declarat de colegii de la același angajator, pe aceeași funcție, adus la nivelul anului 2026.

## Done when

- Steps 1–7 are done, and the test suite passes.
- The report lists each changed text, with before and after.
- Screenshots of the detail page (a law figure with a side note; a draft figure) and of `/despre/#ce-nu-stim`, at 375 px and 1280 px.
- The coverage numbers on `/despre/` come from the VPS export, not from the local copy.
- The activity log and the backlog are updated. DATA-SALARY-STALE-COPY is `[x]`.

## Stop and ask

- If a label needs a new DS component or a new token.
- If a step changes the sort order, the filter or the keys of `salary_bucket`.
- If the owner text of § Proposed text does not fit the page.

# Audit — pay of the PIDS project teams (SALARY-PIDS)

Date: 2026-11-22. Brief: `docs/briefs/2026-11-20-salary-pids.md`. Spike only. No code, grid, ADR or database row changed.

## 1. Result

**No public document that I found fixes the gross pay of a team role.** The documents fix a budget ceiling for each commune, not a pay for each role. The step 4 count on the VPS is pending (see "For the VPS session").

## 2. Documents found

Files are in `tmp/261120/pids/` (not in git), with URLs and dates in `sources.md`.

| Document | URL | Pay content |
|---|---|---|
| Procedura cadru de selecție a UAT rurale (comune), PIDS/586/PO4/339395, ed. 1 rev. 0, Feb 2025 | https://primariaboiumare.ro/wp-content/uploads/2025/03/PROIECT-EUROPEAN-Procedura-cadru-selectie-UAT-uri-proiect-SCI-2000.pdf | One ceiling per commune: „Cheltuieli pentru pachetele salariale membrii echipei comunitare integrate — 914,123.84 lei/UAT”. No role, no period, no work time. |
| Fișa sintetică a proiectului (SCI 2000) | https://www.edu.ro/sites/default/files/FISA-SINTETICA-PROIECT-PoIDS_339395_SCI2000.pdf | Total eligible value 4,056,702,255.30 lei; 60 months (Jan 2025 – Dec 2029); 2,000 teams. No pay. |
| Comunicat de presă, lansare SCI 2000, Oct 2025 | https://www.edu.ro/sites/default/files/Comunicat_presa_Conferinta_lansare_SCI2000_octombrie_2025.pdf | No pay. |
| Ghidul solicitantului, Condiții generale PIDS (Mar 2023) | https://primariahereclean.ro/wp-content/uploads/2025/06/GS-CG_PIDS.pdf | Net hourly ceilings (section 3.4) for project management and implementation experts only: 70 / 80 / 90 lei per hour for under 5 / 5–10 / over 10 years of experience. See below. |

I did not obtain the Condiții specifice guide of the call (AP4 „Sprijinirea comunităților rurale fără acces sau cu acces limitat la serviciile sociale”, Jan 2025). The ADR Centru copy answers HTTP 403 (Cloudflare). The mfe.gov.ro page was not reached by search. The Protocol de colaborare (Anexa 6 of the procedure, in the same file) holds no pay either. The financing contract model and the budget methodology were not found. **This is the main gap.** The Condiții specifice guide may hold more.

## 3. Pay rules read

- **Team.** Social worker, tehnician asistență socială, asistent medical comunitar, mediator sanitar (communes with at least 700 Roma), school mediator, consilier școlar and others, per commune. The procedure does not list one pay for any of them.
- **Ceiling.** 914,123.84 lei per commune for all „pachete salariale”. The document gives no number of members, no months and no gross/net basis. A monthly pay for a role cannot be derived from it. I did not try.
- **General guide, section 3.4.** The ceilings are net per hour. They apply to project management and „experți implementare activități”, and they apply only to staff outside the organigram. Staff in the organigram of a public institution are paid under **Legea 153/2017**: „se aplică prevederile Legii nr. 153/2017”. The community-team members are municipal staff (job postings use the contractual-staff status and „Funcția conform căreia se face încadrarea salarială (Conform legislației)”, posting 12947). So the law grid, which ADR 0008 already uses (`lege_in_vigoare`), is the right reference, not a project rate.
- **Manager proiect.** The 70/80/90 lei net per hour applies to the project manager of an applicant under the general guide. It is a ceiling by experience, for a project the commune applies for. I did not confirm that the SCI 2000 communes follow it: in this project the communes sign a protocol and do not apply. Do not use it without the Condiții specifice guide.

## 4. Local counts (copy of 9 Sep 2026)

PIDS = text of `body_markdown` + `attachment_text` matches `PIDS|servicii integrate`.

| Occupation | Postings | PIDS | PIDS, other wording only | PIDS with `lege_in_vigoare` figure | PIDS with no figure (`baza: proiect`) | Not PIDS, no figure |
|---|---|---|---|---|---|---|
| Asistent medical comunitar | 293 | 194 | 1 | 190 | 4 | 3 |
| Consilier școlar | 216 | 175 | 2 | 147 | 28 | 7 |
| Tehnician asistență socială | 41 | 20 | 1 | 7 | 13 | 14 |
| Manager proiect | 29 | 18 | 3 | 0 | 18 | 11 |
| Mediator sanitar | 18 | 7 | 0 | 6 | 1 | 3 |
| **Total** | 597 | 414 | 7 | 350 | **64** | 38 |

„Other wording” = text matches `SCI ?2000|echipe? comunitar[ae] integrat|incluziune și demnitate|339395|PIDS/586` but not `PIDS|servicii integrate`.

Finding: the brief assumed no pay for these postings. In the copy, 350 of 414 PIDS postings **already have a figure** (`lege_in_vigoare`, from the occupation row after SALARY-UNMATCHED). Only 64 have none, 18 of them Manager proiect (no grid row).

Pay stated in the text: a regex for „salariu|salarizare|remunerare + 4 digits” hits 4 PIDS postings in total, none a pay rule. The word „salarizare” is a list item or an address („Salarizarea. Timpul de muncă…” in the legal bases). No PIDS posting cites a standard cost. 4 Manager proiect postings cite Legea 153/2017.

## 5. Proposal

1. **Do not add a PIDS `baza`.** No document fixes a pay for a role, so there is no figure to show. A new `baza` would show a number with no source (ADR 0008 rule: a figure needs a public source).
2. **Keep the law grid for the team roles.** The documents say organigram staff follow Legea 153/2017. This is what `lege_in_vigoare` already does for 350 of 414 PIDS postings.
3. **Label (optional, small).** For PIDS postings, add a note on the detail page: „Post finanțat din proiectul PIDS «Furnizare de servicii integrate în comunitățile rurale». Salarizarea urmează Legea 153/2017.” Detection: the text matches `PIDS/586|servicii integrate în comunitățile rurale|echipe? comunitar[ae] integrat` (7 postings carry the project only in other words; the bare `PIDS` token can match other PIDS programmes, check on the VPS).
4. **Postings that gain a figure: 0 from a PIDS rule.** The 64 with no figure are rows without a grid row: Manager proiect (18), Tehnician asistență socială (13; the law row was added 2026-11-22, so the VPS count is probably lower), Consilier școlar (28), others (5). Fix them in the grid work (SALARY-UNMATCHED, DATA-04), not here.
5. **Next step if the owner wants a project figure.** Get the Condiții specifice guide (AP4) from mfe.gov.ro or a commune that attached it to its HCL, and look for a standard cost per role. Ask the MMFTSS (the project leader) for the budget annex of the financing contract.

## 6. Assumed

- PIDS detection by `PIDS|servicii integrate` over body and attachment text, as in the brief. The bare token can match other programmes; not checked.
- „No figure” = `salary_estimate->>'lei_min'` is null. The local estimates date from the 9 Sep copy, before the fixes of 20–22 Nov, so the 64 is an upper bound.
- Hourly ceilings of the general guide do not apply to community staff on municipal payroll. This reads the general guide, not the Condiții specifice.

## For the VPS session

Read-only. Run in `psql` on the live database. The Opus session relays the output.

```sql
-- A. By occupation: all, PIDS, PIDS other wording only, figure/no figure
WITH t AS (
  SELECT o.canonical AS occ,
         p.salary_estimate AS se,
         coalesce(p.body_markdown,'') || ' ' || coalesce(p.attachment_text,'') AS txt
  FROM jobs_jobposting p
  JOIN jobs_occupation o ON o.id = p.occupation_id
  WHERE o.canonical IN ('Asistent medical comunitar','Consilier școlar',
        'Tehnician asistență socială','Manager proiect','Mediator sanitar')
)
SELECT occ,
  count(*) AS total,
  count(*) FILTER (WHERE txt ~* 'PIDS|servicii integrate') AS pids,
  count(*) FILTER (WHERE txt ~* 'SCI ?2000|echipe? comunitar[ae] integrat|incluziune și demnitate|339395|PIDS/586'
                   AND txt !~* 'PIDS|servicii integrate') AS other_wording_only,
  count(*) FILTER (WHERE txt ~* 'PIDS|servicii integrate' AND se->>'lei_min' IS NOT NULL) AS pids_with_figure,
  count(*) FILTER (WHERE txt ~* 'PIDS|servicii integrate' AND se->>'lei_min' IS NULL) AS pids_no_figure,
  count(*) FILTER (WHERE txt !~* 'PIDS|servicii integrate' AND se->>'lei_min' IS NULL) AS not_pids_no_figure
FROM t GROUP BY occ ORDER BY total DESC;

-- B. PIDS postings with no figure, by occupation and baza
SELECT o.canonical, p.salary_estimate->>'baza' AS baza, count(*)
FROM jobs_jobposting p JOIN jobs_occupation o ON o.id = p.occupation_id
WHERE (coalesce(p.body_markdown,'') || ' ' || coalesce(p.attachment_text,'')) ~* 'PIDS|servicii integrate'
  AND p.salary_estimate->>'lei_min' IS NULL
GROUP BY 1,2 ORDER BY 3 DESC;

-- C. How many PIDS-matching postings (any occupation) use only the bare token PIDS
SELECT count(*) FILTER (WHERE txt ~* 'PIDS/586|servicii integrate în comunitățile rurale|echipe? comunitar[ae] integrat') AS project_specific,
       count(*) FILTER (WHERE txt ~* 'PIDS|servicii integrate') AS broad
FROM (SELECT coalesce(body_markdown,'') || ' ' || coalesce(attachment_text,'') AS txt FROM jobs_jobposting) s;
```

# Explainer — pay from the asset declarations (ADR 0008, rule 9)

Date: 2026-11-20. Author: Opus. Status: rule 9 accepted by the owner (2026-11-20); this copy is the draft for the build. Data: `docs/audits/2026-11-20-salary-declaratii-kibana.md`.

The reader sees a figure that comes from the asset declarations. The explainer says, in four short steps, where the figure comes from and how sure we are. Romanian copy follows the project rule: short sentences, active voice, one term for each concept.

## Terms (one word, one meaning)

| Concept | Romanian term | Do not use |
|---|---|---|
| The figure of rule 9 | „estimare” | „prognoză”, „calcul” |
| The yearly income line | „venitul din salariu” | „câștig”, „remunerație” |
| The source | „declarațiile de avere” | „declarații ANI”, „DA” |
| The group | „angajați cu funcția X la Y” | „eșantion”, „cohortă” |
| The p25–p75 band | „cei mai mulți primesc între” | „interval de încredere”, „IQR” |

## Variables

| Variable | Meaning | Example |
|---|---|---|
| `{n}` | declarants in the group | 37 |
| `{functie}` | the job title of the group, as the declarations write it | Inspector superior |
| `{institutie}` | the employer, short display name | Primăria Municipiului Iași |
| `{mediana_2023}` | the group median, net per month, 2023 | 5.950 |
| `{crestere}` | (factor − 1), as a whole percent | 15 |
| `{estimare}` | median × factor, net per month, rounded to 50 lei | 6.850 |
| `{brut}` | the gross that gives `{estimare}` net (`net_from_gross()` inverse), rounded to 50 lei | 11.700 |
| `{p25}`, `{p75}` | p25 and p75 × factor, net per month, rounded to 50 lei | 6.200, 7.600 |
| `{eroare}` | the backtest median error of the family, whole percent | 6 |

Round every lei figure to 50 lei. More digits suggest a precision that we do not have.

## 1. List row

> **≈ {estimare} lei net** · după declarații de avere

The label „după declarații de avere” replaces „după grila legii”. It links to the explainer on the detail page.

## 2. Detail page: the figure

> **≈ {estimare} lei net pe lună** (circa {brut} lei brut)

The line „la gradația 0” of the grid figure does not apply here. Do not show it for `baza: "declaratii"`.
> Cei mai mulți primesc între {p25} și {p75} lei net.
> [Cum a fost calculat ▾]

## 3. The explainer

The explainer goes in the disclosure „Cum a fost calculat ▾” that the detail page already has (`webapp-php/pages/detail.php`). It is not a new pop-up or modal.

> **Cum am estimat salariul**
>
> 1. **Ce au declarat.** În 2023, {n} angajați cu funcția {functie} la {institutie} au trecut venitul din salariu în declarațiile de avere. Valoarea din mijloc a fost {mediana_2023} lei net pe lună.
> 2. **Cât au crescut salariile.** Din 2023 până în 2026, salariul mediu net în administrația publică a crescut cu {crestere}% (INS).
> 3. **Estimarea pentru 2026.** Aproximativ {estimare} lei net pe lună. Cei mai mulți primesc între {p25} și {p75} lei net.
> 4. **Cât de sigur este.** Am verificat metoda pe anii trecuți. Am estimat salariile din 2023 pornind de la cele din 2022. Eroarea tipică a fost de {eroare}%.
>
> **Ce nu știm**
> - Salariul tău depinde de vechime și de sporuri. La angajare, primești de obicei mai puțin decât valoarea din mijloc.
> - Declarația arată venitul pe tot anul, cu prime și vouchere. Datele cele mai noi sunt din 2023.
>
> **Surse:** declarațiile de avere, prin monitorul.ai/integritate; INS, seriile FOM106D și FOM106G. [Metoda pe larg →]

Example, with the numbers of the table above (invented numbers, only to check the length):

> 1. În 2023, 37 de angajați cu funcția Inspector superior la Primăria Municipiului Iași au trecut venitul din salariu în declarațiile de avere. Valoarea din mijloc a fost 5.950 lei net pe lună.
> 2. Din 2023 până în 2026, salariul mediu net în administrația publică a crescut cu 15% (INS).
> 3. Aproximativ 6.850 lei net pe lună. Cei mai mulți primesc între 6.200 și 7.600 lei net.
> 4. Am verificat metoda pe anii trecuți. Am estimat salariile din 2023 pornind de la cele din 2022. Eroarea tipică a fost de 6%.

Notes for the copy:

- Romanian puts „de” after a number whose last two digits are 00 or 20–99 („37 de angajați”, „105 angajați”, „5 angajați”). The template must add it.
- Step 1 says „valoarea din mijloc”, not „mediana”. Readers know the first; few know the second.
- Step 4 gives the error of the method in one number. The p90 (15–20 %) goes to the method page, not to the pop-up.

## 4. The method page („Metoda pe larg”)

One page for all figures of rule 9. It holds, in plain words:

- what the declarations are, and why they show net pay;
- how we choose one income line from a declaration, and why we leave out the other lines (vouchers, back pay, a second job);
- the minimum of 5 declarants, and that we publish no name and no single declaration;
- the factor from INS, with its table (2022 to 2026, section O), and why we do not use the law alone (the cuts of 2025);
- the backtest table by family;
- the families that get no figure, and why (magistrates, court staff, health, education, elected officials, EU projects).

The audit note has all the numbers. The page must not add new ones.

## Defaults (Opus, 2026-11-20; the owner can change them)

- `{institutie}` is the employer name of the posting, because the reader knows that name.
- „Metoda pe larg” is a section of `/estimator-salariu/` (`webapp-php/pages/salary_estimator.php`) with its own anchor, because `/salarii/` does not exist yet. It moves to `/salarii/` when that page exists.

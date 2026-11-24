# Spec: „Posturi interesante”, filters from the statistics (UX-INTERESTING)

Date: 2026-11-24. Author: Opus. Status: decided (owner, 2026-11-24): phase A = filters 1, 3, 4; phase B = 2, 5; „Termen apropiat” is dropped.

## 1. Goal

Some statistical outliers are good news for a reader: high pay for low requirements, many vacancies, a well-paid entry post. The site already measures them. This spec turns them into filters, shows them as ready-made searches on the home page, and links the home page to `/anomalii/`.

A filter is a fact about a posting against its peers. It is never a judgement of the employer. Each filter has one sentence that says why a posting is in it.

## 2. The filters

One new filter parameter, `interes[]`, with one value per filter. Values combine with OR inside the parameter (as the other facets do) and with AND against the other filters.

| # | Value | Label (Romanian) | Rule | Phase |
|---|---|---|---|---|
| 1 | `salariu-cerinte-mici` | Salariu mare, cerințe mici | Pay level 5 of 5 (`pay_level()`, the quintiles of `SALARY_EXPR` over the open postings) **and** low requirements: no experience required, **or** the highest required study level is at most „medii” (liceu, postliceală, școală profesională, generală). | A |
| 3 | `multe-posturi` | Multe posturi la același anunț | The posting offers **5 or more** vacancies of the same post (`nr_posturi`, else `v5_vacancies_total`). A multi-role posting counts the vacancies of one role, not the sum (`posting_roles`). | A |
| 4 | `debutant-platit` | Debutant bine plătit | An entry title („debutant”, „stagiar”, „începător”; the same title test as anomaly check R12) **and** pay level 4 or 5. | A |
| 2 | `greu-de-ocupat` | Post republicat | The posting is in a repost chain: anomaly check E03 (same employer and title, published again 14–90 days later) has a signal for it. | B |
| 5 | `competente-rare` | Cerințe rare | Anomaly check E05 has a signal for the posting. Shown with its caveat: „Semnal nerevizuit de o persoană.” | B |

Thresholds (level 5, level 4, 5 vacancies) are first values. The builder measures the counts (§ 6) and reports; the owner may change a threshold before release.

### 2.1 Why a posting is in a filter

Each value has a sentence, shown on the detail page and as the `title` of the chip:

1. „Salariu în treapta 5 din 5 față de posturile active, fără experiență cerută” (or „…, cu studii medii”).
2. „Același angajator a publicat același titlu și pe {data}.”
3. „{N} posturi la același anunț.”
4. „Post de debutant cu salariu în treapta {4|5} din 5.”
5. „Cerințele sunt rare în grupa de ocupații. Semnal nerevizuit de o persoană.”

## 3. Where the filters show

- **List page, filters:** one group „Posturi interesante”, with the five values (phase B adds two) and their counts, as the other facet groups. The phase B values show only when the export has the anomaly tables.
- **Chips:** the active filter shows as a removable chip, as the other filters.
- **Detail page:** under the salary box, one line per matching filter: „Interesant: {label} — {sentence}”, with a link to the filter.
- **Home page, ready-made searches** (`$combos` in `pages/home.php`, the cards under the hero): add one card per filter, after „Primul job: fără experiență cerută”. A card shows only when its count is above 0 (as today).
- **Home page, card size:** all the ready-made search cards use the base font size (`text-base`) instead of the title size of `g2-link-title`, keeping the link look and the 44 px target. The count stays smaller than the title. Do not change the `.g2-link-title` class (DS); add a project class or utilities.
- **Home page, link to the anomalies:** after the cards, one line: „Vezi și: [anomalii și abateri statistice](/anomalii/)”.

## 4. Data

- Phase A needs no new export column. The rules run in SQL in `build_filters()`: `SALARY_EXPR` against the quintile limits of `pay_quintiles()`, the study level of `posting_roles`, the vacancy columns, and the title test. Put the title test in one PHP helper with the same word list as R12 (`ops/anomaly-survey.py`), and say in a comment that the two must agree.
- Phase B reads `anomaly_signals` (`EXISTS … check_id = 'E03'`), with the `false_positive` exclusion of the anomalies page. An export without the tables: the two values do not show.
- Facet counts use the same predicates (one definition per value, as `SALARY_EXPR` is defined once).

## 5. Out of scope

- No ranking or score of postings.
- No change to the anomaly checks or their thresholds.
- „Termen apropiat” (short deadlines) is dropped (owner).
- No new filter on the anomalies page.

## 6. Measures before release

On the latest local copy with the pay columns, and again by the VPS session on the live export:

- the count of open postings for each value, and for each threshold variant: pay level 4 vs 5 for (1); 3, 5 and 10 vacancies for (3);
- 10 postings of each value read by hand: does the sentence hold, is the posting what the label says;
- the overlap between values (a posting in 1 and 4).

The owner reads the numbers and sets the thresholds. A value with fewer than 5 open postings stays in the filter but not on the home page.

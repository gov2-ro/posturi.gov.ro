# Brief: cercetare juridică — indemnizația de hrană, gradațiile și alte drepturi bănești (2026)

**Data:** 2026-11-23 · **Autor:** Opus (sesiunea posturi-dev-2311-2) · **Executant:** agent de cercetare
**Ieșire:** `docs/audits/2026-11-23-salary-law-research.md` · **Fără modificări de cod.**

## De ce

Pagina unui anunț va afișa salariul ca interval: de la gradația minimă la gradația 5, plus indemnizația de hrană. Filtrul de salariu va folosi același interval. Owner-ul a aprobat (2026-11-23):

1. Intervalul merge de la gradația X la gradația 5. X vine din experiența cerută: 3 ani → g1, 5 → g2, 10 → g3, 15 → g4, 20 → g5.
2. Indemnizația de hrană intră în total și în filtru.
3. Aceeași regulă se aplică și salariului estimat, nu doar celui anunțat.

Pe VPS, doar 6 din 1.644 de anunțuri active menționează o sumă pentru hrană (347 lei). Deci aplicăm indemnizația după lege, nu după textul anunțului. O primă căutare a arătat un plafon de 6.000 lei net din 2026 (Legea 141/2025). Sursele au fost articole de presă, nu textul legii. Acum avem nevoie de textul legii.

## Întrebări

Pentru fiecare răspuns: citatul exact, actul, articolul, alineatul și data de la care se aplică.

### A. Indemnizația de hrană (Legea 153/2017, art. 18)

1. Care este textul consolidat în vigoare azi? Ce acte au modificat art. 18 din 2018 până azi (de ex. OUG 114/2018, Legea 141/2025, OUG-urile anuale de buget)?
2. Ce sumă se acordă în 2026? Este fixă (347 lei) sau o formulă (1/12 din două salarii minime)? Până când se aplică suma fixă?
3. Plafonul de net: ce valoare are, din ce dată? **Ce intră în „salariul net”**: doar salariul de bază, sau și gradația, sporurile și alte drepturi? Cum se tratează cumulul de funcții și fracțiunea de normă?
4. Este obligatorie („se acordă”) sau „în limita bugetului”? Diferă pentru autoritățile locale?
5. Cine este exclus? Numiți categoriile cu „alte drepturi de hrană prin legislație specifică” (militari, polițiști, penitenciare, alții?).
6. Se acordă personalului contractual și funcționarilor publici la fel? Dar personalului angajat pe proiecte cu fonduri europene sau PNRR?
7. Pentru învățământ: ce ordin există, ce spune, ce net se compară cu plafonul?
8. Cum se impozitează indemnizația (impozit, CAS, CASS)? Contează la calculul netului pentru plafon?
9. Dosarul CCR 57D/2026 despre art. 18 alin. (1): stadiul, data termenului, decizia, dacă există.

### B. Gradațiile (Legea 153/2017, art. 10)

1. Textul în vigoare: procentele pe gradații (7,5 / 5 / 5 / 2,5 / 2,5 %?) și tranșele de vechime.
2. Procentele se aplică în cascadă (fiecare la salariul gradației anterioare) sau la salariul de bază de la gradația 0?
3. Ce vechime contează: vechimea în muncă totală sau vechimea în specialitate? Pentru funcționarii publici și pentru contractuali este la fel?
4. Măsurile de înghețare din 2025–2026 (OUG, Legea 141/2025) opresc trecerea la o gradație superioară, sau doar creșterile salariului de bază?

### C. Alte drepturi bănești generale

1. Voucherele de vacanță: se acordă în 2026? Ce sumă, cui?
2. Mai există un drept bănesc pe care îl primește aproape tot personalul bugetar, indiferent de post? Numiți doar drepturile generale, nu sporurile specifice unui post.

### D. Calculul netului în 2026

1. Cotele CAS, CASS și impozit pentru un salariat bugetar, plus deducerea personală. Dați formula și un exemplu: 7.870 lei brut, la gradația 0 și la gradația 5.

## Surse

- **Primare (obligatoriu):** legislatie.just.ro (forma consolidată și actele modificatoare), Monitorul Oficial, ccr.ro.
- **Secundare (doar ca indiciu):** presa și site-urile de specialitate (edupedu, juridice.ro, manager.ro). Nu folosiți o sursă secundară drept răspuns final. Verificați-o în sursa primară.
- Nu scrieți din memorie. Dacă nu găsiți sursa primară, scrieți „neconfirmat” și spuneți ce ați căutat.

## Format raport

1. **Rezumat** — maximum 10 rânduri.
2. **Răspunsuri** — pe fiecare întrebare: răspuns, citat, sursă (link), încredere (sigur / probabil / neconfirmat).
3. **Reguli pentru calcul** — un tabel pe care îl putem implementa:
   `regulă | condiție | valoare | de la | până la | sursă`.
4. **Ambiguități** — ce legea nu spune clar și ce variante există.
5. **Ce ați căutat și n-ați găsit.**

## Limite

- Nu modificați cod, date, ADR-uri sau backlog.
- Nu trageți concluzii de produs: raportați legea, iar Opus decide.
- Opriți-vă și întrebați dacă o întrebare cere o sursă cu plată sau cu autentificare.
- Folosiți româna în raport. Aplicați principiile STE: propoziții scurte, diateza activă, un termen pentru fiecare concept.

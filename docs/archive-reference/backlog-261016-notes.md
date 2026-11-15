# 261016 notes — preserved before triage

Moved from `docs/backlog.md` on 2026-11-15. The open items are now QUAL-01, DATA-11, DATA-QA-01 and UX-17 in the backlog, or notes on BETA-V5, UX-07 and DATA-ORG-06. The text below is unchanged.

## 261016 Notes
### UX & Data quality, Filters & classifications
*2026-10-17 (commits `c3c45b5`…`11decdc`, not deployed). The new filters read columns the next
VPS export writes (classify.py); until then the live site shows them empty or shimmed.*
- [x] filter, ocupație, could be hierarchical, tree shaped? — *"Grupă ocupațională": the COR / ISCO-08 tree (major › sub-major › minor for science, health, teaching, law/culture), from the occupation dictionary's code, a clear-majority code for the same title, or a prefix fallback; 98% of the dev copy has a group.*
- [x] 'funcție publică' on VPS, weird results. same for "funcție militară". what does "funcțîe contractuală" even mean? as opposed to? — *the source tag is the institution's free pick (muncitori filed as "Funcție publică"). "Tip funcție" now reads the regime off the title's grade (clasa I–III, grad) and the hiring wording; "Categorie" dropped (param still works). Publică = funcționar public (Codul administrativ, clasă + grad); contractuală = contract individual de muncă (Codul muncii, HG 1336/2022); militară = cadre militare. On the dev copy ~70% contractual, ~0.3% public, ~0.3% military — posturi.gov.ro carries few public-servant posts (those go through ANFP).*
- [x] program, ture, telemuncă should be merged — *one "Program de lucru" group.*
- [x] limbi străine, tree shaped — *language › CEFR level; "any foreign language" phrasings are one node.*
- [x] competențe move at the end, is mostly fluff. … rare ones? like GIS — *moved last; soft skills (half of all extracted skills) no longer in the facet, spellings merged; v5 prompt asks for named tools/systems/methods (takes effect with the v5 run).*
- [x] documente necesare, also move at the end.
- [x] allow all some filters to also be exclusive … 2 checkboxes? — *a "fără" switch beside each value in 15 groups (`?<group>_not[]=`), hidden until hover on a mouse, always shown on touch; chips "Fără: …".*
- [x] offer at top pre-searches: nu necesită calificare, nu necesită cazier? what else? — *added Fără calificare (COR 9), Studii generale, Normă parțială, Pot aplica?; no "fără cazier" (virtually every public post requires it).*
- [x] hide noi and closing soon - or better move it in the landing, below the archive. — *below the results, bare home page only.*
- [x] how do I find jobs if I am a doctor, what kind of doctor? Engineer, specific type of, etc? — *COR tree (Specialiști › Sănătate › Medici; Științe și inginerie › Ingineri …) plus "Specialitate medicală" (43 specialties from titles).*
- [x] 'telemuncă' remote=1 lists one medic specialist, one electrician … surely not remote? — *right: the old regex matched "Remote Sensing" (bibliographies), "hibridizare" and the telework law (Legea 81/2018) in reading lists — 3 of 22 were real. classify.work_mode() needs a phrase about how this job is done.*
- [x] we should also spot "hibrid" job regimes? — *work_mode = telemunca | hibrid; shown as "Telemuncă sau hibrid".*
- [x] finanțare: "nespecificat" doesn't improve filtering in any way.
- [x] fonduri europene: how are we detecting that? could there be more signals? like cod "smis" / mysmis. — *was the v4 LLM field only (2 of 9,756 on the dev copy). Now: SMIS codes, "finanțat prin PNRR / din fonduri europene", "în cadrul proiectului" + a programme name → 452 EU + 20 PNRR; PNRR is its own value.*
- [x] "sector angajator" should be higher in the list
- [x] we should be able to filter angajator with terms like: minister, primarie, scoala, spital, directii nționale, județene. what else is there? — *"Instituție": 18 kinds from the employer name (primării, spitale, școli, asistență socială, unități militare/poliție, universități, servicii publice locale, cultură, cercetare, sport, agenții, direcții, companii de stat, ministere, DSP/DSV/CAS, consilii județene, prefecturi, instanțe); 95% of postings. The source's level (local/județean/național) stays as "Nivel angajator".*
- [x] domeniu = tehnic includes șofer, paznic, … align to C.O.R. Maybe … models to do this, match COR? — *the COR tree above; no new model. A finer LLM COR match (6-digit) is possible later if the tree is not enough.*
- [x] why are we using url vars with no values? empty variables should be stripped. — *stripped from HTMX requests, the address bar, pagination and canonical URLs (and the default status=active).*

### Misc
- [x] add sort by salariu estimat
- [ ] LLM 
    - [ ] see what other fields might we be able to extract / enhance LLM info extraction. — *v5 (BETA-V5) waits for pax's notes CSV.*
    - [x] add few more extracted keywords along the likes of Funcții de execuție, Permanent. Up to 2-3 rows, but these keywords should what makes the job more special, figure out what's about — *2026-10-18, pax chose rarity-ranked facts (no AI): `classify.tag_candidates` + `rank_tags` — up to 4 tags, rarest first, none on more than 25% of postings (so Permanent / Execuție never show): Perioadă determinată / Până la …, Normă parțială, Ture, Telemuncă, Proiect UE / PNRR, Fără experiență, Studii generale, Doctorat, Post de conducere, Funcție publică, Permis auto, a medical specialty, a tool-like skill (GIS, SICAP, REGES), a language, N posturi. Live data: 68% of postings get at least one. Rows and the detail page show them; the auto meta keeps only grade, studies and experience.*
    - [ ] rank job posting quality. We should talk about the criteria. Counting filled fields. Explicit texts, less fluff? — *2026-10-18, pax: internal first; all four groups. `classify.quality`: 15 checks, 100 points (completeness 45, pay & terms 20, timing 20, clarity 15), scored only with an extraction; `/statistici#calitate` shows the distribution, per-check rates and the least/most complete employers. Live data: median 83; salary stated 2%, duties 22%, bibliography 62% — the checks near 100% (text, attachment, contact, calendar) barely discriminate and are the first to revisit. Open: decide on public display after a week of looking.*
- [x] show all filters explicitly (unbundle "mai multe filtre"). Salary, for example should not be hidden. Remove boxes around filters in "grilă mode" plain text is fine and more compact. 
- [x] remove boxes around post cards. And around post attributes. 
- [x] make each post more compact on mobile. Instead of "Salariu estimat" use 💰 - and add (estimat) at the end of the numbers. All following attributes inline, just after salary (inline with it). we can also do without published date.  
- [x] let's get rid of constrained content. Start from left side of the screen, and responsively fill the viewport with columns / cards, full width.
- [x] at începe cu offer a few more complex filters. Or at least the most offered jobs. Iin the future we might also fine tune this based on web analytics.
- [ ] manual data QA pass. — *pax, with the notes feature.*
- [x] in posts archive, link target should be the full box, not just the title.
- [x] offer archive - different db. Single stats page, both for active and past jobs. — *archive pages (UX-03) + /statistici history (FIX-09).*
- [x] show salariu net also?
- [ ] when finding structured data in posts, like https://posturi.gov.ro/joburi/asistent-medical-comunitar-168/ (CALENDAR DESFĂȘURARE CONCURS / CALENDARUL DE DESFĂȘURARE A CONCURSURILOR etc) render them as tables – whole site normalized/style. Look at all tables found in existing data (here or VPS) and see what types of tabelar format data is found, try to standardize. — *partly: the extracted calendar (v4/v5) is one normalised table on every posting that has it, the scraped calendar the fallback; body markdown tables share one style. Still open: turning prose calendars in bodies without an extraction into tables.*
- [x] hide the "active, termen în 7 zile, înscrieri închise, termen neprecizat buttons in landing. Not that useful. — *now a "Stare" select beside the sort.*
- [x] when opening that has "înscriere închisă" we should display a bold notice.
- [x] move cerinte structurate at the end of the post
- [x] pot aplica shouldn't be per posting, should be a tool, you complete your details and it filters jobs. A nice to have future tool could be upload your cv and see compatible jobs — *`/pot-aplica/`; the per-posting panel is off (one constant). CV upload under Later.*
- [x] let's show prices visually, compare to median, on a 1-5   $    scale — *five bars after the pay, quintiles of the open postings; said in words on hover / for screen readers and on the detail page.*

### Later
- [ ] create /llms.txt, then let ~/devbox/gov2/gov2.ro know, to update their llms.txt - or submit an issue to [gov2-ro/gov2-alpha-sunrise](https://github.com/gov2-ro/gov2-alpha-sunrise)
- [ ] match employers to ~/devbox/gov2/institutii-publice/ / https://github.com/gov2-ro/institutii-publice 
- [ ] upload your CV!

import json, sys, re
root='/Users/pax/devbox/gov2/posturi.gov.ro'
v4=json.load(open(root+'/models_config.json'))['prompts']['v4']
p=v4
def sub(old,new,count=1):
    global p
    assert old in p, old[:80]
    p=p.replace(old,new,count)
# 1. Part A: drop free-text fields that duplicate Part B verbatim; replace application_docs.
sub("""- `educationRequirements`: Required studies, verbatim, role-specific only.
- `experienceRequirements`: Required work experience, verbatim, role-specific only.
""","")
sub("""- `skills`: Competențe/abilități/cunoștințe, verbatim. Markdown bullets.
""","")
sub("""- `application_docs`: the dosar checklist. Include it even when standard. Markdown bullets.
""","")
sub("# Part A — descriptive fields (unchanged from v2)","# Part A — descriptive fields")
# 2. contract: drop remote_mode, add fte/end_date/post_status
sub("""- `hours_per_week`: number. `remote_mode`: `la_sediu` | `hibrid` | `telemunca`.
- `probation_months`: perioadă de probă. `shift_work`: true for ture/gărzi/weekend.""",
"""- `hours_per_week`: number, only when stated or computable from a stated daily/monthly figure ("84 ore/lună" → 19.5).
- `fte`: fraction of a full-time norm when the post is fractional: "0,5 normă" → 0.5, "supraveghetor de noapte-0,75 post" → 0.75, "4 ore/zi" → 0.5. Null for a full norm or when not stated.
- `end_date`: ISO date when a fixed-term post states its end ("până la data de 30.06.2027" → `2027-06-30`).
- `post_status`: `vacant` | `temporar_vacant` — only from the post's own description ("post temporar vacant"), never from the generic phrase "ocuparea unui post vacant sau temporar vacant".
- `probation_months`: perioadă de probă. `shift_work`: true for ture/gărzi/weekend/night work, false only when the posting says so, else null.
- Never infer a remote/on-site mode: there is no such key.""")
# 3. employer_context: drop parent_institution
sub("""- `parent_institution`: the ministry or authority the employer answers to, when the posting makes it knowable. `Unitatea Militară 01042` → `Ministerul Apărării Naționale`. `Inspectoratul Școlar Județean` → `Ministerul Educației`. `Direcția de Sănătate Publică` → `Ministerul Sănătății`. A primărie answers to no ministry: `null`. Do not guess for an employer you cannot place.
""","""- `subordinated_to`: the authority the employer says it is subordinated to, ONLY when the text states it ("din subordinea Consiliului Local al orașului Slănic-Moldova" → "Consiliul Local Slănic-Moldova"). Never from your own knowledge: a county hospital is not "Ministerul Sănătății" unless the posting says so.
""")
# 4. note_suplimentare tightened
sub("""Markdown bullets. Null when the structured fields already cover the posting — this is a safety net for what the schema drops, not a summary of it. Never repeat the dosar checklist, the bibliography or the boilerplate here.""",
"""Markdown bullets, at most 3. Null in most postings. Never restate anything another key holds (duration, fraction of norm, temporary vacancy, number of posts, place of the exam, where the bibliography is displayed, audio-video recording, "probele sunt eliminatorii", submission channels) — those are structured now or are noise.""")
# 5. calendar: undated rows, verbatim cap
sub("""  - Skip rows whose label is a layout artefact (`–`, `•`, an empty cell) rather than an event. Do not emit an event with no date.""",
"""  - Skip rows whose label is a layout artefact (`–`, `•`, an empty cell) rather than an event.
  - A row stated only RELATIVELY ("în termen de două zile lucrătoare de la data expirării termenului de depunere") gets `date: null` and the rule in `relative`, shortened: "2 zile lucrătoare după depunere". Never compute a date from it. Rows with a date have `relative: null`.
  - `verbatim` at most 12 words.""")
sub("""- `events`: one entry per dated row, `{stage, date, time, verbatim}`.""","""- `events`: one entry per row, `{stage, date, time, relative, verbatim}`.""")
# 6. positions: every role
sub("""- Then add **one `positions` entry per role**: `{title, count, education, experience, skill_list, credentials, seniority_hint}`, each with that role's own requirements.""",
"""- Then add **one `positions` entry per role — EVERY role, the first one included**: `{title, count, education, experience, skill_list, credentials, seniority_hint}`, each with that role's own requirements.
- A requirement stated once for all roles ("Cerințe specifice pentru ambele", a common skills list) is copied into EVERY position it applies to. A position must be complete on its own.""")
# 7. credentials evidence
sub("""- `issuer`, `required`.
""","""- `issuer`, `required`.
- `evidence`: the exact words of the posting the credential comes from, copied character for character (at most 20 words, no paraphrase, no "…" inside a word). It is checked against the source text; a paraphrase is discarded.
- `licence_category`: for `permis_conducere`, the categories as a string ("B", "B, C, CE"); null otherwise.
""")
# 8. new part E appended before final instruction
partE = """# Part E — new in v5

## `recruitment` — object
- `kind`: `recrutare` (an open competition for a post) | `promovare` (promotion exam for staff already employed: "promovare în grad/treaptă profesională imediat superioară") | `management` (concurs pentru funcția de manager / proiect de management) | `rezidentiat` | `altele`.
- `internal_only`: true when only current employees may take part (every `promovare`, and any posting that says so), false for an open competition, null when unclear.

## `vacancies_total` — integer or null
The total number of seats advertised across all roles ("118 posturi vacante de asistent medical" → 118; "1 post Referent, 1 post Inspector, 3 posturi muncitor" → 5). Never a project code, a registration number or an article number.

## `application` — object or null
- `channels`: any of `sediu` (personally at the registry/HR office) | `posta` (poștă/curier) | `email` | `online` (a web form or platform). Only those the posting explicitly allows.
- `window_start`: ISO date the submission window opens, when stated.

## `age_limit` — object or null
`{min_years, max_years, verbatim}` only for an explicit age condition ("vârsta minimă 18 ani", "să nu fi împlinit vârsta standard de pensionare" → `max_years: null`, keep the verbatim). Not the generic "capacitate deplină de exercițiu".

## `work_localities` — array
Localities where the work is done when the posting names them and they differ from, or are more specific than, the employer's seat ("Punctul de lucru Bărăbanț", "comunitățile din județ: Aleșd, Beiuș") — locality names only, nominative. `[]` otherwise.

## `documents` — object or null (replaces `application_docs`)
The dosar checklist, split in two so the standard part costs a few tokens:
- `standard`: the standard HG 1.336/2022 items the posting lists, as codes: `formular_inscriere` | `act_identitate` | `certificat_casatorie` | `acte_studii` | `carnet_munca_adeverinta_vechime` | `cazier_judiciar` | `adeverinta_medicala` | `certificat_integritate` | `cv` | `declaratie_conformitate` | `dosar_plic`.
- `specific`: every other document, `{label, evidence, roles}` — `label` short ("aviz psihologic", "proiect de management", "permis de conducere categoria B", "certificat de membru OAMGMAMR avizat"), `evidence` the exact source words (≤ 20 words, as for credentials), `roles` the position titles it applies to, or `[]` for all roles.

## Robustness rules
- Closed lists: if no value fits, use `altele` where the list has it, otherwise null. Never invent a new code and never combine two codes.
- Every `evidence` and `verbatim` is copied from the posting, not rewritten.

Example — "Liceul Tehnologic … ocuparea pe perioadă determinată a funcţiei contractuale temporar vacante: supraveghetor de noapte-0,75 post temporar vacant, până la data de 30.06.2027. … aviz psihologic … Dosarul: cerere, copia actului de identitate, cazier judiciar, adeverinţă medicală, certificat de integritate comportamentală, curriculum vitae, dosar plic. Depunerea dosarelor la secretariatul liceului."
→ {"contract":{"duration":"determinata","duration_months":null,"schedule":"tura_noapte","hours_per_week":null,"fte":0.75,"end_date":"2027-06-30","post_status":"temporar_vacant","probation_months":null,"shift_work":true},"recruitment":{"kind":"recrutare","internal_only":false},"vacancies_total":1,"application":{"channels":["sediu"],"window_start":null},"age_limit":null,"work_localities":[],"documents":{"standard":["formular_inscriere","act_identitate","cazier_judiciar","adeverinta_medicala","certificat_integritate","cv","dosar_plic"],"specific":[{"label":"aviz psihologic","evidence":"aviz psihologic","roles":[]}]},"credentials":[{"label":"aviz psihologic","kind":"aviz_medical","issuer":null,"required":true,"evidence":"aviz psihologic","licence_category":null}]}

"""
sub("Now extract from the posting below and return ONLY the JSON object.", partE+"Now extract from the posting below and return ONLY the JSON object. Do not emit the keys removed in v5 (`educationRequirements`, `experienceRequirements`, `skills`, `application_docs`, `remote_mode`, `parent_institution`).")
# examples in Parts A-D still contain removed keys; add a note
sub("# Few-shot examples","# Few-shot examples\n\n(The examples below predate v5: ignore the keys they show that v5 removed, and add the v5 keys.)")
open(sys.argv[1],'w').write(p)
print(len(v4), len(p))

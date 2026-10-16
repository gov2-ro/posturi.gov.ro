"""Build the final v5 system prompt from the reviewed draft.

    python3 docs/specs/2026-10-16-v5-extraction/build_v5_final.py            # print
    python3 docs/specs/2026-10-16-v5-extraction/build_v5_final.py --write    # also models_config.json prompts.v5

Every change to the draft (draft-v5-prompt.txt, itself v4 + edits via build_v5.py) is an
explicit replacement below, so the diff stays reviewable: v5-shaped few-shot examples
(no removed key appears anywhere), the ≥5-word evidence rule (README §3.4), "null when
silent" for keys with a natural default and the manager-contest duration rule (§3.5).
"""
import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
draft = (HERE / "draft-v5-prompt.txt").read_text(encoding="utf-8")


def j(obj) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))


def rep(text: str, old: str, new: str) -> str:
    assert old in text, old[:80]
    return text.replace(old, new, 1)


EDU1 = {"minimum_level": "licenta", "eqf_level": 6, "fields_of_study": [
    {"label_ro": "geografie", "isced_field": "05_stiinte_naturale_matematica"},
    {"label_ro": "silvicultură", "isced_field": "08_agricultura_silvicultura_veterinara"},
    {"label_ro": "geologie", "isced_field": "05_stiinte_naturale_matematica"},
    {"label_ro": "biologie", "isced_field": "05_stiinte_naturale_matematica"},
    {"label_ro": "știința mediului", "isced_field": "05_stiinte_naturale_matematica"},
    {"label_ro": "inginerie geodezică", "isced_field": "07_inginerie_constructii"}],
    "specialization": None, "required": True,
    "verbatim": "Studii universitare de licență absolvite cu diplomă de licență sau echivalentă în domeniul geografie/ silvicultură/ geologie/ biologie/ știința mediului/ inginerie geodezică."}
EX1 = {"responsibilities": None, "qualifications": None, "baseSalary": None, "jobBenefits": None,
       "workHours": None, "jobLocation": None, "application_fee": None, "application_contact": None,
       "education": EDU1,
       "experience": {"years_minimum": 3, "in_specialty": True, "domain": None, "none_required": False,
                      "verbatim": "Vechime în specialitate minim 3 ani."},
       "skill_list": [
           {"label": "GIS", "type": "knowledge", "proficiency": "avansat", "required": True,
            "evidence": "Cunoștințe avansate de operare sisteme GIS"},
           {"label": "analiză spațială", "type": "skill", "proficiency": None, "required": True,
            "evidence": "analiză spațială"}],
       "language_list": [],
       "credentials": [{"label": "permis de conducere categoria B", "kind": "permis_conducere", "issuer": None,
                        "required": True, "evidence": "Permis de conducere categoria B.", "licence_category": "B"}],
       "contract": None, "policy_domains": ["mediu", "tehnologia_informatiei"], "occupation_title": None,
       "seniority_hint": None, "positions": [], "exam_stages": ["selectie_dosare", "proba_scrisa", "interviu"],
       "bibliography_topics": [], "competition_calendar": None, "funding": None, "employer_context": None,
       "note_suplimentare": None, "recruitment": {"kind": "recrutare", "internal_only": False},
       "vacancies_total": None, "application": None, "age_limit": None, "work_localities": [], "documents": None}

EX2 = {"responsibilities": None, "qualifications": None,
       "baseSalary": {"minValue": 4500, "maxValue": 4500, "currency": "RON", "unitText": "MONTH"},
       "jobBenefits": None, "workHours": "Normă întreagă, 8h/zi.", "jobLocation": None,
       "application_fee": {"amount": 50, "currency": "RON", "account": None, "details": None},
       "application_contact": None,
       "education": {"minimum_level": "licenta", "eqf_level": 6,
                     "fields_of_study": [{"label_ro": "economie", "isced_field": "04_afaceri_administratie_drept"}],
                     "specialization": None, "required": True,
                     "verbatim": "Studii: studii superioare economice, diplomă de licență."},
       "experience": {"years_minimum": None, "in_specialty": False, "domain": None, "none_required": True,
                      "verbatim": "Vechime în muncă: nu este cazul."},
       "skill_list": [{"label": "Microsoft Excel", "type": "knowledge", "proficiency": "mediu", "required": True,
                       "evidence": "Cunoștințe operare PC (Microsoft Excel — nivel mediu)"}],
       "language_list": [{"language": "engleză", "iso_code": "en", "cefr": "B1", "required": False}],
       "credentials": [],
       "contract": {"duration": "nedeterminata", "duration_months": None, "schedule": "norma_intreaga",
                    "hours_per_week": 40, "fte": None, "end_date": None, "post_status": None,
                    "probation_months": None, "shift_work": None},
       "policy_domains": ["economie_finante", "resurse_umane"], "occupation_title": "Referent",
       "seniority_hint": "debutant", "positions": [], "exam_stages": [], "bibliography_topics": [],
       "competition_calendar": None, "funding": None, "employer_context": None, "note_suplimentare": None,
       "recruitment": {"kind": "recrutare", "internal_only": False}, "vacancies_total": 1,
       "application": None, "age_limit": None, "work_localities": [], "documents": None}

POS_SKILL = {"label": "fonduri europene", "type": "knowledge", "proficiency": None, "required": True,
             "evidence": "Cunoașterea procesului de utilizare a fondurilor europene"}
POS_LAW = {"label": "legislație UE", "type": "knowledge", "proficiency": None, "required": True,
           "evidence": "cunoașterea cadrului legal național și al Uniunii Europene"}
EDU_IT = {"minimum_level": "licenta", "eqf_level": 6,
          "fields_of_study": [{"label_ro": "informatică", "isced_field": "06_tic"}], "specialization": None,
          "required": True, "verbatim": "studii superioare absolvite cu diplomă de licență în domeniul informaticii"}
EXP_IT = {"years_minimum": 3, "in_specialty": True, "domain": None, "none_required": False,
          "verbatim": "vechime în specialitate minim 3 ani"}
EX3 = {"responsibilities": None, "qualifications": None, "baseSalary": None, "jobBenefits": None,
       "workHours": None, "jobLocation": None, "application_fee": None, "application_contact": None,
       "education": EDU_IT, "experience": EXP_IT, "skill_list": [POS_SKILL, POS_LAW], "language_list": [],
       "credentials": [], "contract": None,
       "positions": [
           {"title": "expert IT senior", "count": 1, "education": EDU_IT, "experience": EXP_IT,
            "skill_list": [POS_SKILL, POS_LAW], "credentials": [], "seniority_hint": "specialist"},
           {"title": "expert administrație publică I", "count": 2,
            "education": {"minimum_level": "licenta", "eqf_level": 6, "fields_of_study": [], "specialization": None,
                          "required": True, "verbatim": "studii superioare absolvite cu diplomă de licență"},
            "experience": {"years_minimum": 7, "in_specialty": True, "domain": None, "none_required": False,
                           "verbatim": "vechime în specialitate minim 7 ani"},
            "skill_list": [POS_SKILL, POS_LAW], "credentials": [], "seniority_hint": None}],
       "policy_domains": ["fonduri_europene", "tehnologia_informatiei"], "occupation_title": "Expert IT",
       "seniority_hint": "specialist", "exam_stages": [], "bibliography_topics": [],
       "competition_calendar": None, "funding": {"source": "fonduri_europene", "programme": None,
                                                 "project_code": None, "project_name": None},
       "employer_context": None, "note_suplimentare": None,
       "recruitment": {"kind": "recrutare", "internal_only": False}, "vacancies_total": 3,
       "application": None, "age_limit": None, "work_localities": [], "documents": None}

EX4 = {"competition_calendar": {"application_deadline": "2025-06-03", "application_deadline_time": "14:00", "events": [
    {"stage": "publicare", "date": "2025-05-05", "time": None, "relative": None, "verbatim": "Publicarea anunţului"},
    {"stage": "depunere_dosare", "date": "2025-06-03", "time": "14:00", "relative": None,
     "verbatim": "Data limită până la care se pot depune dosarele de concurs"},
    {"stage": "selectie_dosare", "date": "2025-06-05", "time": None, "relative": None, "verbatim": "Selecţia dosarelor"},
    {"stage": "proba_scrisa", "date": "2025-06-12", "time": None, "relative": None, "verbatim": "Susţinerea probei scrise"},
    {"stage": "rezultate_finale", "date": "2025-06-20", "time": None, "relative": None,
     "verbatim": "Afişarea rezultatului final al concursului"}]},
    "funding": {"source": "fonduri_europene", "programme": "PNRR", "project_code": "Componenta C10",
                "project_name": "Ciugud Smart Village"},
    "employer_context": {"sector": "administratie_locala", "subordinated_to": None, "uat_type": "comuna",
                         "uat_name": "Ciugud"},
    "note_suplimentare": "- Deplasări frecvente în teren.\n- Disponibilitate pentru lucru în weekend la evenimente publice."}

p = draft
p = rep(p, "v3 exists to answer a different question than a plain description",
        "This extraction exists to answer a different question than a plain description")
p = rep(p, '5. Return ONLY valid JSON. No code fences, no commentary, no `@context`/`@type`.',
        '5. Return ONLY valid JSON. No code fences, no commentary, no `@context`/`@type`.\n'
        '6. Keys with a natural default stay `null` when the posting is silent — `contract.duration`, `contract.schedule`, '
        '`contract.shift_work`, `contract.post_status`, `employer_context.sector`, `recruitment.internal_only`. Never fill them '
        'from what is typical for the employer or the profession.\n'
        '7. Every `evidence` is copied character for character from the posting: the whole list item or sentence, at '
        'least 5 words when the source has them and at most 20 — never a two-word fragment, never a paraphrase. '
        'It is checked against the source text; anything else is discarded.')
p = rep(p, "- `evidence`: the exact words of the posting the credential comes from, copied character for character (at most 20 words, no paraphrase, no \"…\" inside a word). It is checked against the source text; a paraphrase is discarded.",
        "- `evidence`: the posting's own words for this credential, per rule 7.")
p = rep(p, "- `duration`: `nedeterminata` | `determinata` | `sezonier` | `proiect`; `duration_months` when fixed-term.",
        "- `duration`: `nedeterminata` | `determinata` | `sezonier` | `proiect`; `duration_months` when fixed-term. "
        "A management contest (`recruitment.kind: management`) is never `nedeterminata` by assumption: null unless the posting states the term.")
p = rep(p, "(The examples below predate v5: ignore the keys they show that v5 removed, and add the v5 keys.)\n\n", "")
lines = p.split("\n")
out = []
examples = {"## Example 1 — output:": EX1, "## Example 2 — output:": EX2,
            "## Example 3 — output (only the fields that differ from a single-role posting are shown filled; emit all keys):": EX3,
            "## Example 4 — output (only the Part D keys are shown; emit all keys):": EX4}
skip_next_json = None
for line in lines:
    if skip_next_json is not None and line.startswith("{"):
        out.append(j(skip_next_json))
        skip_next_json = None
        continue
    out.append(line)
    if line in examples:
        skip_next_json = examples[line]
p = "\n".join(out)
p = rep(p, '"Studii universitare de licență absolvite cu diplomă de licență sau echivalentă în domeniul geografie/ silvicultură/ geologie/ biologie/ știința mediului/ inginerie geodezică. Cunoștințe avansate de operare sisteme GIS (lucrul cu baze de date, elaborare și actualizare hărți tematice, analiză spațială, integrare date geospațiale). Vechime în specialitate minim 3 ani. Permis de conducere categoria B. Concursul constă în selecția dosarelor, probă scrisă și interviu."',
        '"Studii universitare de licență absolvite cu diplomă de licență sau echivalentă în domeniul geografie/ silvicultură/ geologie/ biologie/ știința mediului/ inginerie geodezică. Cunoștințe avansate de operare sisteme GIS (lucrul cu baze de date, elaborare și actualizare hărți tematice, analiză spațială, integrare date geospațiale). Vechime în specialitate minim 3 ani. Permis de conducere categoria B. Concursul constă în selecția dosarelor, probă scrisă și interviu."')
p = p.replace("(at most 20 words, no paraphrase", "(at most 20 words, no paraphrase")
p = rep(p, '`evidence` the exact source words (≤ 20 words, as for credentials)', '`evidence` per rule 7')

for removed in ("educationRequirements", "experienceRequirements", '"skills"', "application_docs\"", "remote_mode\"", "parent_institution\""):
    assert removed not in p.split("Now extract from the posting below")[0].replace("replaces `application_docs`", ""), removed

final = HERE / "prompt-v5.txt"
final.write_text(p, encoding="utf-8")
print(f"{len(p)} chars -> {final}")
if "--write" in sys.argv:
    # Textual edit, not a json.dump of the whole file: keeps the hand formatting
    # (inline arrays, 0.60) of everything else in models_config.json.
    cfg_path = ROOT / "models_config.json"
    text = cfg_path.read_text(encoding="utf-8")
    lines = text.split("\n")
    lines = [ln for ln in lines if not ln.lstrip().startswith('"v5": ')]
    at = next(i for i, ln in enumerate(lines) if ln.lstrip().startswith('"v4": '))
    indent = lines[at][: len(lines[at]) - len(lines[at].lstrip())]
    if not lines[at].rstrip().endswith(","):
        lines[at] = lines[at].rstrip() + ","
        lines.insert(at + 1, f"{indent}\"v5\": {json.dumps(p, ensure_ascii=False)}")
    else:
        lines.insert(at + 1, f"{indent}\"v5\": {json.dumps(p, ensure_ascii=False)},")
    new_text = "\n".join(lines)
    assert json.loads(new_text)["prompts"]["v5"] == p
    cfg_path.write_text(new_text, encoding="utf-8")
    print("models_config.json prompts.v5 written")

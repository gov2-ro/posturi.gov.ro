"""classify.py — the deterministic labels behind the 261016 filter fixes.

Each negative case is a phrase that fooled the old regexes on real postings
(dev export of 2026-09-09); each positive is the wording the label must keep.
"""
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
import classify  # noqa: E402


@pytest.mark.parametrize("text", [
    "Legea nr. 81/2018 privind reglementarea activității de telemuncă;",
    "Monitoring biodiversity using remote sensing. Remote Sensing of Environment, 239.",
    "Tehnica hibridizarii produsilor de amplificare PCR",
    "Modele hibride care combină simulări computaționale cu date empirice",
    "telefoanelor mobile ori a altor mijloace de comunicare la distanţă",
    "Pomicultură. Manual universitar pentru învățământ la distanță.",
    "arhitectură hibrid-cloud pe infrastructuri private și publice",
])
def test_work_mode_ignores_words_that_are_not_about_this_job(text):
    assert classify.work_mode(text) == ""


@pytest.mark.parametrize("text,mode", [
    ("Disponibilitate pentru telemunca", "telemunca"),
    ("echipamente IT, conexiune internet) pentru activitatea de telemuncă", "telemunca"),
    ("Se poate lucra în regim de telemuncă 2 zile pe săptămână", "telemunca"),
    ("Programul de lucru este hibrid: 3 zile la sediu", "hibrid"),
    ("Postul permite telemuncă.", "telemunca"),
])
def test_work_mode_reads_how_the_job_is_done(text, mode):
    assert classify.work_mode(text) == mode


@pytest.mark.parametrize("text", [
    "Dosarul se transmis prin e-mail; candidații admiși",          # "smis" inside a word
    "Legea privind tichetele acordate din fonduri externe nerambursabile",
    "cunoștințe despre managementul investițiilor cu fonduri europene",
])
def test_funding_needs_the_post_itself_to_be_project_funded(text):
    assert classify.eu_funding(text) == ""


@pytest.mark.parametrize("text,kind", [
    ("proiectul „Servicii comunitare”, cod SMIS 328212", "fonduri_europene"),
    ("în cadrul proiectului cofinanțat din Fondul Social European prin POCU", "fonduri_europene"),
    ("post finanțat prin Planul Național de Redresare și Reziliență", "pnrr"),
    ("în cadrul proiectului finanțat prin PNRR, Componenta C15", "pnrr"),
])
def test_funding_from_explicit_project_wording(text, kind):
    assert classify.eu_funding(text) == kind


@pytest.mark.parametrize("title,body,regime", [
    ("Consilier, clasa I, grad profesional principal", "", "publica"),
    ("Inspector asistent I", "", "publica"),
    ("Polițist local, clasa III, grad superior", "", "publica"),
    ("Asistent medical generalist, grad profesional debutant", "personal contractual", "contractuala"),
    ("Îngrijitor, clasa I, traptă profesională 1", "", ""),
    ("Muncitor calificat IV", "concurs pentru ocuparea unui post vacant de natură contractuală", "contractuala"),
    ("Referent", "concurs pentru ocuparea funcției publice de execuție vacante de referent", "publica"),
    ("Medic șef", "ocuparea unei funcții militare vacante", "militara"),
    ("Medic sef, specialist medicina militara", "", "militara"),
])
def test_regime(title, body, regime):
    assert classify.regime(title, body) == regime


@pytest.mark.parametrize("name,kind", [
    ("Primăria Comunei Diosig, județul Bihor", "primarie"),
    ("U.A.T. Holboca", "primarie"),
    ("Spitalul Clinic Militar de Urgență", "spital"),
    ("Unitatea Militară 01454 Buzău", "militar"),
    ("Poliția Locală Iași", "servicii-publice"),
    ("Școala Gimnazială Nr. 77, București", "scoala"),
    ("Direcția Generală de Asistență Socială și Protecția Copilului", "asistenta-sociala"),
    ("Serviciul Public de Alimentare cu Apă și Canalizare", "servicii-publice"),
    ("Ministerul Familiei, Tineretului și Egalității de Șanse", "minister"),
    ("Clubul Sportiv Municipal Galați", "sport"),
    ("COMPANIA DE APĂ SOMEȘ S.A.", "companie"),
    ("Muzeul Județean „Ștefan cel Mare”, Vaslui", "cultura"),
])
def test_employer_kind(name, kind):
    assert classify.employer_kind(name) == kind
    assert kind in classify.EMPLOYER_KIND_LABELS


@pytest.mark.parametrize("code,out", [
    ("en", "en"), ("hu", "hu"),
    ("o limbă străină", "straina"), ("Limba de circulație internațională", "straina"),
    ("limbi străine", "straina"), ("A doua limbă străină", "straina"),
    ("Limba Semnelor Române", "Limba Semnelor Române"), ("limba locală", "limba locală"),
])
def test_language_code_folds_any_foreign_language(code, out):
    assert classify.language_code(code) == out


@pytest.mark.parametrize("title,specs", [
    ("Medic specialist, specialitatea Neurologie", ["Neurologie"]),
    ("MEDIC SPECIALIST RADIOLOGIE ȘI IMAGISTICĂ MEDICALĂ – COMPETENȚĂ CT", ["Radiologie și imagistică"]),
    ("medic primar – specialitatea medicină de familie", ["Medicină de familie"]),
    ("Medic veterinar", []),
    ("Asistent medical generalist", []),
])
def test_medical_specialties(title, specs):
    assert classify.medical_specialties(title) == specs


@pytest.mark.parametrize("names,code", [
    (("Muncitor calificat", ""), "7"), (("Muncitor necalificat", ""), "9"),
    (("Medic specialist", ""), "221"), (("", "Șofer autoturism"), "83"),
    (("Membru în Consiliul de Administrație", ""), ""),
])
def test_occupation_fallback(names, code):
    assert classify.occupation_fallback(*names) == code


def test_skill_tags_drop_soft_skills_and_merge_spellings():
    skills = [
        {"label": "lucru în echipă", "type": "transversal"},
        {"label": "Excel", "type": "knowledge"},
        {"label": "Microsoft Excel", "type": "skill"},
        {"label": "operare calculator", "type": "skill"},
        {"label": "GIS", "type": "knowledge"},
        {"label": "limba engleză", "type": "language"},
        {"label": "Contabilitate"},
    ]
    assert classify.skill_tags(skills) == ["Microsoft Excel", "Operare PC", "GIS", "Contabilitate"]
    assert classify.skill_tags(None) == []


def test_tags_are_rare_facts_and_at_most_four():
    common = {"job_type": "Permanent", "job_level": "Funcții de execuție"}
    cands = {i: classify.tag_candidates(dict(common)) for i in range(10)}
    special = dict(common, job_type="Temporar", v3_schedule="norma_partiala", funding_kind="pnrr",
                   med_specialty='["Cardiologie"]', skills=["GIS", "lucru în echipă", "integrare TIC în educație"],
                   nr_posturi=3, v3_credentials='["permis_conducere"]', work_mode="hibrid")
    cands[99] = classify.tag_candidates(special)
    tags = classify.rank_tags(cands)
    assert tags[0] == [], "Permanent / execuție are not tags"
    assert len(tags[99]) == 4
    assert set(tags[99]) <= {"Perioadă determinată", "Normă parțială", "PNRR", "Cardiologie", "GIS",
                             "3 posturi", "Permis auto", "Hibrid"}
    assert "integrare TIC în educație" not in tags[99] and "lucru în echipă" not in tags[99]


def test_a_tag_on_most_postings_is_dropped():
    cands = {i: [("parttime", "Normă parțială")] for i in range(10)}
    assert all(v == [] for v in classify.rank_tags(cands).values())


# ---- project_hire() and project_pay() (SALARY-EU-DETAIL, PIPELINE-PROJECT-PAY) ----
# Texts are quotes from the spike sample (docs/audits/2026-11-18-salary-eu-sample.csv).

@pytest.mark.parametrize("text,kind", [
    ("Postul se află în afara organigramei, pe perioada determinată a contractului.", "fonduri_europene"),
    ("pentru ocuparea pe perioadă determinată, pe perioada de implementare a proiectului „Furnizare de servicii”", "fonduri_europene"),
    ("Consilier școlar în cadrul echipei comunitare integrate, cod SMIS 339395", "fonduri_europene"),
    ("din cadrul structurii: proiect finanțat prin Planul Național de Redresare (PNRR)", "pnrr"),
])
def test_project_hire_reads_a_post_of_the_project(text, kind):
    assert classify.project_hire(text, kind) is True


@pytest.mark.parametrize("text,kind", [
    # the false flags of the spike: the project is a requirement, a law in the reading list
    ("constituie avantaj activitatea în proiectul pilot „Crearea serviciilor”, cod MySMIS 122607", "fonduri_europene"),
    ("Constituie avantaj experiența în cadrul proiectelor educaționale", "fonduri_europene"),
    ("cunoștințe privind managementul investițiilor finanțate din fonduri europene", "fonduri_europene"),
    ("OUG 44/2023 privind implementarea proiectelor de infrastructură finanțate din fonduri externe nerambursabile", "fonduri_europene"),
    # no funding flag: never a project hire
    ("în afara organigramei, pe perioada implementării proiectului", ""),
])
def test_project_hire_ignores_a_project_that_is_only_named(text, kind):
    assert classify.project_hire(text, kind) is False


def test_project_pay_hour_with_hours_and_basis():
    p = classify.project_pay("începând cu data angajării până la 30.11.2029, 84 ore/lună, 50 lei/oră net, timp de lucru inegal")
    assert (p["amount"], p["unit"], p["basis"], p["hours_per_month"]) == (50.0, "ora", "net", 84.0)
    assert "50 lei/oră net" in p["quote"]                 # the quote keeps the diacritics of the source


def test_project_pay_hour_only_basis_not_stated():
    p = classify.project_pay("Norma de lucru: inegală / Tarif orar: 100 lei/oră 4. Cerințele postului")
    assert (p["amount"], p["unit"], p["basis"], p["hours_per_month"]) == (100.0, "ora", "", None)


def test_project_pay_hour_with_hours_in_the_same_text():
    p = classify.project_pay("Norma de lucru: inegală, maxim 84 ore/lună / Tarif orar: 150 lei/oră")
    assert (p["amount"], p["hours_per_month"]) == (150.0, 84.0)


def test_project_pay_month_net_and_gross():
    net = classify.project_pay("având norma de lucru de 42 ore/lună, salariu net 2.100 lei, pentru perioada de 42 luni")
    assert (net["amount"], net["unit"], net["basis"], net["hours_per_month"]) == (2100.0, "luna", "net", None)
    gross = classify.project_pay("Salariu brut: 5.139 lei/lună, în limita bugetului")
    assert (gross["amount"], gross["unit"], gross["basis"]) == (5139.0, "luna", "brut")


def test_project_pay_month_without_basis():
    p = classify.project_pay("| 4 ore/zi, 20 ore/săptămână | 2.767 lei/lună | Logoped |")
    assert (p["amount"], p["unit"], p["basis"]) == (2767.0, "luna", "")


def test_project_pay_one_rate_for_several_roles_keeps_the_rate_not_the_hours():
    p = classify.project_pay("1. Manager proiect, 84 ore pe lună, 70 lei net /oră; 2. Responsabil financiar, 42 ore/lună, 70 lei net pe oră")
    assert (p["amount"], p["basis"], p["hours_per_month"]) == (70.0, "net", None)


@pytest.mark.parametrize("text", [
    "Manager proiect 70 lei/oră net; Îngrijitor la domiciliu 29 lei/oră net",          # several rates: no mixing
    "Expert 1: salariu net 6.720 lei; Expert 2: salariu net 3.360 lei",                 # several month figures
    "Tarif orar: 5 lei/oră",                                                            # under 15 lei/oră
    "Tarif orar: 900 lei/oră",                                                          # over 500 lei/oră
    "Salariu brut 800 lei/lună",                                                        # month figure under 1.000 lei
    "Salariul minim brut pe țară garantat în plată este de 4.325 lei",                  # the minimum wage, not the pay
    "Postul este pe perioadă determinată, cu normă de 4 ore pe zi.",                    # no pay
])
def test_project_pay_returns_none_when_it_is_not_one_pay(text):
    assert classify.project_pay(text) is None


def test_project_pay_quote_is_cut_at_word_boundaries():
    text = "x" * 200 + " cu contract de muncă, 84 ore/lună, 50 lei/oră net, timp de lucru inegal distribuit " + "y" * 200
    q = classify.project_pay(text)["quote"]
    assert "50 lei/oră net" in q and q.startswith("…") and q.endswith("…")
    assert "x" not in q and "y" not in q                      # no cut word at either end


# ---- project_programme() (SALARY-EU-CAP) ----

@pytest.mark.parametrize("text,want", [
    ("proiect finanțat prin Programul Incluziune și Demnitate Socială 2021-2027", "pids"),
    ("finanțat prin PoIDS 2021-2027", "pids"),
    ("Programul PIDS, apel 6.1", "pids"),
    ("Cod SMIS 339395, echipa comunitară integrată", "pids"),
    ("finanțat prin Programul Educație și Ocupare 2021-2027", "peo"),
    ("proiect PEO, cod SMIS 300001", "peo"),
    ("Programul Incluziune și Demnitate Socială și Programul Educație și Ocupare", None),
    ("proiect finanțat prin PNRR", None),
    ("echipa tipografiei Pidso și Peoria", None),      # no whole word
])
def test_project_programme(text, want):
    assert classify.project_programme(text) == want

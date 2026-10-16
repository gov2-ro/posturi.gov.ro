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

"""Shared by the integritate scripts: the family of a declaration (ADR 0008, rule 9).

`family()` keeps one definition for `integritate-panel.py` (backtest) and
`build-declaratii-grupuri.py` (the group table). Spike code moved here on 2026-11-20.
"""
from __future__ import annotations

import re

ELECTED = re.compile(
    r"consilier local|consilier jude|ale[sș]i|^primar|viceprimar|pre[sș]edinte(le)? consiliu|"
    r"membru.*(consiliu|administra)|deputat|senator", re.I)


def family(r: dict) -> str:
    f = r["functie"].lower()
    i = r["institutie_norm"].upper()
    if ELECTED.search(f):
        return "ales"
    if re.search(r"fonduri externe|proiect", f):
        return "proiect"
    if re.search(r"judec[aă]tor|procuror", f):
        return "magistrat"
    if re.search(r"TRIBUNAL|JUDECATORI|CURTEA DE APEL|PARCHET|INALTA CURTE", i):
        return "justitie-personal"
    if re.search(r"politist local|politia local", f) or "POLITIA LOCALA" in i or "POLITIE LOCALA" in i:
        return "politie-locala"
    if re.search(r"POLITIE|JANDARM|SITUATII DE URGENTA|UNITATEA MILITARA|\bU ?M\b|PENITENCIAR|POLITIA DE FRONTIERA|AFACERILOR INTERNE|APARARII", i):
        return "mai-mapn"
    if re.search(r"UNIVERSITATE|ACADEMIA DE STUDII|POLITEHNIC", i):
        return "universitate"
    if re.search(r"medic|asistent medical|infirmier", f) or re.search(r"SPITAL|AMBULANT", i):
        return "sanatate"
    if re.search(r"profesor|[iî]nv[aă][tț][aă]tor|educatoare|inspector [sș]colar|director unitate", f) or \
            re.search(r"SCOALA|LICEU|COLEGIU|GRADINITA|INSPECTORATUL SCOLAR|PALATUL COPIILOR", i):
        return "educatie"
    return {"LOCAL": "admin-local", "JUDETEAN": "admin-judetean", "NATIONAL": "admin-central"}.get(
        r["tip_institutie"], "altul")

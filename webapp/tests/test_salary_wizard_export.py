"""UX-SALARY-WIZARD / SALARY-INS-LINE — the export side of the estimator wizard.

Pinned here:
  * ops/build-ins-context.py: the domain -> CAEN mapping, the Rev.2 -> Rev.3 letters of FOM106G,
    the CSV columns, the county names, the weighted mean, and that the rows of a fake
    `duckdb` answer become the rows of the CSV (nothing here reads tempo-ins-dump);
  * export-to-sqlite.py: `salary_title` (the thresholds and the near-duplicate rule) and
    `salary_ins_context` (the copy of the CSV), on an in-memory SQLite file.
"""

from __future__ import annotations

import csv
import importlib.util
import json
import sqlite3
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    sys.modules[name] = mod
    spec.loader.exec_module(mod)
    return mod


@pytest.fixture(scope="module")
def ins():
    return _load("build_ins_context_under_test", REPO_ROOT / "ops" / "build-ins-context.py")


@pytest.fixture(scope="module")
def exp():
    return _load("export_salary_wizard_under_test", REPO_ROOT / "export-to-sqlite.py")


# ---- ops/build-ins-context.py ----

def test_domain_to_caen_mapping(ins):
    assert ins.DOMAIN_CAEN == {
        "Învățământ": "P", "Sănătate și asistență socială": "Q", "Cultură": "R",
        "Administrație": "O", "Justiție": "O", "Diplomație": "O", "Apărare și ordine publică": "O",
    }
    assert ins.SECTIONS == ["O", "P", "Q", "R"]


def test_rev3_letters_are_shifted_by_one(ins):
    # Rev.3: P public administration, Q education, R health, S culture.
    assert ins.REV2_TO_REV3 == {"O": "P", "P": "Q", "Q": "R", "R": "S"}
    assert set(ins.REV2_TO_REV3) == set(ins.SECTIONS)


def test_csv_columns(ins):
    assert ins.COLUMNS == ["matrice", "sectiune_caen", "judet", "perioada", "masura", "valoare", "unitate", "extras_la"]


def test_county_names(ins):
    j = ins._load_judete()
    assert ins.county_name("Arges", j) == "Argeș"
    assert ins.county_name("Municipiul Bucuresti", j) == "București"
    assert ins.county_name("Bistrita-Nasaud", j) == "Bistrița-Năsăud"
    assert ins.county_name("Caras-Severin", j) == "Caraș-Severin"
    assert ins.county_name("Regiunea CENTRU", j) is None
    assert ins.county_name("MACROREGIUNEA UNU", j) is None


def test_weighted_mean(ins):
    assert ins.weighted_mean([(7237.0, 5962.0), (6357.0, 12989.0)]) == pytest.approx(
        (7237 * 5962 + 6357 * 12989) / (5962 + 12989))
    assert ins.weighted_mean([(5000.0, 0.0)]) is None


def test_rows_from_a_fake_duckdb(ins):
    def fake(sql: str):
        if "FOM106G" in sql:
            letter = sql.split("LIKE '")[1][0]
            return [{"TIME_PERIOD": "2026-05", "OBS_VALUE": {"P": "6900.0", "Q": "5817.0", "R": "5417.0", "S": "4579.0"}[letter]}]
        return [  # FOM106E x FOM104F: both sexes in Cluj, one sex only in Alba (dropped), a region (dropped)
            {"act": "P  INVATAMANT", "area": "Cluj", "sex": "Masculin", "year": "2024", "pay": "7237", "staff": "5962"},
            {"act": "P  INVATAMANT", "area": "Cluj", "sex": "Feminin", "year": "2024", "pay": "6357", "staff": "12989"},
            {"act": "P  INVATAMANT", "area": "Cluj", "sex": "Masculin", "year": "2023", "pay": "7000", "staff": "6000"},
            {"act": "P  INVATAMANT", "area": "Cluj", "sex": "Feminin", "year": "2023", "pay": "6000", "staff": "12000"},
            {"act": "P  INVATAMANT", "area": "Alba", "sex": "Masculin", "year": "2024", "pay": "5000", "staff": "100"},
            {"act": "P  INVATAMANT", "area": "Regiunea CENTRU", "sex": "Masculin", "year": "2024", "pay": "5000", "staff": "100"},
            {"act": "P  INVATAMANT", "area": "Regiunea CENTRU", "sex": "Feminin", "year": "2024", "pay": "5000", "staff": "100"},
        ]

    rows = ins.build(Path("/nowhere"), "2026-11-19", query=fake)
    assert all(list(r) == ins.COLUMNS for r in rows)
    national = [r for r in rows if r["judet"] == ""]
    assert {(r["sectiune_caen"], r["valoare"]) for r in national} == {("O", 6900), ("P", 5817), ("Q", 5417), ("R", 4579)}
    assert {r["matrice"] for r in national} == {"FOM106G"} and {r["perioada"] for r in national} == {"2026-05"}
    county = [r for r in rows if r["judet"]]
    assert len(county) == 1                                  # Alba has one sex only; a region is not a county
    c = county[0]
    assert (c["judet"], c["sectiune_caen"], c["perioada"], c["matrice"]) == ("Cluj", "P", "2024", "FOM106E+FOM104F")
    assert c["valoare"] == round((7237 * 5962 + 6357 * 12989) / (5962 + 12989))
    assert {r["unitate"] for r in rows} == {"lei"} and {r["masura"] for r in rows} == {ins.MEASURE}


def test_the_committed_csv_has_the_columns(ins):
    path = REPO_ROOT / "data" / "salarii" / "ins-context.csv"
    with path.open(encoding="utf-8", newline="") as fh:
        reader = csv.DictReader(fh)
        rows = list(reader)
        assert reader.fieldnames == ins.COLUMNS
    assert 0 < len(rows) <= 400
    assert {r["sectiune_caen"] for r in rows} == set(ins.SECTIONS)
    assert sum(1 for r in rows if r["judet"] == "") == len(ins.SECTIONS)


# ---- export-to-sqlite.py: salary_title ----

def _est(ref, grad="", baza="lege_in_vigoare"):
    return json.dumps({"baza": baza, "randuri": [ref], "selector": {"grad_treapta": grad}})


def test_salary_title_thresholds_and_near_duplicates(exp):
    refs = {"R1", "R2"}
    postings = [("Asistent medical", _est("R1", "principal"))] * 3 + [
        ("ASISTENT MEDICAL", _est("R1", "principal")),            # same normalised title
        ("Asistent  medical generalist", _est("R1")),              # 1 posting, contains a kept title, same ref: dropped
        ("Medic de familie", _est("R2")),                          # 1 posting, new: kept
        ("Medic de familie in cadrul unui spital foarte mare din judet", _est("R2")),   # > 8 words: dropped
        ("Inspector", _est("R9")),                                 # ref not in the grid: dropped
        ("Sofer", _est("R1", baza="proiect")),                     # not a law estimate: dropped
        ("Paznic", None),                                          # no estimate: dropped
        ("Sef birou", _est("R2")),                                 # 1 posting, other words, same ref: kept
    ]
    rows = exp.build_salary_titles(postings, refs)
    by_title = {r[0]: r for r in rows}
    assert set(by_title) == {"Asistent medical", "Medic de familie", "Sef birou"}
    titlu, cautare, ref, grad, nr = by_title["Asistent medical"]
    assert (cautare, ref, grad, nr) == ("asistent medical", "R1", "principal", 4)
    assert by_title["Medic de familie"][4] == 1


def test_salary_title_table_from_a_sqlite_file(exp):
    con = sqlite3.connect(":memory:")
    con.executescript("""
        CREATE TABLE salary_param (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE salary_grid_rows (version TEXT, ref TEXT);
        CREATE TABLE job_postings (id INTEGER PRIMARY KEY, title TEXT);
        CREATE TABLE posting_text (posting_id INTEGER PRIMARY KEY, sal_json TEXT);
    """)
    con.execute("INSERT INTO salary_param VALUES ('law', ?)", (json.dumps({"version": "lege153-2026"}),))
    con.execute("INSERT INTO salary_grid_rows VALUES ('lege153-2026', 'R1')")
    for i, t in enumerate(["Șofer", "Șofer", "Paznic"], start=1):
        con.execute("INSERT INTO job_postings VALUES (?, ?)", (i, t))
        con.execute("INSERT INTO posting_text VALUES (?, ?)", (i, _est("R1")))
    assert exp.export_salary_titles(con) == 2   # "Șofer" (2 postings) and "Paznic" (1, not a near-duplicate of it)
    got = con.execute("SELECT titlu, cautare, ref, grad, nr FROM salary_title ORDER BY nr DESC").fetchall()
    assert got == [("Șofer", "sofer", "R1", "", 2), ("Paznic", "paznic", "R1", "", 1)]


def test_salary_title_skipped_without_the_grid_tables(exp):
    assert exp.export_salary_titles(sqlite3.connect(":memory:")) is None


# ---- export-to-sqlite.py: salary_ins_context ----

def test_salary_ins_context_copies_the_csv(exp, ins, tmp_path):
    path = tmp_path / "ins.csv"
    with path.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=ins.COLUMNS)
        w.writeheader()
        w.writerow({"matrice": "FOM106G", "sectiune_caen": "Q", "judet": "", "perioada": "2026-05",
                    "masura": "castig_net_mediu_lunar", "valoare": 5417, "unitate": "lei", "extras_la": "2026-11-19"})
        w.writerow({"matrice": "FOM106E+FOM104F", "sectiune_caen": "Q", "judet": "Cluj", "perioada": "2024",
                    "masura": "castig_net_mediu_lunar", "valoare": 5703, "unitate": "lei", "extras_la": "2026-11-19"})
    con = sqlite3.connect(":memory:")
    assert exp.export_salary_ins_context(con, path) == 2
    cols = [r[1] for r in con.execute("PRAGMA table_info(salary_ins_context)")]
    assert cols == ins.COLUMNS
    assert con.execute("SELECT valoare FROM salary_ins_context WHERE judet = 'Cluj'").fetchone() == (5703.0,)


def test_salary_ins_context_skipped_without_the_csv(exp, tmp_path):
    con = sqlite3.connect(":memory:")
    assert exp.export_salary_ins_context(con, tmp_path / "missing.csv") is None
    assert con.execute("SELECT COUNT(*) FROM sqlite_master WHERE name = 'salary_ins_context'").fetchone() == (0,)

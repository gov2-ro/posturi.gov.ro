"""SALARY-DECLARATII-PAGE step 1 — export-to-sqlite.py: declared_institutions and declared_groups.

Pinned: the slug rule (name + county, ASCII, stable, no counter), the employer match (the county of most
postings, one employer per institution, the tie rule), the 50-lei figures, and the real CSV (no group with n < 5).
"""

from __future__ import annotations

import importlib.util
import sqlite3
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("export_declared_under_test", REPO_ROOT / "export-to-sqlite.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


def row(inst, county, functie, n, med="5000", p25="4500", p75="5600"):
    return {"institutie_id": f"{inst.upper()}|{county.upper()}", "institutie": inst, "judet": county.upper(), "functie": functie,
            "familie": "admin-local", "n": str(n), "p25_net_2023": p25, "mediana_net_2023": med, "p75_net_2023": p75,
            "an_venit": "2023", "factor": "1.150", "factor_sursa": "INS test", "eroare_tipica": "5.2"}


ROWS = [row("Comuna Gaiceana", "Bacau", "Consilier superior", 12), row("Comuna Gaiceana", "Bacau", "Referent", 5, med="3000"),
        row("Primaria Municipiului Iasi", "Iasi", "Inspector", 7)]


def test_slug_rule(exp):
    assert exp.declared_slug("Comuna Gaiceana", "bacau") == "comuna-gaiceana-bacau"
    assert exp.declared_slug("Școala Ţării, nr. 1", "satu-mare") == "scoala-tarii-nr-1-satu-mare"
    assert exp.declared_slug("Municipiul Iasi iasi", "iasi") == "municipiul-iasi-iasi"      # the county is not repeated


def test_institutions_groups_and_figures(exp):
    b = exp.build_declared(ROWS, {}, [])
    assert [i[0] for i in b["institutions"]] == ["comuna-gaiceana-bacau", "primaria-municipiului-iasi"]
    gaiceana = b["institutions"][0]
    assert gaiceana[3:8] == ("Bacău", "bacau", 17, 2, None)              # judet, judet_slug, n_total, groups, employer_id
    first = b["groups"][0]
    assert first[:4] == ("comuna-gaiceana-bacau", "Consilier superior", "admin-local", 12)      # largest n first
    assert first[4:8] == (5750, 5200, 6450, 5000)                           # median, p25, p75 x 1,15 at 50 lei; 2023 median
    assert all(g[3] >= 5 for g in b["groups"])


def test_slug_is_stable_between_runs(exp):
    a = {i[1]: i[0] for i in exp.build_declared(ROWS, {}, [])["institutions"]}
    b = {i[1]: i[0] for i in exp.build_declared(list(reversed(ROWS)), {}, [])["institutions"]}
    assert a == b


def test_employer_match_county_of_most_postings_and_ties(exp):
    employers = {1: "COMUNA GAICEANA", 2: "Primăria Comunei Gaiceana", 3: "COMUNA GAICEANA, jud. Bacău", 4: "Comuna Gaiceana"}
    counties = [(1, "Bacău", 3), (1, "Iași", 1),         # 1: most postings in Bacău -> matches
                (2, "Bacău", 5),                          # 2: PRIMARIA COMUNEI X is COMUNA X -> matches, more postings
                (3, "Bacău", 5),                          # 3: same count as 2, higher id -> loses
                (4, "Iași", 9)]                           # 4: right name, wrong county -> no match
    b = exp.build_declared(ROWS, employers, counties)
    assert b["institutions"][0][7] == 2
    assert b["lost_ties"] == [("COMUNA GAICEANA|BACAU", 3, 5, 2), ("COMUNA GAICEANA|BACAU", 1, 4, 2)]
    assert b["institutions"][1][7] is None


def test_clash_gets_a_stable_hash(exp):
    rows = [row("Comuna X", "Bacau", "A", 5), row("Comuna X!", "Bacau", "A", 5)]
    b = exp.build_declared(rows, {}, [])
    slugs = [i[0] for i in b["institutions"]]
    assert len(set(slugs)) == 2 and b["slug_clashes"] == 1


def test_real_csv_counts(exp):
    groups = exp.declaratii.Groups.load()
    if not groups.rows:
        pytest.skip("no groups file")
    b = exp.build_declared(groups.rows, {}, [])
    assert len(b["institutions"]) == len(groups.by_inst) and len(b["groups"]) == len(groups.rows)
    assert len({i[0] for i in b["institutions"]}) == len(b["institutions"])
    assert min(g[3] for g in b["groups"]) >= 5
    con = sqlite3.connect(":memory:")
    con.executescript(exp.DECLARED_DDL)
    con.executemany("INSERT INTO declared_institutions VALUES (?,?,?,?,?,?,?,?)", b["institutions"])
    con.executemany("INSERT INTO declared_groups VALUES (?,?,?,?,?,?,?,?,?,?,?)", b["groups"])
    assert con.execute("SELECT COUNT(*) FROM declared_groups").fetchone()[0] == len(groups.rows)

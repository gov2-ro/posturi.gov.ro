"""ops/build-declaratii-grupuri.py: groups, the n >= 5 rule, and no person data in the output."""
import csv
import gzip
import importlib.util
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("bdg", REPO_ROOT / "ops" / "build-declaratii-grupuri.py")
bdg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bdg)

COLS = ["aid", "an", "data_completare_iso", "source", "person", "functie", "institutie_id", "institutie",
        "institutie_norm", "judet_norm", "localitate", "tip_institutie", "n_lines", "sursa",
        "serviciul_prestat", "suma", "match"]


def _row(person, functie="Consilier superior", inst="PRIMARIA X", amount=72000, date="2024-05-01", **kw):
    r = dict.fromkeys(COLS, "")
    r.update(aid=person, an="2024", data_completare_iso=date, person=person, functie=functie,
             institutie_id=inst + "|IASI", institutie=inst.title(), institutie_norm=inst, judet_norm="IASI",
             tip_institutie="LOCAL", suma=str(amount), match="inst")
    r.update(kw)
    return r


def _write(path, rows):
    with gzip.open(path, "wt", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=COLS)
        w.writeheader()
        w.writerows(rows)


def test_group_needs_five_distinct_people(tmp_path):
    rows = [_row(f"p{i}", amount=60000 + i * 6000) for i in range(5)]
    rows += [_row(f"q{i}", functie="Referent") for i in range(4)]            # 4 people: dropped
    rows += [_row("p0", amount=1, date="2023-01-01")]                         # older, same person: ignored
    rows += [_row("p0", amount=5000)]                                         # under the yearly floor
    rows += [_row("e1", match="single")]                                      # not an `inst` row
    src = tmp_path / "s.csv.gz"
    _write(src, rows)
    out = bdg.build_groups(src)
    assert [(r["functie"], r["n"]) for r in out] == [("Consilier superior", 5)]
    assert out[0]["mediana_net_2023"] == 6000 and out[0]["familie"] == "admin-local"
    assert set(out[0]) <= set(bdg.COLUMNS)
    assert not ({"person", "aid", "suma"} & set(bdg.COLUMNS))


def test_elected_and_health_families_are_dropped(tmp_path):
    rows = [_row(f"p{i}", functie="Primar") for i in range(6)]
    rows += [_row(f"m{i}", functie="Medic", inst="SPITALUL Y") for i in range(6)]
    src = tmp_path / "s.csv.gz"
    _write(src, rows)
    assert bdg.build_groups(src) == []


def test_committed_table_has_no_small_group():
    path = REPO_ROOT / "data" / "salarii" / "declaratii-grupuri.csv"
    if not path.exists():
        return
    with path.open(encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    assert rows and all(int(r["n"]) >= 5 for r in rows)
    assert list(rows[0]) == bdg.COLUMNS

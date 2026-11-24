"""ops/anomaly-survey.py: one small fixture per check (ANOMALY-SURVEY, Step 1)."""

import csv
import importlib.util
import json
import sqlite3
import sys
from datetime import date
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]

POSTING_COLS = [
    "url", "title", "employer_name", "published_at", "expires_at", "apply_deadline",
    "deadline_source", "nr_posturi", "inf_salary_min", "inf_salary_max", "sal_min", "sal_max",
    "inf_experience_years", "occ_group", "inf_anomaly_flags", "job_regime", "employer_kind",
    "contact_email", "data_proba_scrisa", "data_interviu", "data_rezultate_finale",
    "v3_languages", "v3_credentials",
]


@pytest.fixture(scope="module")
def sv():
    spec = importlib.util.spec_from_file_location(
        "anomaly_survey_under_test", REPO_ROOT / "ops" / "anomaly-survey.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["anomaly_survey_under_test"] = mod
    spec.loader.exec_module(mod)
    return mod


def make_db(tmp_path, postings=(), events=(), roles=(), evidence=(), drop_cols=(), texts=None):
    path = tmp_path / "x.sqlite"
    db = sqlite3.connect(path)
    cols = [c for c in POSTING_COLS if c not in drop_cols]
    db.execute("CREATE TABLE job_postings (id INTEGER PRIMARY KEY, "
               "inf_anomaly_flags TEXT DEFAULT '[]', "
               + ", ".join(c for c in cols if c != "inf_anomaly_flags") + ")")
    db.execute("CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, posting_id INT, "
               "eveniment TEXT, data TEXT, ora TEXT)")
    db.execute("CREATE TABLE posting_roles (posting_id INT, ordinal INT, exp_years REAL, "
               "isced_fields TEXT, skills TEXT, credentials TEXT)")
    db.execute("CREATE TABLE requirement_evidence (posting_id INT, ordinal INT, fact_kind TEXT, "
               "fact_index INT, status TEXT)")
    if texts is not None:
        db.execute("CREATE TABLE posting_text (posting_id INTEGER PRIMARY KEY, body_markdown TEXT)")
        for pid, body in texts.items():
            db.execute("INSERT INTO posting_text VALUES (?,?)", (pid, body))
    for i, p in enumerate(postings, start=1):
        row = {"id": i, "url": f"https://x/{i}/", "title": f"Post {i}", "employer_name": f"Emp {i}",
               **p}
        row = {k: v for k, v in row.items() if k in cols or k == "id"}
        db.execute(f"INSERT INTO job_postings ({','.join(row)}) VALUES ({','.join('?' * len(row))})",
                   list(row.values()))
    for e in events:
        db.execute("INSERT INTO calendar_events (posting_id, eveniment, data) VALUES (?,?,?)", e)
    for r in roles:
        db.execute("INSERT INTO posting_roles VALUES (?,?,?,?,?,?)", r)
    for ev in evidence:
        db.execute("INSERT INTO requirement_evidence VALUES (?,?,?,?,?)", ev)
    db.commit()
    db.close()
    return str(path)


def run(sv, db_path, cid, **over):
    params = {**sv.DEFAULTS, **over}
    ctx = sv.Ctx(db_path, params, sv.Holidays(None), None)
    report, rows = sv.run(ctx, {cid})
    return report[cid], rows


def counts(sv, tmp_path, cid, postings=(), **kw):
    over = kw.pop("over", {})
    e, rows = run(sv, make_db(tmp_path, postings, **kw), cid, **over)
    assert e["status"] == "ok", e
    return e, rows


A = {"deadline_source": "anunt"}


def test_r01_nr_posturi(sv, tmp_path):
    e, rows = counts(sv, tmp_path, "R01", [{"nr_posturi": 339395}, {"nr_posturi": 3}, {"nr_posturi": 21}])
    assert e["count"] == 2 and e["denominator"] == 3 and e["extra"]["rows_equal_339395"] == 1
    assert e["sample_urls"] == ["https://x/1/", "https://x/3/"]


def test_r02_year_outside(sv, tmp_path):
    ev = [(1, "a", "2019-01-01"), (1, "b", "2025-01-01"), (1, "c", "2028-03-03")]
    e, _ = counts(sv, tmp_path, "R02", [{}], events=ev)
    assert (e["count"], e["denominator"]) == (2, 3)


def test_r03_junk_label(sv, tmp_path):
    ev = [(1, "–", "2025-01-01"), (1, "", "2025-01-01"), (1, " • ", "2025-01-01"),
          (1, "- depunere", "2025-01-01")]
    e, _ = counts(sv, tmp_path, "R03", [{}], events=ev)
    assert (e["count"], e["denominator"]) == (3, 4)


def test_r04_hourly_salary(sv, tmp_path):
    e, _ = counts(sv, tmp_path, "R04", [{"inf_salary_min": 65}, {"inf_salary_min": 5000}, {}])
    assert (e["count"], e["denominator"]) == (1, 2)


def test_r05_employer_variants(sv, tmp_path):
    p = [{"employer_name": "Primăria Șimleu"}, {"employer_name": "Primaria Simleu"},
         {"employer_name": "Spital X"}]
    e, rows = counts(sv, tmp_path, "R05", p)
    assert (e["count"], e["denominator"]) == (1, 2)
    assert "Primaria Simleu" in rows[0][2]


def test_r06_zero_day(sv, tmp_path):
    p = [{"published_at": "2025-03-03", "apply_deadline": "2025-03-03", **A},
         {"published_at": "2025-03-03", "apply_deadline": "2025-03-10", **A},
         {"published_at": "2025-03-03", "apply_deadline": "2025-03-03", "deadline_source": "expirare"}]
    e, _ = counts(sv, tmp_path, "R06", p)
    assert (e["count"], e["denominator"]) == (1, 2)


def test_r07_deadline_before_publication(sv, tmp_path):
    p = [{"published_at": "2025-03-03", "apply_deadline": "2025-03-01", **A},
         {"published_at": "2025-03-03", "apply_deadline": "2025-03-04", **A}]
    e, rows = counts(sv, tmp_path, "R07", p)
    assert e["count"] == 1 and rows[0][2] == -2


def test_r08_short_deadline_flag(sv, tmp_path):
    p = [{"inf_anomaly_flags": '["short_deadline"]', "published_at": "2025-03-03",
          "apply_deadline": "2025-03-03", **A},
         {"inf_anomaly_flags": "[]", "published_at": "2025-03-03", "apply_deadline": "2025-03-20", **A}]
    e, _ = counts(sv, tmp_path, "R08", p)
    assert e["count"] == 1 and e["extra"]["recomputed_short_confirmed"] == 1


def test_r09_stage_order(sv, tmp_path):
    p = [{"apply_deadline": "2025-03-10", "data_proba_scrisa": "2025-03-05T00:00:00+02:00",
          "data_interviu": "2025-03-04", "data_rezultate_finale": "2025-03-03"},
         {"apply_deadline": "2025-03-10", "data_proba_scrisa": "2025-03-15",
          "data_interviu": "2025-03-16", "data_rezultate_finale": "2025-03-17"}]
    db = make_db(tmp_path, p)
    for cid in ("R09a", "R09b", "R09c"):
        e, _ = run(sv, db, cid)
        assert (e["count"], e["denominator"]) == (1, 2), cid


def test_r10_test_just_after_deadline(sv, tmp_path):
    p = [{"apply_deadline": "2025-03-10", "data_proba_scrisa": "2025-03-12"},
         {"apply_deadline": "2025-03-10", "data_proba_scrisa": "2025-03-20"},
         {"apply_deadline": "2025-03-10", "data_proba_scrisa": "2025-03-10"}]
    e, _ = counts(sv, tmp_path, "R10", p)
    assert (e["count"], e["denominator"]) == (2, 3)


def test_r11_weekend_event(sv, tmp_path):
    ev = [(1, "a", "2025-03-01"), (1, "b", "2025-03-02"), (1, "c", "2025-03-03"), (1, "d", "2019-03-02")]
    e, _ = counts(sv, tmp_path, "R11", [{}], events=ev)
    assert (e["count"], e["denominator"]) == (2, 3)


def test_r12_debutant_experience(sv, tmp_path):
    p = [{"title": "Referent DEBUTANT", "inf_experience_years": 2},
         {"title": "Referent debutant", "inf_experience_years": 0},
         {"title": "Referent", "inf_experience_years": 5}]
    e, _ = counts(sv, tmp_path, "R12", p)
    assert (e["count"], e["denominator"]) == (1, 2)


def test_r13_experience_outlier(sv, tmp_path):
    p = [{"occ_group": "5", "inf_experience_years": 1} for _ in range(30)]
    p.append({"occ_group": "5", "inf_experience_years": 9})
    p.append({"occ_group": "6", "inf_experience_years": 20})  # group too small
    e, rows = counts(sv, tmp_path, "R13", p)
    assert e["count"] == 1 and e["denominator"] == 31 and rows[0][2] == 9


def test_r14_very_high_experience(sv, tmp_path):
    p = [{"inf_experience_years": 25}, {"inf_experience_years": 15}, {"inf_experience_years": 16}]
    e, _ = counts(sv, tmp_path, "R14", p)
    assert (e["count"], e["denominator"]) == (2, 3)


def test_r15_employer_short_share(sv, tmp_path):
    short = '["short_deadline"]'
    p = [{"employer_name": "A", "inf_anomaly_flags": short} for _ in range(5)]
    p += [{"employer_name": "A", "inf_anomaly_flags": "[]"} for _ in range(3)]
    p += [{"employer_name": "B", "inf_anomaly_flags": "[]"} for _ in range(8)]
    e, rows = counts(sv, tmp_path, "R15", p)
    assert (e["count"], e["denominator"]) == (1, 2) and rows[0][2] == 0.625


def test_r16_repeat_title(sv, tmp_path):
    p = [{"employer_name": "A", "title": "Îngrijitor"}, {"employer_name": "A", "title": "ingrijitor"},
         {"employer_name": "A", "title": "INGRIJITOR "}, {"employer_name": "A", "title": "Paznic"}]
    e, _ = counts(sv, tmp_path, "R16", p)
    assert (e["count"], e["denominator"]) == (1, 2)


def test_r17_same_day_burst(sv, tmp_path):
    p = [{"employer_name": "A", "published_at": "2025-03-03"} for _ in range(10)]
    p += [{"employer_name": "A", "published_at": "2025-03-04"}]
    e, _ = counts(sv, tmp_path, "R17", p)
    assert (e["count"], e["denominator"]) == (1, 2)


def test_r18_context_only(sv, tmp_path):
    p = [{"contact_email": "a@gmail.com"}, {"contact_email": "b@primarie.ro"}, {"contact_email": ""}]
    e, rows = counts(sv, tmp_path, "R18", p)
    assert e["count"] == 0 and rows == [] and e["extra"]["gmail_yahoo"] == 1 and e["denominator"] == 2


def test_r19_short_expiry(sv, tmp_path):
    p = [{"published_at": "2025-03-03", "expires_at": "2025-03-05"},
         {"published_at": "2025-03-03", "expires_at": "2025-03-08"}]
    e, _ = counts(sv, tmp_path, "R19", p)
    assert (e["count"], e["denominator"]) == (1, 2)


def test_r20_many_skills(sv, tmp_path):
    roles = [(1, 0, None, "[]", json.dumps([str(i) for i in range(12)]), "[]"),
             (1, 1, None, "[]", json.dumps(["a"]), "[]")]
    e, _ = counts(sv, tmp_path, "R20", [{}], roles=roles)
    assert (e["count"], e["denominator"]) == (1, 2)


def test_e01_working_days_by_regime(sv, tmp_path):
    # 2025-03-03 is a Monday: Tue..Fri + Mon..Fri = 9 working days to 2025-03-14 -> below 10
    p = [{"published_at": "2025-03-03", "apply_deadline": "2025-03-14", "job_regime": "contractuala", **A},
         {"published_at": "2025-03-03", "apply_deadline": "2025-03-17", "job_regime": "contractuala", **A},
         {"published_at": "2025-03-03", "apply_deadline": "2025-03-14", "job_regime": "publica", **A}]
    e, rows = counts(sv, tmp_path, "E01", p)
    assert e["count"] == 1 and rows[0][2] == 9
    dist = e["extra"]["distribution_by_job_regime"]
    assert dist["contractuala"]["n"] == 2 and dist["publica"]["5-9"] == 1


def test_e02_window_across_holiday(sv, tmp_path):
    # 2025 Orthodox Easter is 2025-04-20; window 2025-04-14..2025-04-22 holds it and has few days.
    p = [{"published_at": "2025-04-14", "apply_deadline": "2025-04-22", **A},
         {"published_at": "2025-03-03", "apply_deadline": "2025-03-10", **A}]
    e, rows = counts(sv, tmp_path, "E02", p)
    assert e["count"] == 1 and "easter" in rows[0][3]


def test_e03_repost_chain(sv, tmp_path):
    p = [{"employer_name": "A", "title": "Paznic", "published_at": "2025-01-01"},
         {"employer_name": "a", "title": "PAZNIC", "published_at": "2025-02-01"},
         {"employer_name": "A", "title": "Paznic", "published_at": "2025-09-01"},
         {"employer_name": "A", "title": "Paznic", "published_at": "2025-09-01"}]
    e, rows = counts(sv, tmp_path, "E03", p)
    assert e["count"] == 1 and rows[0][2] == 31 and e["extra"]["parallel_pairs_not_counted"] == 1


def test_e03_parallel_and_gap(sv, tmp_path):
    same_day = [{"employer_name": "B", "title": "Admin", "published_at": "2025-11-05"},
                {"employer_name": "B", "title": "Admin", "published_at": "2025-11-05"}]
    (tmp_path / "a").mkdir()
    (tmp_path / "b").mkdir()
    e, _ = counts(sv, tmp_path / "a", "E03", same_day)
    assert e["count"] == 0 and e["extra"]["parallel_pairs_not_counted"] == 1
    twenty = [{"employer_name": "C", "title": "Admin", "published_at": "2025-11-01"},
              {"employer_name": "C", "title": "Admin", "published_at": "2025-11-21"}]
    e, rows = counts(sv, tmp_path / "b", "E03", twenty)
    assert e["count"] == 1 and rows[0][2] == 20


def test_e04_edit_after_publication(sv, tmp_path):
    db = make_db(tmp_path, [{}, {}])
    idx = tmp_path / "index.csv"
    with open(idx, "w", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["url", "updates"])
        w.writerow(["https://x/1/", "2026-05-26: New entry; 2026-06-08: pozitie, expira_in"])
        w.writerow(["https://x/2/", "2026-05-26: New entry"])
    ctx = sv.Ctx(db, sv.DEFAULTS, sv.Holidays(None), str(idx))
    report, _ = sv.run(ctx, {"E04"})
    assert report["E04"]["count"] == 1 and report["E04"]["extra"]["title_changed"] == 1
    # Without the index CSV the check is reported as unavailable, not as zero.
    e, _ = run(sv, db, "E04")
    assert e["status"] == "unavailable"


def test_e05_narrow_requirements(sv, tmp_path):
    n = 40
    p = [{"occ_group": "3"} for _ in range(n)]
    roles, ev = [], []
    for pid in range(1, n + 1):
        roles.append((pid, 0, 1.0, '["common"]', "[]", "[]"))
        ev.append((pid, 0, "experience", 0, "verified"))
        ev.append((pid, 0, "education", 0, "verified"))
        ev.append((pid, 0, "skill", 0, "verified"))
    # Role 1 is far above its peers on experience and skills, and has a rare field.
    roles[0] = (1, 0, 12.0, '["rare"]', "[]", "[]")
    ev += [(1, 0, "skill", i, "verified") for i in range(1, 15)]
    # Role 2 has the same numbers but they are unverified: it must not hit.
    roles[1] = (2, 0, 12.0, '["common"]', "[]", "[]")
    ev = [x for x in ev if not (x[0] == 2 and x[2] == "experience")]
    e, rows = counts(sv, tmp_path, "E05", p, roles=roles, evidence=ev)
    assert [r[0] for r in rows] == [1] and "exp=12.0" in rows[0][2] and "skill=15" in rows[0][2]


def test_e06_pay_vs_grid(sv, tmp_path):
    g = {"sal_min": 6000, "sal_max": 7000}
    p = [{"inf_salary_min": 9000, "inf_salary_max": 9500, **g},     # flagged
         {"inf_salary_min": 6500, "inf_salary_max": 6800, **g},     # inside
         {"inf_salary_min": 65, "inf_salary_max": 70, **g},          # hourly, excluded
         {"inf_salary_min": 3000, "inf_salary_max": 3000, **g}]      # below minimum wage, excluded
    e, rows = counts(sv, tmp_path, "E06", p)
    assert (e["count"], e["denominator"]) == (1, 2) and rows[0][0] == 1


def test_e06_seniority_spread_not_a_hit(sv, tmp_path):
    g = {"sal_min": 4325, "sal_max": 4428}
    p = [{"inf_salary_min": 4325, "inf_salary_max": 5939, **g},     # wide range, min = grid min
         {"inf_salary_min": 4100, "inf_salary_max": 4200, **g}]  # min 5% below, not a hit
    e, rows = counts(sv, tmp_path, "E06", p)
    assert e["count"] == 0 and e["extra"]["range_wider_than_grid_not_counted"] == 2


def test_e07_rare_freemail(sv, tmp_path):
    p = [{"employer_kind": "universitate", "contact_email": "a@gmail.com"}]
    p += [{"employer_kind": "universitate", "contact_email": f"u{i}@uni.ro"} for i in range(9)]
    p += [{"employer_kind": "scoala", "contact_email": f"s{i}@yahoo.com"} for i in range(5)]
    e, rows = counts(sv, tmp_path, "E07", p)
    assert e["count"] == 1 and rows[0][0] == 1 and e["extra"]["rare_kinds"] == ["universitate"]


def test_e08_old_event(sv, tmp_path):
    ev = [(1, "a", "2025-01-01"), (1, "b", "2025-02-20"), (1, "c", "2025-03-10")]
    e, _ = counts(sv, tmp_path, "E08", [{"published_at": "2025-03-01"}], events=ev)
    assert (e["count"], e["denominator"]) == (1, 3)


def test_missing_column_is_reported_not_hidden(sv, tmp_path):
    db = make_db(tmp_path, [{}], drop_cols=("nr_posturi",))
    e, _ = run(sv, db, "R01")
    assert e["status"] == "unavailable" and e["missing"] == ["job_postings.nr_posturi"]


def test_working_days_and_easter(sv):
    assert sv.orthodox_easter(2026) == date(2026, 4, 12)
    assert sv.orthodox_easter(2025) == date(2025, 4, 20)
    h = sv.Holidays(REPO_ROOT.parent / "core-assets/data/zile-libere/ro-holidays.csv")
    # Computed holidays agree with the core-assets file for 2026.
    if h.csv_years.get(2026):
        assert sv.computed_holidays(2026) == h.csv_years[2026]
    # 2026-04-06 Mon .. 2026-04-14 Tue: Good Friday 10th and Easter Monday 13th are not working days.
    assert h.working_days(date(2026, 4, 6), date(2026, 4, 14)) == 4


def test_norm_title_is_the_project_function(sv):
    assert sv._norm_title("Îngrijitor, (femeie)") == "ingrijitor femeie"


def test_cli_writes_json_and_csv(sv, tmp_path):
    db = make_db(tmp_path, [{"nr_posturi": 339395}])
    out_j, out_c = tmp_path / "r.json", tmp_path / "r.csv"
    assert sv.main([db, "--json", str(out_j), "--csv", str(out_c), "--only", "R01"]) == 0
    assert json.loads(out_j.read_text())["checks"]["R01"]["count"] == 1
    assert out_c.read_text().splitlines()[0] == "posting_id,check,value,peer_value"


# ---- Table mode (ANOMALY-PUBLIC) -------------------------------------------

def table_run(sv, db_path, tmp_path, reviews=None, register=None):
    ctx = sv.Ctx(db_path, sv.DEFAULTS, sv.Holidays(None), None)
    report, rows = sv.run(ctx)
    ctx.db.close()
    reg_path = tmp_path / "reg.json"
    reg_path.write_text(json.dumps({"checks": register or {}}), encoding="utf-8")
    rev = sv.load_reviews(reviews or tmp_path / "missing.csv")
    return sv.write_tables(db_path, report, rows, sv.load_register(reg_path), rev)


def test_table_mode_writes_rows_and_replaces(sv, tmp_path):
    db_path = make_db(tmp_path, [{"nr_posturi": 339395}, {"nr_posturi": 3}, {"nr_posturi": 21}])
    reg = {"R01": {"kind": "error_ours", "label_ro": "L", "group": "erori_noastre", "explain_ro": "X", "caveat_ro": "C"}}
    n = table_run(sv, db_path, tmp_path, register=reg)
    assert n["anomaly_signals"] >= 2
    db = sqlite3.connect(db_path)
    r01 = db.execute("SELECT posting_id, value, peer_value FROM anomaly_signals WHERE check_id='R01' "
                     "ORDER BY posting_id").fetchall()
    assert r01 == [(1, "339395", "20"), (3, "21", "20")]
    row = db.execute("SELECT kind, label_ro, group_id, caveat_ro, count, denominator, reviewed FROM anomaly_checks "
                     "WHERE check_id='R01'").fetchone()
    assert row == ("error_ours", "L", "erori_noastre", "C", 2, 3, 0)
    # a check with no register entry still gets a row, with the default group and no caveat
    assert db.execute("SELECT group_id, caveat_ro FROM anomaly_checks WHERE check_id='R02'"
                      ).fetchone() == ("neobisnuit", "")
    assert "state" not in {r[1] for r in db.execute("PRAGMA table_info(anomaly_checks)")}
    idx = {r[1] for r in db.execute("PRAGMA index_list(anomaly_signals)")}
    assert len(idx) == 2
    db.execute("INSERT INTO anomaly_signals VALUES (99,'ZZ','','','{}')")
    db.commit()
    db.close()
    table_run(sv, db_path, tmp_path, register=reg)  # second run replaces both tables
    db = sqlite3.connect(db_path)
    assert db.execute("SELECT COUNT(*) FROM anomaly_signals WHERE check_id='ZZ'").fetchone() == (0,)
    assert db.execute("SELECT COUNT(*) FROM anomaly_checks WHERE check_id='R01'").fetchone() == (1,)


def test_table_mode_reviews_and_missing_file(sv, tmp_path):
    db_path = make_db(tmp_path, [{"nr_posturi": 339395}, {"nr_posturi": 30}])
    rev = tmp_path / "rev.csv"
    rev.write_text("check,posting_id,verdict,reviewer,date,note\n"
                   "R01,1,real,pax,2026-11-23,\nR01,2,false_positive,pax,2026-11-23,x\n"
                   "R01,2,bogus,pax,2026-11-23,\n", encoding="utf-8")
    table_run(sv, db_path, tmp_path, reviews=rev)
    db = sqlite3.connect(db_path)
    assert db.execute("SELECT reviewed, real, false_positive FROM anomaly_checks WHERE check_id='R01'"
                      ).fetchone() == (2, 1, 1)
    d = dict(db.execute("SELECT posting_id, detail_json FROM anomaly_signals WHERE check_id='R01'").fetchall())
    assert json.loads(d[2]) == {"verdict": "false_positive"}
    db.close()
    # a missing reviews file is not an error
    table_run(sv, db_path, tmp_path)
    db = sqlite3.connect(db_path)
    assert db.execute("SELECT reviewed FROM anomaly_checks WHERE check_id='R01'").fetchone() == (0,)


def test_table_mode_unavailable_check_has_null_counts(sv, tmp_path):
    db_path = make_db(tmp_path, [{}], drop_cols=("nr_posturi",))
    table_run(sv, db_path, tmp_path)
    db = sqlite3.connect(db_path)
    assert db.execute("SELECT count, denominator FROM anomaly_checks WHERE check_id='R01'"
                      ).fetchone() == (None, None)


def _text_check(sv, tmp_path, cid, hit, near_miss):
    e, rows = counts(sv, tmp_path, cid, [{}, {}, {}], texts={1: hit, 2: near_miss, 3: "Text fără semnal."})
    assert [r[0] for r in rows] == [1] and (e["count"], e["denominator"]) == (1, 3)
    return rows[0]


def test_r21_domicile_advantage(sv, tmp_path):
    row = _text_check(sv, tmp_path, "R21", "Constituie avantaj domiciliul în UAT pentru care aplică;",
                      "Avantaj: permis de conducere. Domiciliul se dovedește cu buletin.")
    assert row[2] == "avantaj domicili"
    assert row[4]["excerpt"][row[4]["excerpt_start"]:row[4]["excerpt_end"]] == "avantaj domiciliul"   # the whole word is marked


def test_r22_project_advantage(sv, tmp_path):
    _text_check(sv, tmp_path, "R22", "Constituie avantaj activitatea în proiectul pilot MySMIS 122607",
                "Constituie avantaj cursul de limba engleză. Proiectul de ordin se publică.")


def test_r22_generic_projects_are_not_a_hit(sv, tmp_path):
    e, rows = counts(sv, tmp_path, "R22", [{}], texts={1: "Constituie avantaj experiența în proiecte educaționale."})
    assert rows == [] and e["count"] == 0


def test_r25_age_or_sex(sv, tmp_path):
    e, rows = counts(sv, tmp_path, "R25", [{}, {}, {}, {}, {}, {}], texts={
        1: "au vârsta de cel mult 45 de ani la data numirii", 2: "candidați de sex masculin",
        3: "Vârsta minimă reglementată de prevederile legale", 4: "nu mai mare de 35 de ani",
        5: "Sexul nu are importanță.", 6: "copii cu vârsta de până la 2 ani"})
    assert sorted(r[0] for r in rows) == [1, 2, 4] and e["denominator"] == 6


def test_text_checks_unavailable_without_posting_text(sv, tmp_path):
    e, _ = run(sv, make_db(tmp_path, [{}]), "R21")
    assert e["status"] == "unavailable" and "posting_text (table)" in e["missing"]


def test_r24_conditions_above_occupation(sv, tmp_path):
    n = 31
    p = [{"occ_group": "3", "inf_experience_years": 2.0, "v3_languages": "[]",
          "v3_credentials": '["aviz_medical"]'} for _ in range(n)]
    p[0] = {**p[0], "inf_experience_years": 5.0, "v3_languages": '["en:C1"]'}      # hit: language
    p[1] = {**p[1], "inf_experience_years": 4.0, "v3_credentials": '["autorizatie"]'}  # hit: credential
    p[2] = {**p[2], "inf_experience_years": 5.0}                                   # near miss: generic only
    p[3] = {**p[3], "inf_experience_years": 3.0, "v3_languages": '["en:C1"]'}      # near miss: < 2 x median
    p[4] = {**p[4], "inf_experience_years": 5.0, "v3_languages": '["en:B2"]'}      # near miss: language < C1
    e, rows = counts(sv, tmp_path, "R24", p)
    assert sorted(r[0] for r in rows) == [1, 2] and e["denominator"] == n


def test_register_file_is_complete_and_has_groups(sv):
    reg = sv.load_register(sv.DEFAULT_CHECKS)
    assert {c["id"] for c in sv.CHECKS} == set(reg)
    assert all("state" not in v for v in reg.values())
    assert all(v["group"] == sv.GROUP_OF_KIND[v["kind"]] for v in reg.values())
    assert all(reg[c]["caveat_ro"] for c in ("R18", "R20", "E01", "E05", "R22", "R24", "R25"))
    assert all(v["kind"] in ("error_ours", "error_posting", "unusual", "unusual_employer", "context")
               for v in reg.values())


# ---- Source quote (2026-11-24) ----------------------------------------------

def test_excerpt_is_the_paragraph_with_the_match(sv):
    body = "Primul paragraf.\n\nCondiții: vârsta maximă 45 ani,\nla data numirii.\n\nAl treilea."
    s0 = body.index("vârsta maximă 45")
    d = sv.excerpt_around(body, s0, s0 + len("vârsta maximă 45"))
    assert d["excerpt"] == "Condiții: vârsta maximă 45 ani,\nla data numirii."   # line breaks stay
    assert d["excerpt"][d["excerpt_start"]:d["excerpt_end"]] == "vârsta maximă 45"
    assert not d["cut_start"] and not d["cut_end"]


def test_excerpt_drops_markdown_marks(sv):
    body = "| **Criteriu** | Punctaj |\n|---|---|\n| Domiciliul în comună | 5 |\n* vârsta maximă 45 ani\n- studii medii"
    s0 = body.index("vârsta")
    d = sv.excerpt_around(body, s0, s0 + len("vârsta maximă 45"))
    assert d["excerpt"] == "Criteriu · Punctaj\nDomiciliul în comună · 5\n• vârsta maximă 45 ani\n• studii medii"
    assert d["excerpt"][d["excerpt_start"]:d["excerpt_end"]] == "vârsta maximă 45"


def test_excerpt_is_cut_on_a_word(sv):
    body = ("cuvânt " * 200) + "MATCH" + (" cuvânt" * 200)
    s0 = body.index("MATCH")
    d = sv.excerpt_around(body, s0, s0 + 5)
    assert len(d["excerpt"]) <= 600 and d["cut_start"] and d["cut_end"]
    assert d["excerpt"][d["excerpt_start"]:d["excerpt_end"]] == "MATCH"
    assert d["excerpt"].startswith("cuvânt") and d["excerpt"].endswith("cuvânt")


def test_text_check_stores_the_excerpt(sv, tmp_path):
    _, rows = counts(sv, tmp_path, "R25", [{}], texts={1: "Alt text.\n\nCandidați de sex masculin, conform legii."})
    d = rows[0][4]
    assert d["source"] == "text" and "de sex masculin" in d["excerpt"]
    assert d["excerpt"][d["excerpt_start"]:d["excerpt_end"]] == "de sex masculin"
    # table mode keeps it in detail_json
    (tmp_path / "t").mkdir()
    db_path = make_db(tmp_path / "t", [{}], texts={1: "Candidați de sex masculin."})
    table_run(sv, db_path, tmp_path)
    db = sqlite3.connect(db_path)
    (dj,) = db.execute("SELECT detail_json FROM anomaly_signals WHERE check_id='R25'").fetchone()
    assert json.loads(dj)["excerpt"] == "Candidați de sex masculin."


def test_calendar_checks_store_the_calendar_line(sv, tmp_path):
    e, rows = counts(sv, tmp_path, "R11", [{"published_at": "2026-01-02"}],
                     events=[(1, "Proba scrisă", "2026-01-03")])   # a Saturday
    d = rows[0][4]
    assert d["source"] == "calendar"
    assert (d["calendar"][0]["eveniment"], d["calendar"][0]["data"]) == ("Proba scrisă", "2026-01-03")


def test_stage_check_stores_the_lines_it_compares(sv, tmp_path):
    p = [{"apply_deadline": "2026-03-10", "data_proba_scrisa": "2026-03-05"}]
    ev = [(1, "Depunerea dosarelor", "2026-03-10"), (1, "Proba scrisă", "2026-03-05")]
    _, rows = counts(sv, tmp_path, "R09a", p, events=ev)
    assert [l["eveniment"] for l in rows[0][4]["calendar"]] == ["Depunerea dosarelor", "Proba scrisă"]
    # no matching line: nothing is stored
    (tmp_path / "b").mkdir()
    _, rows = counts(sv, tmp_path / "b", "R09a", p)
    assert rows[0][4] is None


def test_number_checks_store_no_detail(sv, tmp_path):
    _, rows = counts(sv, tmp_path, "R01", [{"nr_posturi": 30}])
    assert rows[0][4] is None

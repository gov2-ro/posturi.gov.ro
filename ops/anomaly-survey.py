#!/usr/bin/env python3
"""Read-only survey of unusual postings in an export SQLite (ANOMALY-SURVEY, Step 1).

Brief: docs/briefs/2026-11-22-posting-anomalies.md.

The script never writes to the database. It reads `job_postings`, `calendar_events`,
`posting_roles` and `requirement_evidence`, runs one function per check, and writes:

  * a JSON report: per check the count, the denominator, the share, up to 5 sample URLs,
    notes and extra numbers;
  * a CSV `posting_id, check, value, peer_value`: one row per finding.

A check that needs a column or table that the export does not hold is reported as
`unavailable` with the list of missing columns. The other checks still run.

Usage:
    ops/anomaly-survey.py EXPORT.sqlite --json out.json --csv out.csv
    ops/anomaly-survey.py EXPORT.sqlite --index-csv data/posturi_gov_ro.csv   # enables E1
    ops/anomaly-survey.py EXPORT.sqlite --write-table   # ANOMALY-PUBLIC: write the export tables
    ops/anomaly-survey.py --help

Standard library only. `_norm_title` is read from the Django management command by
parsing its source, so the script runs without Django settings.
"""
from __future__ import annotations

import argparse
import ast
import csv
import json
import math
import re
import sqlite3
import statistics
import sys
import unicodedata
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INFER_SRC = ROOT / "webapp/apps/jobs/management/commands/infer_postings.py"
DEFAULT_HOLIDAYS = ROOT.parent / "core-assets/data/zile-libere/ro-holidays.csv"


# --------------------------------------------------------------------------
# Reuse of `_norm_title` (no copy of the logic)
# --------------------------------------------------------------------------

def _load_norm_title():
    """Execute only `_NORM_TITLE_RE` and `_norm_title` from infer_postings.py."""
    tree = ast.parse(INFER_SRC.read_text(encoding="utf-8"))
    keep = []
    for node in tree.body:
        if isinstance(node, ast.Assign) and any(
            isinstance(t, ast.Name) and t.id == "_NORM_TITLE_RE" for t in node.targets
        ):
            keep.append(node)
        elif isinstance(node, ast.FunctionDef) and node.name == "_norm_title":
            keep.append(node)
    ns: dict = {"re": re, "unicodedata": unicodedata}
    exec(compile(ast.Module(body=keep, type_ignores=[]), str(INFER_SRC), "exec"), ns)
    return ns["_norm_title"]


_norm_title = _load_norm_title()


# --------------------------------------------------------------------------
# Parameters (thresholds). Every number that is a choice sits here.
# --------------------------------------------------------------------------

DEFAULTS = {
    "year_min": 2024,            # row 2
    "year_max": 2026,            # row 2
    "nr_posturi_max": 20,        # row 1
    "salary_floor_monthly": 1000,  # row 4
    "short_days": 7,             # row 8, 15
    "short_expiry_days": 5,      # row 19
    "exp_high_years": 7,         # row 13
    "exp_very_high_years": 16,   # row 14
    "group_min_n": 30,           # row 13, E5
    "employer_min_postings": 8,  # row 15
    "employer_short_share": 0.5,  # row 15
    "repeat_min": 3,             # row 16
    "same_day_min": 10,          # row 17
    "skills_min": 12,            # row 20
    "holiday_window_min_wd": 10,  # E2: "fewer than N working days"
    "repost_days": 90,           # E3
    "repost_min_days": 14,       # E3: a closer pair is a parallel post, not a repost
    "narrow_top_share": 0.02,    # E5
    "narrow_min_hits": 2,        # E5
    "isced_rare_share": 0.02,    # E5
    "pay_tolerance": 0.20,       # E6
    "min_wage_gross": 4050,      # E6: UNCONFIRMED parameter, set it per year
    "freemail_rare_share": 0.15,  # E7
    "old_event_days": 30,        # E8
    # Legal minimum application period, in working days. Contract staff: HG 1336/2022
    # art. 34 (confirmed in the consolidated text of 26.03.2025): 10 for a vacant post,
    # 5 for a temporary vacant post. Civil servants: NOT confirmed, so no default.
    "min_wd_contract_vacant": 10,
    "min_wd_contract_temp": 5,
    "min_wd_civil_servant": None,
}

CONFIRMED_SOURCES = ("concurs", "anunt")  # apply_deadline sources that are a real deadline


# --------------------------------------------------------------------------
# Small helpers
# --------------------------------------------------------------------------

def d10(value) -> date | None:
    """First 10 characters as an ISO date, else None."""
    if not value:
        return None
    try:
        return date.fromisoformat(str(value)[:10])
    except ValueError:
        return None


def days_between(a: date | None, b: date | None) -> int | None:
    return None if a is None or b is None else (b - a).days


def percentile_value(sorted_vals: list[float], share_top: float) -> float:
    """Smallest value that is in the top `share_top` of the list."""
    idx = max(0, math.ceil(len(sorted_vals) * (1 - share_top)) - 1)
    return sorted_vals[idx]


def json_len(text) -> int:
    try:
        v = json.loads(text or "[]")
    except (TypeError, ValueError):
        return 0
    return len(v) if isinstance(v, list) else 0


def orthodox_easter(year: int) -> date:
    """Orthodox Easter Sunday (Julian computus, +13 days; valid 1900-2099)."""
    a, b, c = year % 4, year % 7, year % 19
    d = (19 * c + 15) % 30
    e = (2 * a + 4 * b - d + 34) % 7
    month, day = divmod(d + e + 114, 31)
    return date(year, month, day + 1) + timedelta(days=13)


def computed_holidays(year: int) -> set[date]:
    easter = orthodox_easter(year)
    fixed = [(1, 1), (1, 2), (1, 6), (1, 7), (1, 24), (5, 1), (6, 1), (8, 15),
             (11, 30), (12, 1), (12, 25), (12, 26)]
    out = {date(year, m, d) for m, d in fixed}
    out |= {easter - timedelta(days=2), easter, easter + timedelta(days=1),
            easter + timedelta(days=49), easter + timedelta(days=50)}
    return out


class Holidays:
    """Legal holidays: the core-assets CSV for the years it holds, a computed set else."""

    def __init__(self, path: Path | None):
        self.csv_years: dict[int, set[date]] = defaultdict(set)
        self.source = "computed (fixed dates + Orthodox Easter)"
        if path and Path(path).exists():
            with open(path, encoding="utf-8", newline="") as fh:
                for row in csv.DictReader(fh):
                    d = d10(row.get("date"))
                    if d:
                        self.csv_years[d.year].add(d)
            self.source = f"{path} for {sorted(self.csv_years)}; computed for other years"
        self._cache: dict[int, set[date]] = {}

    def year(self, y: int) -> set[date]:
        if y not in self._cache:
            self._cache[y] = self.csv_years.get(y) or computed_holidays(y)
        return self._cache[y]

    def is_holiday(self, d: date) -> bool:
        return d in self.year(d.year)

    def working_days(self, start: date, end: date) -> int:
        """Working days in (start, end]: the day after publication up to the deadline."""
        n, d = 0, start
        while d < end:
            d += timedelta(days=1)
            if d.weekday() < 5 and not self.is_holiday(d):
                n += 1
        return n


# --------------------------------------------------------------------------
# Context: the database, read once
# --------------------------------------------------------------------------

class Ctx:
    def __init__(self, db_path: str, params: dict, holidays: Holidays, index_csv: str | None):
        uri = f"file:{db_path}?mode=ro"
        self.db = sqlite3.connect(uri, uri=True)
        self.db.row_factory = sqlite3.Row
        self.params = params
        self.holidays = holidays
        self.index_csv = index_csv
        self.columns: dict[str, set[str]] = {}
        for (t,) in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'"):
            self.columns[t] = {r[1] for r in self.db.execute(f'PRAGMA table_info("{t}")')}
        self.postings: list[dict] = []
        if "job_postings" in self.columns:
            self.postings = [dict(r) for r in self.db.execute("SELECT * FROM job_postings")]
        self.by_id = {p["id"]: p for p in self.postings}
        for p in self.postings:
            p["_pub"] = d10(p.get("published_at"))
            p["_dl"] = d10(p.get("apply_deadline"))
            p["_emp"] = _norm_title(p.get("employer_name") or "")
        self._events = None
        self._cal_by_posting = None
        self._texts = None

    def missing(self, needs: dict[str, list[str]]) -> list[str]:
        out = []
        for table, cols in needs.items():
            have = self.columns.get(table)
            if have is None:
                out.append(f"{table} (table)")
                continue
            out += [f"{table}.{c}" for c in cols if c not in have]
        return out

    def url(self, posting_id) -> str:
        p = self.by_id.get(posting_id)
        return p["url"] if p else ""

    def events(self) -> list[dict]:
        """calendar_events rows with the parsed date and the posting's publication date."""
        if self._events is None:
            self._events = []
            if "calendar_events" in self.columns:
                for r in self.db.execute("SELECT posting_id, eveniment, data, ora FROM calendar_events"):
                    e = dict(r)
                    e["_d"] = d10(e["data"])
                    e["_year_raw"] = str(e["data"] or "")[:4]
                    p = self.by_id.get(e["posting_id"])
                    e["_pub"] = p["_pub"] if p else None
                    self._events.append(e)
        return self._events

    def calendar_lines(self, posting_id, d, keyword_re=None) -> list[dict]:
        """The calendar line of a posting dated `d` (one line; the one whose label matches
        `keyword_re` first). Empty list when the posting has no line on that day."""
        if self._cal_by_posting is None:
            self._cal_by_posting = defaultdict(list)
            for e in self.events():
                self._cal_by_posting[e["posting_id"]].append(e)
        same = [e for e in self._cal_by_posting.get(posting_id, []) if d is not None and e["_d"] == d]
        pick = [e for e in same if keyword_re and keyword_re.search(e["eveniment"] or "")] or same
        return [calendar_line(pick[0])] if pick else []

    def texts(self) -> dict[int, str]:
        """posting_id -> body_markdown, read once (empty text is kept out)."""
        if self._texts is None:
            self._texts = {}
            if "posting_text" in self.columns:
                for r in self.db.execute("SELECT posting_id, body_markdown FROM posting_text"):
                    if r["body_markdown"] and r["posting_id"] in self.by_id:
                        self._texts[r["posting_id"]] = r["body_markdown"]
        return self._texts


# --------------------------------------------------------------------------
# Check registry
# --------------------------------------------------------------------------

CHECKS: list[dict] = []


def check(cid: str, title: str, klass: str, needs: dict[str, list[str]], baseline: str = ""):
    """Register a check. The function returns (findings, denominator, notes, extra)."""
    def deco(fn):
        CHECKS.append({"id": cid, "title": title, "class": klass, "needs": needs,
                       "baseline": baseline, "fn": fn})
        return fn
    return deco


def F(posting_id, value, peer="", detail=None):
    """One finding. `detail` (a dict) goes into `detail_json` in table mode: a source quote."""
    return (posting_id, value, peer, detail) if detail else (posting_id, value, peer)


def calendar_line(e: dict) -> dict:
    """A calendar row as the page quotes it."""
    return {"eveniment": " ".join(str(e.get("eveniment") or "").split()),
            "data": str(e.get("data") or ""), "ora": str(e.get("ora") or "")}


EXCERPT_MAX = 600


def _squash(t: str) -> str:
    return re.sub(r"\s+", " ", t)


def excerpt_around(body: str, start: int, end: int, limit: int = EXCERPT_MAX) -> dict:
    """The paragraph of `body` that holds the match [start, end), cut on a word at about `limit`
    characters. Returns {excerpt, excerpt_start, excerpt_end, cut_start, cut_end}; the offsets
    are relative to the excerpt (whitespace is squashed to single spaces)."""
    ps = body.rfind("\n\n", 0, start)
    ps = 0 if ps < 0 else ps + 2
    pe = body.find("\n\n", end)
    pe = len(body) if pe < 0 else pe
    a, m, z = _squash(body[ps:start]), _squash(body[start:end]), _squash(body[end:pe])
    a = a.lstrip()
    z = z.rstrip()
    cut_start = cut_end = False
    if len(a) + len(m) + len(z) > limit:
        room = max(limit - len(m), 0)
        keep_a = min(len(a), room // 2)
        keep_z = min(len(z), room - keep_a)
        keep_a = min(len(a), room - keep_z)
        if keep_a < len(a):
            cut = a[len(a) - keep_a:]
            if a[len(a) - keep_a - 1] != " " and " " in cut:   # drop a broken word
                cut = cut[cut.index(" ") + 1:]
            a, cut_start = cut, True
        if keep_z < len(z):
            cut = z[:keep_z]
            if z[keep_z] != " " and " " in cut:
                cut = cut[:cut.rindex(" ")]
            z, cut_end = cut, True
    return {"excerpt": a + m + z, "excerpt_start": len(a), "excerpt_end": len(a) + len(m),
            "cut_start": cut_start, "cut_end": cut_end}


def cal_detail(events: list[dict]) -> dict | None:
    """Detail of a calendar finding: the calendar lines it compares."""
    return {"source": "calendar", "calendar": [calendar_line(e) for e in events]}


#: Which calendar line stands for a stage column of `job_postings` (R09a-c, R10).
STAGE_KEYWORD = {"apply_deadline": re.compile(r"depun|înscri|inscri", re.I),
                 "data_proba_scrisa": re.compile(r"scris", re.I),
                 "data_interviu": re.compile(r"interviu", re.I),
                 "data_rezultate_finale": re.compile(r"final|rezultat", re.I)}


def short_flag(p) -> bool:
    return '"short_deadline"' in (p.get("inf_anomaly_flags") or "")


JP = "job_postings"


# ---- Rows 1-20 -----------------------------------------------------------

@check("R01", "nr_posturi above the plausible maximum (sentinel or parse error)", "A",
       {JP: ["nr_posturi"]}, "8+ rows at 339395; 50 rows > 20")
def r01(c: Ctx):
    mx = c.params["nr_posturi_max"]
    rows = [p for p in c.postings if p["nr_posturi"] is not None]
    f = [F(p["id"], p["nr_posturi"], mx) for p in rows if p["nr_posturi"] > mx]
    sentinel = sum(1 for p in rows if p["nr_posturi"] == 339395)
    return f, len(rows), "", {"rows_equal_339395": sentinel}


@check("R02", "calendar event year outside the expected range", "A",
       {"calendar_events": ["posting_id", "data"], JP: ["published_at"]}, "~75")
def r02(c: Ctx):
    lo, hi = c.params["year_min"], c.params["year_max"]
    f = []
    ev = c.events()
    for e in ev:
        y = int(e["_year_raw"]) if e["_year_raw"].isdigit() else None
        if y is not None and not (lo <= y <= hi):
            f.append(F(e["posting_id"], e["data"], f"{lo}-{hi}", cal_detail([e])))
    return f, len(ev), f"range {lo}-{hi}; one finding per event", {}


JUNK_LABEL_RE = re.compile(r"^[\s–—•\-·*.:;]*$")


@check("R03", "calendar event label is empty or only a dash/bullet", "A",
       {"calendar_events": ["posting_id", "eveniment"]}, "1,713")
def r03(c: Ctx):
    ev = c.events()
    f = [F(e["posting_id"], e["eveniment"] or "", "", cal_detail([e])) for e in ev
         if JUNK_LABEL_RE.match(e["eveniment"] or "")]
    return f, len(ev), "one finding per event", {}


@check("R04", "inf_salary_min below 1000 (hourly rate read as monthly pay)", "A",
       {JP: ["inf_salary_min"]}, "10 of 16")
def r04(c: Ctx):
    floor = c.params["salary_floor_monthly"]
    rows = [p for p in c.postings if p["inf_salary_min"] is not None]
    f = [F(p["id"], p["inf_salary_min"], floor) for p in rows if p["inf_salary_min"] < floor]
    return f, len(rows), "", {}


@check("R05", "employer name variants after diacritics and punctuation fold", "A",
       {JP: ["employer_name"]}, "333 keys of 5,901")
def r05(c: Ctx):
    groups: dict[str, dict[str, int]] = defaultdict(dict)
    first: dict[tuple, int] = {}
    for p in c.postings:
        name = p["employer_name"] or ""
        if not p["_emp"]:
            continue
        groups[p["_emp"]][name] = groups[p["_emp"]].get(name, 0) + 1
        first.setdefault((p["_emp"], name), p["id"])
    f = []
    for key, names in groups.items():
        if len(names) > 1:
            top = max(names, key=names.get)
            f.append(F(first[(key, top)], " | ".join(sorted(names)), key))
    def fold(t):
        t = "".join(ch for ch in unicodedata.normalize("NFKD", t) if not unicodedata.combining(ch))
        return " ".join(t.lower().split())
    diac: dict[str, set] = defaultdict(set)
    for p in c.postings:
        if p["employer_name"]:
            diac[fold(p["employer_name"])].add(p["employer_name"])
    extra = {"keys_diacritics_and_case_fold_only": sum(1 for v in diac.values() if len(v) > 1),
             "of_keys": len(diac)}
    return f, len(groups), ("one finding per employer key; value lists the variants. The key is "
                            "_norm_title (also strips punctuation), so it merges more than a pure "
                            "diacritics fold; see extra."), extra


def _deadline_rows(c: Ctx, confirmed_only=True):
    for p in c.postings:
        if p["_pub"] and p["_dl"]:
            if confirmed_only and p.get("deadline_source") not in CONFIRMED_SOURCES:
                continue
            yield p, (p["_dl"] - p["_pub"]).days


@check("R06", "deadline on the publication day (0 days)", "A?",
       {JP: ["apply_deadline", "published_at", "deadline_source"]}, "392 (see report: Row 6 verdict)")
def r06(c: Ctx):
    rows = list(_deadline_rows(c))
    f = [F(p["id"], 0, "") for p, n in rows if n == 0]
    return f, len(rows), "denominator: postings with a confirmed deadline (anunt/concurs)", {}


@check("R07", "deadline before publication", "A or B",
       {JP: ["apply_deadline", "published_at", "deadline_source"]}, "29")
def r07(c: Ctx):
    rows = list(_deadline_rows(c))
    f = [F(p["id"], n, 0) for p, n in rows if n < 0]
    return f, len(rows), "denominator: postings with a confirmed deadline", {}


@check("R08", "short_deadline flag (< 7 calendar days)", "B",
       {JP: ["inf_anomaly_flags", "apply_deadline", "published_at", "deadline_source"]}, "531")
def r08(c: Ctx):
    flagged = [p for p in c.postings if short_flag(p)]
    f = []
    for p in flagged:
        n = days_between(p["_pub"], p["_dl"])
        f.append(F(p["id"], "" if n is None else n, c.params["short_days"]))
    rows = list(_deadline_rows(c))
    by_days = Counter(min(max(n, -1), 7) for p, n in rows if n < c.params["short_days"])
    extra = {"recomputed_short_confirmed": sum(by_days.values()),
             "recomputed_by_days (-1 means below 0)": dict(sorted(by_days.items())),
             "zero_day_share_of_flag": round(by_days.get(0, 0) / len(flagged), 3) if flagged else None}
    return f, len(c.postings), "count = postings with the flag in inf_anomaly_flags", extra


def _stage_check(c: Ctx, a_col: str, b_col: str, cmp):
    f, rows = [], 0
    for p in c.postings:
        a = p["_dl"] if a_col == "apply_deadline" else d10(p.get(a_col))
        b = d10(p.get(b_col))
        if a is None or b is None:
            continue
        rows += 1
        if cmp(a, b):
            lines = (c.calendar_lines(p["id"], a, STAGE_KEYWORD.get(a_col))
                     + c.calendar_lines(p["id"], b, STAGE_KEYWORD.get(b_col)))
            f.append(F(p["id"], b.isoformat(), a.isoformat(),
                       {"source": "calendar", "calendar": lines} if lines else None))
    return f, rows


@check("R09a", "written test before the application deadline", "B",
       {JP: ["apply_deadline", "data_proba_scrisa"]}, "3")
def r09a(c: Ctx):
    f, n = _stage_check(c, "apply_deadline", "data_proba_scrisa", lambda dl, t: t < dl)
    return f, n, "value = test date, peer = deadline", {}


@check("R09b", "interview before the written test", "B",
       {JP: ["data_proba_scrisa", "data_interviu"]}, "9")
def r09b(c: Ctx):
    f, n = _stage_check(c, "data_proba_scrisa", "data_interviu", lambda w, i: i < w)
    return f, n, "value = interview date, peer = written test date", {}


@check("R09c", "final results before the interview", "B",
       {JP: ["data_interviu", "data_rezultate_finale"]}, "26")
def r09c(c: Ctx):
    f, n = _stage_check(c, "data_interviu", "data_rezultate_finale", lambda i, r: r < i)
    return f, n, "value = results date, peer = interview date", {}


@check("R10", "written test 0-5 days after the deadline", "B",
       {JP: ["apply_deadline", "data_proba_scrisa"]}, "63")
def r10(c: Ctx):
    f, n = _stage_check(c, "apply_deadline", "data_proba_scrisa",
                        lambda dl, t: 0 <= (t - dl).days <= 5)
    return f, n, "value = test date, peer = deadline", {}


@check("R11", "calendar event on a Saturday or Sunday", "B",
       {"calendar_events": ["posting_id", "data"]}, "~40")
def r11(c: Ctx):
    lo, hi = c.params["year_min"], c.params["year_max"]
    ev = [e for e in c.events() if e["_d"] and lo <= e["_d"].year <= hi]
    f = [F(e["posting_id"], e["_d"].isoformat(), e["_d"].strftime("%a"), cal_detail([e])) for e in ev
         if e["_d"].weekday() >= 5]
    return f, len(ev), f"events dated {lo}-{hi}; one finding per event", {}


@check("R12", "title says debutant but experience is 1 year or more", "B",
       {JP: ["title", "inf_experience_years"]}, "90")
def r12(c: Ctx):
    rows = [p for p in c.postings if "debutant" in _norm_title(p["title"] or "")
            and p["inf_experience_years"] is not None]
    f = [F(p["id"], p["inf_experience_years"], 0) for p in rows if p["inf_experience_years"] >= 1]
    return f, len(rows), "denominator: debutant titles with an experience value", {}


@check("R13", "experience >= 7 years and above 3 x group mean + 3 (occ_group, n >= 30)", "C",
       {JP: ["inf_experience_years", "occ_group"]}, "26")
def r13(c: Ctx):
    groups: dict[str, list[dict]] = defaultdict(list)
    for p in c.postings:
        if p["inf_experience_years"] is not None and p["occ_group"]:
            groups[p["occ_group"]].append(p)
    f, den = [], 0
    for g, rows in groups.items():
        if len(rows) < c.params["group_min_n"]:
            continue
        den += len(rows)
        mean = statistics.fmean(p["inf_experience_years"] for p in rows)
        for p in rows:
            x = p["inf_experience_years"]
            if x >= c.params["exp_high_years"] and x > 3 * mean + 3:
                f.append(F(p["id"], x, round(mean, 2)))
    return f, den, "denominator: postings in groups with n >= 30", {}


@check("R14", "experience >= 16 years", "A? or C",
       {JP: ["inf_experience_years"]}, "17")
def r14(c: Ctx):
    rows = [p for p in c.postings if p["inf_experience_years"] is not None]
    lim = c.params["exp_very_high_years"]
    f = [F(p["id"], p["inf_experience_years"], lim) for p in rows if p["inf_experience_years"] >= lim]
    return f, len(rows), "", {}


@check("R15", "employer with >= 8 postings and >= 50% short_deadline", "C",
       {JP: ["employer_name", "inf_anomaly_flags"]}, "9")
def r15(c: Ctx):
    g: dict[str, list[dict]] = defaultdict(list)
    for p in c.postings:
        if p["_emp"]:
            g[p["_emp"]].append(p)
    f, big = [], 0
    for key, rows in g.items():
        if len(rows) < c.params["employer_min_postings"]:
            continue
        big += 1
        share = sum(1 for p in rows if short_flag(p)) / len(rows)
        if share >= c.params["employer_short_share"]:
            f.append(F(rows[0]["id"], round(share, 3), c.params["employer_short_share"]))
    return f, big, "denominator: employers with >= 8 postings; one finding per employer", {}


@check("R16", "same employer + title at least 3 times", "C",
       {JP: ["employer_name", "title"]}, "47 groups")
def r16(c: Ctx):
    g: dict[tuple, list[int]] = defaultdict(list)
    for p in c.postings:
        g[(p["_emp"], _norm_title(p["title"] or ""))].append(p["id"])
    f = [F(ids[0], len(ids), c.params["repeat_min"]) for ids in g.values()
         if len(ids) >= c.params["repeat_min"]]
    exact: dict[tuple, int] = Counter((p["employer_name"], _norm_title(p["title"] or ""))
                                      for p in c.postings)
    extra = {"groups_with_exact_employer_name": sum(1 for n in exact.values() if n >= c.params["repeat_min"])}
    return f, len(g), "one finding per group; employer merged by row 5 key", extra


@check("R17", "employer publishes >= 10 postings on one day", "C",
       {JP: ["employer_name", "published_at"]}, "13")
def r17(c: Ctx):
    g: dict[tuple, list[int]] = defaultdict(list)
    for p in c.postings:
        if p["_emp"] and p["_pub"]:
            g[(p["_emp"], p["_pub"])].append(p["id"])
    f = [F(ids[0], len(ids), c.params["same_day_min"]) for ids in g.values()
         if len(ids) >= c.params["same_day_min"]]
    exact: dict[tuple, int] = Counter((p["employer_name"], p["_pub"]) for p in c.postings if p["_pub"])
    extra = {"with_exact_employer_name": sum(1 for n in exact.values() if n >= c.params["same_day_min"])}
    return f, len(g), "one finding per employer-day", extra


@check("R18", "context only: contact email on gmail or yahoo (not an anomaly)", "context",
       {JP: ["contact_email"]}, "1,935 of 4,724")
def r18(c: Ctx):
    rows = [p for p in c.postings if (p["contact_email"] or "").strip()]
    n = sum(1 for p in rows if re.search(r"@(gmail|yahoo)\.", p["contact_email"], re.I))
    return [], len(rows), "context only, no findings", {"gmail_yahoo": n}


@check("R19", "expires_at less than 5 days after publication", "B",
       {JP: ["expires_at", "published_at"]}, "88")
def r19(c: Ctx):
    f, rows = [], 0
    for p in c.postings:
        n = days_between(p["_pub"], d10(p["expires_at"]))
        if n is None:
            continue
        rows += 1
        if n < c.params["short_expiry_days"]:
            f.append(F(p["id"], n, c.params["short_expiry_days"]))
    return f, rows, "", {}


@check("R20", "role with >= 12 skills", "C",
       {"posting_roles": ["posting_id", "skills"]}, "78 of 11,209")
def r20(c: Ctx):
    rows = [dict(r) for r in c.db.execute("SELECT posting_id, ordinal, skills FROM posting_roles")]
    f = [F(r["posting_id"], json_len(r["skills"]), c.params["skills_min"]) for r in rows
         if json_len(r["skills"]) >= c.params["skills_min"]]
    return f, len(rows), "denominator: roles; one finding per role", {}


# ---- Added checks --------------------------------------------------------

def _window_rows(c: Ctx):
    """Postings with a confirmed deadline on or after the publication day, with working days."""
    for p, n in _deadline_rows(c):
        if n >= 0:
            yield p, c.holidays.working_days(p["_pub"], p["_dl"])


@check("E01", "working-day window from publication to deadline", "B",
       {JP: ["apply_deadline", "published_at", "deadline_source", "job_regime"]})
def e01(c: Ctx):
    rows = list(_window_rows(c))
    pr = c.params
    buckets = [(0, 0), (1, 4), (5, 9), (10, 14), (15, 10**6)]

    def label(lo, hi):
        return f"{lo}" if lo == hi else (f"{lo}+" if hi >= 10**6 else f"{lo}-{hi}")
    by_reg: dict[str, list[int]] = defaultdict(list)
    for p, wd in rows:
        by_reg[p.get("job_regime") or "(empty)"].append(wd)
    dist = {}
    for reg, wds in sorted(by_reg.items()):
        dist[reg] = {"n": len(wds), "median": statistics.median(wds),
                     **{label(lo, hi): sum(1 for w in wds if lo <= w <= hi) for lo, hi in buckets}}
    # Findings: contract staff below the legal minimum.
    f = []
    hard, soft = pr["min_wd_contract_temp"], pr["min_wd_contract_vacant"]
    for p, wd in rows:
        if p.get("job_regime") == "contractuala" and wd < soft:
            f.append(F(p["id"], wd, soft))
    extra = {
        "distribution_by_job_regime": dist,
        "contract_below_5_wd (below the temporary-post minimum)":
            sum(1 for p, wd in rows if p.get("job_regime") == "contractuala" and wd < hard),
        "contract_below_10_wd (below the vacant-post minimum)": len(f),
        "civil_servant_minimum_wd": pr["min_wd_civil_servant"],
    }
    notes = ("Findings: contract-regime postings with fewer than 10 working days (HG 1336/2022 "
             "art. 34; the post kind, vacant or temporary, is not in the export, so the 5-day "
             "temporary minimum is reported in extra). Civil-servant minimum unconfirmed: "
             f"{pr['min_wd_civil_servant']}. Holidays: {c.holidays.source}. Window counts the "
             "days after publication up to and including the deadline.")
    return f, len(rows), notes, extra


@check("E02", "window across Christmas, Easter or 1-15 August with few working days", "B",
       {JP: ["apply_deadline", "published_at", "deadline_source"]})
def e02(c: Ctx):
    n_min = c.params["holiday_window_min_wd"]
    f, rows = [], 0
    for p, wd in _window_rows(c):
        rows += 1
        pub, dl = p["_pub"], p["_dl"]
        hit = None
        for y in range(pub.year, dl.year + 1):
            probes = {"christmas": date(y, 12, 25), "easter": orthodox_easter(y)}
            for name, d in probes.items():
                if pub < d <= dl:
                    hit = name
            if pub < date(y, 8, 15) and dl >= date(y, 8, 1) and hit is None:
                hit = "august 1-15"
        if hit and wd < n_min:
            f.append(F(p["id"], wd, f"{hit}; N={n_min}"))
    return f, rows, f"N = {n_min} working days; peer_value names the holiday", {}


@check("E03", "repost chain: same employer + title, next posting 14 to 90 days later", "C",
       {JP: ["employer_name", "title", "published_at"]})
def e03(c: Ctx):
    g: dict[tuple, list[dict]] = defaultdict(list)
    for p in c.postings:
        if p["_emp"] and p["_pub"]:
            g[(p["_emp"], _norm_title(p["title"] or ""))].append(p)
    f, parallel = [], 0
    lim, min_gap = c.params["repost_days"], c.params["repost_min_days"]
    chains = 0
    for rows in g.values():
        rows.sort(key=lambda p: (p["_pub"], p["id"]))
        started = False
        for prev, cur in zip(rows, rows[1:]):
            gap = (cur["_pub"] - prev["_pub"]).days
            if gap < min_gap:
                parallel += 1
            elif gap <= lim:
                f.append(F(cur["id"], gap, prev["id"]))
                if not started:
                    chains += 1
                    started = True
    return f, len(c.postings), (
        "finding = the later posting of a pair; value = days after the earlier one, "
        "peer = earlier posting id. Pairs under repost_min_days apart are parallel posts, "
        "not counted."), \
        {"chains": chains, "parallel_pairs_not_counted": parallel, "min_days": min_gap}


@check("E04", "edit after publication: title or expiry changed after first sight", "B",
       {JP: ["url"]})
def e04(c: Ctx):
    if not c.index_csv:
        raise Unavailable("needs --index-csv (the `updates` column lives in the index CSV, "
                          "not in the export)")
    first_seen_re = re.compile(r"(\d{4}-\d{2}-\d{2}): ([^;]*)")
    by_url = {p["url"]: p for p in c.postings}
    f, rows = [], 0
    title_n = expiry_n = 0
    with open(c.index_csv, encoding="utf-8", newline="") as fh:
        for r in csv.DictReader(fh):
            p = by_url.get(r.get("url"))
            if not p:
                continue
            rows += 1
            changes = [(d, fields.strip()) for d, fields in first_seen_re.findall(r.get("updates") or "")
                       if "New entry" not in fields]
            hit = [(d, fl) for d, fl in changes if "pozitie" in fl or "expira_in" in fl]
            title_n += any("pozitie" in fl for _, fl in hit)
            expiry_n += any("expira_in" in fl for _, fl in hit)
            if hit:
                f.append(F(p["id"], "; ".join(f"{d}: {fl}" for d, fl in hit), ""))
    return f, rows, ("`pozitie` = title, `expira_in` = announcement expiry (not the apply "
                     "deadline: the index card has no other date)."), \
        {"title_changed": title_n, "expiry_changed": expiry_n}


class Unavailable(Exception):
    pass


@check("E05", "narrow requirements (possible tailored post): top 2% on two or more measures", "C",
       {JP: ["occ_group"], "posting_roles": ["posting_id", "ordinal", "exp_years", "isced_fields"],
        "requirement_evidence": ["posting_id", "ordinal", "fact_kind", "status"]})
def e05(c: Ctx):
    pr = c.params
    verified = Counter()
    exp_ok = set()
    for r in c.db.execute("SELECT posting_id, ordinal, fact_kind, COUNT(*) n FROM requirement_evidence "
                          "WHERE status='verified' GROUP BY 1,2,3"):
        if r["fact_kind"] == "experience":
            exp_ok.add((r["posting_id"], r["ordinal"]))
        verified[(r["posting_id"], r["ordinal"], r["fact_kind"])] = r["n"]
    edu_ok = {(k[0], k[1]) for k in verified if k[2] == "education"}
    roles = []
    for r in c.db.execute("SELECT posting_id, ordinal, exp_years, isced_fields FROM posting_roles"):
        p = c.by_id.get(r["posting_id"])
        if not p or not p["occ_group"]:
            continue
        key = (r["posting_id"], r["ordinal"])
        roles.append({
            "id": r["posting_id"], "group": p["occ_group"],
            "exp": r["exp_years"] if key in exp_ok and r["exp_years"] is not None else None,
            "cred": verified.get((*key, "credential"), 0),
            "skill": verified.get((*key, "skill"), 0),
            "isced": json.loads(r["isced_fields"] or "[]") if key in edu_ok else [],
        })
    groups: dict[str, list[dict]] = defaultdict(list)
    for r in roles:
        groups[r["group"]].append(r)
    f, den = [], 0
    for g, rows in groups.items():
        if len(rows) < pr["group_min_n"]:
            continue
        den += len(rows)
        thresholds = {}
        for m in ("exp", "cred", "skill"):
            vals = sorted(r[m] for r in rows if r[m] is not None)
            if len(vals) >= pr["group_min_n"]:
                mean = statistics.fmean(vals)
                sd = statistics.pstdev(vals)
                thresholds[m] = (percentile_value(vals, pr["narrow_top_share"]), mean, sd)
        field_n = Counter(x for r in rows for x in set(r["isced"]))
        for r in rows:
            hits = []
            for m, (p98, mean, sd) in thresholds.items():
                v = r[m]
                if v is not None and v > 0 and v >= p98 and sd > 0 and (v - mean) / sd >= 2:
                    hits.append(f"{m}={v}")
            if any(field_n[x] / len(rows) < pr["isced_rare_share"] for x in r["isced"]):
                hits.append("rare_isced")
            if len(hits) >= pr["narrow_min_hits"]:
                f.append(F(r["id"], ", ".join(hits), g))
    return f, den, ("one finding per role; peer = occ_group. Measures use only facts with "
                    "requirement_evidence.status = 'verified'. A measure hits when the value is in "
                    "the group top 2% and z >= 2; a rare ISCED field has < 2% of the group."), {}


@check("E06", "declared minimum pay outside the grid by more than 20%", "B",
       {JP: ["inf_salary_min", "inf_salary_max", "sal_min", "sal_max"]})
def e06(c: Ctx):
    pr = c.params
    f, rows, wider = [], 0, 0
    floor, wage, tol = pr["salary_floor_monthly"], pr["min_wage_gross"], pr["pay_tolerance"]
    for p in c.postings:
        lo = p["inf_salary_min"]
        hi = p["inf_salary_max"] if p["inf_salary_max"] is not None else lo
        gmin, gmax = p["sal_min"], p["sal_max"]
        if lo is None or gmin is None or gmax is None or lo < floor or lo < wage:
            continue
        rows += 1
        if hi > gmax or lo < gmin:
            wider += 1
        if lo < gmin * (1 - tol) or lo > gmax * (1 + tol):
            f.append(F(p["id"], f"{lo:g}-{hi:g}", f"{gmin:g}-{gmax:g}"))
    return f, rows, (f"excluded: declared min below {floor} (hourly) or below the minimum wage "
                     f"{wage} (parameter, unconfirmed). Hit = declared minimum, not the range; "
                     "a wider range (seniority spread) is counted in extra only"), \
        {"range_wider_than_grid_not_counted": wider}


FREE_MAIL_RE = re.compile(r"@(gmail|yahoo|ymail|hotmail|outlook|live|icloud|rocketmail)\.|@mail\.com", re.I)


@check("E07", "free-mail contact where fewer than 15% of the same employer kind use one", "C",
       {JP: ["contact_email", "employer_kind"]})
def e07(c: Ctx):
    rows = [p for p in c.postings if (p["contact_email"] or "").strip() and p["employer_kind"]]
    tot, free = Counter(), Counter()
    for p in rows:
        tot[p["employer_kind"]] += 1
        if FREE_MAIL_RE.search(p["contact_email"]):
            free[p["employer_kind"]] += 1
    rare = {k for k in tot if free[k] / tot[k] < c.params["freemail_rare_share"]}
    f = [F(p["id"], p["contact_email"], f"{p['employer_kind']} {free[p['employer_kind']] / tot[p['employer_kind']]:.0%}")
         for p in rows if p["employer_kind"] in rare and FREE_MAIL_RE.search(p["contact_email"])]
    share = {k: round(free[k] / tot[k], 3) for k in sorted(tot)}
    return f, len(rows), "peer = employer_kind and its free-mail share", {
        "free_mail_share_by_kind": share, "rare_kinds": sorted(rare)}


@check("E08", "calendar event more than 30 days before publication", "A",
       {"calendar_events": ["posting_id", "data"], JP: ["published_at"]})
def e08(c: Ctx):
    lim = c.params["old_event_days"]
    ev = [e for e in c.events() if e["_d"] and e["_pub"]]
    f = [F(e["posting_id"], e["_d"].isoformat(), e["_pub"].isoformat(), cal_detail([e])) for e in ev
         if (e["_pub"] - e["_d"]).days > lim]
    return f, len(ev), "one finding per event; peer = publication date", {}


# --------------------------------------------------------------------------
# Table mode (ANOMALY-PUBLIC): write the findings into the export
# --------------------------------------------------------------------------

DEFAULT_CHECKS = ROOT / "ops" / "anomaly-checks.json"
DEFAULT_REVIEWS = ROOT / "data" / "anomaly-reviews.csv"
VERDICTS = ("real", "false_positive", "unclear")
GROUP_OF_KIND = {"error_ours": "erori_noastre", "error_posting": "erori_anunt", "unusual": "neobisnuit",
                 "unusual_employer": "angajator", "context": "context"}


def load_register(path) -> dict:
    """The register: check id -> {kind, group, label_ro, explain_ro, caveat_ro}. Missing file: empty."""
    p = Path(path)
    if not p.exists():
        return {}
    return json.loads(p.read_text(encoding="utf-8")).get("checks", {})


def load_reviews(path) -> dict:
    """(check, posting_id) -> verdict. The last row of a pair wins. Missing file: empty."""
    out: dict[tuple, str] = {}
    p = Path(path)
    if not p.exists():
        return out
    with open(p, encoding="utf-8", newline="") as fh:
        for r in csv.DictReader(fh):
            v = (r.get("verdict") or "").strip()
            pid = (r.get("posting_id") or "").strip()
            if v in VERDICTS and pid.isdigit():
                out[((r.get("check") or "").strip(), int(pid))] = v
    return out


def write_tables(db_path, report: dict, rows: list[tuple], register: dict, reviews: dict) -> dict:
    """Replace `anomaly_signals` and `anomaly_checks` in the export. Return rows per table.

    Handling of checks without a posting of their own:
      * employer-level checks (R15, R16, R17): one row per employer or group, on the first
        posting; `detail_json` carries {"level": "employer"} when the register kind is
        `unusual_employer`;
      * R05 (name variants): one row per employer key, on the first posting;
      * E01 (a distribution): the contract-staff findings are written as rows; the
        distribution stays in the JSON report only;
      * an unavailable check (a missing column, or E04 without the index CSV): no rows;
        `count` and `denominator` are NULL.
    A review verdict goes into `detail_json` as {"verdict": ...}. The row stays in the table:
    the admin page shows it; the public page skips `false_positive`.
    """
    db = sqlite3.connect(db_path)
    try:
        db.execute("DROP TABLE IF EXISTS anomaly_signals")
        db.execute("DROP TABLE IF EXISTS anomaly_checks")
        db.execute("CREATE TABLE anomaly_signals (posting_id INTEGER, check_id TEXT, value TEXT, "
                   "peer_value TEXT, detail_json TEXT)")
        db.execute("CREATE TABLE anomaly_checks (check_id TEXT PRIMARY KEY, kind TEXT, label_ro TEXT, "
                   "group_id TEXT, explain_ro TEXT, caveat_ro TEXT, count INTEGER, denominator INTEGER, "
                   "reviewed INTEGER, real INTEGER, false_positive INTEGER)")
        sig = []
        for pid, cid, value, peer, *extra in rows:
            detail = dict(extra[0]) if extra and extra[0] else {}
            if register.get(cid, {}).get("kind") == "unusual_employer":
                detail["level"] = "employer"
            verdict = reviews.get((cid, int(pid)))
            if verdict:
                detail["verdict"] = verdict
            sig.append((int(pid), cid, "" if value is None else str(value),
                        "" if peer is None else str(peer),
                        json.dumps(detail, ensure_ascii=False)))
        db.executemany("INSERT INTO anomaly_signals VALUES (?,?,?,?,?)", sig)
        db.execute("CREATE INDEX idx_anomaly_signals_check ON anomaly_signals(check_id)")
        db.execute("CREATE INDEX idx_anomaly_signals_posting ON anomaly_signals(posting_id)")
        chk = []
        for cid, e in report.items():
            reg = register.get(cid, {})
            rv = [v for (c, _), v in reviews.items() if c == cid]
            ok = e["status"] == "ok"
            chk.append((cid, reg.get("kind", "unusual"), reg.get("label_ro", e["title"]),
                        reg.get("group", GROUP_OF_KIND.get(reg.get("kind", "unusual"), "neobisnuit")),
                        reg.get("explain_ro", ""), reg.get("caveat_ro", ""),
                        e["count"] if ok else None, e["denominator"] if ok else None,
                        len(rv), rv.count("real"), rv.count("false_positive")))
        db.executemany("INSERT INTO anomaly_checks VALUES (?,?,?,?,?,?,?,?,?,?,?)", chk)
        db.commit()
        return {"anomaly_signals": len(sig), "anomaly_checks": len(chk)}
    finally:
        db.close()


# ---- Limiting conditions (R21-R25) ---------------------------------------
# Brief: docs/briefs/2026-11-23-anomaly-limiting-conditions.md. Each check is a signal on the
# text, not a verdict: no act that we read forbids R21-R24.

PT = "posting_text"
PT_NEEDS = {PT: ["posting_id", "body_markdown"]}

R21_RE = re.compile(r"avantaj[^.\n;]{0,15}domicili", re.I)
# Named project only: "proiectul pilot" or an SMIS code (Opus review: "proiect" alone gave 5 of 10 generic hits).
R22_RE = re.compile(r"avantaj[^.\n;]{0,80}(proiectul pilot|SMIS)", re.I)
R23_RE = re.compile(r"experien[țţt][aă][^.\n]{0,80}proiect[e]?[^.\n]{0,40}"
                    r"fonduri (europene|nerambursabile|structurale)", re.I)
R25_AGE_RES = (
    # An adult age only (16-99): "vârstă de până la 2 ani" is a child's age (Opus review).
    re.compile(r"v[âa]rst[aă] (maxim[aă]|de (cel mult|p[âa]n[aă] la))\s*(1[6-9]|[2-9]\d)\b", re.I),
    re.compile(r"nu (mai )?(mare|peste) de \d{2} (de )?ani", re.I),
)
R25_SEX_RE = re.compile(r"(sexul|de sex) (masculin|feminin)", re.I)


def _text_regex_check(c: Ctx, regexes) -> tuple[list, int]:
    """One finding per posting: the first match of any regex, cut at 120 characters.
    `detail` holds the paragraph around the match (`excerpt`)."""
    f = []
    texts = c.texts()
    for pid, body in texts.items():
        for rx in regexes:
            m = rx.search(body)
            if m:
                f.append(F(pid, " ".join(m.group(0).split())[:120], "",
                           {"source": "text", **excerpt_around(body, m.start(), m.end())}))
                break
    return f, len(texts)


@check("R21", "advantage for domicile in the posting text", "C", PT_NEEDS)
def r21(c: Ctx):
    f, den = _text_regex_check(c, [R21_RE])
    return f, den, "denominator: postings with a text; one finding per posting", {}


@check("R22", "advantage for work in a named project in the posting text", "C", PT_NEEDS)
def r22(c: Ctx):
    f, den = _text_regex_check(c, [R22_RE])
    return f, den, "denominator: postings with a text; one finding per posting", {}


@check("R23", "experience in EU-funded projects, required or an advantage", "C", PT_NEEDS)
def r23(c: Ctx):
    f, den = _text_regex_check(c, [R23_RE])
    return f, den, "denominator: postings with a text; one finding per posting", {}


GENERIC_CREDENTIALS = {"aviz_medical", "certificat_profesional"}


@check("R24", "several conditions above the occupation: years >= 2 x median and (language >= C1 or named credential)",
       "C", {JP: ["inf_experience_years", "occ_group", "v3_languages", "v3_credentials"]})
def r24(c: Ctx):
    groups: dict[str, list[float]] = defaultdict(list)
    for p in c.postings:
        if p["inf_experience_years"] is not None and p["occ_group"]:
            groups[p["occ_group"]].append(p["inf_experience_years"])
    median = {g: statistics.median(v) for g, v in groups.items() if len(v) >= c.params["group_min_n"]}
    f, den = [], 0
    for p in c.postings:
        x, m = p["inf_experience_years"], median.get(p["occ_group"])
        if x is None or m is None:
            continue
        den += 1
        if x <= 0 or x < 2 * m:
            continue
        langs = [l.split(":")[1] for l in json.loads(p["v3_languages"] or "[]") if ":" in l]
        high_lang = [l for l in langs if l in ("C1", "C2")]
        creds = sorted(set(json.loads(p["v3_credentials"] or "[]")) - GENERIC_CREDENTIALS)
        if high_lang or creds:
            extra = ("lang " + "+".join(high_lang)) if high_lang else "cred " + "+".join(creds)
            f.append(F(p["id"], f"exp={x:g}; {extra}", f"median={m:g}; occ_group={p['occ_group']}"))
    return f, den, ("denominator: postings in groups with n >= 30 and an experience value. "
                    "Named credential = any v3_credentials value except aviz_medical and "
                    "certificat_profesional."), {}


@check("R25", "maximum age or a required sex in the posting text", "B", PT_NEEDS)
def r25(c: Ctx):
    f, den = _text_regex_check(c, [*R25_AGE_RES, R25_SEX_RE])
    return f, den, "denominator: postings with a text; a person reviews every hit", {}


# --------------------------------------------------------------------------
# Runner
# --------------------------------------------------------------------------

def run(c: Ctx, only: set[str] | None = None) -> tuple[dict, list[tuple]]:
    report, rows = {}, []
    for ch in CHECKS:
        if only and ch["id"] not in only:
            continue
        entry = {"title": ch["title"], "class": ch["class"], "baseline": ch["baseline"]}
        missing = c.missing(ch["needs"])
        if missing:
            entry.update(status="unavailable", missing=missing)
            report[ch["id"]] = entry
            continue
        try:
            findings, den, notes, extra = ch["fn"](c)
        except Unavailable as exc:
            entry.update(status="unavailable", missing=[str(exc)])
            report[ch["id"]] = entry
            continue
        urls, seen = [], set()
        for pid, *_ in findings:
            u = c.url(pid)
            if u and u not in seen:
                seen.add(u)
                urls.append(u)
            if len(urls) == 5:
                break
        entry.update(status="ok", count=len(findings), denominator=den,
                     share=round(len(findings) / den, 4) if den else None,
                     sample_urls=urls, notes=notes, extra=extra)
        report[ch["id"]] = entry
        rows += [(f[0], ch["id"], f[1], f[2], f[3] if len(f) > 3 else None) for f in findings]
    return report, rows


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(
        description="Read-only anomaly survey of an export SQLite. Writes a JSON report and "
                    "a CSV of findings (posting_id, check, value, peer_value).")
    ap.add_argument("db", help="path of the export SQLite")
    ap.add_argument("--json", default="anomaly-survey.json", help="JSON report path")
    ap.add_argument("--csv", default="anomaly-survey.csv", help="CSV findings path")
    ap.add_argument("--index-csv", help="index CSV (data/posturi_gov_ro.csv); enables check E04")
    ap.add_argument("--holidays", default=str(DEFAULT_HOLIDAYS),
                    help="core-assets ro-holidays.csv (years it lacks are computed)")
    ap.add_argument("--only", help="comma-separated check ids, for example R06,E01")
    ap.add_argument("--write-table", action="store_true",
                    help="write anomaly_signals and anomaly_checks into the export (it replaces both)")
    ap.add_argument("--checks", default=str(DEFAULT_CHECKS), help="register of checks (JSON)")
    ap.add_argument("--reviews", default=str(DEFAULT_REVIEWS), help="review verdicts (CSV)")
    for k, v in DEFAULTS.items():
        if v is not None:
            ap.add_argument(f"--{k.replace('_', '-')}", type=type(v), default=v,
                            help=f"threshold (default {v})")
    ap.add_argument("--min-wd-civil-servant", type=int, default=None,
                    help="legal minimum in working days for civil servants (unconfirmed, no default)")
    args = ap.parse_args(argv)

    params = {k: getattr(args, k) for k in DEFAULTS}
    ctx = Ctx(args.db, params, Holidays(Path(args.holidays)), args.index_csv)
    only = set(args.only.split(",")) if args.only else None
    report, rows = run(ctx, only)

    ctx.db.close()  # the survey connection is read-only; table mode opens its own
    dates = [p["_pub"] for p in ctx.postings if p["_pub"]]
    out = {
        "meta": {
            "db": str(args.db), "postings": len(ctx.postings),
            "published_min": min(dates).isoformat() if dates else None,
            "published_max": max(dates).isoformat() if dates else None,
            "params": params, "holidays": ctx.holidays.source,
        },
        "checks": report,
    }
    Path(args.json).write_text(json.dumps(out, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    with open(args.csv, "w", encoding="utf-8", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["posting_id", "check", "value", "peer_value"])
        w.writerows(r[:4] for r in rows)
    for cid, e in report.items():
        if e["status"] == "ok":
            print(f"{cid:5} {e['count']:>6} / {e['denominator']:<7} {e['title']}")
        else:
            print(f"{cid:5} unavailable: {', '.join(e['missing'])}")
    if args.write_table:
        n = write_tables(args.db, report, rows, load_register(args.checks), load_reviews(args.reviews))
        print(f"wrote anomaly_signals: {n['anomaly_signals']} rows; anomaly_checks: {n['anomaly_checks']} rows")
    return 0


if __name__ == "__main__":
    sys.exit(main())

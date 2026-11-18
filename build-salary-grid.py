#!/usr/bin/env python3
"""Materialise the 2026 draft salary grid from the MMFTSS coefficient workbook.

The workbook is the primary source: 48 sheets covering annexes I-IX. Most
coefficient cells are XLOOKUP formulas, but Excel saved their results, so
`openpyxl(data_only=True)` reads the values directly. (The pre-existing
`docs/salarii/salarizare_admin_2026_bundle/` CSVs marked 835/1003 rows
`formula_XLOOKUP_necalculata` because the parser that built them ignored the
cached values.)

Layouts differ per sheet -- column positions, whether the professional grade is
its own column or folded into the function cell, whether the variant axis is a
grade or a seniority band. So columns are mapped by *header text*, never by
index, and anything that does not fit is emitted with `needs_review=1` rather
than guessed. See `docs/salarii/.../SPECIFICATII_IMPLEMENTARE.md` section 5.

Outputs (git-tracked, versioned by `--version-id`):
    data/salarii/grila-<version>.csv     one row per grid row
    data/salarii/functii-<version>.csv   retrieval index: name -> grid rows

A second source builds the grid of the law in force (Legea 153/2017) from the
consolidated HTML of legislatie.just.ro, saved in `docs/salarii/lege-153/`:
    python build-salary-grid.py --source lege-153 --report
    python build-salary-grid.py --source lege-153
It writes `grila-lege153-2026.csv` and `plafon-local.csv` (see ADR 0008).

Usage:
    python build-salary-grid.py --report
    python build-salary-grid.py --version-id 2026-07-17
"""
from __future__ import annotations

import argparse
import csv
import math
import re
import sys
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path

try:
    import openpyxl
except ImportError:
    sys.exit("openpyxl is required: pip install openpyxl")

ROOT = Path(__file__).parent.resolve()
DEFAULT_XLSX = (
    ROOT / "docs" / "salarii" / "perplexity pachet_calculator_salarizare_bugetari"
    / "Proiect-COEFICIENTI-1-8-MMFTSS-16.07.2026-1000.xlsx"
)
OUT_DIR = ROOT / "data" / "salarii"

#: A coefficient is a ratio to the reference value; the draft caps the scale at
#: 1:8 (Art. 5), so anything outside this band is a count, a year or a salary.
COEF_MIN, COEF_MAX = 0.4, 30.0

#: Function codes look like `82.60128002.07.2` -- stable across variants, and the
#: recommended lookup key (see calculator_pseudocod.md "Recomandare de arhitectură").
CODE_RE = re.compile(r"^\d{2}\.\d{6,}\.\d{2}\.\d$")

GRADE_TOKENS = re.compile(
    r"^\s*(gradul|grad|treapta|treaptă|gr\.)\s*[IVX]+\s*A?\s*$"
    r"|^\s*(debutant|definitiv|asistent|principal|superior|practicant|stagiar)\w*\s*$"
    r"|^\s*I{1,3}A?\s*$|^\s*IV\s*$|^\s*IA\s*$",
    re.I,
)

# --- header vocabulary -------------------------------------------------------
# Each entry is (role, matcher). Order matters: the first match wins, so the
# more specific patterns come first.
_HEADER_ROLES = [
    ("nr_crt",     re.compile(r"nr\.?\s*crt", re.I)),
    ("coeficient", re.compile(r"coeficien[tţț]i?\s+de\s+salarizare|^coeficient$", re.I)),
    ("salariu_lei", re.compile(r"salari(ile|ul)\s+de\s+baz", re.I)),
    ("vechime",    re.compile(r"vechime|vechimea", re.I)),
    ("grad",       re.compile(r"grad\s*(profesional|/\s*treapt|sau\s+treapt|\s*/\s*Treapt)|grad\s*sau\s*treapt", re.I)),
    ("studii",     re.compile(r"nivel(ul)?\s+studiilor|nivel\s+studii", re.I)),
    ("functie",    re.compile(r"^\s*func[tţț]ia", re.I)),
]

# Sub-header labels that name a coefficient column (Nivel I / Grad II / ...).
_COEF_LABEL = re.compile(
    r"grad(ul)?\s+(managerial|I\b|II\b|i{1,2}\b)|nivel\s+i{1,2}\b|grada[tţț]ia\s*\d"
    r"|^\s*(minim|maxim|an)\s*$|comand[ăa]|execu[tţț]ie",
    re.I,
)

# Section headers that set whether the block is management or execution.
_TIP_CONDUCERE = re.compile(r"func[tţț]ii?\s+(publice\s+)?de\s+conducere|de\s+conducere", re.I)
_TIP_EXECUTIE = re.compile(r"func[tţț]ii?\s+(publice\s+)?de\s+execu[tţț]ie|de\s+execu[tţț]ie", re.I)

# Context derived from the sheet's title rows.
_BAND_PATTERNS = [
    (re.compile(r"peste\s*200\.?000", re.I), "peste 200.000"),
    (re.compile(r"50\.?000\s*[sșş]i?\s*200\.?000|50\.?000\s*-\s*200\.?000", re.I), "50.000-200.000"),
    (re.compile(r"10\.?000\s*-\s*50\.?000|10\.?000\s*[sșş]i?\s*50\.?000", re.I), "10.000-50.000"),
    (re.compile(r"sub\s*10\.?000", re.I), "sub 10.000"),
]
_NIVEL_PATTERNS = [
    (re.compile(r"administra[tţț]ia?\s+public[ăa]\s+local|administratia\s+publica\s+local", re.I), "local"),
    (re.compile(r"unit[ăa][tţț]il?e?\s+teritorial|teritorial", re.I), "teritorial"),
    (re.compile(r"administra[tţț]ia?\s+public[ăa]\s+central|administratia\s+publica\s+central", re.I), "central"),
]
_REGIM_PATTERNS = [
    (re.compile(r"personalul(ui)?\s+contractual", re.I), "personal contractual"),
    (re.compile(r"func[tţț]ionarilor\s+publici|func[tţț]ii\s+publice", re.I), "funcționar public"),
]

_ANEXA_RE = re.compile(r"^(I{1,3}|IV|V|VI{1,3}|IX|X)\b")


def norm(s) -> str:
    """Lowercase, strip diacritics and punctuation. The join key everywhere."""
    s = unicodedata.normalize("NFD", str(s or ""))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn").lower()
    s = s.replace("ş", "s").replace("ţ", "t").replace("ș", "s").replace("ț", "t")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9 ]", " ", s)).strip()


def is_coef(v) -> bool:
    return isinstance(v, (int, float)) and not isinstance(v, bool) and COEF_MIN < v < COEF_MAX


def text_cells(row):
    return [(i, str(c).strip()) for i, c in enumerate(row) if isinstance(c, str) and str(c).strip()]


def anexa_of(sheet_name: str) -> str:
    head = sheet_name.strip().replace("_", " ").split()[0]
    m = _ANEXA_RE.match(head)
    return m.group(1) if m else ""


def sheet_context(rows, header_idx: int) -> dict:
    """Read regim / nivel administrativ / population band from the title rows."""
    blob = " ".join(
        str(c) for r in rows[:header_idx] for c in r if isinstance(c, str)
    )
    ctx = {"regim": "", "nivel_administrativ": "", "banda_populatie": ""}
    for key, patterns in (
        ("regim", _REGIM_PATTERNS),
        ("nivel_administrativ", _NIVEL_PATTERNS),
        ("banda_populatie", _BAND_PATTERNS),
    ):
        hits = {val for pat, val in patterns if pat.search(blob)}
        # `VIII CII A 1` is headed "administraţia publică centrală şi locală" and
        # really does cover both. Ambiguous stays blank -- an unknown widens the
        # estimate honestly, a wrong guess narrows it to the wrong answer.
        if len(hits) == 1:
            ctx[key] = hits.pop()
    return ctx


def find_header(rows):
    """Return (row_index, {col_index: role}) for the sheet's column header.

    The `Funcţia` cell is the anchor. A `Coeficienți de salarizare` cell is NOT
    required: Anexa IX heads its coefficient columns with years (`2026/2027`,
    `2028`, ...) and `VIII CII I` heads them with `Grad I..IV`, so the caller
    falls back to finding coefficient columns from the data.
    """
    for i, row in enumerate(rows[:20]):
        cells = text_cells(row)
        if not any(re.match(r"^\s*func[tţț]ia", t, re.I) or "Funcția/Nivel" in t for _, t in cells):
            continue
        roles: dict[int, str] = {}
        for col, txt in cells:
            flat = re.sub(r"\s+", " ", txt)
            for role, pat in _HEADER_ROLES:
                if pat.search(flat):
                    roles[col] = role
                    break
        # `VII CI` merges function and study level into one header cell.
        for col, txt in cells:
            if "Funcția/Nivel" in txt:
                roles[col] = "functie"
        if "functie" in roles.values():
            return i, roles
    return None, {}


def coefficient_columns(roles: dict[int, str], rows, header_idx: int) -> list[int]:
    """Columns that actually hold coefficients, found from the data, not the header.

    Header text is unreliable here -- `VIII CII I` labels its four coefficient
    columns `Grad I..IV` under a spanning `Grad profesional`, and Anexa IX labels
    them with years. So: count how many rows below the header carry a value in
    the coefficient band, and keep the columns right of `Funcţia`. Lei columns
    (4.000, 10.500) and years (2022) fall outside the band and drop out on their
    own; codes are strings and never counted.
    """
    functie_col = min((c for c, r in roles.items() if r == "functie"), default=0)
    never = {c for c, r in roles.items() if r in {"nr_crt", "functie", "studii", "vechime"}}
    hits: Counter[int] = Counter()
    for row in rows[header_idx + 1:]:
        for i, v in enumerate(row):
            if is_coef(v):
                hits[i] += 1
    cols = [c for c in sorted(hits) if c > functie_col and c not in never]
    # A column headed `coeficient` counts even if sparsely filled.
    cols += [c for c, r in roles.items() if r == "coeficient" and c not in cols and c > functie_col]
    return sorted(cols)


def parse_sheet(ws, version_id: str) -> tuple[list[dict], list[dict]]:
    """Return (grid rows, review notes) for one worksheet."""
    rows = list(ws.iter_rows(values_only=True))
    header_idx, roles = find_header(rows)
    if header_idx is None:
        return [], [{"sheet": ws.title, "reason": "no header row found"}]

    coef_cols = coefficient_columns(roles, rows, header_idx)
    if not coef_cols:
        return [], [{"sheet": ws.title, "reason": "no coefficient columns"}]

    col_of = {role: col for col, role in roles.items() if role != "coeficient"}
    ctx = sheet_context(rows, header_idx)
    anexa = anexa_of(ws.title)
    has_axis = bool({"grad", "studii", "vechime"} & set(col_of))
    # Rows without a code fall back to the sheet's chapter: in Anexa VIII,
    # Capitolul I is funcţionari publici and Capitolul II personal contractual.
    sheet_regim = ""
    if anexa == "VIII":
        chapter = re.sub(r"[^A-Z ]", " ", ws.title.replace("_", " ")).split()
        if "CII" in chapter:
            sheet_regim = "personal contractual"
        elif "CI" in chapter:
            sheet_regim = "funcționar public"

    out: list[dict] = []
    review: list[dict] = []
    labels: dict[int, str] = {}
    section = ""
    tip_post = ""
    cur_functie = ""
    cur_studii = ""

    for idx in range(header_idx + 1, len(rows)):
        row = rows[idx]
        row_no = idx + 1
        coefs = {c: row[c] for c in coef_cols if c < len(row) and is_coef(row[c])}
        cells = text_cells(row)

        if not coefs:
            # Either a coefficient-column sub-header, or a section title.
            lbls = {c: t for c, t in cells if c in coef_cols and _COEF_LABEL.search(t)}
            if lbls:
                labels.update({c: re.sub(r"\s+", " ", t) for c, t in lbls.items()})
            for c, t in cells:
                if c in coef_cols:
                    continue
                if _TIP_CONDUCERE.search(t):
                    tip_post, section = "conducere", re.sub(r"\s+", " ", t)[:160]
                elif _TIP_EXECUTIE.search(t):
                    tip_post, section = "execuție", re.sub(r"\s+", " ", t)[:160]
                elif c == col_of.get("functie") and not GRADE_TOKENS.match(t) and len(t) <= 90:
                    # A function heading a group of grade rows that carry the
                    # coefficients (`I CIII A`: "Preot", then "gradul I" 1.6).
                    cur_functie = re.sub(r"\s+", " ", t).strip()
                elif len(t) > 18:
                    section = re.sub(r"\s+", " ", t)[:160]
            continue

        def cell(role):
            c = col_of.get(role)
            if c is None or c >= len(row):
                return ""
            v = row[c]
            return re.sub(r"\s+", " ", str(v)).strip() if isinstance(v, str) else (
                str(v).strip() if v is not None else ""
            )

        functie = cell("functie")
        grad = cell("grad")
        studii = cell("studii")
        vechime = cell("vechime")

        # Sheets like `III CIV` put the grade in the function column on
        # continuation rows; sheets like `I CIII A` indent it there too.
        if functie and not grad and GRADE_TOKENS.match(functie):
            grad, functie = functie, ""
        # Anexa II has no grade column: it appends the grade to the function
        # cell after a semicolon ("Asistent medical; ...; principal"). Without
        # lifting it out, three rows with different coefficients are
        # indistinguishable — and health is the largest job family on the site.
        if functie and not grad and ";" in functie:
            head, _, tail = functie.rpartition(";")
            if GRADE_TOKENS.match(tail.strip()):
                grad, functie = tail.strip(), head.strip()

        if functie:
            cur_functie = functie
        if studii:
            cur_studii = studii
        functie = functie or cur_functie
        studii = studii or cur_studii

        codes = [t for _, t in cells if CODE_RE.match(t.replace(" ", ""))]

        # The code prefix is a more reliable regim signal than the title rows:
        # Anexa VIII Capitolul I (funcţionari publici) numbers everything 81.*,
        # Capitolul II (personal contractual) 82.*.
        regim = ctx["regim"] or sheet_regim
        if codes:
            regim = {"81": "funcționar public", "82": "personal contractual"}.get(codes[0][:2], regim)

        if not functie:
            review.append({"sheet": ws.title, "row": row_no, "reason": "coefficient with no function name"})
            continue

        coef_vals = [v for _, v in sorted(coefs.items())]
        record = {
            "version_id": version_id,
            "anexa": anexa,
            "sheet": ws.title,
            "source_row": row_no,
            "cod": codes[0] if codes else "",
            "coduri": "|".join(codes),
            "functie": functie,
            "sinonime": "|".join(split_synonyms(functie)),
            "regim": regim,
            "tip_post": tip_post,
            "nivel_administrativ": ctx["nivel_administrativ"],
            "banda_populatie": ctx["banda_populatie"],
            "grad_treapta": grad if grad not in {"-", ""} else "",
            "vechime": vechime if vechime not in {"-", ""} else "",
            "studii": studii if studii not in {"-", ""} else "",
            "sectiune": section,
            "coef_min": min(coef_vals),
            "coef_max": max(coef_vals),
            "coeficienti": "|".join(f"{v:.10g}" for v in coef_vals),
            "coef_labels": "|".join(labels.get(c, "") for c in sorted(coefs)),
            "needs_review": 0,
        }
        if (
            not record["studii"] and not record["grad_treapta"] and not record["vechime"]
            and has_axis and tip_post != "conducere"
        ):
            # The sheet offers a grade/studies axis but this row carries none --
            # worth a human look. Anexa IX (demnitari) and management blocks are
            # ungraded by design, so they are not flagged.
            record["needs_review"] = 1
            review.append({"sheet": ws.title, "row": row_no, "reason": "no grade/studies/seniority axis"})
        out.append(record)

    return out, review


def split_synonyms(functie: str) -> list[str]:
    """`"Consilier; expert; inspector de specialitate"` is one row, three names.

    Those alternatives are what make title retrieval work, so they are indexed
    individually. Footnote markers (`1)`, `2)`) and parentheticals are dropped.
    """
    s = re.sub(r"\s*\d\)\s*", " ", functie)
    parts = re.split(r"\s*[;]\s*|\s*/\s*(?![^(]*\))", s)
    names = []
    for p in parts:
        p = re.sub(r"\s*\(.*?\)\s*", " ", p)
        p = re.sub(r"\s+", " ", p).strip(" .,-")
        if 2 < len(p) <= 90 and not GRADE_TOKENS.match(p):
            names.append(p)
    return names or [re.sub(r"\s+", " ", functie).strip()]


# --- source 2: Legea 153/2017, the law in force -----------------------------
# The consolidated text of legislatie.just.ro has real <table> elements. The
# values are the 2022 annex values, so each table carries the rule that turns
# them into the pay of 2026 (ADR 0008; S1 audit 3.3 and 3.4):
#   * Anexa VIII and the annexes of OUG 53/2024 art. I: 2022 value x 1.15, because
#     OUG 115/2023 (+5%) and OUG 53/2024 (+10%) both count from December 2023.
#   * Health and social assistance: the June 2024 column of OUG 19/2024.
#   * Education: the June 2024 column of OUG 128/2023.
# The freezes of 2025 and 2026 add nothing.
LAW_DIR = ROOT / "docs" / "salarii" / "lege-153"
LAW_HTML = LAW_DIR / "legea-153-2017-id298840-2026-11-18.html"
OUG19_HTML = LAW_DIR / "oug-19-2024-id279777-2026-11-18.html"
LAW_VERSION = "lege153-2026"
FACTOR_2024 = 1.15     # OUG 115/2023 art. I alin. (1) +5% and OUG 53/2024 art. I alin. (1) +10%
FACTOR_115 = 1.05      # annexes that OUG 53/2024 does not name: OUG 115/2023 only
VICEMAYOR_REFERENCE = 2415   # 2,300 x 1.05, OUG 115/2023 art. I alin. (2)

A_VIII = "Legea 153/2017 Anexa VIII"
_R24 = "(2022) x 1,15 (OUG 115/2023 + OUG 53/2024)"
_R05 = "(2022) x 1,05 (OUG 115/2023)"
# (first table, last table, anexa, source text, mode, factor, regim, nivel, review note)
# Annexes III, IV and V were reviewed on 2026-11-18 (docs/activity-log.md): no row carries a note now.
LAW_SPECS = [
    (1, 7, "I", "OUG 128/2023 anexa, iunie 2024", "jun2024", 1.0, "", "", ""),
    (21, 22, "I", f"Legea 153/2017 Anexa I Cap. II {_R24}", "y2022", FACTOR_2024, "", "", ""),
    # Table 45 (Anexa II pct. 3.3, social assistance care staff) is left out on purpose: OUG 19/2024
    # Anexa 2 (tables 17-18) lists the same functions at higher June 2024 values (Infirmieră 4,260
    # against 3,550 x 1,05). Reviewed 2026-11-18: the rows of table 45 are superseded.
    (64, 79, "III", f"Legea 153/2017 Anexa III {_R24}", "y2022", FACTOR_2024, "", "", ""),
    (80, 85, "IV", f"Legea 153/2017 Anexa IV {_R24}", "y2022", FACTOR_2024, "", "", ""),
    (88, 91, "V", f"Legea 153/2017 Anexa V {_R05}", "y2022", FACTOR_115, "", "", ""),
    (92, 93, "V", f"Legea 153/2017 Anexa V Cap. VI {_R24}", "y2022", FACTOR_2024, "", "", ""),
    (94, 94, "V", f"Legea 153/2017 Anexa V Cap. VII {_R05}", "y2022", FACTOR_115, "", "", ""),
    (107, 112, "VIII", f"{A_VIII} Cap. I lit. A pct. I (central) {_R24}", "y2022", FACTOR_2024,
     "funcționar public", "central", ""),
    (113, 115, "VIII", f"{A_VIII} Cap. I lit. A pct. II (teritorial) {_R24}", "y2022", FACTOR_2024,
     "funcționar public", "teritorial", ""),
    (118, 119, "VIII", f"{A_VIII} Cap. II lit. A pct. I (central) {_R24}", "y2022", FACTOR_2024,
     "personal contractual", "central", ""),
    (120, 143, "VIII", f"{A_VIII} Cap. II lit. A (funcții specifice, autorități) {_R24}", "y2022",
     FACTOR_2024, "", "", ""),
    (144, 145, "VIII", f"{A_VIII} Cap. II lit. A pct. III (teritorial) {_R24}", "y2022", FACTOR_2024,
     "personal contractual", "teritorial", ""),
    (150, 155, "VIII", f"{A_VIII} Cap. II lit. B {_R24}", "y2022", FACTOR_2024,
     "personal contractual", "", ""),
    (156, 157, "VIII", f"{A_VIII} Cap. II lit. C {_R24}", "y2022", FACTOR_2024,
     "personal contractual", "", ""),
    (158, 162, "VIII", f"{A_VIII} Cap. II lit. D-F {_R24}", "y2022", FACTOR_2024,
     "personal contractual", "", ""),
    (168, 171, "VIII", f"{A_VIII} Cap. II lit. G-H {_R24}", "y2022", FACTOR_2024,
     "personal contractual", "", ""),
]
# Left out on purpose: Anexa VII (tables 103-106: no function column; 2 of 9,750 postings),
# Anexa I Cap. III clerical staff (table 23) and the aviation tables 163-167 (no postings).
# OUG 19/2024: Anexa 1 (tables 1-3 management, 5-16 by type of unit), Anexa 2 (17-18).
_UNIT = {5: "a.1", 6: "a.2", 7: "a.3", 8: "a.4", 9: "b.1", 10: "b.2", 11: "b.3", 12: "b.4",
         13: "c.1", 14: "c.2", 15: "c.3", 16: "c.4"}
OUG19_SPECS = [(1, 3, "II", "OUG 19/2024 Anexa 1 pct. 1 (conducere), iunie 2024", "jun2024", 1.0, "", "", "")]
for _t, _u in _UNIT.items():
    OUG19_SPECS.append((_t, _t, "II", f"OUG 19/2024 Anexa 1 lit. {_u}, iunie 2024", "jun2024", 1.0, "", "", ""))
OUG19_SPECS.append((17, 18, "II", "OUG 19/2024 Anexa 2 (asistență socială), iunie 2024", "jun2024", 1.0, "", "", ""))

#: Art. 11 alin. (4): the cap of a local post is the vice-mayor indemnity, Anexa IX
#: lit. C coefficient x 2,415. One cap for each band: the highest vice-mayor
#: coefficient of the band, without the county seats. County seats, sectors and
#: county councils have their own row.
# One row per vice-mayor row of Anexa IX lit. C (poz. 2 and 17-28), plus the
# vice-president of a county council (poz. 4 of the same table). (position, type of unit, lower and upper
# limit of population; `None` = no limit.) Positions 23-28 may pay up to 10% over the
# cap (OUG 53/2024 art. II).
PLAFON_ROWS = [
    (2, "capitală", None, None),
    (4, "consiliu județean", None, None),
    (17, "municipiu reședință de județ", None, None),
    (18, "sector", None, None),
    (19, "oraș/municipiu", 100_001, None),
    (20, "oraș/municipiu", 50_001, 100_000),
    (21, "oraș/municipiu", 20_001, 50_000),
    (22, "comună", 20_001, 50_000),
    (23, "oraș", 10_001, 20_000),
    (24, "comună", 10_001, 20_000),
    (25, "oraș", 0, 10_000),
    (26, "comună", 5_001, 10_000),
    (27, "comună", 3_001, 5_000),
    (28, "comună", 0, 3_000),
]
OUG53_POSITIONS = range(23, 29)
OUG53_FACTOR = 1.10
ANEXA_IX_TABLE = 174

_LEI_RE = re.compile(r"^\d{1,3}(\.\d{3})+$|^\d{3,6}$")
_COEF_RE = re.compile(r"^\d{1,2}[.,]\d{1,2}$")
_FOOT = re.compile(r"\s*\*+\s*\)|\s*\*+\d*\)|\s+\d\)|\^\d\)|\s*\*\d+\)")
_GRADE_TAIL = re.compile(
    r"(?:^|[;,]\s*|\s+)("
    r"grad(?:ul)?\s+profesional\s+(?:superior|principal|asistent|debutant)"
    r"|grad(?:ul)?\s+(?:IV|I{1,3}|V)\s?A?|treapt[aă]\s+(?:IV|I{1,3})\s?A?"
    r"|clasa\s+(?:a\s+)?(?:IV|I{1,3})(?:-a)?"
    r"|debutant[ăa]?|definitiv|principal|superior|asistent|practicant|stagiar"
    r"|(?:IV|I{1,3})\s?A?)\s*$", re.I)
_GRADE_ONLY = re.compile(
    r"^(?:grad(?:ul)?\s+profesional\s+\w+|grad(?:ul)?\s+(?:IV|I{1,3}|V)\s?A?|treapt[aă]\s+(?:IV|I{1,3})\s?A?"
    r"|clasa\s+(?:a\s+)?(?:IV|I{1,3})(?:-a)?"
    r"|debutant[ăa]?|definitiv|principal|superior|asistent|practicant|stagiar|(?:IV|I{1,3})\s?A?)$", re.I)


def lei_of(cell: str):
    c = (cell or "").strip()
    if not _LEI_RE.match(c):
        return None
    v = int(c.replace(".", ""))
    return v if 1000 <= v <= 80000 else None


def coef_of(cell: str):
    c = (cell or "").strip()
    return float(c.replace(",", ".")) if _COEF_RE.match(c) else None


def expand_table(table) -> list[list[str]]:
    """The cell text of an HTML table as a rectangular grid (rowspan and colspan expanded)."""
    span: dict[tuple[int, int], str] = {}
    rows = []
    for ri, tr in enumerate(table.find_all("tr")):
        out: dict[int, str] = {}
        ci = 0
        for c in tr.find_all(["td", "th"]):
            while (ri, ci) in span:
                out[ci] = span[(ri, ci)]
                ci += 1
            txt = re.sub(r"\s+", " ", c.get_text(" ")).strip()
            rs, cs = int(c.get("rowspan", 1) or 1), int(c.get("colspan", 1) or 1)
            for dj in range(cs):
                out[ci + dj] = txt
                for di in range(1, rs):
                    span[(ri + di, ci + dj)] = txt
            ci += cs
        while (ri, ci) in span:
            out[ci] = span[(ri, ci)]
            ci += 1
        rows.append([out.get(i, "") for i in range(max(out) + 1)] if out else [])
    return rows


def canon_grade(raw: str, kind: str) -> tuple[str, str]:
    """Spell a grade the way the draft grid and `ocupatii.GRADE_TOKENS` do. Returns (grade, kind)."""
    s = re.sub(r"\s+", " ", raw.lower().replace("profesional", "")).strip(" ;,")
    s = re.sub(r"^grad(?:ul)?\s+(?=superior|principal|asistent|debutant)", "", s)
    if re.fullmatch(r"superior|principal|asistent|debutant[ăa]?|definitiv|practicant|stagiar", s):
        return s.rstrip("ăa") if s.startswith("debutant") else s, kind
    m = re.fullmatch(r"(grad(?:ul)?|treapt[aă])?\s*(iv|i{1,3}|v)\s?(a?)", s)
    if not m:
        return raw.strip(), kind
    word = m.group(1) or ""
    if word:
        kind = "treaptă" if word.startswith("treapt") else "gradul"
    roman = m.group(2).upper() + (" A" if m.group(3) else "")
    return f"{kind or 'gradul'} {roman}", kind


def split_synonyms_law(functie: str) -> list[str]:
    """The law separates alternative names with commas, semicolons and slashes."""
    s = _FOOT.sub(" ", functie)
    s = re.sub(r"\s*\(.*?\)\s*", " ", s)
    names = []
    for p in re.split(r"\s*[;,/]\s*", s):
        p = re.sub(r"\s+", " ", p).strip(" .,-*")
        if 2 < len(p) <= 90 and not _GRADE_ONLY.match(p):
            names.append(p)
    whole = re.sub(r"\s+", " ", s).strip(" .,-*")
    # The draft grid keeps a comma list as one name too, so the dictionary may store it whole.
    if len(names) > 1 and whole not in names and len(whole) <= 160:
        names.append(whole)
    return names or [re.sub(r"\s+", " ", functie).strip()]


def section_text(table) -> str:
    """The three nearest headings before a table, for the audit trail."""
    parts = []
    for t in table.find_all_previous(string=True, limit=60):
        x = re.sub(r"\s+", " ", t).strip()
        if len(x) > 3 and not x.startswith(("(la ", "+")) and x not in parts:
            parts.append(x[:80])
        if len(parts) == 3:
            break
    return " / ".join(reversed(parts))


def parse_law_table(table, tno: int, spec, src: str) -> tuple[list[dict], list[dict]]:
    """One table of the law -> grid records. Anything unclear gets `needs_review`."""
    first, last, anexa, act, mode, factor, regim, nivel, note = spec
    g = expand_table(table)
    out: list[dict] = []
    review: list[dict] = []
    # The first row with a lei value ends the header.
    hdr_end = next((i for i, r in enumerate(g)
                    if not (r[:1] and r[0].lower().startswith("nr")) and any(lei_of(c) for c in r[2:])), None)
    if hdr_end is None:
        return [], [{"sheet": f"{src}-t{tno}", "reason": "no lei value in the table"}]
    ncol = max(len(r) for r in g)
    labels = []
    for c in range(ncol):
        cells = []
        for r in g[:hdr_end]:
            if c < len(r) and r[c] and r[c] not in cells:
                cells.append(r[c])
        labels.append(" ".join(cells).lower())
    if mode == "jun2024":
        val_cols = [c for c in range(2, ncol) if "iunie 2024" in labels[c]]
    else:
        val_cols = [c for c in range(2, ncol)
                    if ("salari" in labels[c] or "anul 2022" in labels[c]) and "coefic" not in labels[c]]
    coef_cols = [c for c in range(2, ncol) if "coefic" in labels[c]]
    if not val_cols:
        return [], [{"sheet": f"{src}-t{tno}", "reason": f"no value column for mode {mode}"}]
    studii_col = next((c for c in range(1, ncol) if "studi" in labels[c]), None)
    vech_col = next((c for c in range(1, ncol) if "vechim" in labels[c]), None)
    text_cols = [c for c in range(1, min(val_cols)) if c not in (studii_col, vech_col)
                 and not re.search(r"salari|lei|20\d\d|coefic", labels[c])]
    sect = section_text(table)
    conducere = len(val_cols) >= 2 and all("grad" in labels[c] for c in val_cols[:2])
    cur_name, cur_kind, prev_nr, order = "", "", None, 0
    for ri in range(hdr_end, len(g)):
        row = g[ri] + [""] * (ncol - len(g[ri]))
        cells = [c for c in row if c]
        if len(cells) >= 2 and len(set(cells)) == 1:      # a spanning heading
            continue
        nr = row[0].strip().rstrip(".")
        texts = [row[c] for c in text_cols if row[c]]
        text = _FOOT.sub(" ", texts[0] if texts else "").strip(" *")
        extra = [t for t in texts[1:] if t not in (texts[0] if texts else "")]
        vals = [lei_of(row[c]) for c in val_cols]
        vals = [v for v in vals if v]
        if not vals:
            if nr and text and not _GRADE_ONLY.match(text):
                cur_name, cur_kind, prev_nr = text, "", nr     # `Preot` heads the grade rows below
            continue
        grade = ""
        if extra:
            grade, cur_kind = canon_grade(extra[0], cur_kind)
        if mode == "y2022" and spec[2] in ("VIII", "I", "III", "IV", "V", "VII"):
            # "Expert criminalist, gradul I (10 ani vechime)": the grade is followed by a note.
            text = re.sub(r"(\bgrad(?:ul)?\s+(?:IV|I{1,3}|V)\s?A?)\s*\([^)]*\)\s*$", r"\1", text, flags=re.I)
            m = _GRADE_TAIL.search(text)
            if m and not grade:
                grade, cur_kind = canon_grade(m.group(1), cur_kind)
                text = text[:m.start()].strip(" ;,-–")   # "- gradul II" leaves no name: it inherits the head row
            elif not m and not grade and not conducere:
                # "curier diplomatic I, referent cabinet": one alternative ends in the grade.
                alts = re.split(r"\s*[;,]\s*", text)
                hits = [a for a in alts if re.search(r"\s(?:IV|I{1,3})$", a)]
                if len(alts) > 1 and len(hits) == 1:
                    grade, cur_kind = canon_grade(hits[0].rsplit(None, 1)[1], cur_kind)
                    text = ", ".join(a.rsplit(None, 1)[0] if a is hits[0] else a for a in alts)
        elif ";" in text or "," in text:
            # OUG 19/2024 writes the grade after a comma: "Asistent medical, principal".
            head, _, tail = text.rpartition(";" if ";" in text else ",")
            if GRADE_TOKENS.match(tail.strip()):
                grade, text = tail.strip(), head.strip()
        if text:
            name = cur_name = text
        else:
            name = cur_name
        prev_nr = nr or prev_nr
        if not name:
            review.append({"sheet": f"{src}-t{tno}", "row": ri + 1, "reason": "value with no function name"})
            continue
        studii_raw = row[studii_col] if studii_col is not None else ""
        levels = [x.strip().upper() for x in re.split(r"[;,]", studii_raw) if x.strip()] or [""]
        cvals = [coef_of(row[c]) for c in coef_cols]
        cvals = [v for v in cvals if v]
        tip = "conducere" if conducere else ("execuție" if anexa == "VIII" else "")
        for lvl in levels:
            order += 1
            out.append({
                "version_id": LAW_VERSION, "anexa": anexa, "sheet": f"{src}-t{tno}", "source_row": ri + 1,
                "cod": "", "coduri": "", "functie": name, "sinonime": "|".join(split_synonyms_law(name)),
                "regim": regim, "tip_post": tip, "nivel_administrativ": nivel, "banda_populatie": "",
                "grad_treapta": grade, "vechime": row[vech_col] if vech_col is not None else "",
                "studii": lvl, "sectiune": sect,
                "coef_min": min(cvals) if cvals else 0, "coef_max": max(cvals) if cvals else 0,
                "coeficienti": "|".join(f"{v:.10g}" for v in cvals), "coef_labels": "",
                "needs_review": 1 if note else 0,
                "lei_min": min(vals), "lei_max": max(vals), "act_sursa": f"{act}; {src} tabel {tno} rând {ri + 1}",
                "tip_unitate": "", "multiplicator_regula": factor,
            })
    return out, review


def build_law_grid() -> tuple[list[dict], list[dict], dict]:
    from bs4 import BeautifulSoup
    grid, review = [], []
    per_table: dict[str, int] = {}
    for html, specs, src in ((LAW_HTML, LAW_SPECS, "L153"), (OUG19_HTML, OUG19_SPECS, "OUG19")):
        tables = BeautifulSoup(html.read_text(encoding="utf-8"), "lxml").find_all("table")
        for spec in specs:
            for tno in range(spec[0], spec[1] + 1):
                rows, notes = parse_law_table(tables[tno], tno, spec, src)
                if src == "OUG19" and tno in _UNIT:
                    for r in rows:
                        # a.N, b.N and c.N are three staff groups of the same unit type N.
                        r["tip_unitate"] = "a." + _UNIT[tno].split(".")[1]
                if spec[8]:
                    for r in rows:
                        r["act_sursa"] += f" [needs_review: {spec[8]}]"
                grid.extend(rows)
                review.extend(notes)
                per_table[f"{src}-t{tno}"] = len(rows)
    # Coefficient-only columns (draft vocabulary) are 0 for rows that have none.
    return grid, review, per_table


def build_plafon() -> list[dict]:
    from bs4 import BeautifulSoup
    tables = BeautifulSoup(LAW_HTML.read_text(encoding="utf-8"), "lxml").find_all("table")
    g = expand_table(tables[ANEXA_IX_TABLE])
    by_poz = {}
    for i, r in enumerate(g):
        m = re.match(r"^(\d+)\.?$", r[0]) if r else None
        if m and len(r) >= 3 and coef_of(r[2]):
            by_poz[int(m.group(1))] = (coef_of(r[2]), r[1], i + 1)
    rows = []
    for poz, tip, lo, hi in PLAFON_ROWS:
        coef, name, ri = by_poz[poz]
        cap = math.ceil(coef * VICEMAYOR_REFERENCE)
        up = poz in OUG53_POSITIONS
        sursa = f"Legea 153/2017 Anexa IX lit. C poz. {poz} ({name}); L153-t{ANEXA_IX_TABLE} rând {ri}"
        if up:
            sursa += "; OUG 53/2024 art. II: până la 10% peste plafon"
        rows.append({
            "tip_unitate": tip,
            "populatie_min": "" if lo is None else lo, "populatie_max": "" if hi is None else hi,
            "coeficient": coef, "plafon_lei": cap,
            "majorare": OUG53_FACTOR if up else 1.0,
            "plafon_maxim_lei": math.ceil(round(cap * OUG53_FACTOR, 6)) if up else cap,
            "valoare_referinta_lei": VICEMAYOR_REFERENCE,
            "sursa": sursa,
        })
    return rows


LAW_FIELDS = ["version_id", "anexa", "sheet", "source_row", "cod", "coduri", "functie", "sinonime", "regim",
              "tip_post", "nivel_administrativ", "banda_populatie", "grad_treapta", "vechime", "studii",
              "sectiune", "coef_min", "coef_max", "coeficienti", "coef_labels", "needs_review",
              "lei_min", "lei_max", "act_sursa", "tip_unitate", "multiplicator_regula"]


def main_law(args) -> int:
    grid, review, per_table = build_law_grid()
    plafon = build_plafon()
    print(f"parsed {len(grid)} grid rows from {len(per_table)} tables of the law in force")
    by_anexa = Counter(r["anexa"] for r in grid)
    print("  by anexă: " + ", ".join(f"{k}={v}" for k, v in sorted(by_anexa.items())))
    empty = [s for s, n in per_table.items() if n == 0]
    if empty:
        print(f"  tables with no rows ({len(empty)}): {', '.join(empty)}")
    print(f"  needs_review rows: {sum(r['needs_review'] for r in grid)}; unparsed notes: {len(review)}")
    for reason, n in Counter(x["reason"] for x in review).most_common():
        print(f"    {n:5d}  {reason}")
    for note, n in Counter(r["act_sursa"].split("[needs_review: ")[1][:-1] for r in grid if r["needs_review"]).most_common():
        print(f"    review {n:4d}  {note}")
    print("  local cap per unit: " + "; ".join(
        f"{p['tip_unitate']} {p['populatie_min']}-{p['populatie_max']}={p['plafon_maxim_lei']}" for p in plafon))
    if args.report:
        return 0
    args.out_dir.mkdir(parents=True, exist_ok=True)
    grid_path = args.out_dir / f"grila-{LAW_VERSION}.csv"
    with grid_path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=LAW_FIELDS)
        w.writeheader()
        w.writerows(grid)
    print(f"wrote {grid_path} ({len(grid)} rows)")
    plafon_path = args.out_dir / "plafon-local.csv"
    with plafon_path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=list(plafon[0].keys()))
        w.writeheader()
        w.writerows(plafon)
    print(f"wrote {plafon_path} ({len(plafon)} rows)")
    if review:
        rev_path = args.out_dir / f"grila-{LAW_VERSION}-neciteti.csv"
        with rev_path.open("w", newline="", encoding="utf-8") as fh:
            w = csv.DictWriter(fh, fieldnames=["sheet", "row", "reason"])
            w.writeheader()
            w.writerows([{"sheet": x["sheet"], "row": x.get("row", ""), "reason": x["reason"]} for x in review])
        print(f"wrote {rev_path} ({len(review)} unparsed rows)")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--source", choices=["draft", "lege-153"], default="draft",
                    help="draft = the MMFTSS workbook; lege-153 = the consolidated HTML of the law in force")
    ap.add_argument("--xlsx", type=Path, default=DEFAULT_XLSX)
    ap.add_argument("--version-id", default="2026-07-17", help="dataset version these coefficients belong to")
    ap.add_argument("--out-dir", type=Path, default=OUT_DIR)
    ap.add_argument("--report", action="store_true", help="print coverage and review counts, write nothing")
    args = ap.parse_args()
    if args.source == "lege-153":
        return main_law(args)

    if not args.xlsx.exists():
        sys.exit(f"workbook not found: {args.xlsx}")

    wb = openpyxl.load_workbook(args.xlsx, read_only=True, data_only=True)
    grid: list[dict] = []
    review: list[dict] = []
    per_sheet: dict[str, int] = {}
    for ws in wb.worksheets:
        rows, notes = parse_sheet(ws, args.version_id)
        grid.extend(rows)
        review.extend(notes)
        per_sheet[ws.title] = len(rows)
    wb.close()

    print(f"parsed {len(grid)} grid rows from {len(per_sheet)} sheets")
    by_anexa = Counter(r["anexa"] for r in grid)
    print("  by anexă: " + ", ".join(f"{k or '?'}={v}" for k, v in sorted(by_anexa.items())))
    empty = [s for s, n in per_sheet.items() if n == 0]
    if empty:
        print(f"  sheets with no rows ({len(empty)}): {', '.join(empty)}")
    flagged = sum(r["needs_review"] for r in grid)
    print(f"  needs_review rows: {flagged}; unparsed notes: {len(review)}")
    if review:
        for reason, n in Counter(x["reason"] for x in review).most_common():
            print(f"    {n:5d}  {reason}")

    if args.report:
        return 0

    args.out_dir.mkdir(parents=True, exist_ok=True)
    grid_path = args.out_dir / f"grila-{args.version_id}.csv"
    with grid_path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=list(grid[0].keys()))
        w.writeheader()
        w.writerows(grid)
    print(f"wrote {grid_path} ({len(grid)} rows)")

    # Retrieval index: one row per distinct function name -> the grid rows it selects.
    index: dict[str, dict] = {}
    for i, r in enumerate(grid):
        for name in r["sinonime"].split("|"):
            key = norm(name)
            if not key:
                continue
            e = index.setdefault(key, {
                "nume_normalizat": key, "nume": name, "anexe": set(),
                "regimuri": set(), "coduri": set(), "randuri": [],
            })
            e["anexe"].add(r["anexa"])
            if r["regim"]:
                e["regimuri"].add(r["regim"])
            if r["cod"]:
                e["coduri"].add(r["cod"])
            e["randuri"].append(f'{r["sheet"]}!{r["source_row"]}')
    func_path = args.out_dir / f"functii-{args.version_id}.csv"
    with func_path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["nume_normalizat", "nume", "anexe", "regimuri", "coduri", "nr_randuri", "randuri"])
        for key in sorted(index):
            e = index[key]
            w.writerow([
                e["nume_normalizat"], e["nume"], "|".join(sorted(e["anexe"])),
                "|".join(sorted(e["regimuri"])), "|".join(sorted(e["coduri"])),
                len(e["randuri"]), "|".join(e["randuri"][:40]),
            ])
    print(f"wrote {func_path} ({len(index)} distinct function names)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

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

Usage:
    python build-salary-grid.py --report
    python build-salary-grid.py --version-id 2026-07-17
"""
from __future__ import annotations

import argparse
import csv
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


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--xlsx", type=Path, default=DEFAULT_XLSX)
    ap.add_argument("--version-id", default="2026-07-17", help="dataset version these coefficients belong to")
    ap.add_argument("--out-dir", type=Path, default=OUT_DIR)
    ap.add_argument("--report", action="store_true", help="print coverage and review counts, write nothing")
    args = ap.parse_args()

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

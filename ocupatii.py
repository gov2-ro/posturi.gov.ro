"""Job-title normalisation: canonical titles, professional grades, COR codes.

One module owns this, the way `webapp/apps/jobs/judete.py` owns county
normalisation. Before it, the same concepts were spelled four different ways in
`infer_postings.py` alone (`_SENIORITY_PATTERNS`, `_GRADE_RE`, `_STUDIES_LEVELS`)
plus `schema_models.StudyLevel` and a fifth notation in the salary grid.

The problem this solves: 9,757 postings carry 5,979 distinct titles, 4,759 once
case and diacritics are normalised. `Îngrijitor`, `ÎNGRIJITOR` and `îngrijitor`
are three facet values today. Worse, a title is not one fact but three —
`"Referent de specialitate gradul III"` is an occupation, a professional grade
and (implicitly) a study level, and the salary grid is keyed on all three.

So `parse_title()` splits them deterministically, and only the genuinely
semantic step — which COR occupation, which grid row — goes to an LLM, with a
shortlist of real candidates retrieved from here so it cannot invent one.
"""
from __future__ import annotations

import csv
import re
import unicodedata
from dataclasses import dataclass
from difflib import SequenceMatcher
from functools import lru_cache
from pathlib import Path

DATA_DIR = Path(__file__).parent.resolve() / "data" / "ocupatii"
COR_CSV = DATA_DIR / "cor.csv"


def norm(s) -> str:
    """Lowercase, strip diacritics and punctuation. The join key everywhere."""
    s = unicodedata.normalize("NFD", str(s or ""))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn").lower()
    s = s.replace("ş", "s").replace("ţ", "t").replace("ș", "s").replace("ț", "t")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9 ]", " ", s)).strip()


# --- professional grades -----------------------------------------------------
# The canonical vocabulary is the salary grid's, because that is what the
# coefficient is keyed on. Postings write the same grade many ways: "gr. II",
# "gradul II", "grad 2", "II".

#: Canonical grade tokens, exactly as `data/salarii/grila-*.csv` spells them.
GRADE_TOKENS = [
    "debutant", "asistent", "principal", "superior", "definitiv",
    "practicant", "stagiar",
    "gradul I A", "gradul I", "gradul II", "gradul III", "gradul IV",
    "treapta I A", "treapta I", "treapta II", "treapta III",
    "categoria I", "categoria II", "categoria III", "categoria IV", "categoria V",
]

_ROMAN = {"i": "I", "ii": "II", "iii": "III", "iv": "IV", "v": "V",
          "1": "I", "2": "II", "3": "III", "4": "IV", "5": "V"}

#: Bare seniority words. `definitiv` is a teaching grade, not a contract term.
_BARE_GRADE = re.compile(
    r"\b(debutant[aă]?|asistent[aă]?|principal[aă]?|superior[aă]?|definitiv[aă]?"
    r"|practicant[aă]?|stagiar[aă]?)\b", re.I)

#: `gradul II`, `gr. I A`, `treapta IA`, `categoria 3`, and the bare-roman tail
#: of `Muncitor calificat I`.
_RANKED_GRADE = re.compile(
    r"\b(grad(?:ul)?|gr\.?|treapt[aă]|tr\.?|categoria|cat\.?)\s*"
    r"([IVX]+|[1-5])\s*(A\b)?", re.I)
_TRAILING_ROMAN = re.compile(r"\s+([IVX]{1,3}A?)\s*$", re.I)

#: Study level as written inside a title: "(S)", "asistent medical PL", "studii M".
_STUDY_IN_TITLE = [
    (re.compile(r"\bstudii\s+superioare\b|\(\s*S\s*\)|\bnivel\s+S\b", re.I), "licenta"),
    (re.compile(r"\bpostliceal\w*\b|\bPL\b|\(\s*PL\s*\)", re.I), "postliceala"),
    (re.compile(r"\bstudii\s+medii\b|\bliceal\w*\b|\(\s*M\s*\)", re.I), "liceala"),
    (re.compile(r"\bSSD\b", re.I), "licenta"),
    (re.compile(r"\bmaster\w*\b", re.I), "master"),
    (re.compile(r"\bdoctor(at|and)\w*\b", re.I), "doctorat"),
    (re.compile(r"\bclasa\s+I\b(?!\s*[IV])", re.I), "licenta"),
    (re.compile(r"\bclasa\s+II\b(?!\s*I)", re.I), "licenta"),
    (re.compile(r"\bclasa\s+III\b", re.I), "liceala"),
]

#: Left over once the grade is lifted out: "grad profesional", "grad", "treapta".
_GRADE_LEFTOVER = re.compile(r"\b(grad(ul)?\s+profesional|grad(ul)?|treapt[aă])\b", re.I)

#: Administrative noise that is never part of an occupation.
_TITLE_NOISE = re.compile(
    r"^\s*\d+\s*(post(uri)?|norm[ae])?\s*(de|:)?\s*"        # "1 post de ..."
    r"|\bperioad[aă]\s+(ne)?determinat[aă]\b"
    r"|\bnorm[aă]\s+(întreag[aă]|partial[aă]|parțial[aă])\b"
    r"|\btemporar\b|\bvacant\w*\b|\bpost\s+vacant\b",
    re.I)


@dataclass(frozen=True)
class ParsedTitle:
    """What a raw posting title decomposes into, before any LLM sees it."""
    raw: str
    base: str            # the occupation, grade and noise removed
    base_norm: str
    grade_token: str     # canonical grid spelling, or ""
    study_hint: str      # a `schema_models.StudyLevel` value, or ""


def _canon_grade(kind: str, rank: str, suffix: str | None) -> str:
    kind = norm(kind)
    if kind.startswith("grad") or kind == "gr":
        prefix = "gradul"
    elif kind.startswith("treapt") or kind == "tr":
        prefix = "treapta"
    else:
        prefix = "categoria"
    roman = _ROMAN.get(norm(rank), rank.upper())
    token = f"{prefix} {roman}"
    if suffix and prefix != "categoria":
        token += " A"
    return token if token in GRADE_TOKENS else ""


def parse_title(raw: str) -> ParsedTitle:
    """Split a posting title into occupation + grade + study hint.

    >>> p = parse_title("1 post Referent de specialitate gr. II — Serviciul Buget")
    >>> p.base, p.grade_token
    ('Referent de specialitate', 'gradul II')
    """
    text = raw or ""
    # Drop a trailing department/compartment qualifier: it names where the post
    # sits, not what it is.
    # A dash usually introduces the department ("Referent — Serviciul Buget"),
    # but just as often the grade ("Asistent medical comunitar – debutant").
    # Keep the tail when it is a grade; the grade is half of the grid key.
    parts = re.split(r"\s+[-–—]\s+|\s*,\s*(?=Serviciul|Compartimentul|Direc[țt]ia|Biroul)", text)
    if len(parts) > 1:
        tail = parts[-1].strip()
        keep_tail = bool(_BARE_GRADE.search(tail) or _RANKED_GRADE.search(tail))
        text = f"{parts[0]} {tail}" if keep_tail else parts[0]
    text = _TITLE_NOISE.sub(" ", text)

    study_hint = ""
    for pat, level in _STUDY_IN_TITLE:
        if pat.search(text):
            study_hint = level
            text = pat.sub(" ", text)
            break

    grade_token = ""
    m = _RANKED_GRADE.search(text)
    if m:
        grade_token = _canon_grade(m.group(1), m.group(2), m.group(3))
        if grade_token:
            text = text[:m.start()] + " " + text[m.end():]
    if not grade_token:
        # Last match, and never at position 0: "Asistent medical principal" is
        # an asistent medical at grade principal, not an asistent.
        matches = [m for m in _BARE_GRADE.finditer(text) if m.start() > 0]
        m = matches[-1] if matches else None
        if m:
            token = norm(m.group(1))
            # Romanian feminine/masculine endings: "debutanta" -> "debutant".
            for canon in GRADE_TOKENS:
                if token.startswith(canon) or canon.startswith(token[:-1] or token):
                    grade_token = canon
                    break
            if grade_token:
                text = text[:m.start()] + " " + text[m.end():]
    if not grade_token:
        m = _TRAILING_ROMAN.search(text)
        if m:
            grade_token = _canon_grade("gradul", m.group(1).rstrip("aA"),
                                       "A" if m.group(1).upper().endswith("A") else None)
            if grade_token:
                text = text[:m.start()]

    text = _GRADE_LEFTOVER.sub(" ", text)
    base = re.sub(r"\s+", " ", re.sub(r"[(),;:]+", " ", text)).strip(" .,-–—")
    return ParsedTitle(raw=raw, base=base, base_norm=norm(base),
                       grade_token=grade_token, study_hint=study_hint)


def canonical_label(base: str) -> str:
    """Display form: sentence case, so `ÎNGRIJITOR` and `îngrijitor` agree."""
    base = re.sub(r"\s+", " ", (base or "").strip())
    if not base:
        return ""
    return base[0].upper() + base[1:].lower() if base.isupper() else base[0].upper() + base[1:]


# --- COR ---------------------------------------------------------------------

@dataclass(frozen=True)
class CorEntry:
    cod_cor: str
    denumire: str
    denumire_normalizata: str
    grupa_majora: str
    subgrupa_majora: str
    grupa_minora: str
    isco_grupa_de_baza: str


#: ISCO-08 / COR major groups. Useful as a coarse, always-present facet even when
#: the 6-digit code is uncertain.
MAJOR_GROUPS = {
    "0": "Forțele armate",
    "1": "Conducători și funcționari superiori",
    "2": "Specialiști în diverse domenii de activitate",
    "3": "Tehnicieni și alți specialiști din domeniul tehnic",
    "4": "Funcționari administrativi",
    "5": "Lucrători în domeniul serviciilor",
    "6": "Lucrători calificați în agricultură, silvicultură și pescuit",
    "7": "Muncitori calificați și asimilați",
    "8": "Operatori la instalații și mașini",
    "9": "Muncitori necalificați",
}


class Cor:
    """The COR registry: exact lookup plus a shortlist for the LLM."""

    def __init__(self, entries: list[CorEntry]):
        self.entries = entries
        self.by_code = {e.cod_cor: e for e in entries}
        self.by_name: dict[str, list[CorEntry]] = {}
        for e in entries:
            self.by_name.setdefault(e.denumire_normalizata, []).append(e)
        self._tokens = {n: set(n.split()) for n in self.by_name}

    def lookup(self, title: str) -> CorEntry | None:
        """Exact normalised-name hit only. ~16% of titles; the rest need the LLM."""
        hits = self.by_name.get(norm(title))
        return hits[0] if hits else None

    def candidates(self, title: str, k: int = 12) -> list[tuple[float, CorEntry]]:
        """Shortlist real COR occupations for a free-text title.

        Token overlap carries more weight than raw string similarity here:
        COR names a job by adding qualifiers (`îngrijitor clădiri`,
        `șofer autoturisme`), so the posting's words are a subset of the COR
        name far more often than the two strings look alike end to end.
        """
        q = norm(title)
        if not q:
            return []
        qt = set(q.split())
        scored: list[tuple[float, CorEntry]] = []
        for name, entries in self.by_name.items():
            nt = self._tokens[name]
            shared = qt & nt
            if not shared:
                continue
            coverage = len(shared) / len(qt)          # how much of the title COR explains
            overlap = len(shared) / len(qt | nt)
            ratio = SequenceMatcher(None, q, name).ratio()
            score = 0.5 * coverage + 0.3 * overlap + 0.2 * ratio
            if score < 0.3:
                continue
            scored.append((round(score, 4), entries[0]))
        scored.sort(key=lambda t: (-t[0], t[1].cod_cor))
        return scored[:k]


@lru_cache(maxsize=2)
def load_cor(path: str | None = None) -> Cor:
    csv_path = Path(path) if path else COR_CSV
    if not csv_path.exists():
        raise FileNotFoundError(f"{csv_path} missing — see data/ocupatii/README.md")
    with csv_path.open(encoding="utf-8") as fh:
        entries = [CorEntry(**row) for row in csv.DictReader(fh)]
    return Cor(entries)


def isco_group(cod_cor: str | None) -> str:
    """ISCO-08 unit group, derived from the COR code. Never asked of a model."""
    cod = (cod_cor or "").strip()
    return cod[:4] if re.fullmatch(r"\d{6}", cod) else ""

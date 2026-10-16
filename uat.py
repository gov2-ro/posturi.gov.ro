"""Resolve an employer name to its administrative unit and population band.

Anexa VIII pays local-government posts on four separate sheets, one per size of
UAT, and the spread is large: `Consilier gradul II` is coefficient 1.35 under
10.000 locuitori and 1.85 over 200.000 — 5.541 vs 7.579 lei. So the band is not
a refinement, it decides the answer, and getting it wrong is worse than not
having it.

Hence this module returns a *set* of plausible bands, not a guess. Where the
administrative type settles it (a comună is under 10.000), the set has one
member. Where it does not (an `oraș` may be either side of 10.000), the set has
two and the estimate widens across them and says so.

A real population table collapses most of that uncertainty. `data/uat-populatie.csv`
(`nume,judet,tip,populatie`, built by `ops/build-uat-population.py`) holds every
town and every commune from the 2021 census. A name is not a key — Slatina is a
municipiu in Olt and a comună in Suceava, Ștefănești is a town in two counties —
so rows are matched on the unit type (`oras` / `comuna`) and, when the employer
string or the posting says which county, on the county too. A name that still
matches rows in different bands is ambiguous and falls back to the type rules
below, which are also what applies when the table is absent.
"""
from __future__ import annotations

import csv
import importlib.util
import re
import unicodedata
from functools import lru_cache
from pathlib import Path
from typing import NamedTuple

DATA_DIR = Path(__file__).parent.resolve() / "data"
POPULATION_CSV = DATA_DIR / "uat-populatie.csv"

#: The bands as `data/salarii/grila-*.csv` spells them.
BAND_OVER_200K = "peste 200.000"
BAND_50_200K = "50.000-200.000"
BAND_10_50K = "10.000-50.000"
BAND_UNDER_10K = "sub 10.000"

_THRESHOLDS = [(200_000, BAND_OVER_200K), (50_000, BAND_50_200K), (10_000, BAND_10_50K)]

#: Every UAT over 200.000 locuitori at the 2021 census. A short, stable and
#: well-known list — unlike the middle of the distribution, this end can be
#: stated with confidence, and it is the end with the largest coefficients.
OVER_200K = {
    "bucuresti", "cluj napoca", "iasi", "constanta",
    "timisoara", "brasov", "craiova", "galati",
}

#: Type of administrative unit, as written in employer names.
# Romanian declines these, so match the stem and let any ending follow:
# municipiu / municipiul / municipiului, oraş / oraşul / oraşului, and so on.
_UAT_TYPE = [
    ("sector", re.compile(r"\bsector\w*\s+[1-6]\b", re.I)),
    ("municipiu", re.compile(r"\bmunicipi\w*\b", re.I)),
    ("oras", re.compile(r"\bora[sșş]\w*\b", re.I)),
    ("comuna", re.compile(r"\bcomun(a|ei)\b", re.I)),
    ("judet", re.compile(r"\bjude[tțţ]ul\w*\b|\bconsiliul\s+jude[tțţ]ean\b", re.I)),
]

#: Employer prefixes that name a UAT: "Primăria Comunei X", "Comuna Y".
_UAT_PREFIX = re.compile(
    r"^\s*(prim[ăa]ria|consiliul\s+local|unitatea\s+administrativ[- ]teritorial[ăa]|uat)\s*"
    r"(ale|al|a)?\b\s*", re.I)
_TYPE_WORD = re.compile(
    r"^\s*(municipi\w*|ora[sșş]\w*|comun(a|ei)|jude[tțţ]ul\w*|sector\w*)\s+", re.I)


def norm(s) -> str:
    s = unicodedata.normalize("NFD", str(s or ""))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn").lower()
    s = s.replace("ş", "s").replace("ţ", "t").replace("ș", "s").replace("ț", "t")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9 ]", " ", s)).strip()


class PopulationRow(NamedTuple):
    tip: str          # "oras" (municipiu or oraș) or "comuna"
    county: str       # norm()ed canonical county
    population: int


@lru_cache(maxsize=1)
def load_populations() -> dict[str, tuple[PopulationRow, ...]]:
    """`data/uat-populatie.csv` as `norm(name) -> rows`. Empty when absent.

    One name can carry several rows (Slatina: a municipiu in Olt, communes
    elsewhere), which is why this maps to a tuple. A file without a `tip` column
    is read as towns only, which is what it held before communes were added.
    """
    if not POPULATION_CSV.exists():
        return {}
    table: dict[str, list[PopulationRow]] = {}
    with POPULATION_CSV.open(encoding="utf-8") as fh:
        for r in csv.DictReader(fh):
            if not (r.get("populatie") or "").isdigit():
                continue
            tip = (r.get("tip") or "oras").strip().lower()
            table.setdefault(norm(r["nume"]), []).append(
                PopulationRow(tip, norm(r.get("judet")), int(r["populatie"])))
    return {k: tuple(v) for k, v in table.items()}


@lru_cache(maxsize=1)
def _judete():
    """`webapp/apps/jobs/judete.py`, the one module that knows the 42 counties.

    Loaded by path: it is stdlib-only, and this script runs outside Django.
    """
    path = Path(__file__).parent.resolve() / "webapp" / "apps" / "jobs" / "judete.py"
    spec = importlib.util.spec_from_file_location("judete_ref", path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def canonical_county(text, *, prefix: bool = False) -> str:
    """Canonical county for `"Timiş"`, `"TIMIŞOARA, Timiș"`, `"judetul prahova"`; else `""`.

    `prefix=True` also accepts a unique prefix of at least three letters, because
    the source truncates ("județul Timi;").
    """
    judete = _judete()
    county = judete.normalize_judet(str(text or "")).judet
    if county or not prefix:
        return county or ""
    key = judete.fold(str(text or ""))
    hits = [c for c in judete.COUNTIES if judete.fold(c).startswith(key)]
    return hits[0] if len(key) >= 3 and len(hits) == 1 else ""


_COUNTY_WORD = re.compile(r"\bjude[tțţ]ul\.?\s+([^,()]+)|\bjud\.\s*([^,()]+)", re.I)


def employer_county(employer: str) -> str:
    """The county an employer string names, or `""`.

    "Comuna Matca, județul Galați" -> "Galați"; "PRIMARIA COMUNA X,JUDETUL PRAHOVA" ->
    "Prahova". A bare trailing county ("Primăria Corod, Galați") counts too.
    """
    text = employer or ""
    m = _COUNTY_WORD.search(text)
    if m:
        return canonical_county(m.group(1) or m.group(2), prefix=True)
    if "," in text:
        return canonical_county(text.rpartition(",")[2])
    return ""


def band_for_population(people: int) -> str:
    for floor, band in _THRESHOLDS:
        if people >= floor:
            return band
    return BAND_UNDER_10K


def parse_employer(employer: str) -> tuple[str, str]:
    """Return `(uat_type, uat_name)` from an employer name.

    `"Primăria Comunei Ciugud"` -> `("comuna", "Ciugud")`.
    `"Spitalul Județean Alba"` -> `("judet", "Alba")` — a county-level employer,
    which is not banded but is useful for `nivel_administrativ`.
    """
    text = (employer or "").strip()
    uat_type = ""
    for name, pattern in _UAT_TYPE:
        if pattern.search(text):
            uat_type = name
            break
    rest = _UAT_PREFIX.sub("", text)
    rest = _TYPE_WORD.sub("", rest)
    # Drop a trailing qualifier: "Ciugud, judeţul Alba" -> "Ciugud".
    rest = re.split(r"\s*[,(]\s*|\s+jude[tțţ]ul\s+", rest)[0]
    return uat_type, rest.strip(" .-–—")


def _population_bands(key: str, uat_type: str, county: str) -> frozenset[str] | None:
    """Band set from the census table, or None when the table cannot decide.

    Rows are filtered by unit type (a commune never borrows a town's population),
    then by county when one is known. If the survivors still span several bands
    the name is ambiguous (Ștefănești without a county) and the caller falls back
    to the type rules rather than guess.
    """
    rows = load_populations().get(key, ())
    if not rows:
        return None
    if uat_type == "comuna":
        tip_groups = [("comuna",)]
    elif uat_type:  # municipiu, oraș, and sector/județ (only towns share those names)
        tip_groups = [("oras",)]
    elif county:
        tip_groups = [("oras", "comuna")]
    else:
        # Type unknown and no county: a town is the likelier reading of a bare
        # name, a commune only if no town has it.
        tip_groups = [("oras",), ("comuna",)]

    for tips in tip_groups:
        cands = [r for r in rows if r.tip in tips]
        if not cands:
            continue
        if county:
            in_county = [r for r in cands if r.county == norm(county)]
            cands = in_county or cands
        bands = frozenset(band_for_population(r.population) for r in cands)
        return bands if len(bands) == 1 else None
    return None


def bands_for(employer: str, county: str | None = None) -> tuple[frozenset[str], str]:
    """Plausible population bands for an employer, plus how they were decided.

    `county` is the posting's own county, used only when the employer string does
    not name one. Returns `(bands, basis)`. An empty set means "no idea, do not
    narrow". A set with more than one member is a genuine ambiguity that the
    estimate must carry as a wider range, not resolve by guessing.
    """
    uat_type, name = parse_employer(employer)
    key = norm(name)

    # data/uat-populatie.csv (2021 census): towns and communes, matched on unit
    # type and, when known, county. A comună that shares a town's name (Slatina
    # in Suceava, Satu Mare in Harghita) must not borrow that town's population.
    if key:
        known = _population_bands(key, uat_type, employer_county(employer) or canonical_county(county))
        if known:
            return known, "populație"

    if uat_type == "sector" or key in OVER_200K:
        return frozenset({BAND_OVER_200K}), "listă orașe peste 200.000"

    if uat_type == "comuna":
        # Reached only when the table has no row for this name or the name is
        # ambiguous across bands. Communes are rural by definition and ~2,800 of
        # ~2,860 are under 10.000, so this is the safe reading of a bare name.
        return frozenset({BAND_UNDER_10K}), "tip UAT: comună"

    if uat_type == "municipiu":
        # A municipiu is at least a town, but "at least" is all the status says:
        # the smallest are ~10.000 and the largest below the 200k list are ~180.000.
        return frozenset({BAND_50_200K, BAND_10_50K}), "tip UAT: municipiu"

    if uat_type == "oras":
        # Orașe straddle the 10.000 line in both directions.
        return frozenset({BAND_10_50K, BAND_UNDER_10K}), "tip UAT: oraș"

    return frozenset(), "nedeterminat"

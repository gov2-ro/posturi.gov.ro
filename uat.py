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

A real population table would collapse most of that uncertainty. Drop a CSV at
`data/uat-populatie.csv` with `nume,judet,populatie` and `load_populations()`
picks it up; everything below is the fallback for when it is absent.
"""
from __future__ import annotations

import csv
import re
import unicodedata
from functools import lru_cache
from pathlib import Path

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


@lru_cache(maxsize=1)
def load_populations() -> dict[str, int]:
    """Optional `nume,judet,populatie` table. Absent by default."""
    if not POPULATION_CSV.exists():
        return {}
    with POPULATION_CSV.open(encoding="utf-8") as fh:
        return {norm(r["nume"]): int(r["populatie"])
                for r in csv.DictReader(fh) if r.get("populatie", "").isdigit()}


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


def bands_for(employer: str) -> tuple[frozenset[str], str]:
    """Plausible population bands for an employer, plus how they were decided.

    Returns `(bands, basis)`. An empty set means "no idea, do not narrow".
    A set with more than one member is a genuine ambiguity that the estimate
    must carry as a wider range, not resolve by guessing.
    """
    uat_type, name = parse_employer(employer)
    key = norm(name)

    populations = load_populations()
    if key and key in populations:
        return frozenset({band_for_population(populations[key])}), "populație"

    if uat_type == "sector" or key in OVER_200K:
        return frozenset({BAND_OVER_200K}), "listă orașe peste 200.000"

    if uat_type == "comuna":
        # Romania's communes are rural by definition; the handful near the
        # threshold are a rounding error next to the ~2,800 that are not.
        return frozenset({BAND_UNDER_10K}), "tip UAT: comună"

    if uat_type == "municipiu":
        # A municipiu is at least a town, but "at least" is all the status says:
        # the smallest are ~10.000 and the largest below the 200k list are ~180.000.
        return frozenset({BAND_50_200K, BAND_10_50K}), "tip UAT: municipiu"

    if uat_type == "oras":
        # Orașe straddle the 10.000 line in both directions.
        return frozenset({BAND_10_50K, BAND_UNDER_10K}), "tip UAT: oraș"

    return frozenset(), "nedeterminat"

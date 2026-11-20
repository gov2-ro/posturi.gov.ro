"""Declared pay (ADR 0008 rule 9): match a posting to a group of the asset declarations.

`data/salarii/declaratii-grupuri.csv` (built by `ops/build-declaratii-grupuri.py`) holds one row
per group: an employer (`institutie_id` = NAME|COUNTY) and a job title (`functie`), with at
least 5 declarants. A posting matches a group when

  1. its employer name and county map to exactly one `institutie_id` (exact match after
     normalisation; no fuzzy match), and
  2. its title maps to exactly one `functie` of that employer (the base word and the grade;
     nothing else).

More than one group, or none: the posting gets no declared figure.
"""
from __future__ import annotations

import csv
import re
from pathlib import Path

import ocupatii
from salary_grid import norm
from salary_net import gross_for_net

DATA_DIR = Path(__file__).parent.resolve() / "data" / "salarii"
GROUPS_CSV = DATA_DIR / "declaratii-grupuri.csv"

DISCLAIMER = ("Estimare după declarațiile de avere din 2023, aduse la nivelul din 2026 cu creșterea "
              "salariilor din administrația publică (INS). Nu este un calcul salarial oficial.")
VARIANT = "declaratii-2023"

# "PRIMARIA COMUNEI X" is "COMUNA X" (ops/integritate-select.py: PRIMARIA, COMUNEI ... are generic words).
_PRIMARIA = re.compile(r"^primaria (comunei|municipiului|orasului) ")
_PRIMARIA_TO = {"comunei": "comuna", "municipiului": "municipiul", "orasului": "orasul"}


def employer_key(name: str) -> str:
    """The normalised employer name: no address or county after a comma, PRIMARIA X -> X."""
    head = (name or "").split(",")[0]
    key = norm(head)
    m = _PRIMARIA.match(key)
    if m:
        key = _PRIMARIA_TO[m.group(1)] + " " + key[m.end():]
    return key


def title_key(title: str) -> str:
    """The base word and the grade of a posting title: 'Consilier, clasa I, grad profesional superior' -> 'consilier superior'."""
    parsed = ocupatii.parse_title(title)
    base = norm(parsed.base)
    grade = norm(parsed.grade_token)
    return f"{base} {grade}".strip()


def round50(x: float) -> int:
    return int(x / 50 + 0.5) * 50


class Groups:
    def __init__(self, rows: list[dict]):
        self.rows = rows
        #: (employer_key, county_key) -> institutie_ids
        self.by_employer: dict[tuple[str, str], set[str]] = {}
        #: institutie_id -> title key -> groups
        self.by_inst: dict[str, dict[str, list[dict]]] = {}
        for r in rows:
            name, _, county = r["institutie_id"].rpartition("|")
            self.by_employer.setdefault((employer_key(name), norm(county)), set()).add(r["institutie_id"])
            self.by_inst.setdefault(r["institutie_id"], {}).setdefault(norm(r["functie"]), []).append(r)

    @classmethod
    def load(cls, path: Path = GROUPS_CSV) -> "Groups":
        if not path.exists():
            return cls([])
        with path.open(encoding="utf-8", newline="") as f:
            return cls(list(csv.DictReader(f)))

    def employer(self, employer_name: str, county_name: str) -> str | None:
        """The one `institutie_id`, or None (no match, or more than one)."""
        ids = self.by_employer.get((employer_key(employer_name), norm(county_name)), set())
        return next(iter(ids)) if len(ids) == 1 else None

    def match(self, employer_name: str, county_name: str, title: str) -> dict | None:
        """The one group of the posting, or None. See the module docstring."""
        inst = self.employer(employer_name, county_name)
        if inst is None:
            return None
        groups = self.by_inst[inst].get(title_key(title), [])
        return groups[0] if len(groups) == 1 else None


def estimate_dict(g: dict) -> dict:
    """The top level and the `declaratii` key of `salary_estimate` for a matched group (rule 9).

    `lei_min` and `lei_max` stay gross (as for the other baselines): the gross that gives
    p25 x factor and p75 x factor net. The net values under `declaratii` are rounded to 50 lei.
    `mediana_net_2023` (the group median before the factor) is an addition to the brief's key list:
    step 1 of the explainer shows it.
    """
    factor = float(g["factor"])
    p25, med, p75 = (float(g[k]) * factor for k in ("p25_net_2023", "mediana_net_2023", "p75_net_2023"))
    lei_min, lei_max = gross_for_net(p25), gross_for_net(p75)
    return {
        "lei_min": lei_min, "lei_max": lei_max, "coef_min": None, "coef_max": None, "gradatie": 0,
        "varianta": VARIANT, "valoare_referinta": None, "incredere": "interval",
        "motiv": f"Declarațiile de avere din 2023: {g['n']} angajați cu funcția {g['functie']} la {g['institutie']}",
        "randuri": [], "avertismente": [], "disclaimer": DISCLAIMER, "baza": "declaratii",
        "declaratii": {
            "n": int(g["n"]), "an_venit": int(g["an_venit"]), "factor": factor,
            "factor_sursa": g["factor_sursa"], "mediana_net": round50(med), "mediana_net_2023": round50(float(g["mediana_net_2023"])),
            "p25_net": round50(p25),
            "p75_net": round50(p75), "institutie_id": g["institutie_id"], "functie": g["functie"],
            "familie": g["familie"], "eroare_tipica": float(g["eroare_tipica"]),
        },
    }

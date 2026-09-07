"""Canonical Romanian county (județ) normalisation.

The scraper stores whatever the source badge says, and posturi.gov.ro has used
two shapes over its lifetime:

    pre-redesign   "Timiş"                  — bare county, cedilla diacritics
    post-redesign  "TIMIŞOARA, Timiș"       — "LOCALITY, County", comma-below

Both go straight into `Judet.name`, which is unique, so the same county lands in
the database several times over: Cluj alone appeared as `Cluj`,
`CLUJ-NAPOCA, Cluj`, `DEJ, Cluj`, `TURDA, Cluj`… 261 distinct raw values for a
country with 42 counties. Every one becomes its own facet row in the browse
sidebar, so filtering by county silently misses most of the matching postings.

This module is the single place that turns a raw value into
`(county, locality)`. Import-time normalisation keeps new data clean;
`manage.py normalize_judete` repairs what is already stored.

Diacritics: Romanian ș/ț are U+0219/U+021B (comma below). Windows-era text
routinely uses the Turkish ş/ţ (U+015F/U+0163, cedilla) instead. Canonical
output always uses the comma-below forms.
"""

from __future__ import annotations

import re
import unicodedata
from typing import NamedTuple

#: The 41 counties plus București. Canonical spelling — comma-below diacritics.
COUNTIES: tuple[str, ...] = (
    "Alba", "Arad", "Argeș", "Bacău", "Bihor", "Bistrița-Năsăud", "Botoșani",
    "Brăila", "Brașov", "București", "Buzău", "Călărași", "Caraș-Severin",
    "Cluj", "Constanța", "Covasna", "Dâmbovița", "Dolj", "Galați", "Giurgiu",
    "Gorj", "Harghita", "Hunedoara", "Ialomița", "Iași", "Ilfov", "Maramureș",
    "Mehedinți", "Mureș", "Neamț", "Olt", "Prahova", "Sălaj", "Satu Mare",
    "Sibiu", "Suceava", "Teleorman", "Timiș", "Tulcea", "Vâlcea", "Vaslui",
    "Vrancea",
)

#: Spellings that are not just a diacritic or case variant of the canonical name.
ALIASES: dict[str, str] = {
    "bucuresti sector 1": "București",
    "bucuresti sector 2": "București",
    "bucuresti sector 3": "București",
    "bucuresti sector 4": "București",
    "bucuresti sector 5": "București",
    "bucuresti sector 6": "București",
    "municipiul bucuresti": "București",
    "bucarest": "București",
    "salaj salaj": "Sălaj",
    "bistrita nasaud": "Bistrița-Năsăud",
    "caras severin": "Caraș-Severin",
    "satu mare": "Satu Mare",
}

#: Cedilla → comma-below, so "Timiş" and "Timiș" are the same string downstream.
_DIACRITIC_REPAIR = str.maketrans({
    "ş": "ș", "Ş": "Ș", "ţ": "ț", "Ţ": "Ț",
})


def repair_diacritics(text: str) -> str:
    """Replace Turkish cedilla forms with the Romanian comma-below ones."""
    return text.translate(_DIACRITIC_REPAIR)


def fold(text: str) -> str:
    """Case-, diacritic- and punctuation-insensitive comparison key.

    "Bistriţa-Năsăud", "BISTRITA NASAUD" and "Bistrița-Năsăud" all fold to
    "bistrita nasaud".
    """
    text = repair_diacritics(text)
    decomposed = unicodedata.normalize("NFKD", text)
    stripped = "".join(c for c in decomposed if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", stripped.lower()).strip()


_COUNTY_BY_KEY: dict[str, str] = {fold(c): c for c in COUNTIES}
_COUNTY_BY_KEY.update(ALIASES)

#: Lowercase words that stay lowercase inside a Romanian place name.
_LOWER_PARTICLES = {"de", "din", "la", "pe", "sub", "si", "și"}


def titlecase_locality(name: str) -> str:
    """Title-case a SHOUTED locality, keeping hyphens and joining particles.

    "CLUJ-NAPOCA" → "Cluj-Napoca", "BAIA DE ARAMĂ" → "Baia de Aramă".
    A name that is already mixed case is left alone — the source sometimes
    supplies "Amara", and re-casing it can only lose information.
    """
    name = repair_diacritics(name.strip())
    if not name or not name.isupper():
        return name

    def cap(word: str, first: bool) -> str:
        if not word:
            return word
        if not first and word.lower() in _LOWER_PARTICLES:
            return word.lower()
        return word[0].upper() + word[1:].lower()

    out: list[str] = []
    for i, space_part in enumerate(name.split(" ")):
        pieces = space_part.split("-")
        out.append("-".join(cap(p, first=(i == 0 and j == 0)) for j, p in enumerate(pieces)))
    return " ".join(out)


class JudetParse(NamedTuple):
    """Result of normalising one raw județ string.

    `judet` is None exactly when the county could not be identified; callers
    should surface those rather than silently dropping them.
    """

    judet: str | None
    locality: str | None
    raw: str

    @property
    def resolved(self) -> bool:
        return self.judet is not None


def normalize_judet(raw: str | None) -> JudetParse:
    """Split a raw badge value into a canonical county and an optional locality.

    >>> normalize_judet("CLUJ-NAPOCA, Cluj")
    JudetParse(judet='Cluj', locality='Cluj-Napoca', raw='CLUJ-NAPOCA, Cluj')
    >>> normalize_judet("Timiş")
    JudetParse(judet='Timiș', locality=None, raw='Timiş')
    >>> normalize_judet("Freedonia").resolved
    False
    """
    raw = (raw or "").strip()
    if not raw:
        return JudetParse(None, None, raw)

    locality: str | None = None
    candidate = raw

    # "LOCALITY, County" — split on the last comma; the county is always last.
    if "," in raw:
        head, _, tail = raw.rpartition(",")
        if _COUNTY_BY_KEY.get(fold(tail)):
            locality = titlecase_locality(head) or None
            candidate = tail

    county = _COUNTY_BY_KEY.get(fold(candidate))

    # Reversed or trailing-junk shapes ("Cluj, ceva"): fall back to the head.
    if county is None and "," in raw:
        county = _COUNTY_BY_KEY.get(fold(raw.rpartition(",")[0]))

    if county is None:
        return JudetParse(None, None, raw)

    # Note: a locality equal to its county is kept ("ARAD, Arad" → Arad city in
    # Arad county). The county capital usually shares the county's name, and
    # dropping it would throw away exactly the city precision we want.
    return JudetParse(county, locality, raw)

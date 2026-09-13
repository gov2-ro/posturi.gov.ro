"""The 2026 draft salary grid: registry, retrieval and the gross-pay calculator.

One module owns this, the way `webapp/apps/jobs/judete.py` owns county
normalisation -- the LLM prompt, the estimator and the webapp labels all read
from here, so there is a single source of truth for the vocabulary.

Build the registry first with `build-salary-grid.py`; this module only reads
`data/salarii/`.

The division of labour matters. **The LLM emits a selector, never a number.**
The law is an unadopted draft with more than one public variant, so re-costing
under a new variant has to be a script that runs in a second, not a re-run of
the extraction over 9,600 postings.

    salariu_baza = ceil(coeficient * valoare_referinta * multiplicator_gradatie)

Everything returned is GROSS and is an estimate. See `parametri.json`.
"""
from __future__ import annotations

import csv
import json
import math
import re
import unicodedata
from dataclasses import dataclass, field
from difflib import SequenceMatcher
from functools import lru_cache
from pathlib import Path

DATA_DIR = Path(__file__).parent.resolve() / "data" / "salarii"
DEFAULT_VERSION = "2026-07-17"

DISCLAIMER = (
    "Estimare bazată pe un proiect de lege neadoptat; "
    "nu reprezintă un calcul salarial oficial."
)

#: Population bands, widest first. Only Anexa VIII local rows are banded.
POPULATION_BANDS = ["peste 200.000", "50.000-200.000", "10.000-50.000", "sub 10.000"]

#: The extraction schema uses ASCII enum values (`personal_contractual`), the
#: workbook uses Romanian prose (`personal contractual`). One map, here, so the
#: translation cannot drift between the prompt and the calculator.
SELECTOR_REGIM = {
    "functionar_public": "funcționar public",
    "personal_contractual": "personal contractual",
    "militar": "",        # Anexa VI does not split by regim
    "demnitate": "",      # Anexa IX either
}
SELECTOR_TIP_POST = {"executie": "execuție", "conducere": "conducere"}


def selector_to_grid(selector: dict) -> dict:
    """Translate an `OccupationMapping.grid_selector` into grid vocabulary."""
    out = dict(selector or {})
    if out.get("regim"):
        out["regim"] = SELECTOR_REGIM.get(out["regim"], out["regim"])
    if out.get("tip_post"):
        out["tip_post"] = SELECTOR_TIP_POST.get(out["tip_post"], out["tip_post"])
    if out.get("functie_grila") and not out.get("functie"):
        out["functie"] = out["functie_grila"]
    return out


#: Study-level notations. The grid writes `S / SSD / PL / M / M;G / G`; v3
#: extraction writes `licenta / postliceala / liceala / ...`. This is the bridge
#: between them -- see `ocupatii.py`, which owns the wider reconciliation.
STUDY_LEVEL_TO_GRID = {
    "doctorat": ["S"],
    "master": ["S"],
    "licenta": ["S", "SSD"],
    "postliceala": ["PL", "SSD"],
    "liceala": ["M"],
    "profesionala": ["G", "M;G"],
    "generala": ["G", "M;G"],
}


def norm(s) -> str:
    """Lowercase, strip diacritics and punctuation. The join key everywhere."""
    s = unicodedata.normalize("NFD", str(s or ""))
    s = "".join(c for c in s if unicodedata.category(c) != "Mn").lower()
    s = s.replace("ş", "s").replace("ţ", "t").replace("ș", "s").replace("ț", "t")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9 ]", " ", s)).strip()


@dataclass(frozen=True)
class GridRow:
    version_id: str
    anexa: str
    sheet: str
    source_row: int
    cod: str
    functie: str
    sinonime: tuple[str, ...]
    regim: str
    tip_post: str
    nivel_administrativ: str
    banda_populatie: str
    grad_treapta: str
    vechime: str
    studii: str
    sectiune: str
    coef_min: float
    coef_max: float
    needs_review: bool

    @property
    def ref(self) -> str:
        return f"{self.sheet}!{self.source_row}"


@dataclass
class Gradation:
    nivel: int
    vechime_min_ani: float
    vechime_max_exclusiv_ani: float | None
    majorare_pct: float
    multiplicator_cumulat: float


@dataclass
class Variant:
    id: str
    eticheta: str
    valoare_referinta_lei: float
    status_juridic: str
    are_grila: bool


@dataclass
class SalaryEstimate:
    """A gross monthly range in lei, plus everything needed to reproduce it."""
    lei_min: int | None
    lei_max: int | None
    coef_min: float | None
    coef_max: float | None
    gradatie: int
    varianta: str
    valoare_referinta: float
    incredere: str          # "exact" | "interval" | "necunoscut"
    motiv: str = ""
    randuri: list[str] = field(default_factory=list)
    avertismente: list[str] = field(default_factory=list)
    disclaimer: str = DISCLAIMER

    def as_dict(self) -> dict:
        return {
            "lei_min": self.lei_min, "lei_max": self.lei_max,
            "coef_min": self.coef_min, "coef_max": self.coef_max,
            "gradatie": self.gradatie, "varianta": self.varianta,
            "valoare_referinta": self.valoare_referinta,
            "incredere": self.incredere, "motiv": self.motiv,
            "randuri": self.randuri, "avertismente": self.avertismente,
            "disclaimer": self.disclaimer,
        }


class Grid:
    """The coefficient registry for one dataset version."""

    def __init__(self, version: str, rows: list[GridRow], params: dict):
        self.version = version
        self.rows = rows
        self.params = params
        self.by_cod: dict[str, list[GridRow]] = {}
        self.by_name: dict[str, list[GridRow]] = {}
        #: normalised synonym -> the spelling the workbook uses, for display.
        self.display_name: dict[str, str] = {}
        for r in rows:
            if r.cod:
                self.by_cod.setdefault(r.cod, []).append(r)
            for name in r.sinonime:
                key = norm(name)
                self.by_name.setdefault(key, []).append(r)
                self.display_name.setdefault(key, name)
        self._name_tokens = {n: set(n.split()) for n in self.by_name}

    # -- variants and gradations ------------------------------------------
    @property
    def variant(self) -> Variant:
        v = self.params["variante"][self.version]
        return Variant(self.version, v["eticheta"], float(v["valoare_referinta_lei"]),
                       v["status_juridic"], bool(v["are_grila"]))

    @property
    def gradations(self) -> list[Gradation]:
        return [Gradation(**{k: g[k] for k in
                             ("nivel", "vechime_min_ani", "vechime_max_exclusiv_ani",
                              "majorare_pct", "multiplicator_cumulat")})
                for g in self.params["gradatii"]["niveluri"]]

    def gradation_for_years(self, years: float | None) -> int:
        if years is None:
            return 0
        for g in self.gradations:
            hi = g.vechime_max_exclusiv_ani
            if years >= g.vechime_min_ani and (hi is None or years < hi):
                return g.nivel
        return 0

    def multiplier(self, gradation: int) -> float:
        for g in self.gradations:
            if g.nivel == gradation:
                return g.multiplicator_cumulat
        raise ValueError(f"unknown gradation {gradation}")

    # -- retrieval ---------------------------------------------------------
    def candidates(self, title: str, *, anexa: str = "", regim: str = "",
                   nivel_administrativ: str = "", k: int = 15,
                   ) -> list[tuple[float, GridRow, str]]:
        """Shortlist real grid functions for a free-text job title.

        This is what keeps the LLM honest: it picks from rows that exist rather
        than inventing a coefficient. Scoring is string similarity plus a small
        bonus when the posting's context agrees with the row's, so `Consilier`
        at a primărie ranks the local rows above the central ones.

        Returns `(score, row, matched_name)`. The third element matters because
        a grid row can carry 27 synonyms — Anexa II pays two dozen health
        professions off one line — and the caller should show the name that
        actually matched, not 1,500 characters of alternatives.

        Results are deduplicated by function code. The same `cod` repeats across
        all four population-band sheets with different coefficients, but the
        band is resolved later from the employer, not chosen by the model, so
        showing it four times only crowds out genuinely different functions.
        """
        q = norm(title)
        if not q:
            return []
        qt = set(q.split())
        scored: list[tuple[float, GridRow, str]] = []
        seen_rows: set[tuple[str, int]] = set()
        for name, rows in self.by_name.items():
            nt = self._name_tokens[name]
            overlap = len(qt & nt) / max(len(qt | nt), 1)
            ratio = SequenceMatcher(None, q, name).ratio()
            base = 0.6 * ratio + 0.4 * overlap
            if base < 0.35:
                continue
            for r in rows:
                bonus = 0.0
                if anexa and r.anexa == anexa:
                    bonus += 0.08
                if regim and r.regim == regim:
                    bonus += 0.04
                if nivel_administrativ and r.nivel_administrativ == nivel_administrativ:
                    bonus += 0.04
                key = (r.sheet, r.source_row)
                if key in seen_rows:
                    continue
                seen_rows.add(key)
                scored.append((round(base + bonus, 4), r, self.display_name[name]))
        scored.sort(key=lambda t: (-t[0], t[1].sheet, t[1].source_row))

        out: list[tuple[float, GridRow, str]] = []
        seen_cod: set[str] = set()
        for score, row, name in scored:
            # Rows without a code cannot be deduplicated on one, so keep them all.
            dedupe_key = (row.cod, row.grad_treapta, row.studii) if row.cod else None
            if dedupe_key is not None:
                if dedupe_key in seen_cod:
                    continue
                seen_cod.add(dedupe_key)
            out.append((score, row, name))
            if len(out) >= k:
                break
        return out

    # -- selection ---------------------------------------------------------
    def select(self, selector: dict) -> list[GridRow]:
        """Resolve a selector to the grid rows it names.

        `cod` alone is enough when present -- it is the stable key across
        variants. Otherwise match on the function name and narrow by whatever
        context the caller could resolve; unknown context does not filter, it
        widens the resulting range.
        """
        cod = (selector.get("cod") or "").strip()
        name = norm(selector.get("functie") or "")

        # Name first, code second. A code identifies one row, and what counts as
        # "the same function at another grade" is not expressible as a code
        # prefix: Anexa VIII numbers grades in the last segment (…07.1, …07.3)
        # but Anexa II gives each grade its own base code entirely
        # (21.00201026 principal, 21.00201028 debutant). The function *name* is
        # the one key that means the same thing in both.
        rows = list(self.by_name.get(name, [])) if name else []
        if not rows and cod:
            rows = list(self.by_cod.get(cod, []))
            # Reached only when the name is missing: widen along the grade axis
            # within the same sheet, so the posting's grade can still choose.
            if rows and selector.get("grad_treapta"):
                sheets = {r.sheet for r in rows}
                widened = {(r.sheet, r.source_row): r for r in rows}
                for seed in list(rows):
                    for syn in seed.sinonime:
                        for r in self.by_name.get(norm(syn), []):
                            if r.sheet in sheets:
                                widened[(r.sheet, r.source_row)] = r
                rows = list(widened.values())
        if not rows:
            return []

        def narrow(candidates, key, value, *, aliases=None):
            """Keep rows that match; fall back to rows that simply do not say.

            Order matters. A row with a blank `banda_populatie` is not a match
            for "sub 10.000" -- it is a row from a sheet that is not banded at
            all. Letting it through alongside the real match silently widened
            every local estimate to the central coefficient.
            """
            if not value:
                return candidates
            if aliases is not None:
                wanted = set(aliases)
            elif isinstance(value, (set, frozenset, list, tuple)):
                # A set of plausible values, not a guess: `uat.bands_for()`
                # returns two bands when an `oraș` could be either side of
                # 10.000 locuitori, and the estimate widens across both.
                wanted = set(value)
            else:
                wanted = {value}
            exact = [r for r in candidates if getattr(r, key) in wanted]
            if exact:
                return exact
            unspecified = [r for r in candidates if not getattr(r, key)]
            return unspecified or candidates

        rows = narrow(rows, "anexa", selector.get("anexa"))
        rows = narrow(rows, "regim", selector.get("regim"))
        rows = narrow(rows, "tip_post", selector.get("tip_post"))
        rows = narrow(rows, "nivel_administrativ", selector.get("nivel_administrativ"))
        rows = narrow(rows, "banda_populatie", selector.get("banda_populatie"))

        studii = selector.get("studii")
        if studii:
            aliases = STUDY_LEVEL_TO_GRID.get(studii, [studii])
            rows = narrow(rows, "studii", studii, aliases=aliases)

        grad = norm(selector.get("grad_treapta") or "")
        if grad:
            exact = [r for r in rows if norm(r.grad_treapta) == grad]
            rows = exact or rows
        return rows

    # -- the calculator ----------------------------------------------------
    def estimate(self, selector: dict, *, gradation: int = 0) -> SalaryEstimate:
        variant = self.variant
        rows = self.select(selector)
        if not rows:
            return SalaryEstimate(
                None, None, None, None, gradation, self.version,
                variant.valoare_referinta_lei, "necunoscut",
                motiv="Nicio poziție din grilă nu corespunde selectorului.",
            )

        coef_min = min(r.coef_min for r in rows)
        coef_max = max(r.coef_max for r in rows)
        mult = self.multiplier(gradation)
        vr = variant.valoare_referinta_lei

        warnings: list[str] = []
        # An unresolved population band is the single biggest source of spread:
        # `Consilier gradul II` is 1.85 over 200.000 locuitori and 1.35 under
        # 10.000 -- a 37% difference. Say so rather than picking one.
        if (not selector.get("banda_populatie")
                and any(r.banda_populatie for r in rows)):
            warnings.append(
                "Banda de populație a UAT-ului nu a putut fi determinată; "
                "intervalul acoperă toate mărimile de unitate administrativ-teritorială."
            )
        if not selector.get("studii") and len({r.studii for r in rows}) > 1:
            warnings.append("Nivelul de studii nu a putut fi determinat; intervalul acoperă mai multe niveluri.")
        if any(r.needs_review for r in rows):
            warnings.append("Cel puțin un rând din grilă este marcat pentru verificare manuală.")

        if len(rows) == 1 and abs(coef_max - coef_min) < 1e-9:
            confidence = "exact"
        elif len(rows) <= 4:
            confidence = "interval"
        else:
            confidence = "interval"
            warnings.append(f"Selectorul corespunde la {len(rows)} poziții din grilă.")

        return SalaryEstimate(
            lei_min=math.ceil(coef_min * vr * mult),
            lei_max=math.ceil(coef_max * vr * mult),
            coef_min=round(coef_min, 6),
            coef_max=round(coef_max, 6),
            gradatie=gradation,
            varianta=self.version,
            valoare_referinta=vr,
            incredere=confidence,
            motiv=f"{len(rows)} rând(uri) din Anexa {rows[0].anexa}",
            randuri=[r.ref for r in rows[:12]],
            avertismente=warnings,
        )


def _row_from_csv(d: dict) -> GridRow:
    return GridRow(
        version_id=d["version_id"], anexa=d["anexa"], sheet=d["sheet"],
        source_row=int(d["source_row"]), cod=d["cod"],
        functie=d["functie"],
        sinonime=tuple(x for x in d["sinonime"].split("|") if x),
        regim=d["regim"], tip_post=d["tip_post"],
        nivel_administrativ=d["nivel_administrativ"],
        banda_populatie=d["banda_populatie"], grad_treapta=d["grad_treapta"],
        vechime=d["vechime"], studii=d["studii"], sectiune=d["sectiune"],
        coef_min=float(d["coef_min"]), coef_max=float(d["coef_max"]),
        needs_review=d["needs_review"] == "1",
    )


@lru_cache(maxsize=4)
def load_grid(version: str = DEFAULT_VERSION, data_dir: str | None = None) -> Grid:
    base = Path(data_dir) if data_dir else DATA_DIR
    grid_csv = base / f"grila-{version}.csv"
    if not grid_csv.exists():
        raise FileNotFoundError(
            f"{grid_csv} missing — run `python build-salary-grid.py --version-id {version}` first"
        )
    params = json.loads((base / "parametri.json").read_text(encoding="utf-8"))
    if version not in params["variante"]:
        raise ValueError(f"unknown variant {version!r}; known: {sorted(params['variante'])}")
    with grid_csv.open(encoding="utf-8") as fh:
        rows = [_row_from_csv(d) for d in csv.DictReader(fh)]
    return Grid(version, rows, params)

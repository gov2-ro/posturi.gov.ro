"""The pay range of one posting: gradations and the food allowance (ADR 0008, rule 11).

One pure function, `pay_range()`. The export calls it for each posting and writes the
`pay_*` columns. The values (food amount, net cap, excluded employers) are in
`data/salarii/drepturi-2026.json`; the gradation table is in `data/salarii/parametri.json`
and is read through `salary_grid`, so there is one copy of each.

Scope (rule 11):

- `stated`: the pay that the posting states. The figure is the value of gradation 0.
- `law`: an estimate from the grid of the law in force. `estimate_source()` decides.
- Anything else (declared pay, project hire, Anexa VI, a management row, the draft grid) has
  no range: `pay_range()` returns None and the old figure stays.

Each end of the range gets the food allowance when (1) the net of that end, without the
allowance, is at most the cap and (2) the employer is not on the exclusion list. All figures
are gross lei per month.
"""
from __future__ import annotations

import json
import math
import re
from functools import lru_cache
from pathlib import Path
from typing import Callable

import salary_grid
import salary_net

RIGHTS_PATH = salary_grid.DATA_DIR / "drepturi-2026.json"

TOP_GRADATION = 5


@lru_cache(maxsize=2)
def load_rights(path: str | None = None) -> dict:
    """The data file, with each exclusion pattern compiled."""
    data = json.loads(Path(path or RIGHTS_PATH).read_text(encoding="utf-8"))
    rules = data["angajatori_exclusi_hrana"]["reguli"]
    data["_excluded"] = [(r["id"], re.compile("|".join(r["modele"]))) for r in rules]
    return data


def excluded_employer(employer: str | None, rights: dict | None = None) -> str | None:
    """The id of the rule that excludes this employer from the food allowance, or None."""
    rights = rights or load_rights()
    name = salary_grid.norm(employer or "")
    for rule_id, rx in rights["_excluded"]:
        if rx.search(name):
            return rule_id
    return None


def estimate_source(est: dict | None) -> str | None:
    """`"law"` when rule 11 applies to this estimate (the JSON of `salary_estimate`), else None.

    Out of scope: a baseline other than the law in force (declared pay, the draft); an Anexa VI
    row (rule 10); a management row, which already holds the top gradation (Art. 19 alin. (2)).
    """
    if not isinstance(est, dict) or est.get("baza") != "lege_in_vigoare" or not est.get("lei_min"):
        return None
    sel = est.get("selector") or {}
    if sel.get("anexa") == "VI" or est.get("lei_functie_min") is not None:
        return None
    if sel.get("tip_post") == "conducere":
        return None
    return "law"


def is_local_range(est: dict | None) -> bool:
    """True for a local-administration range (rule 3): the upper end is the cap of Art. 11 alin. (4)."""
    sel = (est or {}).get("selector") or {}
    return sel.get("nivel_administrativ") == "local" and sel.get("anexa") == "VIII"


def law_figure_at(est: dict, grid: salary_grid.Grid) -> Callable[[int], tuple[int, int]] | None:
    """`gradation -> (lei_min, lei_max)` for a law estimate, computed from its grid rows, or None.

    `Grid.row_pay()` is the one pay formula. It lifts a row to the minimum wage and rounds once,
    so it differs from "figure x multiplier": by up to 2 lei, and at the minimum wage (34 % of
    the law figures) by much more. The refs of `est["randuri"]` name the rows. The function
    returns None, and the caller scales the figure instead, when the refs are cut at 12 or do
    not reproduce the stored figure at gradation 0.
    """
    refs = est.get("randuri") or []
    if not refs or len(refs) >= 12:
        return None
    by_ref = _rows_by_ref(grid)
    rows = [by_ref.get(r) for r in refs]
    if None in rows:
        return None

    def at(gradation: int) -> tuple[int, int]:
        pays = [grid.row_pay(r, gradation) for r in rows]
        return min(p[0] for p in pays), max(p[1] for p in pays)

    lo, hi = at(0)
    # A local range keeps the cap of Art. 11 alin. (4) as its upper end: the rows give only its lower part.
    if lo != est.get("lei_min") or (hi != est.get("lei_max") and not (is_local_range(est) and est["lei_max"] >= hi)):
        return None
    return at


@lru_cache(maxsize=2)
def _rows_by_ref_cached(grid_id: int, grid: salary_grid.Grid) -> dict:
    return {r.ref: r for r in grid.rows}


def _rows_by_ref(grid: salary_grid.Grid) -> dict:
    return _rows_by_ref_cached(id(grid), grid)


def _scaled(lei: float, mult: float) -> int:
    """`ceil(lei x mult)`, rounded as `Grid.row_pay()` does."""
    return int(math.ceil(round(lei * mult, 6)))


def _end(base: int, gradation: int | None, employer_excluded: bool, rights: dict) -> dict:
    food_rule = rights["indemnizatie_hrana"]
    food = 0
    if not employer_excluded and salary_net.net_from_gross(base) <= food_rule["plafon_net_lei"]:
        food = int(food_rule["lei"])
    return {"gradation": gradation, "base": base, "food": food, "total": base + food}


def pay_range(source: str | None, low: float | None, high: float | None = None, *,
              years: float | None = None, employer: str | None = None, local: bool = False,
              figure_at: Callable[[int], tuple[int, int]] | None = None,
              grid: salary_grid.Grid | None = None, rights: dict | None = None) -> dict | None:
    """The range of one posting, or None when rule 11 does not apply.

    - `source`: `"stated"` or `"law"`; any other value gives None.
    - `low`, `high`: the gross figure at gradation 0 (`high` None = `low`).
    - `years`: the experience that the posting requires; None = unknown = gradation 0.
    - `figure_at`: `gradation -> (lei_min, lei_max)` from the grid rows (`law_figure_at()`). Without it
      the figure is scaled: `ceil(figure x multiplier)`.
    - `local`: a local range. The low end takes the floor gradation; the high end is the cap
      of Art. 11 alin. (4), so it gets no gradation, and food only below the net cap.

    Returns `{"low": end, "high": end}`; each end is
    `{"gradation", "base", "food", "total"}`. `gradation` is None for the cap of a local range.
    """
    if source not in ("stated", "law") or not low or low <= 0:
        return None
    rights = rights or load_rights()
    grid = grid or salary_grid.load_grid(salary_grid.LAW_VERSION)
    high = high if high and high >= low else low
    floor = grid.gradation_for_years(years)
    excluded = excluded_employer(employer, rights) is not None
    if source == "stated" and high > low:
        return _stated_span(low, high, floor, excluded, rights, grid)
    if figure_at:
        low_base = figure_at(floor)[0]
        high_base = int(round(high)) if local else figure_at(TOP_GRADATION)[1]
    else:
        low_base = _scaled(low, grid.multiplier(floor))
        high_base = int(round(high)) if local else _scaled(high, grid.multiplier(TOP_GRADATION))
    lo_end = _end(low_base, floor, excluded, rights)
    hi_end = _end(high_base, None if local else TOP_GRADATION, excluded, rights)
    return {"low": lo_end, "high": hi_end, "method": "rows" if figure_at else "scaled"}


#: Owner decision 2026-11-23: a stated figure with a minimum and a maximum is already a span. The ratio of
#: the top gradation to gradation 0 is 1.2452; a stated ratio within this tolerance is read as gradation 0 to 5.
SPAN_TOLERANCE = 0.02


def _stated_span(low: float, high: float, floor: int, excluded: bool, rights: dict, grid) -> dict:
    """A stated minimum and maximum. The high end is the stated maximum, with no gradation on top.

    Its gradation is 5 when `high / low` is the ratio of the table within `SPAN_TOLERANCE`, else None.
    The low end is the minimum x the floor gradation, capped at the maximum; when the cap applies its
    gradation is None and `capped` is True (the page then claims no gradation for that end).
    """
    top = grid.multiplier(TOP_GRADATION)
    high_base = int(round(high))
    low_base = _scaled(low, grid.multiplier(floor))
    capped = low_base > high_base
    if capped:
        low_base = high_base
    high_grad = TOP_GRADATION if abs(high / low / top - 1) <= SPAN_TOLERANCE else None
    return {"low": _end(low_base, None if capped else floor, excluded, rights),
            "high": _end(high_base, high_grad, excluded, rights),
            "method": "stated-span", "capped": capped}


def columns(rng: dict | None) -> tuple:
    """The six export columns of a range: `(pay_min, pay_max, grad_min, grad_max, food_min, food_max)`."""
    if not rng:
        return (None,) * 6
    lo, hi = rng["low"], rng["high"]
    return (lo["total"], hi["total"], lo["gradation"], hi["gradation"], lo["food"], hi["food"])

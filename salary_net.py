"""Net pay from gross pay, and the inverse (Romanian tax rules, 2026).

A port of `net_from_gross()` in `webapp-php/helpers.php`. Keep the two in step:
`webapp/tests/test_salary_net.py` compares them for 200+ gross values.
"""
from __future__ import annotations

import math

NET_MIN_WAGE = 4325  # gross minimum wage from 2026-07-01 (HG 146/2026)


def _round(x: float) -> int:
    """PHP `round()`: half away from zero (Python `round()` rounds half to even)."""
    return int(math.floor(abs(x) + 0.5)) * (1 if x >= 0 else -1)


def net_from_gross(gross: float) -> int:
    """Net monthly pay in whole lei for a monthly gross; no dependants, base job."""
    if gross <= 0:
        return 0
    cas = _round(gross * 0.25)
    cass = _round(gross * 0.10)
    over = gross - NET_MIN_WAGE
    if over <= 0:
        pct = 20.0
    elif over > 2000:
        pct = 0.0
    else:
        pct = max(0.0, 20.0 - 0.5 * math.ceil(over / 50))
    deduction = _round(NET_MIN_WAGE * pct / 100)
    tax = _round(max(0, gross - cas - cass - deduction) * 0.10)
    return int(gross - cas - cass - tax)


def gross_for_net(net: float) -> int:
    """The smallest gross, in whole lei, for which `net_from_gross()` gives at least `net`."""
    lo, hi = 0, 10_000_000  # net_from_gross(hi) >= net
    # net_from_gross() dips by 1-3 lei at each 50-lei step of the deduction (gross 4,325 to 6,325),
    # so it is not monotone there. The search finds a crossing; the walk back finds the first.
    while lo < hi:
        mid = (lo + hi) // 2
        if net_from_gross(mid) >= net:
            hi = mid
        else:
            lo = mid + 1
    first = lo
    for g in range(max(lo - 60, 0), lo):
        if net_from_gross(g) >= net:
            first = g
            break
    return first

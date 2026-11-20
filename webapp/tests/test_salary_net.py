"""salary_net.py against webapp-php/helpers.php (the two must agree)."""
import json
import shutil
import subprocess
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import salary_net as sn  # noqa: E402

GROSS = list(range(3000, 30001, 97))  # 279 values, plus the edges of the deduction steps
GROSS += [4325, 4326, 4375, 6325, 6326, 6375, 30000]


@pytest.mark.skipif(shutil.which("php") is None, reason="php CLI not installed")
def test_python_matches_php():
    assert len(GROSS) >= 200
    code = (
        "require '%s'; echo json_encode(array_map('net_from_gross', json_decode('%s')));"
        % (REPO_ROOT / "webapp-php" / "helpers.php", json.dumps(GROSS))
    )
    out = subprocess.run(["php", "-r", code], capture_output=True, text=True, check=True).stdout
    php = json.loads(out)
    py = [sn.net_from_gross(g) for g in GROSS]
    assert py == php


def test_half_away_from_zero():
    assert sn._round(2.5) == 3 and sn._round(0.5) == 1


@pytest.mark.parametrize("net", [1000, 3000, 4000, 5000, 6850, 7000, 12345, 20000])
def test_gross_for_net_is_enough_and_smallest(net):
    g = sn.gross_for_net(net)
    assert sn.net_from_gross(g) >= net
    assert sn.net_from_gross(g - 1) < net or g == 0


def test_gross_for_net_covers_dip_range():
    for n in range(2800, 9000, 7):
        g = sn.gross_for_net(n)
        assert sn.net_from_gross(g) >= n


def test_gross_for_net_is_the_first_crossing_everywhere():
    """Brute force over gross 0..30,000: the function is not monotone between 4,325 and 6,325."""
    tab = [sn.net_from_gross(g) for g in range(0, 30001)]
    for n in range(1, tab[-1], 3):
        first = next(g for g, v in enumerate(tab) if v >= n)
        assert sn.gross_for_net(n) == first

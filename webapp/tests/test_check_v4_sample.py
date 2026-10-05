"""The pure helpers of ops/check-v4-sample.py — no database needed."""
import importlib.util
import re
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def mod():
    spec = importlib.util.spec_from_file_location(
        "check_v4_sample", REPO_ROOT / "ops" / "check-v4-sample.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["check_v4_sample"] = module
    spec.loader.exec_module(module)
    return module


@pytest.mark.parametrize("text", [
    "termen 15.10.2026 ora 16",
    "termen 15/10/2026 ora 16",
    "termen 15-10-2026 ora 16",
    "termen 15.10.26 ora 16",
    "termen 15 octombrie 2026 ora 16",
    "termen 15 OCTOMBRIE 2026 ora 16",
])
def test_date_formats_found(mod, text):
    assert mod.find_snippet(text, "2026-10-15") is not None


def test_leading_zero_optional(mod):
    assert mod.find_snippet("până la 5.10.2026", "2026-10-05")
    assert mod.find_snippet("până la 05.10.2026", "2026-10-05")
    assert mod.find_snippet("până la 5 octombrie 2026", "2026-10-05")
    assert mod.find_snippet("până la 05 octombrie 2026", "2026-10-05")


def test_diacritics_in_month_names(mod):
    # Both cedilla and comma-below forms appear in scraped pages.
    assert mod.find_snippet("până la 3 februarie 2027", "2027-02-03")
    assert mod.find_snippet("până la 3 Februarie 2027", "2027-02-03")
    assert mod.find_snippet("până la 20 iulie 2026", "2026-07-20")
    assert mod.find_snippet("ȘTEFAN 7 ianuarie 2026", "2026-01-07")


def test_not_found(mod):
    assert mod.find_snippet("termen 16.10.2026", "2026-10-15") is None
    assert mod.find_snippet("", "2026-10-15") is None


def test_no_match_inside_longer_numbers(mod):
    # 5.10.2026 must not be found inside 15.10.2026, nor 15.10.26 inside 15.10.2026x.
    assert mod.find_snippet("termen 15.10.2026", "2026-10-05") is None
    assert mod.find_snippet("termen 115.10.2026", "2026-10-15") is None
    assert mod.find_snippet("termen 15.10.2026", "2026-10-15")


def test_earliest_position_wins_not_pattern_order(mod):
    text = "primul: 15 octombrie 2026 apoi 15.10.2026"
    snip = mod.find_snippet(text, "2026-10-15", width=40)
    assert snip.startswith("primul") and "15.10.2026" not in snip


def test_snippet_width_and_whitespace_collapse(mod):
    text = ("x" * 300) + "\n\n  termen   15.10.2026  \n" + ("y" * 300)
    snip = mod.find_snippet(text, "2026-10-15", width=160)
    assert "15.10.2026" in snip
    assert "\n" not in snip and "  " not in snip
    assert len(snip) <= 165
    assert snip.count("x") > 30 and snip.count("y") > 30


def test_snippet_at_text_edges(mod):
    assert mod.find_snippet("15.10.2026", "2026-10-15") == "15.10.2026"


def test_snippet_keeps_original_case_and_diacritics(mod):
    snip = mod.find_snippet("Depunere până la 15 OCTOMBRIE 2026, ora 16", "2026-10-15")
    assert "Depunere până la" in snip


def test_find_depunere(mod):
    assert "DEPUNEREA" in mod.find_depunere("termen\nDEPUNEREA dosarelor la sediu")
    assert mod.find_depunere("nimic aici") is None


def test_patterns_are_valid_regexes(mod):
    for pat in mod.date_patterns("2026-02-03"):
        re.compile(pat)


def test_range_end_is_found(mod):
    # The deadline is usually the closing day of a submission window.
    text = "Depunerea dosarelor se efectuează în perioada: 9.09.2026-22.09.2026, ora 16:30"
    snippet = mod.find_snippet(text, "2026-09-22")
    assert snippet is not None and "22.09.2026" in snippet

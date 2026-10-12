"""compute_cost: DeepSeek list prices, with the weekday peak tier (COST-01)."""

import importlib.util
import sys
from datetime import datetime, timezone
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


@pytest.fixture(scope="module")
def llm():
    spec = importlib.util.spec_from_file_location("llmschema_cost_test", REPO_ROOT / "llm-schema.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def utc(*args):
    return datetime(*args, tzinfo=timezone.utc)


# 2026-10-05 is a Monday, 2026-10-10 a Saturday, 2026-10-04 a Sunday.
MON_PEAK = utc(2026, 10, 5, 7)
MON_OFF = utc(2026, 10, 5, 5)
MON_BOUNDARY = utc(2026, 10, 5, 10)
SAT = utc(2026, 10, 10, 7)


def cost(llm, at, i=1_000_000, o=1_000_000, c=0):
    return llm.compute_cost("deepseek", "deepseek-v4-flash", i, o, c, at=at)


def test_off_peak_rates(llm):
    assert cost(llm, MON_OFF) == pytest.approx(0.15 + 0.60)


def test_peak_doubles(llm):
    assert cost(llm, MON_PEAK) == pytest.approx(2 * (0.15 + 0.60))


def test_peak_window_edges(llm):
    assert cost(llm, utc(2026, 10, 5, 1)) == pytest.approx(1.5)   # start inclusive
    assert cost(llm, utc(2026, 10, 5, 4)) == pytest.approx(0.75)  # end exclusive
    assert cost(llm, MON_BOUNDARY) == pytest.approx(0.75)         # 10:00 is off-peak
    assert cost(llm, utc(2026, 10, 5, 9, 59)) == pytest.approx(1.5)


def test_weekend_is_off_peak(llm):
    assert cost(llm, SAT) == pytest.approx(0.75)


def test_naive_datetime_is_utc(llm):
    assert cost(llm, datetime(2026, 10, 5, 7)) == pytest.approx(1.5)


def test_cached_tokens_use_cache_rate(llm):
    # 1M input, all cached, no output
    assert cost(llm, MON_OFF, i=1_000_000, o=0, c=1_000_000) == pytest.approx(0.003)
    assert cost(llm, MON_PEAK, i=1_000_000, o=0, c=1_000_000) == pytest.approx(0.006)


def test_provider_without_peak_tier_unchanged(llm):
    other = [(p, m) for p, pc in llm.MODELS_CONFIG["providers"].items()
             for m, mc in pc["models"].items() if "peak" not in mc]
    assert other
    p, m = other[0]
    assert llm.compute_cost(p, m, 1000, 1000, at=MON_PEAK) == llm.compute_cost(p, m, 1000, 1000, at=SAT)


def test_default_at_is_now(llm):
    assert llm.compute_cost("deepseek", "deepseek-v4-flash", 1000, 1000) is not None


def test_2026_10_04_run_regression(llm):
    got = llm.compute_cost("deepseek", "deepseek-v4-flash", 4_389_515, 1_093_462, 4_265_689,
                           at=utc(2026, 10, 4, 20, 57))
    assert got == pytest.approx(0.687, abs=0.001)


# ------------------------------------------------------------------ rate_card (FIX-10)
# The ledger stores the rates a call was billed at, so editing models_config.json later
# cannot restate old spend. The card must be exactly what compute_cost applied.

class TestRateCard:
    def test_off_peak_card(self, llm):
        card = llm.rate_card("deepseek", "deepseek-v4-flash", at=MON_OFF)
        assert (card["input_per_million"], card["output_per_million"],
                card["cache_read_per_million"]) == (0.15, 0.6, 0.003)
        assert card["peak"] is False and card["multiplier"] == 1.0

    def test_peak_card_carries_the_rates_actually_applied(self, llm):
        card = llm.rate_card("deepseek", "deepseek-v4-flash", at=MON_PEAK)
        assert (card["input_per_million"], card["output_per_million"],
                card["cache_read_per_million"]) == (0.30, 1.2, 0.006)
        assert card["peak"] is True and card["multiplier"] == 2.0

    def test_the_card_reproduces_compute_cost(self, llm):
        for at in (MON_OFF, MON_PEAK, MON_BOUNDARY, SAT):
            card = llm.rate_card("deepseek", "deepseek-v4-flash", at=at)
            i, o, c = 1_234_567, 98_765, 800_000
            by_card = ((i - c) * card["input_per_million"] + c * card["cache_read_per_million"]
                       + o * card["output_per_million"]) / 1_000_000
            assert llm.compute_cost("deepseek", "deepseek-v4-flash", i, o, c, at=at) \
                == pytest.approx(by_card)

    def test_unknown_model_has_no_card(self, llm):
        assert llm.rate_card("deepseek", "no-such-model", at=MON_OFF) is None
        assert llm.rate_card("nope", "x", at=MON_OFF) is None

    def test_the_card_is_plain_json(self, llm):
        import json
        card = llm.rate_card("deepseek", "deepseek-v4-flash", at=MON_PEAK)
        assert json.loads(json.dumps(card)) == card

    def test_the_config_hash_identifies_the_rate_revision(self, llm, monkeypatch):
        import copy
        before = llm.rate_card("deepseek", "deepseek-v4-flash", at=MON_OFF)["config_hash"]
        assert llm.rate_card("deepseek", "deepseek-v4-flash", at=MON_PEAK)["config_hash"] == before
        changed = copy.deepcopy(llm.MODELS_CONFIG)
        changed["providers"]["deepseek"]["models"]["deepseek-v4-flash"]["output_cost_per_million"] = 0.7
        monkeypatch.setattr(llm, "MODELS_CONFIG", changed)
        assert llm.rate_card("deepseek", "deepseek-v4-flash", at=MON_OFF)["config_hash"] != before

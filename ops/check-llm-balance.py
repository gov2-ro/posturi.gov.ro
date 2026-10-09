#!/usr/bin/env python3
"""Refuse to start a run the LLM account cannot pay for.

On 2026-10-05 a backfill died mid-run on DeepSeek `402 Insufficient Balance`:
llm-schema.py exits 2, nothing deploys, and the only signal was the failure itself,
half an hour in. A scheduled run would do the same and leave the site stale until
somebody happened to top up. This asks DeepSeek for the balance first
(`GET /user/balance`, free, same Bearer key) and stops the run before it spends
anything, or warns early enough to top up in time.

Three outcomes, by balance:

  ABORT   below --min (or --need, or the account reports is_available: false).
          Exit 69 (EX_UNAVAILABLE); run-pipeline.sh pings /fail and stops.
  WARN    below --warn. Printed, recorded, exit 0 — the run goes ahead.
  OK      otherwise.

A broken CHECK must never be what blocks the pipeline, so everything that is not a
verdict on the balance — a network error, a non-200, a malformed body, no key, no
USD entry, a provider with no balance endpoint — prints a line, is recorded as
`unavailable` / `skipped`, and exits 0. The API itself still fails loudly if the
account really is empty.

The provider is whatever the schema step will use: `llm_config.resolve_provider()`
(--provider > $LLM_PROVIDER > models_config.json), the same call pipeline.py makes.

Every observation is appended to data/pipeline-runs.jsonl as `kind: "llm-balance"`,
beside the run's own records and joined to them by `run_id`. No reader treats it as
a run, an export check or a deploy (check-export.py's baselines select on `kind`).

Usage:
    ops/check-llm-balance.py --no-record            # by hand: what is left?
    ops/check-llm-balance.py --need 8               # before a backfill costing ~$8
    LLM_BALANCE_MIN=1 LLM_BALANCE_WARN=5 ops/check-llm-balance.py
"""
from __future__ import annotations

import argparse
import json
import os
import socket
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_RUN_LOG = ROOT / "data" / "pipeline-runs.jsonl"

BALANCE_URL = "https://api.deepseek.com/user/balance"
TOP_UP_URL = "https://platform.deepseek.com/top_up"
TIMEOUT_S = 10.0

#: EX_UNAVAILABLE. run-pipeline.sh keys on this exact value; nothing else this
#: script can exit with stops a run.
EXIT_ABORT = 69

DEFAULT_MIN = 0.50    # a run that starts below this will not finish
DEFAULT_WARN = 3.00   # a few runs' worth: enough warning to top up in time


@dataclass
class Verdict:
    outcome: str                  # ok | warn | abort | unavailable | skipped
    message: str
    balance: float | None = None
    is_available: bool | None = None
    currency: str | None = None

    @property
    def exit_code(self) -> int:
        return EXIT_ABORT if self.outcome == "abort" else 0


# ---------------------------------------------------------------------------
# Pure pieces
# ---------------------------------------------------------------------------

def env_float(name: str, default: float) -> float:
    """$name as a float; unset, blank or garbage falls back to the default.

    A typo in .env must not turn into an argparse error and a failed run.
    """
    raw = (os.environ.get(name) or "").strip()
    if not raw:
        return default
    try:
        return float(raw)
    except ValueError:
        print(f"WARNING: ignoring {name}={raw!r} (not a number), using {default:g}")
        return default


def money(amount: float) -> str:
    return f"${amount:,.2f}"


def parse_balance(data: object) -> tuple[bool | None, list[dict]]:
    """(is_available, balance_infos) from the response body.

    Raises ValueError for a body that is not the documented shape, so the caller
    reports "unavailable" rather than judging a balance it could not read.
    """
    if not isinstance(data, dict):
        raise ValueError(f"expected a JSON object, got {type(data).__name__}")
    infos = data.get("balance_infos")
    if not isinstance(infos, list) or not all(isinstance(i, dict) for i in infos):
        raise ValueError("no balance_infos list in the response")
    available = data.get("is_available")
    return (available if isinstance(available, bool) else None), infos


def assess(
    data: object,
    *,
    minimum: float,
    warn: float,
    need: float | None = None,
) -> Verdict:
    """Turn a /user/balance response into a verdict. No I/O."""
    try:
        available, infos = parse_balance(data)
    except ValueError as exc:
        return Verdict("unavailable", f"WARNING: balance check unavailable: {exc}")

    usd = next((i for i in infos if str(i.get("currency", "")).upper() == "USD"), None)
    balance: float | None = None
    if usd is not None:
        try:
            balance = float(usd.get("total_balance"))
        except (TypeError, ValueError):
            return Verdict(
                "unavailable",
                f"WARNING: balance check unavailable: unreadable USD total_balance "
                f"{usd.get('total_balance')!r}",
                is_available=available)

    # The account's own verdict outranks any threshold: is_available is false when
    # the balance cannot cover a request, whatever currency it is held in.
    if available is False:
        shown = f"balance {money(balance)}" if balance is not None else (
            "balance " + (", ".join(_describe(i) for i in infos) or "none listed"))
        return Verdict(
            "abort",
            f"ABORT: DeepSeek reports the account unavailable ({shown}). "
            f"Top up at {TOP_UP_URL}, then re-run.",
            balance, available, "USD" if usd else None)

    if usd is None:
        listed = ", ".join(_describe(i) for i in infos) or "no balances listed"
        return Verdict(
            "unavailable",
            f"WARNING: balance check unavailable: no USD balance entry ({listed})",
            is_available=available)

    if balance < minimum:
        return Verdict(
            "abort",
            f"ABORT: DeepSeek balance {money(balance)} is below the {money(minimum)} "
            f"minimum — the run would stop on 402 part-way. Top up at {TOP_UP_URL}, "
            f"then re-run.",
            balance, available, "USD")
    if need is not None and balance < need:
        return Verdict(
            "abort",
            f"ABORT: DeepSeek balance {money(balance)} is below the {money(need)} "
            f"this run needs (--need). Top up at {TOP_UP_URL}, then re-run.",
            balance, available, "USD")
    if balance < warn:
        return Verdict(
            "warn",
            f"WARNING: DeepSeek balance {money(balance)} is below {money(warn)} "
            f"— top up soon",
            balance, available, "USD")
    return Verdict("ok", f"DeepSeek balance {money(balance)} (ok)",
                   balance, available, "USD")


def _describe(info: dict) -> str:
    return f"{info.get('currency', '?')} {info.get('total_balance', '?')}"


def fetch_balance(api_key: str, timeout: float = TIMEOUT_S) -> object:
    """GET /user/balance and return the decoded JSON. Raises on any failure.

    Callers turn every exception into "unavailable": urllib errors, timeouts and
    JSONDecodeError all derive from OSError or ValueError, and the key is only ever
    in a request header, never in an exception message.
    """
    request = urllib.request.Request(
        BALANCE_URL,
        headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"},
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def describe_error(exc: BaseException) -> str:
    if isinstance(exc, urllib.error.HTTPError):
        return f"HTTP {exc.code} from the balance endpoint"
    if isinstance(exc, urllib.error.URLError):
        return f"network error ({exc.reason})"
    if isinstance(exc, TimeoutError):
        return "timed out"
    if isinstance(exc, ValueError):
        return f"unparseable response ({exc})"
    return f"{type(exc).__name__}: {exc}"


def check(
    *,
    provider: str,
    api_key: str | None,
    minimum: float,
    warn: float,
    need: float | None = None,
    timeout: float = TIMEOUT_S,
) -> Verdict:
    """The whole decision for one provider, with the network call in fetch_balance."""
    if provider != "deepseek":
        return Verdict("skipped", f"skipped: provider {provider} has no balance check")
    if not api_key:
        return Verdict("skipped", "skipped: DEEPSEEK_API_KEY is not set")
    try:
        data = fetch_balance(api_key, timeout)
    except Exception as exc:  # a broken check must not block the pipeline
        return Verdict("unavailable",
                       f"WARNING: balance check unavailable: {describe_error(exc)}")
    return assess(data, minimum=minimum, warn=warn, need=need)


def build_record(
    verdict: Verdict,
    *,
    provider: str,
    minimum: float,
    warn: float,
    need: float | None,
) -> dict:
    return {
        "kind": "llm-balance",
        "run_id": os.environ.get("POSTURI_RUN_ID") or
                  datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "trigger": os.environ.get("POSTURI_RUN_TRIGGER") or "manual",
        "checked_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "host": socket.gethostname(),
        "provider": provider,
        "currency": verdict.currency,
        "balance": verdict.balance,
        "is_available": verdict.is_available,
        "min": minimum,
        "warn": warn,
        "need": need,
        "outcome": verdict.outcome,
        "message": verdict.message,
    }


def record(run_log: Path, rec: dict) -> None:
    """Append the observation. Failing to is a warning, never a failure."""
    try:
        sys.path.insert(0, str(ROOT))
        from pipeline import append_run_record
        append_run_record(run_log, rec)    # swallows OSError itself, with a warning
    except Exception as exc:
        print(f"WARNING: could not record the balance check: "
              f"{type(exc).__name__}: {exc}")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def resolve_provider(explicit: str | None) -> str:
    """The provider the schema step will use — llm_config's rule, not a copy."""
    sys.path.insert(0, str(ROOT))
    from llm_config import resolve_provider as resolve   # also loads .env
    return resolve(explicit)


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(
        description="Abort a pipeline run before it starts if the LLM balance is too low.")
    ap.add_argument("--min", type=float, default=None, metavar="USD", dest="minimum",
                    help=f"Abort (exit {EXIT_ABORT}) below this balance. Default "
                         f"$LLM_BALANCE_MIN, else {DEFAULT_MIN:.2f}.")
    ap.add_argument("--warn", type=float, default=None, metavar="USD",
                    help="Warn (exit 0) below this balance. Default $LLM_BALANCE_WARN, "
                         f"else {DEFAULT_WARN:.2f}.")
    ap.add_argument("--need", type=float, default=None, metavar="USD",
                    help="Abort unless at least this much is left — for a manual "
                         "backfill whose cost is known.")
    ap.add_argument("--provider", default=None,
                    help="Override the provider (default: $LLM_PROVIDER, then "
                         "models_config.json — what the schema step uses).")
    ap.add_argument("--timeout", type=float, default=TIMEOUT_S, metavar="S",
                    help=f"HTTP timeout in seconds (default {TIMEOUT_S:g}).")
    ap.add_argument("--run-log", default=str(DEFAULT_RUN_LOG), metavar="PATH",
                    help="Run log to append this observation to.")
    ap.add_argument("--no-record", action="store_true",
                    help="Do not append a record (for manual use).")
    args = ap.parse_args(argv)

    try:
        provider = resolve_provider(args.provider)
    except Exception as exc:
        # An unknown provider is pipeline.py's error to report, not this check's.
        print(f"WARNING: balance check unavailable: cannot resolve the provider "
              f"({type(exc).__name__}: {exc})")
        return 0

    # After the provider: importing llm_config is what loads .env, and the
    # thresholds may live there.
    minimum = args.minimum if args.minimum is not None else \
        env_float("LLM_BALANCE_MIN", DEFAULT_MIN)
    warn = args.warn if args.warn is not None else \
        env_float("LLM_BALANCE_WARN", DEFAULT_WARN)

    verdict = check(provider=provider, api_key=os.environ.get("DEEPSEEK_API_KEY"),
                    minimum=minimum, warn=warn, need=args.need, timeout=args.timeout)
    print(verdict.message)
    sys.stdout.flush()

    if not args.no_record:
        record(Path(args.run_log),
               build_record(verdict, provider=provider, minimum=minimum,
                            warn=warn, need=args.need))
    return verdict.exit_code


if __name__ == "__main__":
    sys.exit(main())

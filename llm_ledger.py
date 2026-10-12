"""FIX-10 -- an append-only record of every provider API attempt.

The schema, infer and occupations steps each call an LLM. Before this, only the schema
step counted anything, in a mutable per-posting variant row, so a re-extraction
overwrote the earlier spend and the other two steps were invisible. Here every attempt
-- a retry, a repair, a call that failed before returning any usage -- becomes one
immutable `jobs_llmcall` row, and each step reports its own total to the run log.

How a step uses it:

    ledger = make_ledger("occupations", provider, model, run_id=...)
    ...
    # in the provider code, right after the response arrives (before validating it):
    note_usage(input_tokens=..., output_tokens=..., cache_read_tokens=...)
    ...
    generate_with_retry(call, content, ledger=ledger.request("title or slug"))
    ...
    ledger.write_usage_record(run_log)

Design rules, each of them a test:

  * Unknown is None, never 0. A call that returned no usage has no tokens and no cost; a
    sum over `cost_usd` under-counts by exactly the calls whose cost is null, and the
    per-run record says how many those were.
  * The price is captured at call time (`pricing`), so editing models_config.json later
    cannot restate old spend.
  * Recording never raises into the caller. A dead database, a bad price lookup, a full
    disk: the extraction goes on, `write_errors` says the ledger is incomplete, and the
    in-memory totals -- what the run log and the ntfy summary use -- do not depend on
    the database at all.
  * A row holds no prompt, response, key or error message: only the exception's class.

It is a local record of attempts this code made, not the provider's invoice. A process
that dies after the provider accepted a request and before the row was written leaves a
call that cost money and is not here.
"""
from __future__ import annotations

import importlib.util
import json
import os
import sys
import threading
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

ROOT = Path(__file__).resolve().parent

DEFAULT_DSN = "postgres://localhost/posturi_dev"


# ---------------------------------------------------------------------------
# Usage hand-off: provider code -> the retry loop
# ---------------------------------------------------------------------------

@dataclass(frozen=True)
class Usage:
    """What the provider reported for ONE response. None means it did not say."""
    input_tokens: int | None = None          # total prompt tokens, cached ones included
    output_tokens: int | None = None         # reasoning tokens are inside this number
    cache_read_tokens: int | None = None
    cache_write_tokens: int | None = None
    reasoning_tokens: int | None = None      # recorded; never added to the cost again
    request_id: str | None = None


_local = threading.local()


def note_usage(input_tokens=None, output_tokens=None, cache_read_tokens=None, *,
               cache_write_tokens=None, reasoning_tokens=None, request_id=None) -> None:
    """Stash the usage of the response this thread just received.

    Call it as soon as the response is in hand and BEFORE validating it: a rejected
    answer is still billed, and validation raising would otherwise lose the numbers.
    The retry loop collects it with take_usage() once the attempt ends.
    """
    _local.usage = Usage(input_tokens, output_tokens, cache_read_tokens,
                         cache_write_tokens, reasoning_tokens, request_id)


def take_usage() -> Usage | None:
    usage = getattr(_local, "usage", None)
    _local.usage = None
    return usage


def discard_usage() -> None:
    _local.usage = None


# ---------------------------------------------------------------------------
# Pricing
# ---------------------------------------------------------------------------

Pricer = Callable[[str, str, "Usage | None", datetime], "tuple[float | None, dict | None]"]


def pricer_from(compute_cost, rate_card) -> Pricer:
    """A pricer on top of llm-schema.py's two functions: the one implementation of
    the rates, so the card stored on a row is exactly what the amount was computed from."""
    def price(provider, model, usage, ended_at):
        if usage is None:
            return None, None
        cost = compute_cost(provider, model, usage.input_tokens, usage.output_tokens,
                            usage.cache_read_tokens or 0, at=ended_at)
        return cost, rate_card(provider, model, at=ended_at)
    return price


_runner = None


def default_pricer() -> Pricer:
    """Price with llm-schema.py's functions, loaded by path (its name has a hyphen)."""
    global _runner
    if _runner is None:
        sys.path.insert(0, str(ROOT))
        spec = importlib.util.spec_from_file_location("llm_schema_runner", ROOT / "llm-schema.py")
        module = importlib.util.module_from_spec(spec)
        sys.modules.setdefault("llm_schema_runner", module)
        spec.loader.exec_module(module)
        _runner = module
    return pricer_from(_runner.compute_cost, _runner.rate_card)


# ---------------------------------------------------------------------------
# Sinks
# ---------------------------------------------------------------------------

INSERT_SQL = """
INSERT INTO jobs_llmcall (
    call_id, run_id, step, subject, posting_id, provider, model, prompt_version,
    source_revision, started_at, ended_at, latency_ms, attempt, is_repair, api_ok,
    outcome, error_class, request_id, input_tokens, output_tokens, cache_read_tokens,
    cache_write_tokens, reasoning_tokens, pricing, cost_usd, created_at
) VALUES (
    %(call_id)s, %(run_id)s, %(step)s, %(subject)s, %(posting_id)s, %(provider)s,
    %(model)s, %(prompt_version)s, %(source_revision)s, %(started_at)s, %(ended_at)s,
    %(latency_ms)s, %(attempt)s, %(is_repair)s, %(api_ok)s, %(outcome)s, %(error_class)s,
    %(request_id)s, %(input_tokens)s, %(output_tokens)s, %(cache_read_tokens)s,
    %(cache_write_tokens)s, %(reasoning_tokens)s, %(pricing)s::jsonb, %(cost_usd)s, now()
) ON CONFLICT (call_id) DO NOTHING
"""

_ROW_FIELDS = (
    "call_id", "run_id", "step", "subject", "posting_id", "provider", "model",
    "prompt_version", "source_revision", "started_at", "ended_at", "latency_ms", "attempt",
    "is_repair", "api_ok", "outcome", "error_class", "request_id", "input_tokens",
    "output_tokens", "cache_read_tokens", "cache_write_tokens", "reasoning_tokens",
    "pricing", "cost_usd",
)


def row_params(row: dict) -> dict:
    """The INSERT's named parameters: every column, `pricing` as JSON text."""
    params = {f: row.get(f) for f in _ROW_FIELDS}
    params["pricing"] = json.dumps(row["pricing"]) if row.get("pricing") is not None else None
    return params


class NullSink:
    """Writes nothing (POSTURI_LEDGER=off). Totals are kept by the Ledger regardless."""

    def write(self, row: dict) -> bool:
        return True


class MemorySink:
    """Keeps rows in a list, one per call_id -- the same idempotence as the table."""

    def __init__(self):
        self.rows: list[dict] = []
        self._seen: set[str] = set()
        self._lock = threading.Lock()

    def write(self, row: dict) -> bool:
        with self._lock:
            if row["call_id"] not in self._seen:
                self._seen.add(row["call_id"])
                self.rows.append(row)
        return True


def _default_connect(dsn: str):
    import psycopg
    return psycopg.connect(dsn, autocommit=True)


class PostgresSink:
    """Inserts into `jobs_llmcall` over one autocommit connection shared by all workers.

    The workers of a step run in a thread pool, and a psycopg connection must not be
    used by two threads at once, so writes are serialised by a lock. A connection that
    has dropped is reopened once per write. Failure is `False`, never an exception, and
    the warning names the exception's class only: its message can carry the DSN.
    """

    def __init__(self, dsn: str, connect: Callable[[str], object] | None = None):
        self.dsn = dsn
        self._connect = connect or _default_connect
        self._conn = None
        self._lock = threading.Lock()
        self.errors = 0

    def _drop(self) -> None:
        conn, self._conn = self._conn, None
        try:
            if conn is not None:
                conn.close()
        except Exception:
            pass

    def write(self, row: dict) -> bool:
        params = row_params(row)
        last: Exception | None = None
        with self._lock:
            for _ in range(2):
                try:
                    if self._conn is None:
                        self._conn = self._connect(self.dsn)
                    self._conn.execute(INSERT_SQL, params)
                    return True
                except Exception as exc:
                    last = exc
                    self._drop()
            self.errors += 1
            if self.errors == 1:
                print(f"WARNING: could not write the LLM call ledger ({type(last).__name__}); "
                      f"further failures are counted, not printed", file=sys.stderr)
        return False


# ---------------------------------------------------------------------------
# The ledger
# ---------------------------------------------------------------------------

def _opt(value, cast=int):
    return None if value is None else cast(value)


class Ledger:
    """Records attempts for ONE step of ONE run and keeps its running totals."""

    def __init__(self, sink, *, step: str, provider: str, model: str,
                 prompt_version: str = "", run_id: str = "", pricer: Pricer | None = None):
        self.sink = sink
        self.step, self.provider, self.model = step, provider, model
        self.prompt_version, self.run_id = prompt_version, run_id
        self._pricer = pricer
        self._nonce = uuid.uuid4().hex[:10]
        self._seq = 0
        self._lock = threading.Lock()
        self._totals = {"calls": 0, "api_ok_calls": 0, "error_calls": 0, "repair_calls": 0,
                        "unknown_cost_calls": 0, "write_errors": 0, "input_tokens": 0,
                        "output_tokens": 0, "cache_read_tokens": 0, "reasoning_tokens": 0,
                        "cost_usd": 0.0}

    def request(self, subject: str = "", *, posting_id: int | None = None,
                source_revision: str = "") -> "Request":
        """One logical request (a posting, a title): it may take several attempts."""
        return Request(self, subject, posting_id, source_revision)

    def _next_id(self) -> str:
        with self._lock:
            self._seq += 1
            return f"{self.run_id or 'manual'}/{self.step}/{self._nonce}-{self._seq}"

    def _price(self, usage, ended_at):
        if self._pricer is None:
            self._pricer = default_pricer()
        try:
            return self._pricer(self.provider, self.model, usage, ended_at)
        except Exception:     # a price that cannot be looked up is an unknown cost
            return None, None

    def _count(self, row: dict, stored: bool) -> None:
        with self._lock:
            t = self._totals
            t["calls"] += 1
            t["api_ok_calls"] += bool(row["api_ok"])
            t["error_calls"] += not row["api_ok"]
            t["repair_calls"] += bool(row["is_repair"])
            t["write_errors"] += not stored
            for field in ("input_tokens", "output_tokens", "cache_read_tokens", "reasoning_tokens"):
                t[field] += row[field] or 0
            if row["cost_usd"] is None:
                t["unknown_cost_calls"] += 1
            else:
                t["cost_usd"] += row["cost_usd"]

    def totals(self) -> dict:
        with self._lock:
            return dict(self._totals)

    def usage_record(self) -> dict | None:
        """The `kind: "llm-usage"` run-log record for this step; None if it made no calls."""
        t = self.totals()
        if not t["calls"]:
            return None
        return {
            "kind": "llm-usage", "format": 1, "run_id": self.run_id, "step": self.step,
            "provider": self.provider, "model": self.model,
            "prompt_version": self.prompt_version,
            "calls": t["calls"], "api_ok_calls": t["api_ok_calls"],
            "error_calls": t["error_calls"], "repair_calls": t["repair_calls"],
            "unknown_cost_calls": t["unknown_cost_calls"],
            "ledger_write_errors": t["write_errors"],
            "usage": {"input_tokens": t["input_tokens"], "output_tokens": t["output_tokens"],
                      "cached_input_tokens": t["cache_read_tokens"],
                      "reasoning_tokens": t["reasoning_tokens"],
                      "cost_usd": round(t["cost_usd"], 6)},
        }

    def write_usage_record(self, run_log) -> None:
        """Append the step's total to the run log. Never fails the step."""
        record = self.usage_record()
        if record is None:
            return
        try:
            sys.path.insert(0, str(ROOT))
            from pipeline import append_run_record
            append_run_record(Path(run_log), record)
        except Exception as exc:
            print(f"WARNING: could not write the LLM usage record: {type(exc).__name__}",
                  file=sys.stderr)


class Request:
    """The attempts made for one subject. The retry loop calls record_attempt()."""

    def __init__(self, ledger: Ledger, subject: str, posting_id, source_revision: str):
        self._ledger = ledger
        self.subject = subject[:300]
        self.posting_id = posting_id
        self.source_revision = source_revision or ""

    def record_attempt(self, *, attempt: int, is_repair: bool, started_at: datetime,
                       ended_at: datetime, outcome: str, error: BaseException | None = None,
                       usage: Usage | None = None, api_ok: bool | None = None) -> dict | None:
        """Write one row. Returns it, or None if even building it failed. Never raises."""
        try:
            ledger = self._ledger
            if api_ok is None:
                api_ok = usage is not None or outcome in ("ok", "validation_failed", "truncated")
            cost, card = ledger._price(usage, ended_at)
            u = usage or Usage()
            row = {
                "call_id": ledger._next_id(), "run_id": ledger.run_id, "step": ledger.step,
                "subject": self.subject, "posting_id": self.posting_id,
                "provider": ledger.provider, "model": ledger.model,
                "prompt_version": ledger.prompt_version,
                "source_revision": self.source_revision,
                "started_at": started_at, "ended_at": ended_at,
                "latency_ms": round((ended_at - started_at).total_seconds() * 1000),
                "attempt": attempt, "is_repair": bool(is_repair), "api_ok": bool(api_ok),
                "outcome": outcome,
                "error_class": type(error).__name__[:80] if error is not None else "",
                "request_id": (u.request_id or "")[:120],
                "input_tokens": _opt(u.input_tokens), "output_tokens": _opt(u.output_tokens),
                "cache_read_tokens": _opt(u.cache_read_tokens),
                "cache_write_tokens": _opt(u.cache_write_tokens),
                "reasoning_tokens": _opt(u.reasoning_tokens),
                "pricing": card, "cost_usd": _opt(cost, float),
            }
            try:
                stored = ledger.sink.write(row) is not False
            except Exception:
                stored = False
            ledger._count(row, stored)
            return row
        except Exception as exc:
            print(f"WARNING: could not record an LLM call: {type(exc).__name__}", file=sys.stderr)
            return None


def make_ledger(step: str, provider: str, model: str, prompt_version: str = "", *,
                run_id: str | None = None, sink=None, pricer: Pricer | None = None,
                dsn: str | None = None) -> Ledger:
    """A Ledger for one step: Postgres unless POSTURI_LEDGER=off, run id from the env.

    `POSTURI_LEDGER=off` keeps the in-memory totals (so the run log still gets its
    `llm-usage` record) and writes no rows; the test suite sets it so that no test can
    insert into the real table.
    """
    if sink is None:
        if (os.environ.get("POSTURI_LEDGER") or "").strip().lower() == "off":
            sink = NullSink()
        else:
            sink = PostgresSink(dsn or os.environ.get("DATABASE_URL", DEFAULT_DSN))
    return Ledger(sink, step=step, provider=provider, model=model,
                  prompt_version=prompt_version,
                  run_id=run_id if run_id is not None else os.environ.get("POSTURI_RUN_ID", ""),
                  pricer=pricer)

#!/usr/bin/env python3
"""Push notifications for the unattended pipeline, through ntfy.sh (OPS-BALANCE).

On 2026-10-12 the DeepSeek balance sat under the $3 warning level for 2.5 days and
then stopped a run, and the only places the warning went were `pipeline.log` and the
run log -- nobody reads either, and the box has no MTA. This puts the two things worth
a phone buzz in front of you:

  * a low balance        -- sent by ops/check-llm-balance.py (see `warn_already_sent`
                            for the once-a-day rule), and
  * a line per run       -- `ops/ntfy.py run`, called by ops/run-pipeline.sh when a run
                            ends: the outcome, postings, extraction and its LLM cost,
                            the export checks, and the balance left.

It is additive. Healthchecks stays the dead-man's switch: only it can report a run
that never started, which no push from inside a run can.

A notifier must never be what breaks the pipeline, so nothing here raises: a push that
cannot be delivered prints one WARNING line (never the topic, which is the only secret
on a public ntfy.sh server) and returns False, and `run` always exits 0.

Configuration (.env):
    NTFY_TOPIC    the topic to publish to; blank turns every push off. Treat it like a
                  password: on the public server anyone who knows it can read it.
    NTFY_URL      the server (default https://ntfy.sh).
    NTFY_SUMMARY  `off` keeps only the pushes that need attention (a failed or degraded
                  run, a balance under the abort level); blank sends one per run.

Usage:
    ops/ntfy.py test                                    # one marker push: is it wired?
    ops/ntfy.py run --outcome ok --exit 0               # what run-pipeline.sh calls
    ops/ntfy.py send --title T --message M [--priority 4] [--tag x]   # what ops/backup.sh calls
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Mapping
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_RUN_LOG = ROOT / "data" / "pipeline-runs.jsonl"

DEFAULT_URL = "https://ntfy.sh"
TIMEOUT_S = 10.0
LOCAL_TZ = ZoneInfo("Europe/Bucharest")      # the slots are Bucharest times
TOP_UP_URL = "https://platform.deepseek.com/top_up"

#: The pipeline runs twice a day, 5 h and 19 h apart. Twenty hours puts one warning
#: on each day's first run and none on the second, and still lets the next day's fire
#: when the clock drifts a few minutes.
WARN_WINDOW_H = 20

#: Below this, a gap between the balance and the log is rounding plus the cents the
#: `occupations` step spends without writing a record, not somebody else's spend.
LEDGER_TOLERANCE = 0.05

_TS_FORMAT = "%Y-%m-%dT%H:%M:%SZ"


@dataclass(frozen=True)
class Message:
    title: str
    body: str
    priority: int = 3                     # ntfy: 1 min .. 5 urgent
    tags: tuple[str, ...] = ()            # ntfy turns known names into emoji


# ---------------------------------------------------------------------------
# Transport
# ---------------------------------------------------------------------------

def settings(env: Mapping[str, str] | None = None) -> tuple[str, str] | None:
    """(server url, topic), or None when no topic is configured."""
    env = os.environ if env is None else env
    topic = (env.get("NTFY_TOPIC") or "").strip()
    if not topic:
        return None
    return (env.get("NTFY_URL") or "").strip() or DEFAULT_URL, topic


def describe_error(exc: BaseException) -> str:
    """A reason fit for a log. Never the URL or the topic."""
    if isinstance(exc, urllib.error.HTTPError):
        return f"HTTP {exc.code}"
    if isinstance(exc, urllib.error.URLError):
        return f"network error ({exc.reason})"
    if isinstance(exc, TimeoutError):
        return "timed out"
    return type(exc).__name__


def send(message: Message, *, url: str, topic: str, timeout: float = TIMEOUT_S) -> bool:
    """Publish as JSON to the server root. True when the server accepted it.

    JSON rather than headers: ntfy takes the title and tags from headers only as
    ASCII, and these messages carry Romanian text.
    """
    payload = {"topic": topic, "title": message.title, "message": message.body,
               "priority": message.priority, "tags": list(message.tags)}
    request = urllib.request.Request(
        url.rstrip("/") + "/",
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json; charset=utf-8"},
        method="POST")
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return 200 <= getattr(response, "status", 200) < 300
    except Exception as exc:   # a notifier must not break the pipeline
        print(f"WARNING: ntfy push failed: {describe_error(exc)}")
        return False


def notify(message: Message, env: Mapping[str, str] | None = None) -> bool:
    """send() to the configured server; False (and silent) when none is configured."""
    configured = settings(env)
    if configured is None:
        return False
    url, topic = configured
    return send(message, url=url, topic=topic)


# ---------------------------------------------------------------------------
# The run log
# ---------------------------------------------------------------------------

def parse_ts(value: object) -> datetime | None:
    try:
        return datetime.strptime(str(value), _TS_FORMAT).replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def read_records(path: Path | str) -> list[dict]:
    """Every JSON object in the run log; a missing file or a bad line is skipped."""
    try:
        text = Path(path).read_text(encoding="utf-8")
    except OSError:
        return []
    records = []
    for line in text.splitlines():
        try:
            record = json.loads(line)
        except ValueError:
            continue
        if isinstance(record, dict):
            records.append(record)
    return records


_KIND_TO_KEY = {"run": "run", "export-check": "export", "llm-balance": "balance",
                "deploy": "deploy"}


def for_run(records: list[dict], run_id: str) -> dict[str, dict]:
    """The last record of each kind written under `run_id`, keyed run/export/schema/
    balance/deploy, plus `usage`: the `llm-usage` record of each LLM step by step name.
    A kind the run did not write is absent."""
    found: dict[str, dict] = {}
    for record in records:
        if record.get("run_id") != run_id:
            continue
        kind = record.get("kind")
        if kind == "llm-schema":
            if record.get("step", "schema") == "schema":
                found["schema"] = record
        elif kind == "llm-usage":
            found.setdefault("usage", {})[record.get("step", "?")] = record
        elif kind in _KIND_TO_KEY:
            found[_KIND_TO_KEY[kind]] = record
    return found


def previous_export(records: list[dict], run_id: str) -> dict | None:
    """The export-check this run is compared with: the latest from a different run that
    precedes this run's own. A run that died before its export check has none of its
    own, so it is compared with the latest there is."""
    own = next((i for i, r in enumerate(records)
                if r.get("kind") == "export-check" and r.get("run_id") == run_id),
               len(records))
    for record in reversed(records[:own]):
        if record.get("kind") == "export-check" and record.get("run_id") != run_id:
            return record
    return None


def warn_already_sent(records: list[dict], now: datetime,
                      window_h: float = WARN_WINDOW_H) -> bool:
    """True when a low-balance warning was *delivered* within the last `window_h`.

    A record counts only if it is a warn with `notified: true`: a push that failed is
    retried on the next run, and records from before notifications existed carry no
    flag at all.
    """
    cutoff = now - timedelta(hours=window_h)
    for record in records:
        if (record.get("kind") == "llm-balance" and record.get("outcome") == "warn"
                and record.get("notified") is True):
            at = parse_ts(record.get("checked_at"))
            if at is not None and at > cutoff:
                return True
    return False


def _cost(record: dict) -> float:
    return float((record.get("usage") or {}).get("cost_usd") or 0.0)


def _logged_spend(records: list[dict]) -> float:
    """What this project logged as spent in `records`, every LLM step counted once.

    `llm-usage` records (schema, infer, occupations: every attempt, from the ledger) are
    the full account. An `llm-schema` record covers the same schema spend more narrowly
    (ok and truncated postings only), so it counts only for a run that has no
    `llm-usage` record for the schema step, i.e. one from before FIX-10.
    """
    covered = {(r.get("run_id"), r.get("step")) for r in records if r.get("kind") == "llm-usage"}
    total = 0.0
    for r in records:
        kind = r.get("kind")
        if kind == "llm-usage":
            total += _cost(r)
        elif kind == "llm-schema" and (r.get("run_id"), "schema") not in covered:
            total += _cost(r)
    return total


def balance_ledger(records: list[dict], run_id: str) -> dict[str, float] | None:
    """What moved the account between the previous start-of-run reading and this run's.

    The DeepSeek balance belongs to the account, whichever API key reads it, and other
    projects spend from it. Between two readings it can only fall by what this project
    logged (every step's spend written in between, hand-runs included) plus
    whatever else was spent, and rise by a top-up:

        gap = (balance now - balance then) + our logged spend

    gap > 0 is `topped_up`, gap < 0 is `elsewhere`, and anything inside LEDGER_TOLERANCE
    is neither. None when there is no earlier reading to compare with. Needs no new
    records: the readings are the `llm-balance` ones written at the top of each run.
    """
    readings = [(i, r) for i, r in enumerate(records)
                if r.get("kind") == "llm-balance"
                and isinstance(r.get("balance"), (int, float))]
    mine = [(i, r) for i, r in readings if r.get("run_id") == run_id]
    if not mine:
        return None
    now_at, now = mine[-1]
    earlier = [(i, r) for i, r in readings if i < now_at]
    if not earlier:
        return None
    then_at, then = earlier[-1]
    ours = _logged_spend(records[then_at + 1:now_at])
    gap = (now["balance"] - then["balance"]) + ours
    if gap > LEDGER_TOLERANCE:
        return {"elsewhere": 0.0, "topped_up": round(gap, 6)}
    if gap < -LEDGER_TOLERANCE:
        return {"elsewhere": round(-gap, 6), "topped_up": 0.0}
    return {"elsewhere": 0.0, "topped_up": 0.0}


# ---------------------------------------------------------------------------
# Messages (pure)
# ---------------------------------------------------------------------------

def money(amount: float) -> str:
    return f"${amount:,.2f}"


def build_balance_alert(outcome: str, balance: float | None, *, minimum: float,
                        warn: float, top_up_url: str = TOP_UP_URL) -> Message:
    """The push for check-llm-balance's `abort` and `warn` verdicts."""
    if outcome == "abort":
        if balance is not None and balance < minimum:
            lead = f"DeepSeek balance {money(balance)} is below the {money(minimum)} minimum."
        elif balance is not None:
            lead = f"DeepSeek cannot cover this run (balance {money(balance)})."
        else:
            lead = "DeepSeek reports the account unavailable."
        return Message(
            "posturi: LLM balance too low, run aborted",
            f"{lead}\nNothing was fetched or extracted this slot; the site keeps "
            f"serving the previous database.\nTop up: {top_up_url}",
            5, ("rotating_light",))
    shown = money(balance) if balance is not None else "unknown"
    return Message(
        "posturi: LLM balance low",
        f"DeepSeek balance {shown} is below {money(warn)}. The run continues; top up "
        f"before it reaches the {money(minimum)} minimum.\nTop up: {top_up_url}",
        3, ("warning",))


def _local_clock(ts: datetime | None) -> str:
    return f"{ts.astimezone(LOCAL_TZ):%H:%M}" if ts else ""


def _tokens(count: object) -> str:
    n = int(count or 0)
    if n >= 100_000:
        return f"{n / 1_000_000:.1f}M"
    if n >= 1_000:
        return f"{n / 1_000:.0f}k"
    return str(n)


def _delta(now: object, before: object) -> str:
    if not isinstance(now, (int, float)) or not isinstance(before, (int, float)):
        return ""
    change = int(now) - int(before)
    return " (±0)" if change == 0 else f" ({change:+,})"


def _failed_step_lines(run: dict, exit_code: int) -> list[str]:
    lines = []
    exits = {s.get("step"): s.get("exit") for s in run.get("steps") or []}
    for name in run.get("failed_steps") or []:
        lines.append(f"{name} failed (exit {exits.get(name, '?')})")
    return lines


def _llm_line(usage_by_step: dict[str, dict], schema: dict | None) -> str:
    """`LLM $0.377 (schema $0.370, occupations $0.007) · 1.9M in / 0.4M out`.

    From the `llm-usage` records, which cover every step and every attempt; a run from
    before FIX-10 falls back to the schema record alone. A step under half a cent stays
    in the total but not in the breakdown. Calls whose cost is unknown, and calls the
    ledger could not store, are said, never folded into a smaller-looking number.
    """
    if usage_by_step:
        steps = {name: rec for name, rec in usage_by_step.items()}
        cost = sum(_cost(r) for r in steps.values())
        tokens_in = sum((r.get("usage") or {}).get("input_tokens") or 0 for r in steps.values())
        tokens_out = sum((r.get("usage") or {}).get("output_tokens") or 0 for r in steps.values())
        unpriced = sum(r.get("unknown_cost_calls") or 0 for r in steps.values())
        missing = sum(r.get("ledger_write_errors") or 0 for r in steps.values())
        shown = [f"{name} ${_cost(r):.3f}" for name, r in steps.items() if _cost(r) >= 0.0005]
        text = f"LLM ${cost:.3f}" + (f" ({', '.join(shown)})" if len(shown) > 1 else "")
    elif schema:
        usage = schema.get("usage") or {}
        cost = usage.get("cost_usd")
        text = f"LLM ${cost:.3f}" if isinstance(cost, (int, float)) else "LLM"
        tokens_in, tokens_out = usage.get("input_tokens"), usage.get("output_tokens")
        unpriced = missing = 0
    else:
        return ""
    text += f" · {_tokens(tokens_in)} in / {_tokens(tokens_out)} out"
    if unpriced:
        text += f" · {unpriced} calls unpriced"
    if missing:
        text += f" · {missing} calls not in the ledger"
    return text


def build_run_summary(outcome: str, exit_code: int, recs: dict[str, dict],
                      previous: dict | None, *, run_id: str, end_balance: float | None,
                      minimum: float, warn: float,
                      ledger: dict[str, float] | None = None) -> Message:
    """One run, in a phone-sized message. `recs` is `for_run()`'s result; every part of
    it is optional because the run may have died anywhere."""
    run = recs.get("run") or {}
    export = recs.get("export") or {}
    schema = recs.get("schema")
    deploy = recs.get("deploy")
    metrics = export.get("metrics") or {}
    before = (previous or {}).get("metrics") or {}
    checks = export.get("checks") or []
    failing = [c for c in checks if not c.get("ok")]
    status = export.get("status")

    started = parse_ts(run.get("started_at")) or parse_ts(run_id)
    clock = _local_clock(started)
    label = {"ok": "ok", "degraded": "degraded", "failed": "FAILED"}[outcome]
    title = f"posturi {clock} {label}" if clock else f"posturi {label}"

    duration = run.get("duration_s")
    minutes = f"{max(1, round(duration / 60))} min" if isinstance(duration, (int, float)) else ""
    lines: list[str] = []

    # What went wrong first: it is what the reader needs before any number.
    if outcome != "ok":
        problems = _failed_step_lines(run, exit_code)
        if exit_code == 78:
            problems.append("Blocked by unapplied migrations: run `webapp/manage.py migrate`, "
                            "then re-run")
        elif exit_code == 65 or status == "fail":
            hard = [c for c in failing if c.get("level") == "hard"] or failing
            problems.append("The export failed its hard checks and was not deployed:")
            problems += [f"- {c.get('name')}: {c.get('message')}" for c in hard]
        elif not problems:
            problems.append(f"Run failed (exit {exit_code})")
        if outcome == "failed" and minutes and problems and "hard checks" not in problems[0]:
            problems[0] += f" after {minutes}"
        lines += problems
        if outcome == "failed":
            lines.append("The site is still serving the previous build.")

    headline = [minutes] if minutes and (outcome != "failed") else []
    if deploy and deploy.get("deployed"):
        stamp = _local_clock(parse_ts(deploy.get("at")))
        headline.append(f"deployed {stamp}" if stamp else "deployed")
    if headline:
        lines.insert(0, " · ".join(headline))

    if "active" in metrics:
        counts = f"Active {metrics['active']:,}{_delta(metrics['active'], before.get('active'))}"
        if "calendar_events" in metrics:
            counts += (f" · calendar {metrics['calendar_events']:,}"
                       f"{_delta(metrics['calendar_events'], before.get('calendar_events'))}")
        lines.append(counts)

    if schema:
        text = f"Schema {schema.get('ok', 0)}/{schema.get('selected', 0)} ok"
        if schema.get("failed"):
            classes = ", ".join(k if v == 1 else f"{k} ×{v}"
                                for k, v in (schema.get("failure_classes") or {}).items())
            text += f" · {schema['failed']} failed" + (f" ({classes})" if classes else "")
        lines.append(text)

    llm_line = _llm_line(recs.get("usage") or {}, schema)
    if llm_line:
        lines.append(llm_line)

    balance_level = None
    if end_balance is not None:
        text = f"Balance {money(end_balance)}"
        if end_balance < minimum:
            text += f" · below the {money(minimum)} minimum, next slot will abort. Top up: {TOP_UP_URL}"
            balance_level = "abort"
        elif end_balance < warn:
            text += f" · below {money(warn)}, top up soon"
            balance_level = "warn"
        lines.append(text)

    # The account is shared, so say when it moved by something this run did not do.
    if ledger and ledger.get("topped_up"):
        lines.append(f"Top-up {money(ledger['topped_up'])} since last run")
    if ledger and ledger.get("elsewhere"):
        lines.append(f"Other spend {money(ledger['elsewhere'])} since last run")

    if checks:
        text = f"Checks {status or '?'} {len(checks) - len(failing)}/{len(checks)}"
        for key, name in (("schema_coverage_pct", "schema"), ("v3_coverage_pct", "v3")):
            if isinstance(metrics.get(key), (int, float)):
                text += f" · {name} {metrics[key]:.1f}%"
        lines.append(text)
        if status == "warn":
            lines += [f"- {c.get('name')}: {c.get('message')}" for c in failing]

    # The loudest thing that is true wins; a tie keeps the earlier (run) reason.
    candidates = [{"ok": (2, ("white_check_mark",)), "degraded": (4, ("warning",)),
                   "failed": (4, ("x",))}[outcome]]
    if status == "warn":
        candidates.append((3, ("warning",)))
    if balance_level == "warn":
        candidates.append((3, ("warning",)))
    if balance_level == "abort":
        candidates.append((4, ("rotating_light",)))
    priority, tags = max(candidates, key=lambda c: c[0])

    return Message(title, "\n".join(lines), priority, tags)


# ---------------------------------------------------------------------------
# Balance lookups (shared with ops/check-llm-balance.py)
# ---------------------------------------------------------------------------

_balance_module = None


def balance_module():
    """ops/check-llm-balance.py, loaded by path (its name is not importable)."""
    global _balance_module
    if _balance_module is None:
        path = ROOT / "ops" / "check-llm-balance.py"
        spec = importlib.util.spec_from_file_location("check_llm_balance", path)
        module = importlib.util.module_from_spec(spec)
        sys.modules["check_llm_balance"] = module     # @dataclass resolves its module here
        spec.loader.exec_module(module)
        _balance_module = module
    return _balance_module


def thresholds() -> tuple[float, float]:
    bal = balance_module()
    return (bal.env_float("LLM_BALANCE_MIN", bal.DEFAULT_MIN),
            bal.env_float("LLM_BALANCE_WARN", bal.DEFAULT_WARN))


def fetch_end_balance() -> float | None:
    """What is left right now (a free call), or None if it cannot be read."""
    bal = balance_module()
    verdict = bal.check(provider=bal.resolve_provider(None),
                        api_key=os.environ.get("DEEPSEEK_API_KEY"), minimum=0.0, warn=0.0)
    return verdict.balance


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _load_env() -> None:
    """Importing llm_config loads .env, where NTFY_TOPIC and the thresholds live."""
    sys.path.insert(0, str(ROOT))
    try:
        import llm_config  # noqa: F401
    except Exception as exc:
        print(f"WARNING: could not load .env: {type(exc).__name__}")


def cmd_test() -> int:
    configured = settings()
    if configured is None:
        print("NTFY_TOPIC is not set in .env: nothing to send to.")
        return 1
    url, topic = configured
    sent = send(Message("posturi ntfy test",
                        "If you can read this, the pipeline can reach you.",
                        3, ("white_check_mark",)), url=url, topic=topic)
    print("Test push sent." if sent else "Test push NOT sent.")
    return 0 if sent else 1


def cmd_run(args: argparse.Namespace) -> int:
    if settings() is None:
        return 0
    run_id = args.run_id or os.environ.get("POSTURI_RUN_ID") or ""
    records = read_records(args.run_log)
    try:
        end_balance = fetch_end_balance()
    except Exception as exc:    # the balance is a nicety; the summary goes out without it
        print(f"WARNING: could not read the balance for the summary: {type(exc).__name__}")
        end_balance = None
    minimum, warn = thresholds()
    try:
        ledger = balance_ledger(records, run_id)
    except Exception as exc:    # a nicety, like the balance
        print(f"WARNING: could not reconcile the balance: {type(exc).__name__}")
        ledger = None
    message = build_run_summary(
        args.outcome, args.exit, for_run(records, run_id), previous_export(records, run_id),
        run_id=run_id, end_balance=end_balance, minimum=minimum, warn=warn, ledger=ledger)
    quiet = (os.environ.get("NTFY_SUMMARY") or "").strip().lower() == "off"
    out_of_credit = end_balance is not None and end_balance < minimum
    if quiet and args.outcome == "ok" and not out_of_credit:
        return 0
    notify(message)
    return 0


def cmd_send(args: argparse.Namespace) -> int:
    """One free-form push (ops/backup.sh's failure alert). Like `run`, it never fails
    its caller: no topic means nothing is sent, and a push that cannot be delivered
    has already printed its WARNING."""
    notify(Message(args.title, args.message, args.priority, tuple(args.tag)))
    return 0


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="ntfy.sh notifications for the pipeline.")
    sub = ap.add_subparsers(dest="command", required=True)
    run = sub.add_parser("run", help="Send the summary of a finished run.")
    run.add_argument("--outcome", required=True, choices=("ok", "degraded", "failed"))
    run.add_argument("--exit", type=int, default=0, metavar="N",
                     help="The wrapper's exit status (65 export failed, 78 migrations).")
    run.add_argument("--run-id", default=None,
                     help="Default: $POSTURI_RUN_ID, which run-pipeline.sh exports.")
    run.add_argument("--run-log", default=str(DEFAULT_RUN_LOG), metavar="PATH")
    sub.add_parser("test", help="Send one marker push to check the wiring.")
    send = sub.add_parser("send", help="Send one free-form push (ops/backup.sh).")
    send.add_argument("--title", required=True)
    send.add_argument("--message", required=True)
    send.add_argument("--priority", type=int, default=3, choices=range(1, 6),
                      help="ntfy priority, 1 (min) to 5 (urgent). Default 3.")
    send.add_argument("--tag", action="append", default=[],
                      help="An ntfy tag (a known name becomes an emoji); repeatable.")
    args = ap.parse_args(argv)

    _load_env()
    if args.command == "test":
        return cmd_test()
    try:
        return cmd_send(args) if args.command == "send" else cmd_run(args)
    except Exception as exc:    # never change a run's exit status
        print(f"WARNING: ntfy summary failed: {type(exc).__name__}: {exc}")
        return 0


if __name__ == "__main__":
    sys.exit(main())

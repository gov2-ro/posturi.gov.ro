#!/usr/bin/env python3
"""Append an alert-delivery record to the pipeline run log (OPS-ALERT-CHECK).

run-pipeline.sh sends two kinds of alert: a healthchecks.io ping and an ntfy push.
Both end in `|| true`, so a failed delivery used to leave no trace. This script
writes one `kind: "alert"` record for each ping. ops/ntfy.py writes its own record
through record_alert() below.

The record holds the run id, the channel, the event, the result and the HTTP
status or exit code. It never holds the healthcheck URL or the ntfy topic: both
are secrets.

Usage:
    ops/record-alert.py --channel healthcheck --event fail --http-status 200 --exit-code 0

This script always exits 0: a record that cannot be written must not fail a run.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_RUN_LOG = ROOT / "data" / "pipeline-runs.jsonl"

CHANNELS = ("healthcheck", "ntfy")
EVENTS = ("start", "success", "fail")


def alert_record(*, run_id: str, channel: str, event: str, ok: bool,
                 http_status: int | None = None, exit_code: int | None = None,
                 detail: str | None = None) -> dict:
    return {
        "kind": "alert",
        "run_id": run_id,
        "channel": channel,
        "event": event,
        "ok": ok,
        "http_status": http_status,
        "exit_code": exit_code,
        "detail": detail,
        "at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }


def record_alert(path: Path, **fields) -> None:
    """Append one alert record. Swallows every error: instrumentation only."""
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(alert_record(**fields), ensure_ascii=False) + "\n")
    except Exception as exc:
        print(f"  WARNING: could not write the alert record: {type(exc).__name__}",
              file=sys.stderr)


def _int_or_none(value: str | None) -> int | None:
    try:
        return int(value) if value not in (None, "") else None
    except ValueError:
        return None


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--channel", required=True, choices=CHANNELS)
    ap.add_argument("--event", required=True, choices=EVENTS)
    ap.add_argument("--http-status", default=None,
                    help="HTTP status that curl reported ('000' or empty: none).")
    ap.add_argument("--exit-code", default=None, help="Exit code of the sender (curl).")
    ap.add_argument("--run-log", default=str(DEFAULT_RUN_LOG), metavar="PATH")
    args = ap.parse_args(argv)

    status = _int_or_none(args.http_status) or None      # curl prints 000 on no reply
    code = _int_or_none(args.exit_code)
    ok = code == 0 if code is not None else bool(status and 200 <= status < 300)
    record_alert(Path(args.run_log), run_id=os.environ.get("POSTURI_RUN_ID", ""),
                 channel=args.channel, event=args.event, ok=ok,
                 http_status=status, exit_code=code)
    return 0


if __name__ == "__main__":
    sys.exit(main())

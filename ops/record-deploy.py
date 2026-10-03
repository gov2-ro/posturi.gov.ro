#!/usr/bin/env python3
"""Append a deploy-outcome record to the pipeline run log.

check-export.py builds its baselines only from export-checks whose candidate
was actually deployed — this script is what ties a candidate's run id to that
outcome. run-pipeline.sh calls it after the rsync succeeds (and can call it
with --degraded when a degraded publication was explicitly allowed).

Usage:
    ops/record-deploy.py [--degraded]
"""

import argparse
import os
import socket
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from pipeline import append_run_record  # noqa: E402

DEFAULT_RUN_LOG = ROOT / "data" / "pipeline-runs.jsonl"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--degraded", action="store_true",
                    help="Mark the deployment as a deliberately allowed degraded publication.")
    ap.add_argument("--run-log", default=str(DEFAULT_RUN_LOG), metavar="PATH")
    args = ap.parse_args()

    run_id = os.environ.get("POSTURI_RUN_ID")
    if not run_id:
        print("ERROR: POSTURI_RUN_ID is not set — refusing to record an "
              "unattributed deployment", file=sys.stderr)
        return 1

    append_run_record(Path(args.run_log), {
        "kind": "deploy",
        "run_id": run_id,
        "deployed": True,
        "degraded": args.degraded,
        "at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "host": socket.gethostname(),
    })
    print(f"Recorded deployment of {run_id} ({'degraded' if args.degraded else 'healthy'}).")
    return 0


if __name__ == "__main__":
    sys.exit(main())

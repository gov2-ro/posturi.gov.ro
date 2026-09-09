"""pipeline.py — run the full posturi.gov.ro data pipeline.

Steps (in order):
  fetch-index   fetch-index.py          — scrape index pages → data/posturi_gov_ro.csv
  fetch-detail  fetch-anunturi.py       — scrape individual posting pages → data/anunturi/
  parse         parse-anunturi.py       — parse HTML cache → data/anunturi/anunturi.csv + data/calendar.csv
  download      download-attachments.py — download linked attachments → data/downloads/
  import        manage.py import_csvs   — load CSVs into Postgres
  extract       manage.py extract_attachments  — extract text from downloaded files
  infer         manage.py infer_postings       — run metadata inference (dict + optional LLM)
  schema        llm-schema.py                  — extract structured display sections → schema_json
  export-sqlite export-to-sqlite.py            — PostgreSQL → active-only posturi.sqlite

Usage:
  python pipeline.py                          # run all steps
  python pipeline.py --steps fetch-index,parse,import
  python pipeline.py --skip download,infer
  python pipeline.py --force --no-llm
  python pipeline.py --steps infer --provider anthropic --limit 100
  python pipeline.py --steps export-sqlite    # rebuild SQLite only

  # Backfill the LLM extraction for everything currently live (~1,440 postings):
  python pipeline.py --steps schema --active-only --resume --workers 8 --prompt-version v3
"""
from __future__ import annotations

import argparse
import os
import subprocess
import sys
import time
from pathlib import Path

from llm_config import resolve_provider

#: Providers every pipeline step can drive (see --provider below).
_PIPELINE_PROVIDERS = ("gemini", "openai", "anthropic", "deepseek")

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

ROOT = Path(__file__).parent.resolve()
WEBAPP_DIR = ROOT / "webapp"
VENV_PYTHON = ROOT / ".venv" / "bin" / "python"

ALL_STEPS = [
    "fetch-index",
    "fetch-detail",
    "parse",
    "download",
    "import",
    "extract",
    "infer",
    "schema",
    "export-sqlite",
]

# Map step name → command (list of str/Path); placeholders filled in _build_cmd()
_SCRAPER_STEPS = {
    "fetch-index":   ROOT / "fetch-index.py",
    "fetch-detail":  ROOT / "fetch-anunturi.py",
    "parse":         ROOT / "parse-anunturi.py",
    "download":      ROOT / "download-attachments.py",
    "schema":        ROOT / "llm-schema.py",
    "export-sqlite": ROOT / "export-to-sqlite.py",
}

_MANAGE_STEPS = {
    "import":  "import_csvs",
    "extract": "extract_attachments",
    "infer":   "infer_postings",
}


def _load_env() -> None:
    """Load .env into the environment if python-dotenv is available."""
    env_path = ROOT / ".env"
    if not env_path.exists():
        return
    try:
        from dotenv import load_dotenv
        load_dotenv(env_path, override=False)
    except ImportError:
        pass  # dotenv not installed — env vars must be set externally


def _build_cmd(
    step: str,
    *,
    force: bool,
    no_llm: bool,
    provider: str,
    limit: int | None,
    since: int | None,
    workers: int | None = None,
    resume: bool = False,
    active_only: bool = False,
    prompt_version: str | None = None,
) -> list[str | Path]:
    """Return the subprocess command for a given step."""
    if step in _SCRAPER_STEPS:
        cmd: list[str | Path] = [VENV_PYTHON, _SCRAPER_STEPS[step]]
        if step == "schema":
            if force:
                cmd.append("--force")
            if provider:
                cmd.extend(["--provider", provider])
            # The schema step is the long pole: ~9,600 LLM calls. Without these
            # it ran one call at a time over every posting ever scraped, which
            # is why it never finished — 1,440 of 1,799 *active* postings still
            # have no schema_json.
            if workers is not None:
                cmd.extend(["--workers", str(workers)])
            if resume:
                cmd.append("--resume")
            if active_only:
                cmd.append("--active-only")
            if prompt_version:
                cmd.extend(["--prompt-version", prompt_version])
            if limit is not None:
                cmd.extend(["--limit", str(limit)])
        if step == "download" and since is not None:
            cmd.extend(["--since", str(since)])
        if step == "export-sqlite":
            cmd.append("--active-only")
        return cmd

    if step not in _MANAGE_STEPS:
        raise ValueError(f"Unknown step: {step!r}")

    manage_cmd = _MANAGE_STEPS[step]
    cmd: list[str | Path] = [VENV_PYTHON, WEBAPP_DIR / "manage.py", manage_cmd]

    if force:
        cmd.append("--force")

    if step == "infer":
        if no_llm:
            cmd.append("--no-llm")
        else:
            cmd.extend(["--provider", provider])
        if limit is not None:
            cmd.extend(["--limit", str(limit)])

    return cmd


def _run_step(step: str, cmd: list[str | Path], *, continue_on_error: bool) -> bool:
    """Run a single step. Returns True on success, False on failure."""
    print(f"\n{'=' * 60}")
    print(f"  STEP: {step}")
    print(f"  CMD:  {' '.join(str(c) for c in cmd)}")
    print(f"{'=' * 60}")
    t0 = time.monotonic()

    try:
        subprocess.run(cmd, check=True, cwd=ROOT)
        elapsed = time.monotonic() - t0
        print(f"\n  ✓ {step} completed in {elapsed:.1f}s")
        return True
    except subprocess.CalledProcessError as exc:
        elapsed = time.monotonic() - t0
        print(f"\n  ✗ {step} FAILED (exit {exc.returncode}) after {elapsed:.1f}s", file=sys.stderr)
        if continue_on_error:
            return False
        sys.exit(exc.returncode)
    except FileNotFoundError as exc:
        print(f"\n  ✗ {step} FAILED — command not found: {exc}", file=sys.stderr)
        if continue_on_error:
            return False
        sys.exit(1)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Run the posturi.gov.ro data pipeline.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=f"Available steps (in order): {', '.join(ALL_STEPS)}",
    )
    parser.add_argument(
        "--steps",
        metavar="STEP[,STEP...]",
        help="Comma-separated list of steps to run (default: all).",
    )
    parser.add_argument(
        "--skip",
        metavar="STEP[,STEP...]",
        help="Comma-separated list of steps to skip.",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="Pass --force to import, extract, infer, and schema steps.",
    )
    parser.add_argument(
        "--no-llm",
        action="store_true",
        help="Pass --no-llm to infer step (skip LLM fallback).",
    )
    parser.add_argument(
        "--provider",
        # Both LLM-consuming steps (infer and schema) support this full set.
        choices=_PIPELINE_PROVIDERS,
        default=None,
        help="LLM provider for infer and schema steps. Defaults to $LLM_PROVIDER, "
             "then models_config.json \"defaults.provider\".",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        metavar="N",
        help="Pass --limit N to the infer and schema steps (useful for testing).",
    )
    parser.add_argument(
        "--workers",
        type=int,
        default=None,
        metavar="N",
        help="Concurrent LLM calls in the schema step (llm-schema.py default: 4).",
    )
    parser.add_argument(
        "--resume",
        action="store_true",
        help="Schema step: skip postings that already have a variant row for this "
             "provider/model/prompt-version. Makes an interrupted run restartable.",
    )
    parser.add_argument(
        "--prompt-version",
        default=None,
        metavar="VERSION",
        help="Schema step: prompt version (v1/v2/v3). Defaults to $LLM_PROMPT_VERSION, "
             "then models_config.json \"defaults.prompt_version\".",
    )
    parser.add_argument(
        "--active-only",
        action="store_true",
        help="Schema step: only extract postings whose deadline has not passed. "
             "The export-sqlite step is always active-only, so this matches what "
             "actually reaches the deployed site.",
    )
    parser.add_argument(
        "--since",
        type=int,
        default=None,
        metavar="DAYS",
        help="Pass --since N to download step: only process rows published in the last N days.",
    )
    parser.add_argument(
        "--continue-on-error",
        action="store_true",
        help="Log failures and continue rather than aborting.",
    )

    args = parser.parse_args()

    _load_env()

    # Resolve step list
    if args.steps:
        selected = [s.strip() for s in args.steps.split(",") if s.strip()]
        unknown = [s for s in selected if s not in ALL_STEPS]
        if unknown:
            parser.error(f"Unknown step(s): {', '.join(unknown)}. Valid: {', '.join(ALL_STEPS)}")
    else:
        selected = list(ALL_STEPS)

    if args.skip:
        skipped = {s.strip() for s in args.skip.split(",") if s.strip()}
        unknown_skip = skipped - set(ALL_STEPS)
        if unknown_skip:
            parser.error(f"Unknown step(s) in --skip: {', '.join(unknown_skip)}")
        selected = [s for s in selected if s not in skipped]

    if not selected:
        print("No steps to run (all selected steps were skipped).")
        return

    # Check Django venv exists for management command steps
    mgmt_steps_selected = [s for s in selected if s in _MANAGE_STEPS]
    if mgmt_steps_selected and not VENV_PYTHON.exists():
        print(
            f"Error: {VENV_PYTHON} not found. Create the virtualenv first:\n"
            f"  python3 -m venv .venv && .venv/bin/pip install -r requirements.txt",
            file=sys.stderr,
        )
        sys.exit(1)

    args.provider = resolve_provider(args.provider)
    if args.provider not in _PIPELINE_PROVIDERS:
        parser.error(
            f"provider {args.provider!r} (from $LLM_PROVIDER or models_config.json) "
            f"is not supported by the infer step; pass --provider "
            f"{'/'.join(_PIPELINE_PROVIDERS)} explicitly."
        )

    print(f"Running {len(selected)} step(s): {', '.join(selected)}")
    t_total = time.monotonic()
    failures: list[str] = []

    for step in selected:
        cmd = _build_cmd(
            step,
            force=args.force,
            no_llm=args.no_llm,
            provider=args.provider,
            limit=args.limit,
            since=args.since,
            workers=args.workers,
            resume=args.resume,
            active_only=args.active_only,
            prompt_version=args.prompt_version,
        )
        ok = _run_step(step, cmd, continue_on_error=args.continue_on_error)
        if not ok:
            failures.append(step)

    total_elapsed = time.monotonic() - t_total
    print(f"\n{'=' * 60}")
    if failures:
        print(f"  Pipeline finished with {len(failures)} failure(s): {', '.join(failures)}")
        print(f"  Total time: {total_elapsed:.1f}s")
        sys.exit(1)
    else:
        print(f"  Pipeline complete. {len(selected)} step(s) in {total_elapsed:.1f}s")
    print("=" * 60)


if __name__ == "__main__":
    main()

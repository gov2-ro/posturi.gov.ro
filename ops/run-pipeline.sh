#!/usr/bin/env bash
# Unattended pipeline run + data deploy. Driven by ops/systemd/posturi-pipeline.timer.
#
# Runs on the VPS, which owns Postgres, the scrape cache and the API keys. It never
# touches git: code deploys are manual from the development machine, and pulling here
# would fight them. It ships the database only -- see deploy-php.sh for why.
#
# Exit codes: 0 fine, 65 the export failed its hard checks and was not deployed,
# 75 a previous run is still going, 78 the checkout has unapplied migrations,
# anything else a failure. Every non-zero exit has already been reported to
# $HEALTHCHECK_URL.
#
# Commands whose failure this script HANDLES are written `cmd || status=$?`. An
# ERR trap is not disabled by `set +e`: the old `set +e; cmd; status=$?` form let
# on_error exit straight away, so the ABORT/degraded branches below were dead code
# and every failed step looked like a crash (REV-13).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# One run at a time. Six hours and forty-eight minutes separate the two daily slots;
# a run that overruns that must not be joined by the next one, and neither must a
# hand-run overlap the timer. Re-exec under flock rather than relying on the unit,
# so the lock holds whichever way the script was started.
LOCKFILE="${LOCKFILE:-$ROOT/.pipeline.lock}"
if [ "${POSTURI_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
    exec env POSTURI_LOCKED=1 flock -n -E 75 "$LOCKFILE" "$0" "$@"
fi

. "$ROOT/ops/env.sh"
ENV_FILE="$ROOT/.env"

PYTHON="${PYTHON:-$ROOT/.venv/bin/python}"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-$(env_value HEALTHCHECK_URL)}"
# Pinned, not left to models_config.json, whose default is still v2. The deployed
# schema has v3_* columns and the site's facets read them; a v2 extraction would
# import as a posting with every v3 facet empty.
PROMPT_VERSION="${LLM_PROMPT_VERSION:-v3}"
WORKERS="${SCHEMA_WORKERS:-4}"
DOWNLOAD_SINCE="${DOWNLOAD_SINCE:-7}"

# One id ties the pipeline record and the export-check record together in
# data/pipeline-runs.jsonl. `trigger` separates the timer's runs from hand-runs so
# /pipeline-check does not read a debugging session as a missed slot.
export POSTURI_RUN_ID="${POSTURI_RUN_ID:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
export POSTURI_RUN_TRIGGER="${POSTURI_RUN_TRIGGER:-cron}"

ping_health() {          # $1: "" (success) | /start | /fail
    [ -n "${HEALTHCHECK_URL:-}" ] || return 0
    curl -fsS -m 10 -o /dev/null "${HEALTHCHECK_URL}${1:-}" || true
}

on_error() {
    local status=$?
    echo "FAILED (exit ${status}) at $(date -u +%Y-%m-%dT%H:%M:%SZ)" >&2
    ping_health /fail
    exit "$status"
}
trap on_error ERR

echo "=== posturi pipeline run ${POSTURI_RUN_ID} (${POSTURI_RUN_TRIGGER}) —" \
     "$(date -u +%Y-%m-%dT%H:%M:%SZ) on $(hostname) @ $(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo '?') ==="
ping_health /start

# A `git pull` here is manual and so is `migrate` (docs/deploy-vps.md). Code that
# expects new columns would otherwise run 20 minutes before the import trips over
# them; stop first, loudly, without touching the site.
migrate_status=0
"$PYTHON" webapp/manage.py migrate --check >/dev/null 2>&1 || migrate_status=$?
if [ "$migrate_status" -ne 0 ]; then
    echo "ABORT: unapplied migrations (or manage.py failed, exit ${migrate_status})."
    echo "       Run: $PYTHON webapp/manage.py migrate   — then re-run this script."
    "$PYTHON" webapp/manage.py showmigrations jobs 2>&1 | grep -F '[ ]' || true
    ping_health /fail
    echo "=== aborted before the pipeline — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
    exit 78
fi

# --active-only is the cost guard, not --resume: 6,904 postings have no schema_json
# but only ~15 of them are active, and the rest will never reach the export. Without
# it every run would pay for LLM extraction on thousands of expired postings.
pipeline_status=0
"$PYTHON" pipeline.py \
    --continue-on-error \
    --since "$DOWNLOAD_SINCE" \
    --active-only \
    --resume \
    --workers "$WORKERS" \
    --prompt-version "$PROMPT_VERSION" || pipeline_status=$?

if [ "$pipeline_status" -ne 0 ]; then
    # Degraded publication is an EXPLICIT configured policy, off by default:
    # step failures mean the data pipeline did not complete, so the site keeps
    # the last whole export rather than silently shipping a partial run. Set
    # POSTURI_ALLOW_DEGRADED_DEPLOY=1 (and read /pipeline-check, which surfaces
    # the degraded flag) to opt into deploying anyway when the check passes.
    if [ "${POSTURI_ALLOW_DEGRADED_DEPLOY:-0}" != "1" ]; then
        echo "ABORT: pipeline steps failed (exit ${pipeline_status}) and"
        echo "       POSTURI_ALLOW_DEGRADED_DEPLOY is not 1 — not deploying. The"
        echo "       shared host keeps serving the previous database."
        ping_health /fail
        echo "=== aborted before deploy — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
        exit "$pipeline_status"
    fi
    echo "WARNING: pipeline reported step failures (exit ${pipeline_status}) but"
    echo "         POSTURI_ALLOW_DEGRADED_DEPLOY=1 — continuing to the export check;"
    echo "         the deployment will be recorded as degraded."
fi

# The gate. Hard checks are corruption-shaped -- 43 counties, a short FTS index, a
# build_meta that says the export never ran -- and none of them can be a bad day's
# data. Soft warnings print and are recorded but do not block; add --strict once the
# thresholds have a few weeks of runs behind them.
check_status=0
"$PYTHON" ops/check-export.py --prompt-version "$PROMPT_VERSION" || check_status=$?

if [ "$check_status" -ne 0 ]; then
    echo "ABORT: the export failed its hard checks and was NOT deployed. The shared"
    echo "       host keeps serving the previous database, which is stale but whole."
    ping_health /fail
    echo "=== aborted before deploy — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
    exit 65
fi

# --no-export: pipeline.py's export-sqlite step already built the file, floors and all.
./deploy-php.sh --data-only --no-export

# Tie the deploy outcome to the candidate run id so check-export's baselines
# only ever learn from candidates that actually reached the shared host.
if [ "$pipeline_status" -ne 0 ]; then
    "$PYTHON" ops/record-deploy.py --degraded
    ping_health /fail
    echo "=== finished with failures — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
    exit "$pipeline_status"
fi

"$PYTHON" ops/record-deploy.py
ping_health
echo "=== done — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="

#!/usr/bin/env bash
# Unattended pipeline run + data deploy. Driven by ops/systemd/posturi-pipeline.timer.
#
# Runs on the VPS, which owns Postgres, the scrape cache and the API keys. It never
# touches git: code deploys are manual from the development machine, and pulling here
# would fight them. It ships the database only -- see deploy-php.sh for why.
#
# Exit codes: 0 fine, 65 the export failed its hard checks and was not deployed,
# 75 a previous run is still going, anything else a failure. Every non-zero exit has
# already been reported to $HEALTHCHECK_URL.
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

# --active-only is the cost guard, not --resume: 6,904 postings have no schema_json
# but only ~15 of them are active, and the rest will never reach the export. Without
# it every run would pay for LLM extraction on thousands of expired postings.
set +e
"$PYTHON" pipeline.py \
    --continue-on-error \
    --since "$DOWNLOAD_SINCE" \
    --active-only \
    --resume \
    --workers "$WORKERS" \
    --prompt-version "$PROMPT_VERSION"
pipeline_status=$?
set -e

if [ "$pipeline_status" -ne 0 ]; then
    echo "WARNING: pipeline reported step failures (exit ${pipeline_status}). Continuing"
    echo "         to the export check — a stale-but-correct site beats a site nobody"
    echo "         updated, and the check is what decides whether this one is correct."
fi

# The gate. Hard checks are corruption-shaped -- 43 counties, a short FTS index, a
# build_meta that says the export never ran -- and none of them can be a bad day's
# data. Soft warnings print and are recorded but do not block; add --strict once the
# thresholds have a few weeks of runs behind them.
set +e
"$PYTHON" ops/check-export.py --prompt-version "$PROMPT_VERSION"
check_status=$?
set -e

if [ "$check_status" -ne 0 ]; then
    echo "ABORT: the export failed its hard checks and was NOT deployed. The shared"
    echo "       host keeps serving the previous database, which is stale but whole."
    ping_health /fail
    echo "=== aborted before deploy — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
    exit 65
fi

# --no-export: pipeline.py's export-sqlite step already built the file, floors and all.
./deploy-php.sh --data-only --no-export

if [ "$pipeline_status" -ne 0 ]; then
    ping_health /fail
    echo "=== finished with failures — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
    exit "$pipeline_status"
fi

ping_health
echo "=== done — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="

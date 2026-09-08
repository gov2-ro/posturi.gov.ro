#!/usr/bin/env bash
# Unattended pipeline run + data deploy. Driven by ops/systemd/posturi-pipeline.timer.
#
# Runs on the VPS, which owns Postgres, the scrape cache and the API keys. It never
# touches git: code deploys are manual from the development machine, and pulling here
# would fight them. It ships the database only -- see deploy-php.sh for why.
#
# Exit codes: 0 fine, 75 a previous run is still going, anything else a failure that
# has already been reported to $HEALTHCHECK_URL.
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

echo "=== posturi pipeline run — $(date -u +%Y-%m-%dT%H:%M:%SZ) on $(hostname) ==="
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
    echo "WARNING: pipeline reported step failures (exit ${pipeline_status}). Deploying"
    echo "         anyway — export-to-sqlite.py's floors decide whether the data is fit"
    echo "         to ship, and a stale-but-correct site beats a site nobody updated."
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

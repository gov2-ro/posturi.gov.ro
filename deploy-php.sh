#!/usr/bin/env bash
# Rebuild + deploy: PostgreSQL -> SQLite -> shared host.
#
# Usage:
#   ./deploy-php.sh [user@host] [remote_path] [flags]
#
#   --code-only   push the PHP tree, never the database. Manual releases from the Mac.
#   --data-only   push only posturi.sqlite. What the cron on the VPS runs.
#   --no-export   deploy the posturi.sqlite that is already there, don't rebuild it.
#   --dry-run     show what rsync would move, change nothing.
#
# Why the split: code deploys are manual from the development machine, while the
# data deploy runs unattended twice a day from the VPS. If the cron pushed the whole
# directory, the VPS's older checkout would silently revert templates pushed from the
# Mac. Each side ships only what it owns.
#
# Examples:
#   ./deploy-php.sh user@example.com 'public_html'              # both, one shot
#   ./deploy-php.sh --code-only                                 # after a template change
#   ./deploy-php.sh --data-only --no-export                     # cron, after pipeline.py
#   DEPLOY_HOST=user@example.com DEPLOY_PATH=public_html ./deploy-php.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DB_FILE="$SCRIPT_DIR/webapp-php/posturi.sqlite"

deploy_code=1
deploy_data=1
do_export=1
dry_run=0
positional=()

for arg in "$@"; do
    case "$arg" in
        --code-only) deploy_data=0 ;;
        --data-only) deploy_code=0 ;;
        --no-export) do_export=0 ;;
        --dry-run)   dry_run=1 ;;
        -h|--help)   sed -n '2,20p' "$0"; exit 0 ;;
        -*)          echo "Unknown flag: $arg" >&2; exit 2 ;;
        *)           positional+=("$arg") ;;
    esac
done

die() { echo "ERROR: $*" >&2; exit 1; }

# env_value(), shared with ops/run-pipeline.sh.
. "$SCRIPT_DIR/ops/env.sh"
ENV_FILE="$SCRIPT_DIR/.env"

DEPLOY_HOST="${positional[0]:-${DEPLOY_HOST:-$(env_value DEPLOY_HOST)}}"
DEPLOY_PATH="${positional[1]:-${DEPLOY_PATH:-$(env_value DEPLOY_PATH)}}"
SITE_URL="${SITE_URL:-$(env_value SITE_URL)}"
: "${DEPLOY_PATH:=public_html}"

if [[ -z "$DEPLOY_HOST" ]]; then
    echo "Usage: $0 user@host [remote_path] [--code-only|--data-only] [--no-export]"
    echo "   or: DEPLOY_HOST=user@host ./deploy-php.sh"
    echo "   or: set DEPLOY_HOST / DEPLOY_PATH in .env"
    exit 1
fi

# rsync --delete against the wrong path empties a home directory.
case "$DEPLOY_PATH" in
    ""|"/"|"."|".."|"~"|"~/") die "refusing to deploy to DEPLOY_PATH='$DEPLOY_PATH'" ;;
esac
if [[ "$DEPLOY_PATH" == "$HOME" || "$DEPLOY_PATH" == "$HOME/" ]]; then
    die "DEPLOY_PATH is this machine's home directory — that is never the remote target"
fi
if [[ "$DEPLOY_PATH" == "$HOME/"* ]]; then
    die "DEPLOY_PATH is '$DEPLOY_PATH' — an unquoted ~ expanded against the *local*
       home. The remote host has a different one. Quote it: '~/posturi.gov2.ro'"
fi

echo "==> Target: ${DEPLOY_HOST}:${DEPLOY_PATH}/"
if [[ $dry_run -eq 1 ]]; then
    echo "==> DRY RUN — nothing will be written"
    do_export=0          # a dry run has no business spending three minutes on 50 MB
fi

# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

if [[ $deploy_code -eq 1 && ! -f "$SCRIPT_DIR/webapp-php/static/app.css" ]]; then
    die "webapp-php/static/app.css is missing — run 'npm run css' first."
fi

if [[ $deploy_data -eq 1 && $do_export -eq 1 ]]; then
    echo "==> Exporting PostgreSQL -> SQLite (active only)..."
    python "$SCRIPT_DIR/export-to-sqlite.py" --active-only --out "$DB_FILE"
fi

# The export enforces its own floors, but --no-export means somebody else built this
# file. Never ship one that cannot be opened or has nothing in it.
if [[ $deploy_data -eq 1 ]]; then
    [[ -f "$DB_FILE" ]] || die "$DB_FILE not found — drop --no-export, or run export-to-sqlite.py"
    if command -v sqlite3 >/dev/null 2>&1; then
        integrity=$(sqlite3 "$DB_FILE" "PRAGMA integrity_check;" 2>&1 | head -n 1)
        [[ "$integrity" == "ok" ]] || die "$DB_FILE fails integrity_check: $integrity"
        rows=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM job_postings;" 2>/dev/null || echo 0)
        [[ "$rows" -gt 0 ]] || die "$DB_FILE has no job_postings — refusing to deploy an empty site"
        built=$(sqlite3 "$DB_FILE" "SELECT built_at FROM build_meta WHERE id=1;" 2>/dev/null || true)
        echo "==> Database: ${rows} postings, built ${built:-unknown}"
    fi
fi

# ---------------------------------------------------------------------------
# Deploy
# ---------------------------------------------------------------------------

rsync_common=(-avz --no-perms --no-owner --no-group --omit-dir-times)
if [[ $dry_run -eq 1 ]]; then rsync_common+=(--dry-run); fi

if [[ $deploy_code -eq 1 ]]; then
    echo "==> Deploying code (PHP, static, .htaccess)"
    # Excluding *.sqlite* also protects it from --delete: the live database is not
    # ours to remove, and assets/ + router.php are development-only.
    rsync "${rsync_common[@]}" --delete \
        --exclude='.DS_Store' \
        --exclude='assets/' \
        --exclude='router.php' \
        --exclude='*.sqlite' \
        --exclude='*.sqlite-wal' \
        --exclude='*.sqlite-shm' \
        "$SCRIPT_DIR/webapp-php/" \
        "${DEPLOY_HOST}:${DEPLOY_PATH}/"
fi

if [[ $deploy_data -eq 1 ]]; then
    echo "==> Deploying database"
    if [[ $dry_run -eq 0 ]]; then
        # Left over from when the export shipped a WAL database. A -wal describing a
        # file that rsync has since replaced reads as "disk image is malformed".
        ssh "$DEPLOY_HOST" \
            "rm -f ${DEPLOY_PATH}/posturi.sqlite-wal ${DEPLOY_PATH}/posturi.sqlite-shm"
    fi
    # No --delete, and no --inplace: rsync writes a temp file and renames it over the
    # target, so a request served mid-transfer still sees the whole previous database.
    rsync "${rsync_common[@]}" \
        "$DB_FILE" \
        "${DEPLOY_HOST}:${DEPLOY_PATH}/posturi.sqlite"
fi

# ---------------------------------------------------------------------------
# Verify
# ---------------------------------------------------------------------------

if [[ $dry_run -eq 0 && -n "$SITE_URL" ]]; then
    echo "==> Checking ${SITE_URL}"
    code=$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 30 "$SITE_URL" || echo "000")
    [[ "$code" == "200" ]] || die "site returned HTTP ${code} after deploy"
    echo "    HTTP 200"
fi

echo "==> Done."

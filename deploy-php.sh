#!/usr/bin/env bash
# Daily rebuild + deploy: PostgreSQL → SQLite → shared host
# Usage: ./deploy-php.sh [user@host] [remote_path]
#
# Examples:
#   ./deploy-php.sh user@example.com public_html
#   DEPLOY_HOST=user@example.com DEPLOY_PATH=public_html ./deploy-php.sh
set -euo pipefail

DEPLOY_HOST="${1:-${DEPLOY_HOST:-}}"
DEPLOY_PATH="${2:-${DEPLOY_PATH:-public_html}}"

if [[ -z "$DEPLOY_HOST" ]]; then
    echo "Usage: $0 user@host [remote_path]"
    echo "   or: DEPLOY_HOST=user@host ./deploy-php.sh"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "==> Exporting PostgreSQL → SQLite (active only)..."
python "$SCRIPT_DIR/export-to-sqlite.py" --active-only --out "$SCRIPT_DIR/webapp-php/posturi.sqlite"

echo "==> Deploying webapp-php/ to ${DEPLOY_HOST}:${DEPLOY_PATH}/"
rsync -avz --delete \
    --exclude='.DS_Store' \
    --no-perms --no-owner --no-group --omit-dir-times \
    "$SCRIPT_DIR/webapp-php/" \
    "${DEPLOY_HOST}:${DEPLOY_PATH}/"

echo "==> Done."

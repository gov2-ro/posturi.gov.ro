#!/usr/bin/env bash
# Pull a backup of the VPS onto this machine (BETA-BACKUP). Run it on the Mac, by
# hand, now and then:
#
#   ops/pull-backup.sh                 # host gov2-1, into ~/posturi-backups
#   POSTURI_VPS=pax@1.2.3.4 ops/pull-backup.sh
#
# Each run writes one dated snapshot:
#
#   ~/posturi-backups/2026-10-25T1930Z/posturi.sql.gz   pg_dump, plain SQL, gzipped
#   ~/posturi-backups/2026-10-25T1930Z/data/...         raw pages, attachments, run log
#   ~/posturi-backups/latest -> 2026-10-25T1930Z
#
# Files unchanged since the previous snapshot are hard links to it (rsync --link-dest),
# so every snapshot is a full, browsable tree but only new files take space. The dump
# is plain SQL rather than -Fc so this machine's older pg_restore/psql can read it.
# Restoring is in docs/deploy-vps.md § 7.
#
# Settings: the environment, else this checkout's .env (read key by key, never sourced).
#   POSTURI_VPS          ssh destination              (default gov2-1, an ~/.ssh/config Host)
#   POSTURI_VPS_DIR      checkout on the VPS          (default /home/pax/g2-dev/posturi.gov.ro)
#   POSTURI_BACKUP_DIR   where snapshots go           (default ~/posturi-backups)
#   POSTURI_BACKUP_KEEP  snapshots kept, newest first (default 8)
#   POSTURI_BACKUP_MIN_DUMP_BYTES  smallest believable dump (default 1000000)
#
# Exit codes: 0 ok, 1 a step failed (the partial snapshot is removed and `latest` is
# untouched), 69 the VPS cannot be reached, 78 bad settings.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
# shellcheck source=ops/env.sh
. "$ROOT/ops/env.sh"

setting() {      # NAME DEFAULT -> environment, else .env, else DEFAULT
    local value="${!1:-}"
    [ -n "$value" ] || value="$(env_value "$1")"
    printf '%s' "${value:-$2}"
}

VPS="$(setting POSTURI_VPS gov2-1)"
VPS_DIR="$(setting POSTURI_VPS_DIR /home/pax/g2-dev/posturi.gov.ro)"
BACKUP_DIR="$(setting POSTURI_BACKUP_DIR "$HOME/posturi-backups")"
KEEP="$(setting POSTURI_BACKUP_KEEP 8)"
MIN_DUMP_BYTES="$(setting POSTURI_BACKUP_MIN_DUMP_BYTES 1000000)"

case "$KEEP" in
    ''|*[!0-9]*|0) echo "ERROR: POSTURI_BACKUP_KEEP must be a whole number of at least 1, not '$KEEP'." >&2; exit 78 ;;
esac
for tool in ssh rsync gzip; do
    command -v "$tool" >/dev/null 2>&1 || { echo "ERROR: $tool not found." >&2; exit 78; }
done

echo "=== posturi pull-backup — $(date -u +%Y-%m-%dT%H:%M:%SZ): $VPS:$VPS_DIR -> $BACKUP_DIR ==="

if ! ssh -o BatchMode=yes -o ConnectTimeout=15 "$VPS" "test -d '$VPS_DIR/data'"; then
    echo "ERROR: cannot reach $VPS (key login), or $VPS_DIR/data is missing there." >&2
    echo "       Add a Host entry to ~/.ssh/config or set POSTURI_VPS=user@address; see docs/deploy-vps.md § 7." >&2
    exit 69
fi

mkdir -p "$BACKUP_DIR"
for stale in "$BACKUP_DIR"/*.partial; do
    [ -e "$stale" ] || continue
    echo "removing an unfinished earlier attempt: $(basename "$stale")"
    rm -rf "$stale"
done

STAMP="$(date -u +%Y-%m-%dT%H%MZ)"
DEST="$BACKUP_DIR/$STAMP"
PARTIAL="$DEST.partial"
[ ! -e "$DEST" ] || { echo "ERROR: $DEST already exists; wait a minute and run again." >&2; exit 1; }
mkdir -p "$PARTIAL/data"

STEP="starting"
finish() {
    local status=$?
    trap - EXIT
    if [ "$status" -ne 0 ]; then
        rm -rf "$PARTIAL"
        echo "FAILED (exit $status) during: $STEP. Nothing kept; 'latest' is unchanged." >&2
    fi
    exit "$status"
}
trap finish EXIT

# -- 1. The database, dumped on the VPS and compressed before it crosses the wire --------
STEP="pg_dump on the VPS"
# The remote side reads DATABASE_URL from its own .env with the same env_value.
ssh -o BatchMode=yes "$VPS" bash -s -- "$VPS_DIR" > "$PARTIAL/posturi.sql.gz" <<'REMOTE'
set -euo pipefail
cd "$1"
ENV_FILE="$PWD/.env"
. ops/env.sh
url="$(env_value DATABASE_URL)"
[ -n "$url" ] || { echo "DATABASE_URL is not set in $PWD/.env" >&2; exit 1; }
pg_dump --no-owner --no-privileges --dbname="$url" | gzip -6
REMOTE

STEP="checking the dump"
dump_bytes="$(wc -c < "$PARTIAL/posturi.sql.gz" | tr -d ' ')"
if [ "$dump_bytes" -lt "$MIN_DUMP_BYTES" ]; then
    echo "ERROR: the dump is only $dump_bytes bytes (minimum $MIN_DUMP_BYTES): wrong database?" >&2
    exit 1
fi
gzip -t "$PARTIAL/posturi.sql.gz"
# Read to the end (no grep -q): an early exit would SIGPIPE gzip and fail the pipeline.
postings_tables="$(gzip -dc "$PARTIAL/posturi.sql.gz" | grep -c '^COPY public\.jobs_jobposting ' || true)"
if [ "$postings_tables" -ne 1 ]; then
    echo "ERROR: the dump has no data for jobs_jobposting." >&2
    exit 1
fi
echo "dump: $dump_bytes bytes, gzip intact, jobs_jobposting present"

# -- 2. The archive the database cannot rebuild ---------------------------------------
STEP="counting files on the VPS"
remote_files="$(ssh -o BatchMode=yes "$VPS" "cd '$VPS_DIR/data' && find anunturi downloads -type f ! -name anunturi.csv ! -name '*.tmp' ! -name '.tmp-*' | wc -l" | tr -d ' ')"

STEP="rsync"
link_dest=()
if [ -d "$BACKUP_DIR/latest/data" ]; then
    link_dest=(--link-dest="$BACKUP_DIR/latest/data")
fi
# Raw pages and sidecars, attachments, the run log, and posturi_gov_ro.csv, whose
# `updates` column is change history nothing else holds. Everything else under data/
# is rebuilt by the pipeline (anunturi.csv, calendar.csv, exports) or is in git.
rsync -az ${link_dest[@]+"${link_dest[@]}"} \
    --exclude='anunturi/anunturi.csv' --exclude='*.tmp' --exclude='.tmp-*' --exclude='.DS_Store' \
    --include='anunturi/***' --include='downloads/***' \
    --include='pipeline-runs.jsonl' --include='posturi_gov_ro.csv' \
    --include='index-scan.json' --include='schema-failures.jsonl' \
    --exclude='*' \
    "$VPS:$VPS_DIR/data/" "$PARTIAL/data/"

STEP="checking the archive"
local_files="$(cd "$PARTIAL/data" && find anunturi downloads -type f 2>/dev/null | wc -l | tr -d ' ')"
echo "archive: $local_files files here, $remote_files on the VPS before the copy"
if [ "$local_files" -lt "$remote_files" ]; then
    echo "ERROR: fewer files arrived than the VPS had." >&2
    exit 1
fi

# -- 3. Publish, then prune ----------------------------------------------------------
STEP="publishing the snapshot"
mv "$PARTIAL" "$DEST"
ln -sfn "$STAMP" "$BACKUP_DIR/latest"

STEP="pruning"
snapshots=()
while IFS= read -r name; do
    snapshots+=("$name")
done < <(cd "$BACKUP_DIR" && find . -maxdepth 1 -type d -name '20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9][0-9][0-9]Z' | sed 's|^\./||' | sort)
excess=$(( ${#snapshots[@]} - KEEP ))
for (( i = 0; i < excess; i++ )); do
    echo "pruning ${snapshots[$i]}"
    rm -rf "${BACKUP_DIR:?}/${snapshots[$i]}"
done

kept=$(( ${#snapshots[@]} < KEEP ? ${#snapshots[@]} : KEEP ))
echo "=== done: $STAMP; $kept snapshot(s) kept; $(du -sh "$BACKUP_DIR" | cut -f1) on disk in all ==="

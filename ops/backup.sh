#!/usr/bin/env bash
# Nightly encrypted off-site backup with restic (BETA-BACKUP). Setup and restore:
# docs/deploy-vps.md, section "7. Backups".
#
# Runs on the VPS from cron. What goes into one snapshot (tag `nightly`):
#   data/.backup-staging/posturi.dump   pg_dump -Fc of the database, taken to a temp file
#                                       here first and deleted when the script ends
#   data/anunturi/                      cached detail HTML + .meta.json sidecars (minus the
#                                       regenerable anunturi.csv)
#   data/downloads/                     downloaded attachments (.doc/.docx/.pdf + manifests)
#   data/pipeline-runs.jsonl            the run log
#   data/posturi_gov_ro.csv             the index; its `updates` column is change history
#   data/index-scan.json, data/schema-failures.jsonl   small evidence files, if present
# Never backed up: .env, the restic password file, venv, logs, exports, regenerable CSVs.
#
# Then `restic forget --prune` (7 daily, 5 weekly, 12 monthly) and `restic check` (the
# repository structure every night, plus a random sample of the data: 1% nightly, 10% on
# Sundays). Repository, password and credentials come from the environment or .env:
#   RESTIC_REPOSITORY, RESTIC_PASSWORD_FILE (or RESTIC_PASSWORD),
#   AWS_ACCESS_KEY_ID + AWS_SECRET_ACCESS_KEY (s3: repository) or B2_ACCOUNT_ID +
#   B2_ACCOUNT_KEY (b2: repository), DATABASE_URL (as the Django app), and optionally
#   BACKUP_HEALTHCHECK_URL, RESTIC_BIN, BACKUP_CHECK_SUBSET, BACKUP_MIN_DUMP_BYTES.
#
# Usage:
#   ops/backup.sh                         the backup (what cron runs)
#   ops/backup.sh --restic <args...>      any restic command with this configuration, e.g.
#                                         `--restic init`, `--restic snapshots --tag nightly`
#
# Exit codes: 0 fine, 3 the snapshot was written but restic could not read every file,
# 75 a previous backup is still running, 78 missing configuration or tool (nothing was
# touched), anything else a failure. Every non-zero exit has already been reported to
# $BACKUP_HEALTHCHECK_URL (/fail) and, when NTFY_TOPIC is set, pushed through ops/ntfy.py
# (a run that never starts, or a cron that never fires, only shows as the healthcheck's
# silence).
#
# Locking: this script has its OWN lock (.backup.lock), deliberately not the pipeline's.
# pg_dump reads one consistent snapshot of the database whatever the pipeline is doing,
# and the files are append-mostly and written by rename, so the dump and the archive
# need not describe the same instant. Sharing .pipeline.lock would let a stuck backup
# make the pipeline skip a slot (exit 75, a false alarm) -- the worse failure. The
# cron slot is 04:40 Bucharest, nine hours clear of both pipeline slots.
set -euo pipefail
umask 077            # the dump holds the whole database; the staging files are 0600

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
SELF="$ROOT/ops/backup.sh"

. "$ROOT/ops/env.sh"
. "$ROOT/ops/backup-env.sh"
ENV_FILE="$ROOT/.env"
backup_load_config

PYTHON="${PYTHON:-$ROOT/.venv/bin/python}"
PG_DUMP="${PG_DUMP:-pg_dump}"
PG_RESTORE="${PG_RESTORE:-pg_restore}"
BACKUP_HEALTHCHECK_URL="${BACKUP_HEALTHCHECK_URL:-}"
# An empty or truncated dump (a wrong DATABASE_URL pointing at a fresh database) must
# not become last night's "good" snapshot. The real dump is over 100 MB.
MIN_DUMP_BYTES="${BACKUP_MIN_DUMP_BYTES:-1048576}"

# The dump goes to a FIXED path. restic picks the parent snapshot (which lets it skip
# re-reading ~2 GB of unchanged files every night) by host + path set, so a random temp
# directory would turn every run into a full re-scan. data/ is gitignored.
STAGING="${BACKUP_STAGING:-$ROOT/data/.backup-staging}"
DUMP="$STAGING/posturi.dump"
DUMP_PARTIAL="$DUMP.partial"

if [ "${1:-}" = "--restic" ]; then
    shift
    backup_check_config || { backup_print_problems; exit 78; }
    exec "$RESTIC" "$@"
fi

# One backup at a time. Re-exec under flock (as run-pipeline.sh does) so the lock holds
# however the script was started. No flock (macOS): no lock.
LOCKFILE="${BACKUP_LOCKFILE:-$ROOT/.backup.lock}"
if [ "${POSTURI_BACKUP_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
    exec env POSTURI_BACKUP_LOCKED=1 flock -n -E 75 "$LOCKFILE" "$SELF" "$@"
fi

STEP="starting"
INCOMPLETE=0

ping_health() {          # $1: "" (success) | /start | /fail
    [ -n "${BACKUP_HEALTHCHECK_URL:-}" ] || return 0
    curl -fsS -m 10 -o /dev/null "${BACKUP_HEALTHCHECK_URL}${1:-}" || true
}

# A free-form push (ops/ntfy.py send): does nothing without NTFY_TOPIC and exits 0 on
# every path of its own; `|| true` keeps even a crash of it from changing our status.
notify_failure() {       # $1: the exit status being reported
    [ -x "$PYTHON" ] || return 0
    "$PYTHON" ops/ntfy.py send --priority=4 --tag=x \
        --title="posturi backup FAILED" \
        --message="Nightly backup failed (exit $1) during: ${STEP}. Last good snapshot: ops/backup.sh --restic snapshots --tag nightly. Log: logs/backup.log" \
        || true
}

# Runs on every way out: success, `exit N`, a failing command under set -e, a signal.
finish() {
    local status=$?
    trap - EXIT
    rm -f "$DUMP" "$DUMP_PARTIAL"
    if [ "$status" -ne 0 ]; then
        echo "FAILED (exit ${status}) during: ${STEP} at $(date -u +%Y-%m-%dT%H:%M:%SZ)" >&2
        ping_health /fail
        notify_failure "$status"
    fi
    exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "=== posturi backup — $(date -u +%Y-%m-%dT%H:%M:%SZ) on $(hostname 2>/dev/null || echo '?') @" \
     "$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo '?') ==="

STEP="checking the configuration"
backup_check_config || true         # fills BACKUP_PROBLEMS; the local checks add to it
for required in data/anunturi data/downloads; do
    [ -d "$ROOT/$required" ] \
        || BACKUP_PROBLEMS+=("$required does not exist under $ROOT: there is nothing to back up there")
done
command -v "$PG_DUMP" >/dev/null 2>&1 \
    || BACKUP_PROBLEMS+=("$PG_DUMP not found: install the PostgreSQL client tools (postgresql-client)")
command -v "$PG_RESTORE" >/dev/null 2>&1 \
    || BACKUP_PROBLEMS+=("$PG_RESTORE not found: install the PostgreSQL client tools (postgresql-client)")
if [ -z "${DATABASE_URL:-}" ] && [ -z "${PGDATABASE:-}" ]; then
    BACKUP_PROBLEMS+=("DATABASE_URL is not set (and no PGDATABASE): pg_dump does not know which database to dump")
fi
if [ "${#BACKUP_PROBLEMS[@]}" -ne 0 ]; then
    backup_print_problems
    echo "ABORT: not configured; nothing was dumped or uploaded." >&2
    exit 78
fi
case "$RESTIC_REPOSITORY" in
    /*|./*|../*) echo "NOTE: RESTIC_REPOSITORY is a local path, so this copy is not off-site." ;;
esac

ping_health /start
"$RESTIC" version

# Fail before the 100 MB dump if the repository cannot be opened: wrong password, a key
# that lost its bucket access, or `restic init` was never run.
STEP="opening the repository"
if ! "$RESTIC" cat config >/dev/null; then
    echo "ABORT: cannot open the restic repository at ${RESTIC_REPOSITORY}." >&2
    echo "       First time? Run: ops/backup.sh --restic init" >&2
    exit 1
fi

STEP="pg_dump"
mkdir -p "$STAGING"
rm -f "$DUMP" "$DUMP_PARTIAL"
dump_args=(--format=custom --file="$DUMP_PARTIAL")
if [ -n "${DATABASE_URL:-}" ]; then
    # A libpq URI: `postgres://pax@/posturi` (socket, peer auth) is valid as it stands.
    # (Visible in `ps` for the seconds pg_dump runs; the box's URL carries no password.)
    dump_args+=(--dbname="$DATABASE_URL")
fi
"$PG_DUMP" "${dump_args[@]}"

STEP="checking the dump"
dump_bytes="$(wc -c < "$DUMP_PARTIAL" | tr -d ' ')"
if [ "$dump_bytes" -lt "$MIN_DUMP_BYTES" ]; then
    echo "ABORT: the dump is only ${dump_bytes} bytes (minimum ${MIN_DUMP_BYTES}): wrong database?" >&2
    exit 1
fi
"$PG_RESTORE" --list "$DUMP_PARTIAL" >/dev/null     # the table of contents must be readable
mv "$DUMP_PARTIAL" "$DUMP"
echo "dump: ${dump_bytes} bytes"

sources=("$DUMP" "$ROOT/data/anunturi" "$ROOT/data/downloads")
for optional in pipeline-runs.jsonl posturi_gov_ro.csv index-scan.json schema-failures.jsonl; do
    if [ -e "$ROOT/data/$optional" ]; then
        sources+=("$ROOT/data/$optional")
    else
        echo "NOTE: data/$optional is absent, not backed up"
    fi
done

STEP="restic backup"
backup_status=0
"$RESTIC" backup --tag nightly \
    --exclude "$ROOT/data/anunturi/anunturi.csv" \
    --exclude '*.tmp' --exclude '.tmp-*' --exclude '.DS_Store' \
    --exclude '.env' --exclude '.env.*' \
    "${sources[@]}" || backup_status=$?
if [ "$backup_status" -eq 3 ]; then
    # A snapshot exists but some source file could not be read. Carry on (retention and
    # the check are still due) and report the run as failed at the end.
    echo "WARNING: restic wrote the snapshot but could not read every file (exit 3)." >&2
    INCOMPLETE=1
elif [ "$backup_status" -ne 0 ]; then
    exit "$backup_status"
fi
rm -f "$DUMP"        # off the disk as soon as it is safe; finish() would do it anyway

# --group-by host,tags: the default groups by host AND path set, so a changed path list
# would start a second retention group. --tag nightly keeps hand-made snapshots (for
# example the one-off upload of an old attachment archive) out of the policy altogether.
STEP="restic forget --prune"
"$RESTIC" forget --tag nightly --group-by host,tags \
    --keep-daily 7 --keep-weekly 5 --keep-monthly 12 --prune

# The structure check is always run: it is the one that notices a damaged index or a key
# that lost its access. The data subset is random, so nights add up: after ~100 nights
# 1% reads cover about two thirds of the repository; Sundays read 10%. A full
# --read-data is left to a hand-run (it downloads the whole repository).
STEP="restic check"
subset="${BACKUP_CHECK_SUBSET:-}"
if [ -z "$subset" ]; then
    if [ "$(date +%u)" = "7" ]; then subset="10%"; else subset="1%"; fi
fi
"$RESTIC" check --read-data-subset="$subset"

STEP="finishing"
"$RESTIC" snapshots --tag nightly --latest 1 --compact || true

if [ "$INCOMPLETE" -eq 1 ]; then
    echo "=== finished with an incomplete snapshot — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
    exit 3
fi
ping_health
echo "=== done — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="

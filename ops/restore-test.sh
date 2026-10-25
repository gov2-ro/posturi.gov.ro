#!/usr/bin/env bash
# Restore test for the restic backup (BETA-BACKUP): a backup nobody has restored is a
# hope, not a backup. Restores the latest `nightly` snapshot into a scratch directory and
# checks it. Nothing is restored into the live database or over the live files.
#
#   * the Postgres dump must exist and `pg_restore --list` must read its table of
#     contents, including the data of the jobs_jobposting table;
#   * data/anunturi and data/downloads are counted (by the rule ops/backup.sh excludes
#     by) in the restored tree and in the live checkout. The archive only grows, so the
#     restored copy may trail the live one by what arrived since the snapshot, but by no
#     more than RESTORE_TOLERANCE_PCT percent (default 2);
#   * data/pipeline-runs.jsonl must be there.
# Prints one line per check and a verdict; exit 0 PASS, 1 FAIL, 78 not configured.
#
# It downloads the whole snapshot (every attachment: a few GB) and needs that much free
# space in the scratch directory (RESTORE_TMPDIR, else $TMPDIR, else /tmp).
#
# Usage:
#   ops/restore-test.sh                   latest nightly snapshot, scratch dir removed after
#   ops/restore-test.sh --snapshot ID     a given snapshot (see: ops/backup.sh --restic snapshots)
#   ops/restore-test.sh --keep            keep the restored tree and print where it is
#
# Same configuration as ops/backup.sh (environment or .env): RESTIC_REPOSITORY, the
# password file and the B2 credentials. A real database drill is in docs/deploy-vps.md.
set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

. "$ROOT/ops/env.sh"
. "$ROOT/ops/backup-env.sh"
ENV_FILE="$ROOT/.env"
backup_load_config

PG_RESTORE="${PG_RESTORE:-pg_restore}"
TOLERANCE_PCT="${RESTORE_TOLERANCE_PCT:-2}"
SNAPSHOT=""
KEEP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --snapshot) SNAPSHOT="${2:?--snapshot needs a snapshot id}"; shift 2 ;;
        --keep) KEEP=1; shift ;;
        -h|--help) sed -n '2,/^set -euo/p' "$0" | sed -e '/^set -euo/d' -e 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown argument: $1 (try --help)" >&2; exit 64 ;;
    esac
done

case "$TOLERANCE_PCT" in
    ''|*[!0-9]*) echo "RESTORE_TOLERANCE_PCT must be a whole number, not '$TOLERANCE_PCT'" >&2; exit 64 ;;
esac

backup_check_config || true
command -v "$PG_RESTORE" >/dev/null 2>&1 \
    || BACKUP_PROBLEMS+=("$PG_RESTORE not found: install the PostgreSQL client tools (postgresql-client)")
if [ "${#BACKUP_PROBLEMS[@]}" -ne 0 ]; then
    backup_print_problems
    echo "ABORT: not configured; nothing was restored." >&2
    exit 78
fi

TARGET="$(mktemp -d "${RESTORE_TMPDIR:-${TMPDIR:-/tmp}}/posturi-restore.XXXXXX")"
cleanup() {
    if [ "$KEEP" -eq 1 ]; then
        echo "kept: $TARGET"
    else
        rm -rf "$TARGET"
    fi
}
trap cleanup EXIT

started="$(date +%s)"
problems=0

report() {               # $1: label  $2: OK|FAIL|NOTE  $3: detail
    printf '%-10s %-5s %s\n' "$1" "$2" "$3"
    if [ "$2" = "FAIL" ]; then problems=$((problems + 1)); fi
}

first_match() {          # find ... | first line, without a SIGPIPE under pipefail
    find "$@" 2>/dev/null | sed -n 1p
}

echo "=== posturi restore test — $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
echo "repository: ${RESTIC_REPOSITORY}"

if [ -n "$SNAPSHOT" ]; then
    restore_args=("$SNAPSHOT")
    "$RESTIC" snapshots "$SNAPSHOT" --compact
else
    restore_args=(latest --tag nightly)
    "$RESTIC" snapshots --tag nightly --latest 1 --compact
fi

if ! "$RESTIC" restore "${restore_args[@]}" --target "$TARGET"; then
    report restore FAIL "restic could not restore ${restore_args[*]}"
    echo "VERDICT: FAIL (${problems} problem)"
    exit 1
fi
report restore OK "into $TARGET"

# --- the database dump: readable, and carrying the data ---------------------------------
dump="$(first_match "$TARGET" -type f -name posturi.dump)"
if [ -z "$dump" ] || [ ! -s "$dump" ]; then
    report dump FAIL "no posturi.dump in the snapshot"
else
    dump_bytes="$(wc -c < "$dump" | tr -d ' ')"
    if toc="$("$PG_RESTORE" --list "$dump" 2>&1)"; then
        entries="$(awk '!/^;/ && NF' <<< "$toc" | wc -l | tr -d ' ')"
        if grep -q 'TABLE DATA public jobs_jobposting ' <<< "$toc"; then
            report dump OK "${dump_bytes} bytes, ${entries} entries, jobs_jobposting data present"
        else
            report dump FAIL "pg_restore reads it (${entries} entries) but jobs_jobposting has no data entry"
        fi
    else
        report dump FAIL "pg_restore --list cannot read it: $(sed -n 1p <<< "$toc")"
    fi
fi

# --- the archives: restored count against the live one ----------------------------------
compare_counts() {       # $1: path under data/
    local name="$1" restored_dir live_dir restored live
    restored_dir="$(first_match "$TARGET" -type d -path "*/data/$name")"
    if [ -z "$restored_dir" ]; then
        report "$name" FAIL "data/$name is not in the snapshot"
        return
    fi
    restored="$(backup_count_files "$restored_dir")"
    live_dir="$ROOT/data/$name"
    if [ "$restored" -eq 0 ]; then
        report "$name" FAIL "the restored data/$name has no files"
    elif [ ! -d "$live_dir" ]; then
        report "$name" NOTE "restored ${restored} files; no live data/$name here to compare with"
    else
        live="$(backup_count_files "$live_dir")"
        if [ $((restored * 100)) -lt $((live * (100 - TOLERANCE_PCT))) ]; then
            report "$name" FAIL "restored ${restored} files, live ${live}: more than ${TOLERANCE_PCT}% missing"
        elif [ "$restored" -gt "$live" ]; then
            report "$name" OK "restored ${restored} files, live ${live} (${restored} - ${live} removed since the snapshot)"
        else
            report "$name" OK "restored ${restored} files, live ${live} ($((live - restored)) newer than the snapshot)"
        fi
    fi
}
compare_counts anunturi
compare_counts downloads

# --- the run log ------------------------------------------------------------------------
runlog="$(first_match "$TARGET" -type f -name pipeline-runs.jsonl)"
if [ -z "$runlog" ] || [ ! -s "$runlog" ]; then
    report runlog FAIL "data/pipeline-runs.jsonl is missing or empty in the snapshot"
else
    restored_lines="$(wc -l < "$runlog" | tr -d ' ')"
    if [ -f "$ROOT/data/pipeline-runs.jsonl" ]; then
        live_lines="$(wc -l < "$ROOT/data/pipeline-runs.jsonl" | tr -d ' ')"
        report runlog OK "${restored_lines} lines, live ${live_lines}"
    else
        report runlog OK "${restored_lines} lines"
    fi
fi

echo "(the dump was listed, not restored into any database; $(( $(date +%s) - started )) s)"
if [ "$problems" -eq 0 ]; then
    echo "VERDICT: PASS"
else
    echo "VERDICT: FAIL (${problems} problem(s))"
    exit 1
fi

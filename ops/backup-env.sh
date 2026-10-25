# shellcheck shell=bash
# Shared configuration for ops/backup.sh and ops/restore-test.sh (BETA-BACKUP). Sourced,
# never executed. The caller sources ops/env.sh first and sets ENV_FILE.
#
# Every setting is read the way run-pipeline.sh reads HEALTHCHECK_URL: a value already in
# the environment wins, otherwise it comes from .env through env_value. Nothing in .env
# is sourced as shell. Secrets are exported for restic and the AWS/B2 SDK to read; they
# are never echoed.

BACKUP_CONFIG_VARS="RESTIC_BIN RESTIC_REPOSITORY RESTIC_PASSWORD_FILE RESTIC_PASSWORD \
AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION B2_ACCOUNT_ID B2_ACCOUNT_KEY \
DATABASE_URL BACKUP_HEALTHCHECK_URL"

backup_load_var() {      # $1: NAME -> exported from the environment, else from .env
    local name="$1" value
    value="${!name:-}"
    [ -n "$value" ] || value="$(env_value "$name")"
    if [ -n "$value" ]; then
        export "$name=$value"
    fi
}

backup_load_config() {
    local name
    for name in $BACKUP_CONFIG_VARS; do
        backup_load_var "$name"
    done
    RESTIC="${RESTIC_BIN:-restic}"
}

# backup_check_config: print every missing piece of restic configuration and return 1,
# or return 0. Credentials are required per repository scheme: an `s3:` repository
# (Backblaze B2's S3-compatible endpoint) needs AWS_*, a native `b2:` one needs B2_*,
# anything else (a local path, sftp:, rest:) brings its own authentication.
backup_check_config() {
    BACKUP_PROBLEMS=()

    if ! command -v "$RESTIC" >/dev/null 2>&1; then
        BACKUP_PROBLEMS+=("restic not found (looked for '$RESTIC'): install it, or set RESTIC_BIN=/path/to/restic in .env")
    fi

    if [ -z "${RESTIC_REPOSITORY:-}" ]; then
        BACKUP_PROBLEMS+=("RESTIC_REPOSITORY is not set (e.g. s3:https://s3.<region>.backblazeb2.com/<bucket>/posturi)")
    fi

    if [ -n "${RESTIC_PASSWORD_FILE:-}" ]; then
        if [ ! -r "$RESTIC_PASSWORD_FILE" ]; then
            BACKUP_PROBLEMS+=("RESTIC_PASSWORD_FILE ($RESTIC_PASSWORD_FILE) does not exist or is not readable")
        elif [ ! -s "$RESTIC_PASSWORD_FILE" ]; then
            BACKUP_PROBLEMS+=("RESTIC_PASSWORD_FILE ($RESTIC_PASSWORD_FILE) is empty")
        fi
    elif [ -z "${RESTIC_PASSWORD:-}" ]; then
        BACKUP_PROBLEMS+=("neither RESTIC_PASSWORD_FILE nor RESTIC_PASSWORD is set: the repository cannot be opened")
    fi

    case "${RESTIC_REPOSITORY:-}" in
        s3:*)
            [ -n "${AWS_ACCESS_KEY_ID:-}" ] \
                || BACKUP_PROBLEMS+=("AWS_ACCESS_KEY_ID is not set (the B2 application key's keyID; RESTIC_REPOSITORY is an s3: repository)")
            [ -n "${AWS_SECRET_ACCESS_KEY:-}" ] \
                || BACKUP_PROBLEMS+=("AWS_SECRET_ACCESS_KEY is not set (the B2 applicationKey; RESTIC_REPOSITORY is an s3: repository)")
            ;;
        b2:*)
            [ -n "${B2_ACCOUNT_ID:-}" ] \
                || BACKUP_PROBLEMS+=("B2_ACCOUNT_ID is not set (RESTIC_REPOSITORY is a b2: repository)")
            [ -n "${B2_ACCOUNT_KEY:-}" ] \
                || BACKUP_PROBLEMS+=("B2_ACCOUNT_KEY is not set (RESTIC_REPOSITORY is a b2: repository)")
            ;;
    esac

    [ "${#BACKUP_PROBLEMS[@]}" -eq 0 ]
}

backup_print_problems() {
    local problem
    for problem in "${BACKUP_PROBLEMS[@]}"; do
        echo "ERROR: $problem" >&2
    done
    echo "       Setup: docs/deploy-vps.md, section \"7. Backups\"." >&2
}

# backup_count_files DIR: regular files under DIR that a backup carries. Mirrors the
# --exclude list in ops/backup.sh (half-written *.tmp / .tmp-* files, .DS_Store, and
# anunturi.csv, which parse-anunturi.py regenerates), so a restored tree and the live
# one are counted by the same rule.
backup_count_files() {
    find "$1" -type f ! -name '*.tmp' ! -name '.tmp-*' ! -name '.DS_Store' \
        ! -name 'anunturi.csv' 2>/dev/null | wc -l | tr -d ' '
}

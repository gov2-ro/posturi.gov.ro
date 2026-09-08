# Shared .env reader, sourced by deploy-php.sh and ops/run-pipeline.sh.
#
# .env holds API keys and may hold inline comments, so it is read key by key rather
# than sourced -- nothing in it should ever be interpreted as shell. Python-side
# entry points load the same file through python-dotenv (see pipeline.py::_load_env).

# env_value KEY [ENV_FILE] -> the value, or nothing.
env_value() {
    local key="$1"
    local file="${2:-${ENV_FILE:-$PWD/.env}}"
    [ -f "$file" ] || return 0
    sed -n "s/^[[:space:]]*${key}=//p" "$file" \
        | tail -n 1 \
        | sed -e 's/[[:space:]]*#.*$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
              -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" \
        | tr -d '\r'
}

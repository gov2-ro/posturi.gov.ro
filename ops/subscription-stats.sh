#!/usr/bin/env bash
# Read-only counts of the email alert subscriptions on the shared host.
# Prints counts only. It never decrypts or prints an email address.
# Usage: ops/subscription-stats.sh        (run on the VPS; reads DEPLOY_HOST from .env)
set -euo pipefail
cd "$(dirname "$0")/.."
. ops/env.sh
HOST="${DEPLOY_HOST:-$(env_value DEPLOY_HOST)}"
[[ -n "$HOST" ]] || { echo "DEPLOY_HOST not set" >&2; exit 1; }
# The private directory defaults to ~/posturi-private (deploy-php.sh ALERTS_DIR).
ssh -o BatchMode=yes -o ConnectTimeout=15 "$HOST" 'sqlite3 -readonly -header ~/posturi-private/subscriptions.sqlite "
select count(*) subscriptions, count(distinct email_index) emails, min(created_at) first_created, max(created_at) last_created from subscriptions;
select status, count(*) subs, count(distinct email_index) emails from subscriptions group by status;
select n subs_per_email, count(*) emails from (select email_index, count(*) n from subscriptions group by email_index) group by n order by n;
select count(*) confirmed_with_digest from subscriptions where status=\"confirmed\" and last_digest_at is not null;
select count(*) outbox_waiting from outbox;
select count(*) pending_older_than_7_days from subscriptions where status=\"pending\" and created_at < datetime(\"now\",\"-7 day\");
"'

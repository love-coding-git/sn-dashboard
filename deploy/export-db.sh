#!/usr/bin/env bash
# Dump the dashboard database for the server (run where the history lives, from the app folder):
#   bash deploy/export-db.sh  ->  storage/app/sn_dashboard.sql.gz
# Sessions, cache entries and queued jobs are left out (structure only): they belong to this machine's running copy.
set -euo pipefail
cd "$(dirname "$0")/.."
env_val() { grep "^$1=" .env | head -1 | cut -d= -f2- | sed 's/^"//;s/"$//'; }
DB=$(env_val DB_DATABASE); USER=$(env_val DB_USERNAME); HOST=$(env_val DB_HOST)
OUT=storage/app/sn_dashboard.sql.gz
CNF=$(mktemp); chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$USER" "$(env_val DB_PASSWORD)" "${HOST:-127.0.0.1}" > "$CNF"
SKIP=(sessions cache cache_locks jobs job_batches failed_jobs)
IGNORE=(); for t in "${SKIP[@]}"; do IGNORE+=(--ignore-table="$DB.$t"); done
{
    mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces --routines "${IGNORE[@]}" "$DB"
    mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces --no-data "$DB" "${SKIP[@]}"
} | gzip -9 > "$OUT"
ls -la "$OUT"

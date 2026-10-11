#!/usr/bin/env bash
# Nightly backup for Hostinger: the database plus the private uploads (payment proofs, signatures, signed
# contracts) and .env. Backups go to ~/backups, outside public_html, and are kept for 30 days.
# Cron (hPanel → Advanced → Cron Jobs), daily at 02:30:
#   30 2 * * * bash ~/public_html/scripts/backup.sh >> ~/backups/backup.log 2>&1
# Copy ~/backups off the server regularly (download it, or sync it to Google Drive/S3): a backup that only
# lives on the same server does not survive losing that server.
set -euo pipefail

APP="${APP_DIR:-$HOME/public_html}"
DEST="${BACKUP_DIR:-$HOME/backups}"
STAMP="$(date +%F-%H%M)"
mkdir -p "$DEST"
chmod 700 "$DEST"

# Read the database settings from .env without executing it.
env_value() { grep -E "^$1=" "$APP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"
DB_NAME="$(env_value DB_DATABASE)"; DB_USER="$(env_value DB_USERNAME)"; DB_PASS="$(env_value DB_PASSWORD)"

# The password goes through a temporary option file so it never appears in the process list.
CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$DB_USER" "$DB_PASS" "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" > "$CNF"

mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces --routines "$DB_NAME" | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/files-$STAMP.tgz" -C "$APP" storage/app/private .env
chmod 600 "$DEST/db-$STAMP.sql.gz" "$DEST/files-$STAMP.tgz"

find "$DEST" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'files-*.tgz' \) -mtime +30 -delete
echo "$(date '+%F %T') backup ok: db-$STAMP.sql.gz files-$STAMP.tgz"

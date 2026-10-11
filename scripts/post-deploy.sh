#!/usr/bin/env bash
# Finishes a Hostinger Git auto-deploy. Hostinger only pulls the new commit; this script, run by cron every
# minute, notices the new commit and does the rest:
#   - installs PHP packages when composer.lock changed
#   - when migrations are pending: maintenance mode, database + uploads backup, migrate, back up
#   - clears and rebuilds Laravel's caches (stale cached routes/config/views break pages after a pull)
# When nothing changed it exits immediately, so running it every minute costs nothing.
#
# Cron (hPanel → Advanced → Cron Jobs), every minute:
#   * * * * * bash ~/public_html/scripts/post-deploy.sh >> ~/public_html/storage/logs/deploy.log 2>&1
set -euo pipefail
cd "$(dirname "$0")/.."

STATE=storage/app/deployed-commit
HEAD="$(git rev-parse HEAD)"
PREV="$(cat "$STATE" 2>/dev/null || true)"
[[ "$PREV" == "$HEAD" ]] && exit 0

# One run at a time (a slow deploy must not be started again by the next minute's cron). A lock older than
# 15 minutes is treated as left over from a crash.
LOCK=storage/app/post-deploy.lock
if ! mkdir "$LOCK" 2>/dev/null; then
    if [[ -n "$(find "$LOCK" -maxdepth 0 -mmin +15 2>/dev/null)" ]]; then rmdir "$LOCK" && mkdir "$LOCK"; else exit 0; fi
fi
trap 'rmdir "$LOCK" 2>/dev/null || true' EXIT

echo "$(date '+%F %T') deploying $(git rev-parse --short HEAD): $(git log -1 --pretty=%s) (was ${PREV:0:7})"

COMPOSER="$(command -v composer || echo /usr/local/bin/composer)"
[[ "$COMPOSER" == *.phar || "$COMPOSER" == /usr/local/bin/composer ]] && COMPOSER="php $COMPOSER"

# PHP packages: when vendor/ is missing or composer.lock changed since the last deploy.
if [[ ! -f vendor/autoload.php ]] || { [[ -n "$PREV" ]] && ! git diff --quiet "$PREV" "$HEAD" -- composer.lock 2>/dev/null; }; then
    echo "  installing PHP packages"
    $COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet
fi

# Database: only when there is something to migrate, with a backup first and visitors shown "back soon".
PENDING="$(php artisan migrate:status --pending 2>&1 || true)"
if grep -qE 'Pending|Migration table not found' <<< "$PENDING"; then
    echo "  migrations pending: backing up, then migrating"
    php artisan down --retry=15 || true
    trap 'php artisan up >/dev/null 2>&1 || true; rmdir "$LOCK" 2>/dev/null || true' EXIT
    [[ "${SKIP_BACKUP:-0}" == "1" ]] || APP_DIR="$PWD" bash scripts/backup.sh
    php artisan migrate --force
    php artisan up
fi

php artisan optimize:clear >/dev/null
php artisan optimize >/dev/null

[[ -n "$PREV" ]] && echo "$PREV" > storage/app/last-deploy
echo "$HEAD" > "$STATE"
echo "$(date '+%F %T') done"

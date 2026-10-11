#!/usr/bin/env bash
# Put the code back to the version that was live before the last scripts/deploy.sh run.
# NOT for Hostinger Git auto-deploy: it pulls and resets the code itself. With auto-deploy use scripts/post-deploy.sh (cron).
# Database migrations are NOT undone automatically: if the bad deploy added migrations, check
# `php artisan migrate:status` and, if needed, restore the database backup deploy.sh took (~/backups/db-*.sql.gz).
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f storage/app/last-deploy ]] || { echo "No previous version recorded (storage/app/last-deploy)."; exit 1; }

COMPOSER="$(command -v composer || echo /usr/local/bin/composer)"
[[ "$COMPOSER" == *.phar || "$COMPOSER" == /usr/local/bin/composer ]] && COMPOSER="php $COMPOSER"

PREV="$(cat storage/app/last-deploy)"
php artisan down --retry=15 || true
trap 'php artisan up' EXIT
git reset --hard "$PREV"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan optimize:clear
php artisan optimize
echo "Rolled back to $(git rev-parse --short HEAD). Check: php artisan migrate:status"

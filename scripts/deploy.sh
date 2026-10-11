#!/usr/bin/env bash
# Update the live site after pushing to GitHub. Run on the server:  bash ~/public_html/scripts/deploy.sh
#  1. maintenance mode (visitors see a short "back soon" page instead of half-updated code)
#  2. records the current version for scripts/rollback.sh, then backs up the database and uploads
#  3. pulls the pushed code exactly (any edits made directly on the server are discarded; .env and uploads are not tracked, so they are kept)
#  4. installs PHP packages, runs migrations, rebuilds caches, and brings the site back up
set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSER="$(command -v composer || echo /usr/local/bin/composer)"
[[ "$COMPOSER" == *.phar || "$COMPOSER" == /usr/local/bin/composer ]] && COMPOSER="php $COMPOSER"

php artisan down --retry=15 || true
trap 'php artisan up' EXIT

git rev-parse HEAD > storage/app/last-deploy
echo "Previous version: $(cat storage/app/last-deploy)"

if [[ "${SKIP_BACKUP:-0}" != "1" ]]; then
    APP_DIR="$PWD" bash scripts/backup.sh
fi

git fetch --quiet origin main
git reset --hard origin/main
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

echo "Deployed $(git rev-parse --short HEAD): $(git log -1 --pretty=%s)"

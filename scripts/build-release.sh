#!/usr/bin/env bash
# Build an upload-ready copy of the portal for Hostinger (public_html of portal.codecanvastt.com).
# Output: release/portal.codecanvastt.com.zip  — see DEPLOY.md §2a for what to do with it.
set -euo pipefail
cd "$(dirname "$0")/.."

NAME=portal.codecanvastt.com
STAGE="release/$NAME"
ZIP="release/$NAME.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

# 1. Copy every git-tracked file (working-tree versions), which already excludes vendor,
#    node_modules, .env, the SQLite database and logs. public/build is tracked, so no Node needed.
git ls-files -z | tar --null -T - -cf - | tar -xf - -C "$STAGE"

# 2. Drop development-only files.
(cd "$STAGE" && rm -rf tests docs scripts resources/css resources/js \
    phpunit.xml phpstan.neon .editorconfig .gitattributes .npmrc \
    package.json package-lock.json vite.config.js .env.example README.md)

# 3. Production vendor/ (also regenerates bootstrap/cache/packages.php without dev packages).
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --no-interaction --quiet

# 4. Production .env with a fresh APP_KEY. DB_PASSWORD and MAIL_PASSWORD stay blank on purpose.
cp "$STAGE/.env.hostinger.example" "$STAGE/.env"
php "$STAGE/artisan" key:generate --force --no-interaction --quiet

# 5. Sanity check: config compiles and routes register, then remove the cached files
#    (they contain this machine's absolute paths).
php "$STAGE/artisan" config:cache --quiet
php "$STAGE/artisan" route:cache --quiet
php "$STAGE/artisan" config:clear --quiet && php "$STAGE/artisan" route:clear --quiet
rm -f "$STAGE"/bootstrap/cache/{config,routes-v7,routes}.php "$STAGE"/storage/logs/*.log
find "$STAGE" -name .DS_Store -delete
chmod -R ug+rwx "$STAGE/storage" "$STAGE/bootstrap/cache"

# 6. Zip (includes dotfiles: .htaccess and .env).
(cd "$STAGE" && zip -rqX "../$NAME.zip" .)

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1)) from $(git rev-parse --short HEAD)"

#!/bin/bash
# Deploy the backend to the Hostinger account (lugha.iabdallah.com). Run from backend/:  ./deploy/hostinger.sh
#
# Layout on the host (same pattern as the account's other Laravel apps):
#   ~/domains/iabdallah.com/lugha_app            the app (outside the web root; .env, storage/ and bin/ live only here)
#   ~/domains/iabdallah.com/public_html/lugha -> lugha_app/public   (the subdomain's document root)
# The subdomain runs PHP 8.4 through an AddHandler line added to public/.htaccess below (the account default is 8.3).
set -euo pipefail

HOST=${LUGHA_HOST:-u115767235@72.62.184.50}
PORT=${LUGHA_PORT:-65002}
KEY=${LUGHA_KEY:-$HOME/.ssh/blooming}
APP=domains/iabdallah.com/lugha_app
PHP=/opt/alt/php84/usr/bin/php
SSH=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes "$HOST")
PKG_BIN=../engine/target/linux/x86_64-unknown-linux-musl/release/lugha-pkg

cd "$(dirname "$0")/.."

echo "› building assets"
npm run build >/dev/null

echo "› uploading code"
rsync -az --delete -e "ssh -i $KEY -p $PORT" \
  --exclude='.env' --exclude='.env.*' --exclude='/vendor/' --exclude='/node_modules/' --exclude='/storage/' \
  --exclude='/bin/' --exclude='/database/*.sqlite' --exclude='/public/hot' --exclude='/public/storage' \
  --exclude='/bootstrap/cache/*.php' --exclude='.phpunit*' --exclude='/tests/' --exclude='/deploy/' \
  --exclude='/public/tv.apk' --exclude='/public/windows.exe' --exclude='/public/mac.dmg' \
  --exclude='.git' --exclude='CLAUDE.md' --exclude='AGENTS.md' --exclude='.DS_Store' \
  ./ "$HOST:$APP/"

if [[ -f $PKG_BIN ]]; then
  echo "› uploading the package builder (lugha-pkg, linux-musl)"
  rsync -az -e "ssh -i $KEY -p $PORT" "$PKG_BIN" "$HOST:$APP/bin/lugha-pkg"
fi

echo "› install, migrate, cache"
"${SSH[@]}" "cd $APP && \
  grep -q 'alt-php84' public/.htaccess || sed -i '1i AddHandler application/x-httpd-alt-php84___lsphp .php' public/.htaccess; \
  $PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q && \
  $PHP artisan down --retry=15 >/dev/null || true; \
  $PHP artisan migrate --force && \
  $PHP artisan optimize && $PHP artisan filament:optimize >/dev/null && \
  $PHP artisan queue:restart >/dev/null && \
  $PHP artisan up"

echo "✓ deployed: https://lugha.iabdallah.com"

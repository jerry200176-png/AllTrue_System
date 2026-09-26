#!/bin/sh
# Staging-only entrypoint. Must NEVER touch a host Git checkout.
# Syncs immutable image contents (/opt/alltrue-src) into the named volume at
# /var/www, then prepares writable storage + bootstrap/cache mounts only.
set -e

echo "AllTrue staging — container starting (immutable image → named volume)"

if [ ! -d /opt/alltrue-src ]; then
  echo "ERROR: missing /opt/alltrue-src in image" >&2
  exit 2
fi

mkdir -p /var/www

# Seed named volume from baked image. Exclude writable mounts so storage
# volume data survives restarts. Never rsync onto a host bind mount.
rsync -a --delete \
  --exclude 'storage/' \
  --exclude 'bootstrap/cache/' \
  /opt/alltrue-src/ /var/www/

mkdir -p \
  /var/www/storage/app/public \
  /var/www/storage/framework/cache/data \
  /var/www/storage/framework/sessions \
  /var/www/storage/framework/views \
  /var/www/storage/logs \
  /var/www/bootstrap/cache

chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

if [ ! -f /var/www/.env ]; then
  echo "Creating .env from environment..."
  env | grep -E '^(APP_|DB_|LOG_|SESSION_|SANCTUM_|CACHE_|QUEUE_|MAIL_|BROADCAST_)' | sort > /var/www/.env
fi
if ! grep -q '^LOG_CHANNEL=' /var/www/.env 2>/dev/null; then
  echo 'LOG_CHANNEL=stack' >> /var/www/.env
fi

cd /var/www

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
  echo "Generating application key..."
  php artisan key:generate --force
fi

echo "Caching config & routes..."
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true

echo "Staging ready — handing off to $*"
exec "$@"

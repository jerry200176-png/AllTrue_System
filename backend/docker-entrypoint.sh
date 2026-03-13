#!/bin/sh
set -e

echo "🚀 AllTrue — Container starting..."

# ── Sync code from build image to shared volume ─────────────────────
echo "📦 Syncing application code..."
cp -rf /app-src/. /var/www/

# ── Ensure Laravel directories exist ────────────────────────────────
mkdir -p \
    /var/www/storage/app/public \
    /var/www/storage/framework/cache/data \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs \
    /var/www/bootstrap/cache

# ── Set permissions (chown may fail on some hosts; chmod still applied) ──
chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# ── Ensure .env exists; prefer mounted .env, else create from env ──
if [ ! -f /var/www/.env ]; then
    echo "📄 Creating .env from environment..."
    env | grep -E '^(APP_|DB_|LOG_|SESSION_|SANCTUM_|CACHE_|QUEUE_|MAIL_|BROADCAST_)' | sort > /var/www/.env
fi
# Ensure LOG_CHANNEL is set so Laravel does not resolve to empty
if ! grep -q '^LOG_CHANNEL=' /var/www/.env 2>/dev/null; then
    echo 'LOG_CHANNEL=stack' >> /var/www/.env
fi

# ── Generate APP_KEY if not set ─────────────────────────────────────
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "🔑 Generating application key..."
    php artisan key:generate --force
fi

# ── Laravel optimisation cache (non-fatal so PHP-FPM still starts) ───
echo "⚡ Caching config & routes..."
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true

echo "✅ Ready — handing off to $*"
exec "$@"

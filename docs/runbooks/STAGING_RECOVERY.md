# Staging recovery basics (Dell / dedicated host)

Staging-local only. This does **not** implement production backup replication
or production cutover to Dell.

Paths assume:

- checkout: `/home/staging/AllTrue_System`
- DB: `AllTrue_staging`
- DB user: `atr_staging`@`localhost`

## Redeploy the same SHA

```bash
cd /home/staging/AllTrue_System
WANT=$(git rev-parse HEAD)   # or paste the known 40-char SHA
git fetch origin
git checkout -f "$WANT"
cd backend
composer install --no-interaction --prefer-dist --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
cd ../frontend
npm ci
npm run build
sudo systemctl reload php8.2-fpm apache2
curl -fsS http://127.0.0.1/api/v1/health
# Confirm version.json / deployment.json build_sha == $WANT
```

## Rollback to a previous known-good SHA

```bash
cd /home/staging/AllTrue_System
PREV=<40-char-known-good-sha>
git fetch origin
git checkout -f "$PREV"
cd backend
composer install --no-interaction --prefer-dist --no-dev
# Prefer migrate rollback only when you know the migration boundary.
# Otherwise restore the matching staging DB dump taken before the bad deploy.
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
cd ../frontend
npm ci
npm run build
sudo systemctl reload php8.2-fpm apache2
curl -fsS http://127.0.0.1/api/v1/health
```

Record the previous SHA in the operator log before every staging deploy.

## MariaDB dump (staging only)

```bash
ts=$(date -u +%Y%m%dT%H%M%SZ)
out=/home/staging/backups/AllTrue_staging_${ts}.sql.gz
mkdir -p /home/staging/backups
mysqldump -u atr_staging -p AllTrue_staging | gzip -c > "$out"
ls -lh "$out"
```

Keep dumps on the staging host (or encrypted offline storage). Never load a
staging dump into production.

## Restore staging DB

```bash
# Stop writers briefly (Apache) if you need a clean restore window.
sudo systemctl stop apache2
gunzip -c /home/staging/backups/AllTrue_staging_<ts>.sql.gz \
  | mysql -u atr_staging -p AllTrue_staging
sudo systemctl start apache2
sudo systemctl reload php8.2-fpm
curl -fsS http://127.0.0.1/api/v1/health
```

## TrueFit flag roll-off

After Slice 0 acceptance (or on failure):

1. Set `TRUEFIT_V1=false` in staging `backend/.env`
2. Rebuild frontend with `VITE_TRUEFIT_V1` unset/false
3. `php artisan config:cache` and reload PHP-FPM/Apache
4. Confirm `/#/truefit` is gated off and normal product routes still work

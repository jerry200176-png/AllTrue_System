#!/usr/bin/env bash
# DRAFT — proposed path: scripts/agreed-fee-isolated-stack.sh
# Boots AllTrue_test → seed → PHP router :8000 → Vite fixture :5179
# Does NOT flip production flag file. Refuses wrong DB name.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# When installed as scripts/*.sh, ROOT is repo root:
if [[ -d "$ROOT/backend" ]]; then :; else ROOT="$(cd "$(dirname "$0")/.." && pwd)"; fi

: "${DB_DATABASE:=AllTrue_test}"
: "${DB_HOST:=127.0.0.1}"
: "${DB_USERNAME:=admin}"
: "${DB_PASSWORD:=ci-ephemeral}"

if [[ "$DB_DATABASE" != "AllTrue_test" ]]; then
  echo "refusing DB_DATABASE=$DB_DATABASE" >&2
  exit 90
fi

export DB_CONNECTION=mysql DB_HOST DB_PORT="${DB_PORT:-3306}" DB_DATABASE DB_USERNAME DB_PASSWORD

cd "$ROOT/backend"
php artisan migrate --force --database=mysql

# After copy into tests/Support/:
#   php tests/Support/agreed-fee-isolated-seed.php
#   php -S 127.0.0.1:8000 tests/Support/agreed-fee-isolated-router.php &
# For draft preview from docs/plans:
php "$ROOT/docs/plans/agreed-fee-isolated-fullstack/agreed-fee-isolated-seed.proposed.php"
php -S 127.0.0.1:8000 "$ROOT/docs/plans/agreed-fee-isolated-fullstack/agreed-fee-isolated-router.proposed.php" \
  > /tmp/agreed-fee-api.log 2>&1 &
echo $! > /tmp/agreed-fee-api.pid

cd "$ROOT/frontend"
# Fixture page to be added with the Playwright proposed config (Vite on 5179).
# npx vite --config vite.agreed-fee-isolated.proposed.config.js &
echo "API http://127.0.0.1:8000  (pid $(cat /tmp/agreed-fee-api.pid))"
echo "source /tmp/agreed-fee-isolated.env before Playwright"
echo "DRAFT NOTE: Vite fixture + Playwright config still proposed — see sibling *.proposed.*"

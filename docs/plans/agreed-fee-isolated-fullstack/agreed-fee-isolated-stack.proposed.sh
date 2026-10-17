#!/usr/bin/env bash
# DRAFT — proposed install path: scripts/agreed-fee-isolated-stack.sh
#
# Order (hard):
#   1) db-guard (name+loopback+no DATABASE_URL/socket)
#   2) migrate
#   3) seed (chmod 0600 env; no token on stdout beyond redacted notice)
#   4) PHP router :8000 (APP_KEY process-local)
#   5) Vite fixture :5179 (same-origin /api proxy → :8000)
#
# Does NOT flip config/billing.php. Does NOT use SMOKE_*.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# When still invoked from docs/plans/agreed-fee-isolated-fullstack/:
if [[ ! -d "$ROOT/backend" ]]; then
  ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
fi
DRAFT_DIR="$ROOT/docs/plans/agreed-fee-isolated-fullstack"
SUPPORT="$ROOT/backend/tests/Support"
FRONT_FIXTURE="$ROOT/frontend/e2e/fixtures/agreed-fee-isolated"

: "${DB_DATABASE:=AllTrue_test}"
: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USERNAME:=admin}"
: "${DB_PASSWORD:=ci-ephemeral}"
export DB_CONNECTION=mysql DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
# Fail closed if a caller exported overrides that bypass mysql env:
unset DATABASE_URL DB_URL DB_SOCKET DB_READ_HOST DB_WRITE_HOST || true

# --- install proposed files into runnable paths (idempotent copy for local dry-run) ---
install_draft() {
  mkdir -p "$SUPPORT" "$FRONT_FIXTURE" "$ROOT/frontend"
  cp -f "$DRAFT_DIR/db-guard.proposed.php" "$SUPPORT/agreed-fee-isolated-db-guard.php"
  cp -f "$DRAFT_DIR/db-guard.proposed.sh" "$ROOT/scripts/agreed-fee-isolated-db-guard.sh"
  cp -f "$DRAFT_DIR/agreed-fee-isolated-router.proposed.php" "$SUPPORT/agreed-fee-isolated-router.php"
  cp -f "$DRAFT_DIR/agreed-fee-isolated-seed.proposed.php" "$SUPPORT/agreed-fee-isolated-seed.php"
  cp -f "$DRAFT_DIR/fixture/agreed-fee-isolated.html.proposed" "$FRONT_FIXTURE/agreed-fee-isolated.html"
  cp -f "$DRAFT_DIR/fixture/agreed-fee-isolated-mount.proposed.js" "$FRONT_FIXTURE/agreed-fee-isolated-mount.js"
  cp -f "$DRAFT_DIR/vite.agreed-fee-isolated.proposed.config.js" "$ROOT/frontend/vite.agreed-fee-isolated.config.js"
  cp -f "$DRAFT_DIR/playwright.agreed-fee-isolated.proposed.config.js" "$ROOT/frontend/playwright.agreed-fee-isolated.config.js"
  cp -f "$DRAFT_DIR/agreed-fee-isolated-fullstack.proposed.spec.js" "$ROOT/frontend/e2e/agreed-fee-isolated-fullstack.spec.js"
  chmod +x "$ROOT/scripts/agreed-fee-isolated-db-guard.sh"
}

scrub_logs() {
  if [[ -f /tmp/agreed-fee-api.log ]]; then
    sed -i -E 's/Bearer [A-Za-z0-9_-]+/Bearer [REDACTED]/g; s/AGREED_FEE_ISOLATED_TOKEN=.*/AGREED_FEE_ISOLATED_TOKEN=[REDACTED]/' /tmp/agreed-fee-api.log || true
  fi
}
stop_servers() {
  if [[ -f /tmp/agreed-fee-api.pid ]]; then kill "$(cat /tmp/agreed-fee-api.pid)" 2>/dev/null || true; rm -f /tmp/agreed-fee-api.pid; fi
  if [[ -f /tmp/agreed-fee-vite.pid ]]; then kill "$(cat /tmp/agreed-fee-vite.pid)" 2>/dev/null || true; rm -f /tmp/agreed-fee-vite.pid; fi
  scrub_logs
}
remove_token_env() {
  # Never upload /tmp/agreed-fee-isolated.env as a CI artifact.
  rm -f /tmp/agreed-fee-isolated.env
}
# When EXTERNAL=1, leave servers up for the caller (Playwright); caller must stop_servers + rm env.
if [[ "${AGREED_FEE_STACK_EXTERNAL:-0}" != "1" ]]; then
  trap 'stop_servers; remove_token_env' EXIT
fi

mkdir -p "$ROOT/scripts"
install_draft
bash "$ROOT/scripts/agreed-fee-isolated-db-guard.sh"

cd "$ROOT/backend"
php artisan migrate --force --database=mysql
php tests/Support/agreed-fee-isolated-seed.php

php -S 127.0.0.1:8000 tests/Support/agreed-fee-isolated-router.php \
  > /tmp/agreed-fee-api.log 2>&1 &
echo $! > /tmp/agreed-fee-api.pid

cd "$ROOT/frontend"
npx vite --config vite.agreed-fee-isolated.config.js \
  > /tmp/agreed-fee-vite.log 2>&1 &
echo $! > /tmp/agreed-fee-vite.pid

for i in $(seq 1 60); do
  if curl -sf -o /dev/null "http://127.0.0.1:5179/agreed-fee-isolated.html"; then
    break
  fi
  sleep 0.5
done

echo "stack: API :8000 Vite :5179 ready (env /tmp/agreed-fee-isolated.env mode 0600 — do not cat/log/upload)"
if [[ "${AGREED_FEE_STACK_HOLD:-0}" == "1" ]]; then
  wait
fi

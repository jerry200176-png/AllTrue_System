#!/usr/bin/env bash
# DRAFT — install as: scripts/agreed-fee-isolated-stack.sh
# Order: env guard → same-process guarded migrate → seed → API+Vite → ready BOTH or fail.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
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
export AGREED_FEE_BACKEND_ROOT="$ROOT/backend"
unset DATABASE_URL DB_URL DB_SOCKET DB_READ_HOST DB_WRITE_HOST || true

install_draft() {
  mkdir -p "$SUPPORT" "$FRONT_FIXTURE" "$ROOT/frontend" "$ROOT/scripts" "$ROOT/frontend/e2e"
  cp -f "$DRAFT_DIR/db-guard.proposed.php" "$SUPPORT/agreed-fee-isolated-db-guard.php"
  cp -f "$DRAFT_DIR/db-guard.proposed.sh" "$ROOT/scripts/agreed-fee-isolated-db-guard.sh"
  cp -f "$DRAFT_DIR/agreed-fee-isolated-migrate.proposed.php" "$SUPPORT/agreed-fee-isolated-migrate.php"
  cp -f "$DRAFT_DIR/agreed-fee-isolated-router.proposed.php" "$SUPPORT/agreed-fee-isolated-router.php"
  cp -f "$DRAFT_DIR/agreed-fee-isolated-seed.proposed.php" "$SUPPORT/agreed-fee-isolated-seed.php"
  cp -f "$DRAFT_DIR/fixture/agreed-fee-isolated.html.proposed" "$FRONT_FIXTURE/agreed-fee-isolated.html"
  cp -f "$DRAFT_DIR/fixture/agreed-fee-isolated-mount.proposed.js" "$FRONT_FIXTURE/agreed-fee-isolated-mount.js"
  cp -f "$DRAFT_DIR/vite.agreed-fee-isolated.proposed.config.js" "$ROOT/frontend/vite.agreed-fee-isolated.config.js"
  cp -f "$DRAFT_DIR/playwright.agreed-fee-isolated.proposed.config.js" "$ROOT/frontend/playwright.agreed-fee-isolated.config.js"
  cp -f "$DRAFT_DIR/agreed-fee-isolated-fullstack.proposed.spec.js" "$ROOT/frontend/e2e/agreed-fee-isolated-fullstack.spec.js"
  chmod +x "$ROOT/scripts/agreed-fee-isolated-db-guard.sh"
}

# Always write owned cleanup so EXTERNAL=1 callers can trap it (Playwright failure must not skip).
cat > /tmp/agreed-fee-owned-cleanup.sh <<'EOS'
#!/usr/bin/env bash
set -euo pipefail
if [[ -f /tmp/agreed-fee-api.pid ]]; then kill "$(cat /tmp/agreed-fee-api.pid)" 2>/dev/null || true; rm -f /tmp/agreed-fee-api.pid; fi
if [[ -f /tmp/agreed-fee-vite.pid ]]; then kill "$(cat /tmp/agreed-fee-vite.pid)" 2>/dev/null || true; rm -f /tmp/agreed-fee-vite.pid; fi
rm -f /tmp/agreed-fee-isolated.env /tmp/agreed-fee-isolated-courses.json
if [[ -f /tmp/agreed-fee-api.log ]]; then
  sed -i -E 's/Bearer [A-Za-z0-9_-]+/Bearer [REDACTED]/g; s/AGREED_FEE_ISOLATED_TOKEN=.*/AGREED_FEE_ISOLATED_TOKEN=[REDACTED]/' /tmp/agreed-fee-api.log || true
fi
EOS
chmod 700 /tmp/agreed-fee-owned-cleanup.sh

if [[ "${AGREED_FEE_STACK_EXTERNAL:-0}" != "1" ]]; then
  trap 'bash /tmp/agreed-fee-owned-cleanup.sh' EXIT
fi

install_draft
bash "$ROOT/scripts/agreed-fee-isolated-db-guard.sh"

cd "$ROOT/backend"
# SAME PROCESS guard+DDL — never plain `php artisan migrate` here.
php tests/Support/agreed-fee-isolated-migrate.php
php tests/Support/agreed-fee-isolated-seed.php

php -S 127.0.0.1:8000 tests/Support/agreed-fee-isolated-router.php \
  > /tmp/agreed-fee-api.log 2>&1 &
echo $! > /tmp/agreed-fee-api.pid

cd "$ROOT/frontend"
node node_modules/vite/bin/vite.js --config vite.agreed-fee-isolated.config.js \
  > /tmp/agreed-fee-vite.log 2>&1 &
echo $! > /tmp/agreed-fee-vite.pid

api_ready=0
vite_ready=0
for i in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8000/__isolated/fee-snapshot?course_id=0" || echo 000)
  # course_id=0 → 401 still proves router is up (auth required)
  if [[ "$code" == "401" || "$code" == "403" ]]; then api_ready=1; fi
  if curl -sf -o /dev/null "http://127.0.0.1:5179/agreed-fee-isolated.html"; then vite_ready=1; fi
  if [[ "$api_ready" == "1" && "$vite_ready" == "1" ]]; then
    echo "stack: API :8000 AND Vite :5179 ready"
    break
  fi
  sleep 0.5
done

if [[ "$api_ready" != "1" || "$vite_ready" != "1" ]]; then
  echo "stack: ready timeout api=${api_ready} vite=${vite_ready}" >&2
  bash /tmp/agreed-fee-owned-cleanup.sh || true
  exit 1
fi

echo "stack: source /tmp/agreed-fee-isolated.env then Playwright (0600; do not cat/upload); cleanup via /tmp/agreed-fee-owned-cleanup.sh"
if [[ "${AGREED_FEE_STACK_HOLD:-0}" == "1" ]]; then
  wait
fi

#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
test_dir="$(mktemp -d)"
cleanup() { rm -rf "$test_dir"; }
trap cleanup EXIT

printf '%s\n' \
  'APP_ENV=production' \
  'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
  'DB_HOST=127.0.0.1' > "$test_dir/.env"
chmod 640 "$test_dir/.env"

ALLTRUE_ENV_FILE="$test_dir/.env" \
ALLTRUE_OPERATION_STATE_DIR="$test_dir/state" \
  bash "$SCRIPT_DIR/rotate-app-key-2026-07-19.sh"

grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' "$test_dir/.env"
[[ "$(grep -c '^APP_KEY=' "$test_dir/.env")" -eq 1 ]]
[[ -f "$test_dir/state/app-key-rotation-2026-07-19.complete" ]]
[[ "$(stat -c '%a' "$test_dir/.env")" == "640" ]]

before="$(sha256sum "$test_dir/.env" | cut -d' ' -f1)"
ALLTRUE_ENV_FILE="$test_dir/.env" \
ALLTRUE_OPERATION_STATE_DIR="$test_dir/state" \
  bash "$SCRIPT_DIR/rotate-app-key-2026-07-19.sh"
after="$(sha256sum "$test_dir/.env" | cut -d' ' -f1)"
[[ "$before" == "$after" ]]

echo "rotate-app-key test: PASS"

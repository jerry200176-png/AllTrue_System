#!/usr/bin/env bash
# One-time APP_KEY containment for issue #1007.
# Runs only from deploy.yml on the production host. Never prints either key.
set -euo pipefail

ENV_FILE="${ALLTRUE_ENV_FILE:-/home/admin/backend/.env}"
STATE_DIR="${ALLTRUE_OPERATION_STATE_DIR:-/home/admin/.alltrue-operations}"
MARKER="${STATE_DIR}/app-key-rotation-2026-07-19.complete"

if [[ -f "$MARKER" ]]; then
  echo "APP_KEY containment already applied; skipping"
  exit 0
fi

[[ -f "$ENV_FILE" ]] || { echo "APP_KEY containment aborted: environment file missing" >&2; exit 1; }
[[ "$(grep -c '^APP_KEY=' "$ENV_FILE")" -eq 1 ]] || {
  echo "APP_KEY containment aborted: expected exactly one APP_KEY entry" >&2
  exit 1
}

umask 077
mkdir -p "$STATE_DIR"
tmp_file="$(mktemp "${ENV_FILE}.app-key-rotation.XXXXXX")"
cleanup() { rm -f "$tmp_file"; }
trap cleanup EXIT

new_key="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
[[ "$new_key" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || {
  echo "APP_KEY containment aborted: generated key failed format validation" >&2
  exit 1
}

awk -v replacement="APP_KEY=${new_key}" '
  /^APP_KEY=/ { print replacement; next }
  { print }
' "$ENV_FILE" > "$tmp_file"

[[ "$(grep -c '^APP_KEY=' "$tmp_file")" -eq 1 ]] || {
  echo "APP_KEY containment aborted: replacement validation failed" >&2
  exit 1
}
grep -q '^APP_KEY=base64:[A-Za-z0-9+/]\{43\}=$' "$tmp_file" || {
  echo "APP_KEY containment aborted: replacement format invalid" >&2
  exit 1
}

chmod --reference="$ENV_FILE" "$tmp_file"
chown --reference="$ENV_FILE" "$tmp_file" 2>/dev/null || true
mv -f "$tmp_file" "$ENV_FILE"
touch "$MARKER"

unset new_key
echo "APP_KEY containment applied; encrypted sessions are intentionally invalidated"

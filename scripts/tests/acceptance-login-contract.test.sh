#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=/dev/null
source "$ROOT_DIR/scripts/acceptance/login-contract.sh"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

printf '%s\n' '{"account":"director","password":"placeholder","role":"director"}' > "$tmp_dir/valid.json"
acceptance_login_request_valid "$tmp_dir/valid.json"

printf '%s\n' '{"account":"","password":"placeholder","role":"director"}' > "$tmp_dir/invalid.json"
if acceptance_login_request_valid "$tmp_dir/invalid.json"; then
  echo 'invalid login request unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"errors":{"account":["redacted"],"password":["redacted"]},"code":"not-allowlisted","message":"must not be printed"}' > "$tmp_dir/rejected.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/rejected.json")" = 'json=valid errors=account,password code=none'

printf '%s\n' '{"code":"teacher_pending_approval","message":"must not be printed"}' > "$tmp_dir/pending.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/pending.json")" = 'json=valid errors=none code=teacher_pending_approval'

printf '%s\n' 'not-json' > "$tmp_dir/malformed.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/malformed.json")" = 'json=unparseable errors=none code=none'

echo 'acceptance login contract: PASS'

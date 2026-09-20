#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=/dev/null
source "$ROOT_DIR/scripts/acceptance/login-contract.sh"

unset SMOKE_DIRECTOR_LOGIN SMOKE_DIRECTOR_PASSWORD
SMOKE_DIRECTOR_PASSWORD=placeholder
export SMOKE_DIRECTOR_PASSWORD
if acceptance_require_login_secrets; then
  echo 'missing login secret unexpectedly passed' >&2
  exit 1
fi

unset SMOKE_DIRECTOR_LOGIN SMOKE_DIRECTOR_PASSWORD
SMOKE_DIRECTOR_LOGIN=placeholder
export SMOKE_DIRECTOR_LOGIN
if acceptance_require_login_secrets; then
  echo 'missing password secret unexpectedly passed' >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

printf '%s\n' '{"account":"director","password":"placeholder","role":"director"}' > "$tmp_dir/valid.json"
acceptance_login_request_valid "$tmp_dir/valid.json"

printf '%s\n' '{"account":"","password":"placeholder","role":"director"}' > "$tmp_dir/invalid.json"
if acceptance_login_request_valid "$tmp_dir/invalid.json"; then
  echo 'invalid login request unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"account":"   \t  ","password":"placeholder","role":"director"}' > "$tmp_dir/whitespace-account.json"
if acceptance_login_request_valid "$tmp_dir/whitespace-account.json"; then
  echo 'whitespace-only account unexpectedly passed' >&2
  exit 1
fi

long_account="$(printf 'a%.0s' {1..129})"
printf '{"account":"%s","password":"placeholder","role":"director"}\n' "$long_account" > "$tmp_dir/long-account.json"
if acceptance_login_request_valid "$tmp_dir/long-account.json"; then
  echo 'overlong account unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"account":"director","password":"","role":"director"}' > "$tmp_dir/empty-password.json"
if acceptance_login_request_valid "$tmp_dir/empty-password.json"; then
  echo 'empty password unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"account":"director","password":"placeholder","role":"teacher"}' > "$tmp_dir/invalid-role.json"
if acceptance_login_request_valid "$tmp_dir/invalid-role.json"; then
  echo 'invalid role unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"errors":{"account":["redacted"],"password":["redacted"]},"code":"not-allowlisted","message":"must not be printed"}' > "$tmp_dir/rejected.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/rejected.json")" = 'json=valid errors=account,password code=none'

printf '%s\n' '{"code":"teacher_pending_approval","message":"must not be printed"}' > "$tmp_dir/pending.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/pending.json")" = 'json=valid errors=none code=teacher_pending_approval'

printf '%s\n' 'not-json' > "$tmp_dir/malformed.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/malformed.json")" = 'json=unparseable errors=none code=none'

: > "$tmp_dir/empty-response.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/empty-response.json")" = 'json=unparseable errors=none code=none'

printf ' \t\n' > "$tmp_dir/whitespace-response.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/whitespace-response.json")" = 'json=unparseable errors=none code=none'

echo 'acceptance login contract: PASS'

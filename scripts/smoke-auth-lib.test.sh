#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/smoke-auth-lib.sh
source "$SCRIPT_DIR/smoke-auth-lib.sh"

fail() {
  echo "❌ smoke-auth-lib self-test: $1" >&2
  exit 1
}

assert_eq() {
  local expected="$1" actual="$2" label="$3"
  [[ "$actual" == "$expected" ]] || fail "$label (expected '$expected', got '$actual')"
}

assert_token() {
  local json="$1" expected="$2"
  local actual
  actual="$(printf '%s' "$json" | smoke_extract_access_token)"
  assert_eq "$expected" "$actual" "token extraction"
}

assert_token '{"data":{"session":{"access_token":"nested-token"}}}' 'nested-token'
assert_token '{"session":{"access_token":"session-token"}}' 'session-token'
assert_token '{"token":"legacy-token"}' 'legacy-token'
assert_token '{"access_token":"root-token"}' 'root-token'
assert_token '{"data":[]}' ''
assert_token 'not-json' ''

TEST_TMP="$(mktemp -d)"
trap 'rm -rf "$TEST_TMP"' EXIT
COUNT_FILE="$TEST_TMP/count"
SCENARIO=""

reset_scenario() {
  SCENARIO="$1"
  printf '0' > "$COUNT_FILE"
}

request_count() {
  cat "$COUNT_FILE"
}

# Override the transport boundary. State is kept in a file because login is
# invoked in command substitution and therefore runs in a subshell.
smoke_login_request() {
  local count
  count="$(request_count)"
  count=$((count + 1))
  printf '%s' "$count" > "$COUNT_FILE"

  case "$SCENARIO:$count" in
    transient:1)
      printf '{"message":"temporarily unavailable"}\n503'
      ;;
    transport:1)
      printf '\n000'
      return 7
      ;;
    unauthorized:*)
      printf '{"message":"bad credentials","echo":"test-password"}\n401'
      ;;
    missing_token:*)
      printf '{"data":{}}\n200'
      ;;
    *)
      printf '{"data":{"session":{"access_token":"recovered-token"}}}\n200'
      ;;
  esac
}

stderr_file="$TEST_TMP/stderr"

reset_scenario transient
token="$(SMOKE_LOGIN_RETRY_DELAY_SECONDS=0 smoke_login_and_token https://example.test account test-password teacher teacher 2>"$stderr_file")"
assert_eq 'recovered-token' "$token" 'transient HTTP recovery token'
assert_eq '2' "$(request_count)" 'transient HTTP retry count'

reset_scenario transport
token="$(SMOKE_LOGIN_RETRY_DELAY_SECONDS=0 smoke_login_and_token https://example.test account test-password teacher teacher 2>"$stderr_file")"
assert_eq 'recovered-token' "$token" 'transport recovery token'
assert_eq '2' "$(request_count)" 'transport retry count'

reset_scenario unauthorized
if SMOKE_LOGIN_RETRY_DELAY_SECONDS=0 smoke_login_and_token https://example.test account test-password teacher teacher > /dev/null 2>"$stderr_file"; then
  fail 'HTTP 401 must fail'
fi
assert_eq '1' "$(request_count)" 'HTTP 401 retry count'
grep -q 'HTTP 401 (not retried)' "$stderr_file" || fail 'HTTP 401 diagnostic missing'
if grep -q 'test-password\|bad credentials' "$stderr_file"; then
  fail 'response body or credential leaked to diagnostics'
fi

reset_scenario missing_token
if SMOKE_LOGIN_RETRY_DELAY_SECONDS=0 smoke_login_and_token https://example.test account test-password teacher teacher > /dev/null 2>"$stderr_file"; then
  fail 'HTTP 200 without token must fail'
fi
assert_eq '1' "$(request_count)" 'missing-token retry count'
grep -q 'without an access token (not retried)' "$stderr_file" || fail 'missing-token diagnostic missing'

echo '✅ smoke-auth-lib self-test passed'

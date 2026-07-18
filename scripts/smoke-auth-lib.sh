#!/usr/bin/env bash

# Shared credentialed-login helpers for production smoke checks.
# This file is sourced by smoke-api.sh and post-merge-smoke.sh.

smoke_extract_access_token() {
  python3 -c '
import json
import sys

try:
    data = json.load(sys.stdin)
except Exception:
    raise SystemExit(0)

if not isinstance(data, dict):
    raise SystemExit(0)

candidates = (
    data.get("data", {}).get("session", {}).get("access_token")
        if isinstance(data.get("data"), dict)
        and isinstance(data.get("data", {}).get("session"), dict)
        else None,
    data.get("session", {}).get("access_token")
        if isinstance(data.get("session"), dict)
        else None,
    data.get("token"),
    data.get("access_token"),
)

for candidate in candidates:
    if isinstance(candidate, str) and candidate.strip():
        print(candidate.strip())
        raise SystemExit(0)
'
}

smoke_login_request() {
  local api_base="$1"
  local payload="$2"

  printf '%s' "$payload" | curl -skL \
    --connect-timeout "${SMOKE_LOGIN_CONNECT_TIMEOUT_SECONDS:-8}" \
    --max-time "${SMOKE_LOGIN_MAX_TIME_SECONDS:-20}" \
    -X POST "$api_base/auth/login" \
    -H 'Content-Type: application/json' \
    --data-binary @- \
    -w $'\n%{http_code}'
}

smoke_login_and_token() {
  local api_base="$1"
  local account="$2"
  local password="$3"
  local role="$4"
  local label="${5:-authenticated}"
  local max_attempts="${SMOKE_LOGIN_MAX_ATTEMPTS:-3}"
  local retry_delay="${SMOKE_LOGIN_RETRY_DELAY_SECONDS:-2}"

  if [[ ! "$max_attempts" =~ ^[1-9][0-9]*$ ]]; then
    echo "❌ Smoke login configuration error: max attempts must be a positive integer" >&2
    return 64
  fi
  if [[ ! "$retry_delay" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
    echo "❌ Smoke login configuration error: retry delay must be non-negative" >&2
    return 64
  fi

  local payload
  payload="$(
    SMOKE_LOGIN_ACCOUNT="$account" \
    SMOKE_LOGIN_PASSWORD="$password" \
    SMOKE_LOGIN_ROLE="$role" \
      python3 - <<'PY'
import json
import os

print(json.dumps({
    "account": os.environ["SMOKE_LOGIN_ACCOUNT"],
    "password": os.environ["SMOKE_LOGIN_PASSWORD"],
    "role": os.environ["SMOKE_LOGIN_ROLE"],
}))
PY
  )"

  local attempt=0
  local combined=""
  local curl_status=0
  local http_code="000"
  local body=""
  local token=""

  while [[ "$attempt" -lt "$max_attempts" ]]; do
    attempt=$((attempt + 1))
    if combined="$(smoke_login_request "$api_base" "$payload")"; then
      curl_status=0
    else
      curl_status=$?
    fi

    if [[ "$combined" == *$'\n'* ]]; then
      http_code="${combined##*$'\n'}"
      body="${combined%$'\n'*}"
    else
      http_code="000"
      body=""
    fi

    if [[ "$curl_status" -eq 0 && "$http_code" =~ ^2[0-9][0-9]$ ]]; then
      token="$(printf '%s' "$body" | smoke_extract_access_token)"
      if [[ -n "$token" ]]; then
        printf '%s\n' "$token"
        return 0
      fi

      echo "❌ $label login returned HTTP $http_code without an access token (not retried)" >&2
      return 1
    fi

    local transient=0
    if [[ "$curl_status" -ne 0 ]]; then
      transient=1
    elif [[ "$http_code" == "408" || "$http_code" == "425" || "$http_code" == "429" || "$http_code" =~ ^5[0-9][0-9]$ ]]; then
      transient=1
    fi

    if [[ "$transient" -eq 0 ]]; then
      echo "❌ $label login rejected with HTTP $http_code (not retried)" >&2
      return 1
    fi

    if [[ "$attempt" -lt "$max_attempts" ]]; then
      if [[ "$curl_status" -ne 0 ]]; then
        echo "⚠️  $label login transport failure (curl $curl_status); retry $((attempt + 1))/$max_attempts" >&2
      else
        echo "⚠️  $label login transient HTTP $http_code; retry $((attempt + 1))/$max_attempts" >&2
      fi
      sleep "$retry_delay"
    fi
  done

  if [[ "$curl_status" -ne 0 ]]; then
    echo "❌ $label login failed after $max_attempts attempts (last transport status: curl $curl_status)" >&2
  else
    echo "❌ $label login failed after $max_attempts attempts (last HTTP status: $http_code)" >&2
  fi
  return 1
}

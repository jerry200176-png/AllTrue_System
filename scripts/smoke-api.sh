#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${SMOKE_BASE_URL:-https://daan.lifenet.com.tw}"
API_BASE="${BASE_URL%/}/api/v1"

TEACHER_LOGIN="${SMOKE_TEACHER_LOGIN:-}"
TEACHER_PASSWORD="${SMOKE_TEACHER_PASSWORD:-}"
BRANCH_ID="${SMOKE_BRANCH_ID:-15}"
START_DATE="${SMOKE_START_DATE:-$(date +%F)}"
END_DATE="${SMOKE_END_DATE:-$START_DATE}"
PER_PAGE="${SMOKE_PER_PAGE:-20}"

failures=0

http_code() {
  local method="$1"
  local url="$2"
  local body="${3:-}"
  local auth="${4:-}"

  if [[ -n "$body" ]]; then
    if [[ -n "$auth" ]]; then
      curl -skL -o /dev/null -w "%{http_code}" \
        -X "$method" "$url" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $auth" \
        --data "$body"
    else
      curl -skL -o /dev/null -w "%{http_code}" \
        -X "$method" "$url" \
        -H "Content-Type: application/json" \
        --data "$body"
    fi
  else
    if [[ -n "$auth" ]]; then
      curl -skL -o /dev/null -w "%{http_code}" \
        -X "$method" "$url" \
        -H "Authorization: Bearer $auth"
    else
      curl -skL -o /dev/null -w "%{http_code}" -X "$method" "$url"
    fi
  fi
}

check_non_5xx() {
  local label="$1"
  local code="$2"
  if [[ -z "$code" || "$code" == "000" || "${code:0:1}" == "5" ]]; then
    echo "❌ $label -> $code"
    failures=$((failures + 1))
  else
    echo "✅ $label -> $code"
  fi
}

echo "== Smoke API (read-only) =="
echo "Base: $API_BASE"

code="$(http_code GET "$API_BASE/health")"
check_non_5xx "GET /health" "$code"

code="$(http_code GET "$API_BASE/branches")"
check_non_5xx "GET /branches" "$code"

code="$(http_code POST "$API_BASE/auth/login" "{}")"
check_non_5xx "POST /auth/login (route alive)" "$code"

token=""
if [[ -n "$TEACHER_LOGIN" && -n "$TEACHER_PASSWORD" ]]; then
  payload="$(python3 - <<PY
import json
print(json.dumps({"account": "$TEACHER_LOGIN", "password": "$TEACHER_PASSWORD", "role": "teacher"}))
PY
)"
  login_json="$(curl -skL -X POST "$API_BASE/auth/login" -H "Content-Type: application/json" --data "$payload")"
  token="$(python3 - <<'PYEOF' "$login_json"
import json, sys
try:
    data = json.loads(sys.argv[1])
except Exception:
    print("")
    raise SystemExit(0)
candidates = [
    data.get("data", {}).get("session", {}).get("access_token"),
    data.get("session", {}).get("access_token"),
    data.get("token"),
    data.get("access_token"),
]
for v in candidates:
    if isinstance(v, str) and v.strip():
        print(v.strip())
        raise SystemExit(0)
print("")
PYEOF
)"

  if [[ -z "$token" ]]; then
    echo "❌ Teacher login succeeded without extractable token"
    failures=$((failures + 1))
  else
    code="$(http_code GET "$API_BASE/me" "" "$token")"
    check_non_5xx "GET /me" "$code"

    code="$(http_code GET "$API_BASE/class-sessions?start=$START_DATE&end=$END_DATE&branch_id=$BRANCH_ID&per_page=$PER_PAGE" "" "$token")"
    check_non_5xx "GET /class-sessions" "$code"

    code="$(http_code GET "$API_BASE/learning-records?branch_id=$BRANCH_ID&per_page=$PER_PAGE" "" "$token")"
    check_non_5xx "GET /learning-records" "$code"

    code="$(http_code GET "$API_BASE/system/trust-summary?branch_id=$BRANCH_ID" "" "$token")"
    if [[ "$code" == "200" || "$code" == "403" ]]; then
      echo "✅ GET /system/trust-summary -> $code"
    else
      echo "❌ GET /system/trust-summary -> $code (expected 200/403, not 401)"
      failures=$((failures + 1))
    fi
  fi
else
  echo "ℹ️ Skip authenticated smoke: SMOKE_TEACHER_LOGIN / SMOKE_TEACHER_PASSWORD not set"
fi

if [[ "$failures" -gt 0 ]]; then
  echo "❌ Smoke failed: $failures checks failed"
  exit 1
fi

echo "✅ Smoke passed"

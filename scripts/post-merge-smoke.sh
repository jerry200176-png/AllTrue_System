#!/usr/bin/env bash
# Post-merge smoke (read-only) — health, deploy artifact, optional auth API paths.
# Auth: SMOKE_* env vars OR fetch latest valid token from Pi DB (read-only, no password).
# See docs/SMOKE_TEST_RUNBOOK.md
set -euo pipefail

BASE_URL="${SMOKE_BASE_URL:-https://daan.lifenet.com.tw}"
API_BASE="${BASE_URL%/}/api/v1"
PI_SSH="${SMOKE_PI_SSH:-admin@pi.lifenet.com.tw}"
PI_BACKEND="${SMOKE_PI_BACKEND:-/home/admin/backend}"
EXPECTED_HASH="${SMOKE_EXPECTED_HASH:-$(git rev-parse --short=8 HEAD 2>/dev/null || echo '')}"

# Running on Pi during deploy.yml (no SSH hop)
if [[ -z "${SMOKE_ON_PI:-}" && -d "${PI_BACKEND}/public/assets" ]]; then
  SMOKE_ON_PI=1
fi

pi_sh() {
  if [[ "${SMOKE_ON_PI:-}" == "1" ]]; then
    bash -lc "$1"
  else
    ssh -o BatchMode=yes "$PI_SSH" "$1"
  fi
}

pi_mysql() {
  local sql="$1"
  if [[ "${SMOKE_ON_PI:-}" == "1" ]]; then
    DB_PASS="$(grep ^DB_PASSWORD= "${PI_BACKEND}/.env" | cut -d= -f2-)"
    mysql -u admin -p"$DB_PASS" AllTrue -N -e "$sql" 2>/dev/null
  else
    ssh -o BatchMode=yes "$PI_SSH" "DB_PASS=\$(grep ^DB_PASSWORD= ${PI_BACKEND}/.env | cut -d= -f2-); mysql -u admin -p\"\$DB_PASS\" AllTrue -N -e \"$sql\"" 2>/dev/null
  fi
}

failures=0
pass() { echo "✅ $1"; }
fail() { echo "❌ $1"; failures=$((failures + 1)); }
warn() { echo "⚠️  $1"; }

echo "== Post-merge smoke =="
echo "Base: $API_BASE"

# ── Layer 1: public API (reuse smoke-api.sh) ──
if ! bash "$(dirname "$0")/smoke-api.sh"; then
  fail "scripts/smoke-api.sh public checks"
fi

# ── Layer 2: deploy artifact ──
ver_json="$(curl -sk "$BASE_URL/version.json" || true)"
ver_hash="$(python3 - <<'PY' "$ver_json"
import json, sys
try:
    print(json.loads(sys.argv[1]).get("hash", "").strip())
except Exception:
    print("")
PY
)"
if [[ -z "$ver_hash" ]]; then
  fail "version.json missing or invalid"
elif [[ -n "$EXPECTED_HASH" && "$ver_hash" != "$EXPECTED_HASH" ]]; then
  if [[ "${SMOKE_STRICT_VERSION:-}" == "1" ]]; then
    fail "version.json hash=$ver_hash (expected $EXPECTED_HASH after frontend deploy)"
  else
    warn "version.json hash=$ver_hash (expected $EXPECTED_HASH from local HEAD — may lag if docs-only)"
  fi
else
  pass "version.json hash=$ver_hash"
fi

if [[ "${SMOKE_ON_PI:-}" == "1" ]] || ssh -o BatchMode=yes -o ConnectTimeout=8 "$PI_SSH" true 2>/dev/null; then
  pi_commit="$(pi_sh "cd ${PI_BACKEND} && git rev-parse --short=8 HEAD" 2>/dev/null || echo '')"
  [[ -n "$pi_commit" ]] && pass "Pi git HEAD=$pi_commit" || warn "Could not read Pi git HEAD"

  for needle in cancelMakeupSchedule fetchPendingMakeups pending-makeups-panel; do
    if pi_sh "grep -q '$needle' ${PI_BACKEND}/public/assets/CourseManagement-*.js 2>/dev/null"; then
      pass "bundle contains $needle"
    else
      fail "CourseManagement bundle missing $needle"
    fi
  done

  if pi_sh "grep -q 'trust-summary' ${PI_BACKEND}/public/assets/TeacherHomePage-*.js 2>/dev/null"; then
    pass "TeacherHome bundle contains trust-summary"
  else
    fail "TeacherHome bundle missing trust-summary"
  fi
else
  warn "Skip Pi artifact checks (SSH unavailable)"
fi

# ── Layer 3: authenticated API (no writes except idempotent 404 probes) ──
login_and_token() {
  local account="$1" password="$2" role="$3"
  local resp
  resp="$(curl -sk -X POST "$API_BASE/auth/login" \
    -H 'Content-Type: application/json' \
    -d "$(python3 - <<PY
import json
print(json.dumps({"account": "$account", "password": "$password", "role": "$role"}))
PY
)")"
  python3 - <<'PY' "$resp"
import json, sys
try:
    d = json.loads(sys.argv[1])
except Exception:
    sys.exit(0)
for path in (
    d.get("data", {}).get("session", {}).get("access_token"),
    d.get("session", {}).get("access_token"),
    d.get("token"),
    d.get("access_token"),
):
    if isinstance(path, str) and path.strip():
        print(path.strip())
        raise SystemExit(0)
PY
}

fetch_pi_token() {
  local user_type="$1"
  pi_mysql "SELECT t.token FROM auth_tokens t JOIN User u ON u.id=t.user_id WHERE u.type='${user_type}' AND t.expires_at > NOW() ORDER BY t.expires_at DESC LIMIT 1;" | head -1
}

fetch_pi_campus() {
  local user_id="$1"
  pi_mysql "SELECT CampusID FROM UserCampus WHERE UserID=${user_id} AND Approved=1 LIMIT 1;" | head -1
}

http_auth_code() {
  local method="$1" url="$2" token="$3"
  curl -skL -o /dev/null -w '%{http_code}' \
    -X "$method" "$url" \
    -H "Authorization: Bearer $token" \
    -H 'Accept: application/json'
}

teacher_token=""
director_token=""

if [[ -n "${SMOKE_TEACHER_LOGIN:-}" && -n "${SMOKE_TEACHER_PASSWORD:-}" ]]; then
  teacher_token="$(login_and_token "$SMOKE_TEACHER_LOGIN" "$SMOKE_TEACHER_PASSWORD" teacher || true)"
elif [[ -f "${SMOKE_ENV_FILE:-$HOME/alltrue/.cursor/.local/smoke.env}" ]]; then
  # shellcheck disable=SC1090
  source "${SMOKE_ENV_FILE:-$HOME/alltrue/.cursor/.local/smoke.env}"
  [[ -n "${SMOKE_TEACHER_LOGIN:-}" && -n "${SMOKE_TEACHER_PASSWORD:-}" ]] && \
    teacher_token="$(login_and_token "$SMOKE_TEACHER_LOGIN" "$SMOKE_TEACHER_PASSWORD" teacher || true)"
  [[ -n "${SMOKE_DIRECTOR_LOGIN:-}" && -n "${SMOKE_DIRECTOR_PASSWORD:-}" ]] && \
    director_token="$(login_and_token "$SMOKE_DIRECTOR_LOGIN" "$SMOKE_DIRECTOR_PASSWORD" director || true)"
fi

[[ -z "$teacher_token" ]] && teacher_token="$(fetch_pi_token T || true)"
[[ -z "$director_token" ]] && director_token="$(fetch_pi_token D || true)"

if [[ -n "$teacher_token" ]]; then
  branch_id="${SMOKE_BRANCH_ID:-15}"
  code="$(http_auth_code GET "$API_BASE/system/trust-summary?branch_id=$branch_id" "$teacher_token")"
  if [[ "$code" == "200" ]]; then
    pass "teacher GET /system/trust-summary -> 200 (#529 auth path)"
  elif [[ "$code" == "403" ]]; then
    warn "teacher trust-summary -> 403 (branch $branch_id not in scope; token OK)"
  else
    fail "teacher GET /system/trust-summary -> $code (expected 200/403, not 401)"
  fi
else
  warn "Skip teacher auth smoke (no token)"
fi

if [[ -n "$director_token" ]]; then
  code="$(http_auth_code GET "$API_BASE/schedules?type=extra&status=scheduled&per_page=5" "$director_token")"
  [[ "$code" == "200" ]] && pass "director GET /schedules (pending makeup list) -> 200" \
    || fail "director GET /schedules -> $code"

  code="$(http_auth_code POST "$API_BASE/schedules/999999999/cancel-makeup" "$director_token")"
  if [[ "$code" == "404" || "$code" == "422" ]]; then
    pass "director POST /cancel-makeup (probe) -> $code (auth OK, not 401)"
  else
    fail "director POST /cancel-makeup probe -> $code (expected 404/422)"
  fi
else
  warn "Skip director auth smoke (no token)"
fi

if [[ "$failures" -gt 0 ]]; then
  echo "❌ Post-merge smoke failed: $failures"
  exit 1
fi
echo "✅ Post-merge smoke passed"
exit 0

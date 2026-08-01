#!/usr/bin/env bash
# Post-merge smoke (read-only) — health, deploy artifact, optional auth API paths.
# Auth: SMOKE_* env vars OR fetch latest valid token from Pi DB (read-only, no password).
# See docs/SMOKE_TEST_RUNBOOK.md
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/smoke-auth-lib.sh
source "$SCRIPT_DIR/smoke-auth-lib.sh"

BASE_URL="${SMOKE_BASE_URL:-https://daan.lifenet.com.tw}"
API_BASE="${BASE_URL%/}/api/v1"
PI_SSH="${SMOKE_PI_SSH:-admin@pi.lifenet.com.tw}"
PI_BACKEND="${SMOKE_PI_BACKEND:-/home/admin/backend}"
EXPECTED_HASH="${SMOKE_EXPECTED_HASH:-$(git rev-parse --short=8 HEAD 2>/dev/null || echo '')}"
EXPECTED_BACKEND_SHA="${SMOKE_EXPECTED_BACKEND_SHA:-}"
REQUIRE_DEPLOYMENT_MANIFEST="${SMOKE_REQUIRE_DEPLOYMENT_MANIFEST:-0}"

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

deployment_json="$(curl -sk "$BASE_URL/deployment.json" || true)"
manifest_backend_sha="$(python3 - <<'PY' "$deployment_json"
import json, sys
try:
    print(json.loads(sys.argv[1]).get("backend_sha", "").strip())
except Exception:
    print("")
PY
)"
manifest_frontend_sha="$(python3 - <<'PY' "$deployment_json"
import json, sys
try:
    print((json.loads(sys.argv[1]).get("frontend_build_sha") or "").strip())
except Exception:
    print("")
PY
)"
if [[ -z "$manifest_backend_sha" ]]; then
  if [[ "$REQUIRE_DEPLOYMENT_MANIFEST" == "1" ]]; then
    fail "deployment.json missing backend identity"
  else
    warn "deployment.json missing (runtime manifest not required for this invocation)"
  fi
else
  if [[ -n "$EXPECTED_BACKEND_SHA" && "$manifest_backend_sha" != "$EXPECTED_BACKEND_SHA" ]]; then
    fail "deployment.json backend_sha=$manifest_backend_sha (expected $EXPECTED_BACKEND_SHA)"
  else
    pass "deployment.json backend_sha=$manifest_backend_sha"
  fi
  if [[ -n "$manifest_frontend_sha" ]]; then
    pass "deployment.json frontend_build_sha=$manifest_frontend_sha"
  else
    warn "deployment.json has no frontend build identity (frontend artifact may predate this manifest schema)"
  fi
fi

if [[ "${SMOKE_ON_PI:-}" == "1" ]] || ssh -o BatchMode=yes -o ConnectTimeout=8 "$PI_SSH" true 2>/dev/null; then
  pi_commit="$(pi_sh "cd ${PI_BACKEND} && git rev-parse --short=8 HEAD" 2>/dev/null || echo '')"
  if [[ -n "$pi_commit" ]]; then
    pass "Pi git HEAD=$pi_commit"
    if [[ -n "$ver_hash" && "$ver_hash" != "$pi_commit" ]]; then
      warn "version.json hash=$ver_hash ≠ Pi git HEAD=$pi_commit (expected when backend-only deploy skipped frontend rebuild)"
    fi
    if [[ -n "$manifest_backend_sha" && "${manifest_backend_sha:0:8}" != "${pi_commit:0:8}" ]]; then
      fail "deployment.json backend_sha=$manifest_backend_sha ≠ Pi git HEAD=$pi_commit"
    fi
  else
    warn "Could not read Pi git HEAD"
  fi

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

# ── Layer 3: authenticated API (no writes except login tokens and idempotent 404 probes) ──

fetch_pi_token() {
  local user_type="$1"
  # Prefer tokens whose user has at least one Approved campus (require_campus).
  # Fall back to any valid token of that type if none match (legacy accounts).
  local token=""
  token="$(pi_mysql "SELECT t.token FROM auth_tokens t JOIN User u ON u.id=t.user_id JOIN UserCampus uc ON uc.UserID=u.id AND (uc.Approved=1 OR uc.Approved IS NULL) WHERE u.type='${user_type}' AND t.expires_at > NOW() ORDER BY t.expires_at DESC LIMIT 1;" | head -1 || true)"
  if [[ -z "$token" ]]; then
    token="$(pi_mysql "SELECT t.token FROM auth_tokens t JOIN User u ON u.id=t.user_id WHERE u.type='${user_type}' AND t.expires_at > NOW() ORDER BY t.expires_at DESC LIMIT 1;" | head -1 || true)"
  fi
  printf '%s' "$token"
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
  teacher_token="$(smoke_login_and_token "$API_BASE" "$SMOKE_TEACHER_LOGIN" "$SMOKE_TEACHER_PASSWORD" teacher teacher || true)"
elif [[ -f "${SMOKE_ENV_FILE:-$HOME/alltrue/.cursor/.local/smoke.env}" ]]; then
  # shellcheck disable=SC1090
  source "${SMOKE_ENV_FILE:-$HOME/alltrue/.cursor/.local/smoke.env}"
  [[ -n "${SMOKE_TEACHER_LOGIN:-}" && -n "${SMOKE_TEACHER_PASSWORD:-}" ]] && \
    teacher_token="$(smoke_login_and_token "$API_BASE" "$SMOKE_TEACHER_LOGIN" "$SMOKE_TEACHER_PASSWORD" teacher teacher || true)"
  [[ -n "${SMOKE_DIRECTOR_LOGIN:-}" && -n "${SMOKE_DIRECTOR_PASSWORD:-}" ]] && \
    director_token="$(smoke_login_and_token "$API_BASE" "$SMOKE_DIRECTOR_LOGIN" "$SMOKE_DIRECTOR_PASSWORD" director director || true)"
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
  # Post route:cache / opcache:reset warmup can yield a transient 500 on first hit (#1040).
  warmup="${SMOKE_POST_OPTIMIZE_SLEEP:-3}"
  if [[ "${SMOKE_ON_PI:-}" == "1" && "$warmup" -gt 0 ]]; then
    sleep "$warmup"
  fi

  schedules_url="$API_BASE/schedules?type=extra&status=scheduled&per_page=5"
  code=""
  attempt=0
  max_attempts=4
  while [[ "$attempt" -lt "$max_attempts" ]]; do
    attempt=$((attempt + 1))
    code="$(http_auth_code GET "$schedules_url" "$director_token")"
    if [[ "$code" == "200" ]]; then
      break
    fi
    # 500: post route:cache / opcache warmup (#1040). 403: transient campus/token
    # race after deploy (seen on #1465 merge — same SHA later OK on prior deploys).
    if [[ "$code" == "500" || "$code" == "403" ]] && [[ "$attempt" -lt "$max_attempts" ]]; then
      warn "director GET /schedules attempt $attempt -> $code (retry; refetch director token)"
      refreshed="$(fetch_pi_token D || true)"
      [[ -n "$refreshed" ]] && director_token="$refreshed"
      sleep 2
      continue
    fi
    break
  done
  if [[ "$code" == "200" ]]; then
    pass "director GET /schedules (pending makeup list) -> 200 after ${attempt} attempt(s)"
  else
    body_snip="$(curl -skL -X GET "$schedules_url" \
      -H "Authorization: Bearer $director_token" \
      -H 'Accept: application/json' 2>/dev/null | head -c 200 | tr '\n' ' ')"
    fail "director GET /schedules -> $code after ${attempt} attempt(s) body=${body_snip:-<empty>}"
  fi

  # Probe a non-existent ID; 404/422 = auth OK. Retry 500s before failing deploy.
  probe_url="$API_BASE/schedules/999999999/cancel-makeup"
  code=""
  attempt=0
  max_attempts=4
  while [[ "$attempt" -lt "$max_attempts" ]]; do
    attempt=$((attempt + 1))
    code="$(http_auth_code POST "$probe_url" "$director_token")"
    if [[ "$code" == "404" || "$code" == "422" ]]; then
      break
    fi
    if [[ "$code" == "500" && "$attempt" -lt "$max_attempts" ]]; then
      warn "director POST /cancel-makeup probe attempt $attempt -> 500 (post-cache warmup?)"
      sleep 2
      continue
    fi
    break
  done
  if [[ "$code" == "404" || "$code" == "422" ]]; then
    pass "director POST /cancel-makeup (probe) -> $code after ${attempt} attempt(s) (auth OK, not 401)"
  else
    fail "director POST /cancel-makeup probe -> $code (expected 404/422 after retries)"
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

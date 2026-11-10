#!/usr/bin/env bash
# Preflight before starting AllTrue Daan staging. Fail closed on unsafe co-residency.
set -euo pipefail
# shellcheck source=./_common.sh
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"

MIN_AVAIL_MB="${STAGING_MIN_AVAIL_MB:-900}"

echo "=== AllTrue staging preflight ==="
echo "repo_root=${REPO_ROOT}"
echo "compose=${COMPOSE_FILE}"

require_env_file

if ! command -v docker >/dev/null; then
  echo "ERROR: docker CLI not available" >&2
  exit 2
fi
if ! docker info >/dev/null 2>&1; then
  echo "ERROR: cannot talk to Docker daemon (rootless daemon down? systemctl --user start docker)" >&2
  exit 2
fi

mem_snapshot
AVAIL="$(available_mem_mb || echo 0)"
echo "MemAvailable_MB=${AVAIL} (minimum ${MIN_AVAIL_MB})"
if [[ "${AVAIL}" -lt "${MIN_AVAIL_MB}" ]]; then
  echo "ERROR: insufficient MemAvailable for staging co-residency; refusing to start." >&2
  echo "Do NOT stop Dify/Hermes automatically. Report to Founder." >&2
  exit 3
fi

# Port collision checks
DOCKER_INFO="$(docker info 2>/dev/null || true)"
if ! grep -qi rootless <<<"${DOCKER_INFO}"; then
  echo "ERROR: Docker daemon is not rootless; refusing (no docker group / sudo)." >&2
  exit 2
fi
if ss -lnt 2>/dev/null | grep -qE "127\\.0\\.0\\.1:${STAGING_PORT}\\s" \
  && ! grep -qx 'alltrue-stage-nginx' <<<"$(docker ps --format '{{.Names}}')"; then
  echo "ERROR: 127.0.0.1:${STAGING_PORT} is held by something other than this stack" >&2
  echo "(e.g. a legacy rootful alltrue-stage; set STAGING_HTTP_PORT or remove it)." >&2
  exit 2
fi
if ss -lnt 2>/dev/null | grep -qE '0\.0\.0\.0:8080\s'; then
  echo "OK: host :8080 in use (expected Dify) — staging will NOT bind 8080"
fi
if ss -lnt 2>/dev/null | grep -qE '127\.0\.0\.1:3306\s'; then
  echo "OK: host 127.0.0.1:3306 in use (expected host MySQL) — staging MySQL is Docker-internal only"
fi

[[ -s "${STAGING_HTPASSWD}" && -s "${STAGING_GATE_CONF}" ]] || { echo "ERROR: ${STAGING_HTPASSWD} missing; run gen-basic-auth.sh (staging is never served without the gate)" >&2; exit 2; }
echo "=== compose config validate ==="
compose config -q
echo "PREFLIGHT_OK"

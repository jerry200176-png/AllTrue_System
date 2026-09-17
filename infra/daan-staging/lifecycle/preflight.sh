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
  echo "ERROR: cannot talk to Docker daemon (need docker group or sudo bootstrap)" >&2
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

# Port collision checks (must remain free of host binds we refuse to take)
for spec in "0.0.0.0:8080" "*:8080" "0.0.0.0:3306" "127.0.0.1:3306"; do
  :
done
if ss -lnt 2>/dev/null | grep -qE '127\.0\.0\.1:18080\s'; then
  echo "WARN: 127.0.0.1:18080 already listening — staging nginx may fail to bind"
fi
if ss -lnt 2>/dev/null | grep -qE '0\.0\.0\.0:8080\s'; then
  echo "OK: host :8080 in use (expected Dify) — staging will NOT bind 8080"
fi
if ss -lnt 2>/dev/null | grep -qE '127\.0\.0\.1:3306\s'; then
  echo "OK: host 127.0.0.1:3306 in use (expected host MySQL) — staging MySQL is Docker-internal only"
fi

echo "=== compose config validate ==="
compose config -q
echo "PREFLIGHT_OK"

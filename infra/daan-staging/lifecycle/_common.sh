#!/usr/bin/env bash
# Shared paths for AllTrue Daan staging lifecycle scripts.
set -euo pipefail

STAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "${STAGE_ROOT}/../.." && pwd)"
COMPOSE_FILE="${STAGE_ROOT}/docker-compose.yml"
ENV_FILE="${STAGE_ROOT}/.env"
PROJECT_NAME="alltrue-stage"
STAGING_HTTP="http://127.0.0.1:18080"
IDENTITY_FILE="${STAGE_ROOT}/runtime/deployment.json"
DOCKER_BIN="${DOCKER_BIN:-docker}"

export COMPOSE_PROJECT_NAME="${PROJECT_NAME}"

compose() {
  "${DOCKER_BIN}" compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" "$@"
}

compose_exec_noninteractive() {
  "${DOCKER_BIN}" compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" \
    exec -T --interactive=false "$@" </dev/null
}

compose_exec_bounded() {
  local timeout_seconds="${COMPOSE_EXEC_TIMEOUT_SECONDS:-20}"
  timeout --kill-after=5s "${timeout_seconds}s" \
    "${DOCKER_BIN}" compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" \
    exec -T --interactive=false "$@" </dev/null
}

require_env_file() {
  if [[ ! -f "${ENV_FILE}" ]]; then
    echo "ERROR: missing ${ENV_FILE}" >&2
    echo "Copy .env.example → .env and generate staging-only credentials." >&2
    exit 2
  fi
  if grep -q 'CHANGE_ME_staging' "${ENV_FILE}"; then
    echo "ERROR: replace CHANGE_ME_* placeholders in ${ENV_FILE} before use." >&2
    exit 2
  fi
  if grep -Ei '^(APP_URL|DB_HOST|DB_DATABASE|DB_USERNAME|DB_PASSWORD|STAGING_DB_|STAGING_MYSQL)=.*(daan\.lifenet\.com\.tw|alltrue\.com\.tw|PI_SSH|mysql://.*lifenet)' "${ENV_FILE}"; then
    echo "ERROR: staging .env must not reference production hosts/secrets." >&2
    exit 2
  fi
}

staging_evidence_dir() {
  mkdir -p "${STAGE_ROOT}/runtime/evidence"
  echo "${STAGE_ROOT}/runtime/evidence"
}

mem_snapshot() {
  echo "=== memory/swap ==="
  free -h || true
  swapon --show 2>/dev/null || true
}

available_mem_mb() {
  # Prefer MemAvailable (kB) from /proc/meminfo
  awk '/MemAvailable:/ {print int($2/1024); found=1} END{if(!found) exit 1}' /proc/meminfo
}

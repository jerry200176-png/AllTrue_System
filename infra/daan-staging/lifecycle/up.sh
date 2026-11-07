#!/usr/bin/env bash
# Start AllTrue Daan staging stack (on-demand).
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
source "${DIR}/_common.sh"

TARGET_SHA="${1:-}"
bash "${DIR}/preflight.sh"

if [[ -n "${TARGET_SHA}" ]]; then
  cd "${REPO_ROOT}"
  CURRENT="$(git rev-parse HEAD)"
  if [[ "${CURRENT}" != "${TARGET_SHA}" ]]; then
    echo "Checking out exact staging SHA ${TARGET_SHA} (was ${CURRENT})"
    git fetch --all --tags
    git checkout --detach "${TARGET_SHA}"
  fi
fi

echo "=== build + up ==="
mem_snapshot
compose build app nginx
compose up -d
mkdir -p "${STAGE_ROOT}/runtime"
bash "${DIR}/identity.sh"
echo "STAGING_UP"
echo "Local: ${STAGING_HTTP} (ssh -L ${STAGING_PORT}:127.0.0.1:${STAGING_PORT} admin@alltrue.daan.lifenet.com.tw)"

#!/usr/bin/env bash
# Start AllTrue Daan staging stack (on-demand).
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"

TARGET_SHA="${1:-}"
bash "$(dirname "$0")/preflight.sh"

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
compose build app
compose up -d
mkdir -p "${STAGE_ROOT}/runtime"
bash "$(dirname "$0")/identity.sh"
echo "STAGING_UP"
echo "Tunnel: ssh -L 18080:127.0.0.1:18080 admin@alltrue.daan.lifenet.com.tw"

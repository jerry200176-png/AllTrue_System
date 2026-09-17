#!/usr/bin/env bash
# DAAN_STAGING_V1 bounded validation cycle (requires session-scoped sudo docker).
# Phase 1: validate infra at STAGING_INFRA_SHA (default 142cac7901c5b492a539dc057d47feae2d1d7534).
# Phase 2 (optional): PRODUCT_REHEARSAL=1 redeploys MAIN_SHA for in-app #296 rehearsal.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./_common.sh
source "${DIR}/_common.sh"

STAGING_INFRA_SHA="${STAGING_INFRA_SHA:-142cac7901c5b492a539dc057d47feae2d1d7534}"
MAIN_SHA="${MAIN_SHA:-$(git -C "${REPO_ROOT}" rev-parse origin/main 2>/dev/null || git -C "${REPO_ROOT}" rev-parse HEAD)}"
PRODUCT_REHEARSAL="${PRODUCT_REHEARSAL:-0}"
EVID="$(staging_evidence_dir)"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
LOG="${EVID}/validate-cycle-${TS}.log"
exec > >(tee -a "${LOG}") 2>&1

echo "=== DAAN_STAGING_V1 validate-cycle ${TS} ==="
echo "STAGING_INFRA_SHA=${STAGING_INFRA_SHA}"
echo "MAIN_SHA=${MAIN_SHA} PRODUCT_REHEARSAL=${PRODUCT_REHEARSAL}"

mem_snapshot | tee "${EVID}/mem-before-${TS}.txt"

bash "${DIR}/preflight.sh"

echo "=== Phase 1: infra acceptance at ${STAGING_INFRA_SHA} ==="
bash "${DIR}/up.sh" "${STAGING_INFRA_SHA}"
bash "${DIR}/migrate.sh"
bash "${DIR}/identity.sh"
bash "${DIR}/health.sh"
bash "${DIR}/smoke.sh"

echo "=== restart proof ==="
bash "${DIR}/down.sh"
bash "${DIR}/up.sh" "${STAGING_INFRA_SHA}"
bash "${DIR}/migrate.sh"
bash "${DIR}/health.sh"

echo "=== rollback/redeploy proof (same SHA) ==="
bash "${DIR}/redeploy.sh" "${STAGING_INFRA_SHA}"

if [[ "${PRODUCT_REHEARSAL}" == "1" ]]; then
  echo "=== Phase 2: product rehearsal #296 at ${MAIN_SHA} ==="
  bash "${DIR}/redeploy.sh" "${MAIN_SHA}"
  bash "${DIR}/rehearse-inapp-296.sh"
fi

bash "${DIR}/down.sh"
mem_snapshot | tee "${EVID}/mem-after-${TS}.txt"

echo "VALIDATE_CYCLE_OK log=${LOG}"
echo "NOT production verified — staging evidence only"

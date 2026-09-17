#!/usr/bin/env bash
# DAAN_STAGING_V1 bounded validation cycle (requires session-scoped sudo docker).
# Phase 1: infra at STAGING_INFRA_SHA (default = HEAD; must include immutable-checkout fix).
# Phase 2 (optional): PRODUCT_REHEARSAL=1 redeploys MAIN_SHA for in-app #296 rehearsal.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./_common.sh
source "${DIR}/_common.sh"

require_sha() {
  local label="$1"
  local value="$2"
  if [[ ! "${value}" =~ ^[0-9a-f]{40}$ ]]; then
    echo "ERROR: ${label} must be a 40-char lowercase hex SHA; got '${value}'" >&2
    exit 2
  fi
}

if [[ -z "${STAGING_INFRA_SHA:-}" ]]; then
  STAGING_INFRA_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
fi
require_sha STAGING_INFRA_SHA "${STAGING_INFRA_SHA}"

if [[ -z "${MAIN_SHA:-}" ]]; then
  MAIN_SHA="$(git -C "${REPO_ROOT}" rev-parse origin/main 2>/dev/null || git -C "${REPO_ROOT}" rev-parse HEAD)"
fi
require_sha MAIN_SHA "${MAIN_SHA}"

PRODUCT_REHEARSAL="${PRODUCT_REHEARSAL:-0}"
EVID="$(staging_evidence_dir)"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
PHASE1_TAG="phase1-infra-${STAGING_INFRA_SHA:0:12}"
PHASE2_TAG="phase2-product-${MAIN_SHA:0:12}"
LOG="${EVID}/validate-cycle-${TS}.log"
exec > >(tee -a "${LOG}") 2>&1

echo "=== DAAN_STAGING_V1 validate-cycle ${TS} ==="
echo "STAGING_INFRA_SHA=${STAGING_INFRA_SHA}"
echo "MAIN_SHA=${MAIN_SHA} PRODUCT_REHEARSAL=${PRODUCT_REHEARSAL}"
echo "EVIDENCE_DIR=${EVID}"

mem_snapshot | tee "${EVID}/mem-before-${TS}.txt"

bash "${DIR}/assert-host-checkout-clean.sh"
bash "${DIR}/preflight.sh"

echo "=== Phase 1: infra acceptance at ${STAGING_INFRA_SHA} (${PHASE1_TAG}) ==="
bash "${DIR}/up.sh" "${STAGING_INFRA_SHA}"
bash "${DIR}/assert-host-checkout-clean.sh"
bash "${DIR}/migrate.sh"
bash "${DIR}/identity.sh"
bash "${DIR}/health.sh"
bash "${DIR}/smoke.sh"
bash "${DIR}/assert-host-checkout-clean.sh"
printf '%s\n' "phase=1" "sha=${STAGING_INFRA_SHA}" "result=OK" "at=${TS}" \
  > "${EVID}/${PHASE1_TAG}-up-smoke.ok"

echo "=== restart proof ==="
bash "${DIR}/down.sh"
bash "${DIR}/assert-host-checkout-clean.sh"
bash "${DIR}/up.sh" "${STAGING_INFRA_SHA}"
bash "${DIR}/migrate.sh"
bash "${DIR}/health.sh"
bash "${DIR}/assert-host-checkout-clean.sh"
printf '%s\n' "phase=1-restart" "sha=${STAGING_INFRA_SHA}" "result=OK" "at=${TS}" \
  > "${EVID}/${PHASE1_TAG}-restart.ok"

echo "=== redeploy proof (same SHA; not a true rollback) ==="
bash "${DIR}/redeploy.sh" "${STAGING_INFRA_SHA}"
bash "${DIR}/assert-host-checkout-clean.sh"
printf '%s\n' "phase=1-redeploy" "sha=${STAGING_INFRA_SHA}" "result=OK" "note=same-sha-redeploy-not-rollback" "at=${TS}" \
  > "${EVID}/${PHASE1_TAG}-redeploy.ok"

if [[ "${PRODUCT_REHEARSAL}" == "1" ]]; then
  echo "=== Phase 2: product rehearsal #296 at ${MAIN_SHA} (${PHASE2_TAG}) ==="
  export PRODUCT_REHEARSAL_MAIN_SHA="${MAIN_SHA}"
  bash "${DIR}/redeploy.sh" "${MAIN_SHA}"
  bash "${DIR}/rehearse-inapp-296.sh"
  bash "${DIR}/assert-host-checkout-clean.sh"
  printf '%s\n' "phase=2" "sha=${MAIN_SHA}" "result=OK" "product=inapp-296" "at=${TS}" \
    > "${EVID}/${PHASE2_TAG}-rehearsal.ok"
fi

bash "${DIR}/down.sh"
bash "${DIR}/assert-host-checkout-clean.sh"
mem_snapshot | tee "${EVID}/mem-after-${TS}.txt"

echo "VALIDATE_CYCLE_OK log=${LOG}"
echo "NOT production verified — staging evidence only"

#!/usr/bin/env bash
# Health check against localhost-only staging nginx.
# Canonical contract: JSON {"status":"ok"} (see scripts/production-identity.sh).
# STAGING_VALIDATOR_CONTRACT_MISMATCH: do not grep for a boolean ok field.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./_common.sh
source "${DIR}/_common.sh"

URL="${STAGING_HTTP}/api/v1/health"
echo "GET ${URL}"
BODY="$(curl -fsS --max-time 15 "${URL}")"
echo "${BODY}"
printf '%s' "${BODY}" | python3 "${DIR}/health_contract.py" || {
  echo "ERROR: health verifier rejected payload (expected status=ok)" >&2
  exit 1
}
echo "HEALTH_OK"

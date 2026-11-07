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
# php-fpm/nginx need a few seconds after `up`; retry before declaring failure.
BODY=""
for _ in $(seq 1 30); do
  BODY="$(curl -fsS --max-time 15 "${URL}" 2>/dev/null)" && break
  sleep 2
done
[[ -n "${BODY}" ]] || { echo "ERROR: ${URL} not healthy after 60s" >&2; exit 1; }
echo "${BODY}"
printf '%s' "${BODY}" | python3 "${DIR}/health_contract.py" || {
  echo "ERROR: health verifier rejected payload (expected status=ok)" >&2
  exit 1
}
echo "HEALTH_OK"

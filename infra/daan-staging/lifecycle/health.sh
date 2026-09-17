#!/usr/bin/env bash
# Health check against localhost-only staging nginx.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"

URL="${STAGING_HTTP}/api/v1/health"
echo "GET ${URL}"
BODY="$(curl -fsS --max-time 15 "${URL}")"
echo "${BODY}"
echo "${BODY}" | grep -q '"ok":true\|"ok": true' || {
  echo "ERROR: health payload missing ok:true" >&2
  exit 1
}
echo "HEALTH_OK"

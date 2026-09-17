#!/usr/bin/env bash
# Redeploy staging to an exact SHA (build/up/migrate/health/identity).
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"

SHA="${1:?Usage: redeploy.sh <exact-sha>}"
bash "$(dirname "$0")/up.sh" "${SHA}"
bash "$(dirname "$0")/migrate.sh"
bash "$(dirname "$0")/identity.sh"
bash "$(dirname "$0")/health.sh"
echo "REDEPLOY_OK sha=${SHA}"

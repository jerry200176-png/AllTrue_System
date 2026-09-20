#!/usr/bin/env bash
# Roll staging back to a prior staging SHA (redeploy path). Does not touch Pi.
set -euo pipefail
PRIOR="${1:?Usage: rollback.sh <prior-staging-sha>}"
echo "Rolling staging to ${PRIOR} (not production)"
bash "$(cd "$(dirname "$0")" && pwd)/redeploy.sh" "${PRIOR}"
echo "ROLLBACK_OK"

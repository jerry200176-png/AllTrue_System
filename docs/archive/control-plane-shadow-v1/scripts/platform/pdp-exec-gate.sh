#!/usr/bin/env bash
# pdp-exec-gate.sh — Execution gate: verify pdp.json then enforce block_merge.
#
# Usage: ./scripts/platform/pdp-exec-gate.sh [ci-artifacts/pdp.json]

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PDP="${1:-ci-artifacts/pdp.json}"
cd "$ROOT"

bash scripts/platform/control-plane-verify.sh "$PDP"

BLOCK="$(python3 scripts/platform/pdp-read.py --input "$PDP" --field block_merge --format bool)"
if [[ "$BLOCK" == "true" ]]; then
  echo "::error::pdp_v3.block_merge=true — merge blocked by canonical PDP"
  exit 1
fi

echo '{"execution_gate":"PASS","block_merge":false}'

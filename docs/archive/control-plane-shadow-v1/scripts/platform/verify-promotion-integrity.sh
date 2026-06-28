#!/usr/bin/env bash
# verify-promotion-integrity.sh — ADR-001 artifact immutability gate (CI → staging → production).
#
# Usage: verify-promotion-integrity.sh <pdp.json> <commit_sha> [staging-dir]
#
# Exits 0 only when:
#   - PDP is signed and promotion_integrity chain is valid
#   - staging artifact exists, matches PDP hashes, and is non-tampered
#   - production cannot proceed without staging_deployment_hash binding

set -euo pipefail

PDP="${1:-ci-artifacts/pdp.json}"
COMMIT="${2:?commit sha required}"
STAGING_DIR="${3:-ci-artifacts/promotion}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f "$PDP" ]]; then
  echo "::error::promotion integrity: missing PDP at $PDP"
  exit 1
fi

echo "::group::Promotion integrity (ADR-001 artifact chain)"
python3 scripts/platform/promotion_integrity.py \
  --pdp "$PDP" \
  --commit "$COMMIT" \
  --staging-dir "$STAGING_DIR" \
  --require-staging
echo "::endgroup::"

echo '{"promotion_integrity":"PASS","commit":"'"$COMMIT"'"}'

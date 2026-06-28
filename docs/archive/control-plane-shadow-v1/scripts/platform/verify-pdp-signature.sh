#!/usr/bin/env bash
# verify-pdp-signature.sh — Root-of-trust verification for ci-artifacts/pdp.json
#
# Usage: verify-pdp-signature.sh <pdp.json> [--require-staging]
#
# Enforces Ed25519 signature from platform-control-plane identity.
# Fails on unsigned, tampered, or wrong-key artifacts.

set -euo pipefail

PDP="${1:?pdp.json path required}"
shift || true
REQUIRE_STAGING=0
for arg in "$@"; do
  case "$arg" in
    --require-staging) REQUIRE_STAGING=1 ;;
  esac
done

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f "$PDP" ]]; then
  echo "::error::verify-pdp-signature: missing $PDP"
  exit 1
fi

echo "::group::PDP root-of-trust signature"
ARGS=(verify --input "$PDP")
[[ "$REQUIRE_STAGING" -eq 1 ]] && ARGS+=(--require-staging)
python3 scripts/platform/pdp_signing_authority.py "${ARGS[@]}"
echo "::endgroup::"

echo '{"pdp_root_of_trust":"PASS"}'

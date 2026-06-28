#!/usr/bin/env bash
# require-pdp-production-deploy.sh — ADR-001 gate before Pi SSH deploy.
#
# Usage: require-pdp-production-deploy.sh <ci-artifacts/pdp.json> <commit_sha> [staging-dir]
#
# Exits 0 only when PDP authorizes production and promotion artifact matches.

set -euo pipefail

PDP="${1:-ci-artifacts/pdp.json}"
COMMIT="${2:?commit sha required}"
STAGING_DIR="${3:-ci-artifacts/promotion}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f "$PDP" ]]; then
  echo "::error::ADR-001: missing canonical PDP at $PDP"
  exit 1
fi

if [[ "${PDP_SIGNING_ALLOW_TEST_KEY:-}" == "1" ]]; then
  echo "::error::production deploy cannot use PDP_SIGNING_ALLOW_TEST_KEY"
  exit 1
fi

echo "::group::GitHub Enforcement Attestation (required deploy input)"
bash scripts/platform/github-enforcement-attestation.sh
test -f ci-artifacts/enforcement/enforcement_state.json || {
  echo "::error::missing enforcement_state.json attestation snapshot"; exit 1; }
echo "::endgroup::"

echo "::group::GitHub required checks (ADR-001 platform binding)"
bash scripts/platform/verify-github-checks.sh "$COMMIT" --production
echo "::endgroup::"

echo "::group::PDP root-of-trust signature (production scope)"
bash scripts/platform/verify-pdp-signature.sh "$PDP" --require-staging
echo "::endgroup::"

echo "::group::Promotion integrity (CI → staging → production)"
bash scripts/platform/verify-promotion-integrity.sh "$PDP" "$COMMIT" "$STAGING_DIR"
echo "::endgroup::"

echo "::group::PDP production authorization (ADR-001)"
bash scripts/platform/control-plane-verify.sh "$PDP"
python3 scripts/platform/promotion-enforce.py --target production \
  --pdp "$PDP" --commit "$COMMIT" --staging-dir "$STAGING_DIR"
echo "::endgroup::"

ALLOW="$(python3 scripts/platform/pdp-read.py --input "$PDP" --field allow_production --format bool)"
if [[ "$ALLOW" != "true" ]]; then
  echo "::error::ADR-001: pdp_v3.allow_production=false — production deploy denied"
  exit 1
fi

echo "{\"adr001_production_deploy\":\"authorized\",\"commit\":\"$COMMIT\"}"

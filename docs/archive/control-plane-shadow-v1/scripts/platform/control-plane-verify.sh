#!/usr/bin/env bash
# control-plane-verify.sh — Validation only. NEVER computes policy.
#
# Usage: ./scripts/platform/control-plane-verify.sh [ci-artifacts/pdp.json]

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PDP="${1:-ci-artifacts/pdp.json}"
cd "$ROOT"

echo "::group::Secondary Authority Scan"
python3 scripts/platform/drift-nullifier.py
echo "::endgroup::"

echo "::group::PDP Schema Validation"
python3 scripts/platform/git-policy-audit.py --pdp "$PDP"
echo "::endgroup::"

echo "::group::Control Plane Lock"
python3 scripts/platform/control-plane-lock.py "$PDP"
echo "::endgroup::"

echo "::group::PDP v3 Binding Verify"
python3 scripts/platform/pdp-read.py --verify "$PDP"
echo "::endgroup::"

echo "::group::PDP Root-of-Trust Signature"
bash scripts/platform/verify-pdp-signature.sh "$PDP"
echo "::endgroup::"

echo "::group::Policy Fork Detector"
python3 scripts/platform/policy-fork-detector.py --ci "$PDP"
echo "::endgroup::"

echo "::group::PDP Replay Guard"
python3 scripts/platform/pdp-replay-guard.py --pdp "$PDP" --register
echo "::endgroup::"

echo '{"control_plane_verify":"PASS"}'

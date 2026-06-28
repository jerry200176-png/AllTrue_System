#!/usr/bin/env bash
# github-enforcement-attestation.sh — Verified applied GitHub state (not declared config).
#
# Queries live GitHub API, compares to config/github/platform-enforcement.json,
# writes ci-artifacts/enforcement/enforcement_state.json + enforcement_attestation_hash.
#
# Usage: github-enforcement-attestation.sh [--out path] [--branch main]
#
# Required input to all deployment gates. Exits 1 on ANY config/live mismatch.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="ci-artifacts/enforcement/enforcement_state.json"
BRANCH="main"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --out) OUT="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    *) shift ;;
  esac
done

cd "$ROOT"

if [[ "${SKIP_ENFORCEMENT_ATTESTATION:-}" == "1" ]]; then
  echo "::warning::SKIP_ENFORCEMENT_ATTESTATION=1 — attestation bypassed (local dev only)"
  exit 0
fi

echo "::group::GitHub Enforcement Attestation (live API truth)"
python3 scripts/platform/github_enforcement_attestation.py \
  --branch "$BRANCH" \
  --out "$OUT" \
  --write
HASH="$(python3 -c "import json; print(json.load(open('$OUT'))['enforcement_attestation_hash'])")"
echo "enforcement_attestation_hash=$HASH"
echo "::endgroup::"

echo "{\"github_enforcement_attestation\":\"PASS\",\"hash\":\"$HASH\"}"

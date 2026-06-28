#!/usr/bin/env bash
# Full-history gitleaks audit — post filter-repo, before public toggle.
# Exit 0 only when zero findings.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if command -v gitleaks >/dev/null 2>&1; then
  GITLEAKS=gitleaks
elif command -v docker >/dev/null 2>&1; then
  GITLEAKS="docker run --rm -v ${ROOT}:/repo zricethezav/gitleaks:latest detect --source /repo --verbose"
else
  echo "Install gitleaks CLI or Docker." >&2
  exit 1
fi

echo "=== gitleaks detect (full history) ==="
if [ "$GITLEAKS" = "gitleaks" ]; then
  gitleaks detect --source . --verbose --redact
else
  eval "$GITLEAKS --redact"
fi

echo "=== PASS: no gitleaks findings ==="

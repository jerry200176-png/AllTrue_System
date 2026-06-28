#!/usr/bin/env bash
# Full-history gitleaks audit — post filter-repo, before public toggle.
# Exit 0 when zero non-allowlisted findings (publication gate).

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

CONFIG="${ROOT}/.gitleaks.toml"
REPORT="$(mktemp)"
trap 'rm -f "$REPORT"' EXIT

if command -v gitleaks >/dev/null 2>&1; then
  GITLEAKS=gitleaks
elif command -v docker >/dev/null 2>&1; then
  GITLEAKS="docker"
else
  echo "Install gitleaks CLI or Docker." >&2
  exit 1
fi

echo "=== gitleaks detect (full history, allowlist: .gitleaks.toml) ==="
if [ "$GITLEAKS" = "gitleaks" ]; then
  gitleaks detect --source . --config "$CONFIG" --report-format json --report-path "$REPORT" --redact
else
  docker run --rm -v "${ROOT}:/repo" zricethezav/gitleaks:latest detect \
    --source /repo --config /repo/.gitleaks.toml --report-format json --report-path /repo/.gitleaks-report.json --redact
  cp "${ROOT}/.gitleaks-report.json" "$REPORT"
  rm -f "${ROOT}/.gitleaks-report.json"
fi

python3 - <<'PY' "$REPORT"
import json, sys
path = sys.argv[1]
with open(path) as f:
    data = json.load(f)
if not data:
    print("=== PASS: 0 gitleaks findings (publication gate) ===")
    sys.exit(0)
print(f"=== FAIL: {len(data)} non-allowlisted finding(s) ===")
for d in data:
    print(f"  - {d.get('RuleID')} | {d.get('File')}:{d.get('StartLine')}")
sys.exit(1)
PY

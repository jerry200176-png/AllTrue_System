#!/usr/bin/env bash
# Fail if UI foundation visual fixtures leak into frontend/public (production copy target).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PUBLIC="$ROOT/frontend/public"
DIST="$ROOT/frontend/dist_build"
FORBIDDEN_PATTERNS=(
  'ui-foundation-harness'
  'ui-foundation-harness-before'
  'pilot-mount'
)

fail=0

check_tree() {
  local dir="$1"
  local label="$2"
  [[ -d "$dir" ]] || return 0
  while IFS= read -r -d '' f; do
    base="$(basename "$f")"
    for pat in "${FORBIDDEN_PATTERNS[@]}"; do
      if [[ "$base" == *"$pat"* ]]; then
        echo "❌ Forbidden UI fixture in ${label}: $f"
        fail=1
      fi
    done
  done < <(find "$dir" -type f -print0 2>/dev/null)
}

check_tree "$PUBLIC" "frontend/public"
check_tree "$DIST" "frontend/dist_build"

if [[ "$fail" -ne 0 ]]; then
  echo "Move fixtures to frontend/e2e/fixtures/ui-foundation/ — never frontend/public/."
  exit 1
fi

echo "✅ No UI foundation fixtures in public/ or dist_build/"

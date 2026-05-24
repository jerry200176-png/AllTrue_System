#!/usr/bin/env bash
# Fail if tracked files in protected paths are hidden from git status
# (assume-unchanged h / skip-worktree S).
# Industry practice: visible diffs + PR review; never hide source from index (§R58).
set -euo pipefail

SCOPE="${1:-protected}"
LIST="$(git ls-files -v)"

filter_protected() {
  grep -E '^[hs] (backend/|frontend/|scripts/|\.github/|docs/)' || true
}

filter_all() {
  grep -E '^[hs]' || true
}

case "$SCOPE" in
  protected) FLAGS="$(echo "$LIST" | filter_protected)" ;;
  --all)     FLAGS="$(echo "$LIST" | filter_all)" ;;
  *)
    echo "Usage: $0 [protected|--all]"
    exit 2
    ;;
esac

if [[ -n "$FLAGS" ]]; then
  echo "❌ git index flags on tracked files ($SCOPE scope):"
  echo "$FLAGS"
  echo ""
  echo "Fix one file:  git update-index --no-assume-unchanged <path>"
  echo "Fix all (careful): git ls-files -v | awk '/^[hs]/ {print \$2}' | xargs -r git update-index --no-assume-unchanged"
  echo "See docs/AI_REGRESSION_LESSONS.md §R58"
  exit 1
fi

echo "✅ git index flags clean ($SCOPE scope)"

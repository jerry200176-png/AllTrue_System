#!/usr/bin/env bash
# Fail if tracked files in protected paths are hidden from git status.
# git ls-files -v tags: 'h' = assume-unchanged (lowercase), 'S' = skip-worktree (uppercase).
# Industry practice: visible diffs + PR review; never hide source from index (§R58).
set -euo pipefail

SCOPE="${1:-protected}"
LIST="$(git ls-files -v)"

filter_protected() {
  grep -E '^[hS] (backend/|frontend/|scripts/|\.github/|docs/)' || true
}

filter_all() {
  grep -E '^[hS]' || true
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
  echo "Fix one file:  git update-index --no-assume-unchanged --no-skip-worktree <path>"
  echo "Fix all (careful): git ls-files -v | awk '/^[hS]/ {print \$2}' | xargs -r git update-index --no-assume-unchanged --no-skip-worktree"
  echo "See docs/AI_REGRESSION_LESSONS.md §R58"
  exit 1
fi

echo "✅ git index flags clean ($SCOPE scope)"

#!/usr/bin/env bash
# Remove agent worktrees under .claude/worktrees that hold no work: clean status (untracked
# frontend/node_modules ignored) and a HEAD already on a remote branch. Anything else is kept
# and listed. Never touches the main checkout. Usage: bash scripts/dev/prune-agent-worktrees.sh [--dry-run]
set -euo pipefail
# The main checkout, also when run from inside a worktree.
root="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")"
dry="${1:-}"
cd "$root"
git fetch -q --prune origin || true
repo="$(git remote get-url origin | sed -E 's#(\.git)?$##; s#.*github\.com[:/]##')"
removed=0; kept=()
for w in "$root"/.claude/worktrees/*/; do
  [ -e "$w/.git" ] || continue
  dirty="$(git -C "$w" status --porcelain 2>/dev/null | grep -v '^?? frontend/node_modules' || true)"
  head="$(git -C "$w" rev-parse HEAD 2>/dev/null || true)"
  on_remote="$( [ -n "$head" ] && git branch -r --contains "$head" 2>/dev/null | head -1 || true)"
  # A squash-merged branch is deleted on the remote, so also accept a HEAD whose PR is merged.
  if [ -z "$on_remote" ] && [ -n "$head" ] && [ -n "${GH_TOKEN:-}" ] && [ -n "$repo" ]; then
    on_remote="$(curl -s -H "Authorization: Bearer $GH_TOKEN" "https://api.github.com/repos/$repo/commits/$head/pulls" \
      | python3 -c 'import sys,json; d=json.load(sys.stdin); print("merged" if isinstance(d,list) and any(p.get("merged_at") for p in d) else "")' 2>/dev/null || true)"
  fi
  if [ -z "$dirty" ] && [ -n "$on_remote" ]; then
    if [ "$dry" = "--dry-run" ]; then echo "would remove $(basename "$w")"; else git worktree remove --force "$w" && removed=$((removed+1)); fi
  else
    kept+=("$(basename "$w")")
  fi
done
git worktree prune
echo "removed $removed; kept ${#kept[@]}: ${kept[*]:-}"

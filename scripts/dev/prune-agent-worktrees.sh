#!/usr/bin/env bash
# Remove agent worktrees under .claude/worktrees that hold no work. A worktree is removed only when ALL hold:
#   - `git status` succeeds (a failing status, e.g. a corrupt index on a full disk, keeps the worktree);
#   - nothing is modified, staged or untracked, and no ignored file exists other than dependency, build and generated caches (listed in cache_re);
#   - its HEAD is on a remote branch, or the PR that contains HEAD is merged (squash-merged branches are deleted);
#   - it has not been touched for 2 hours (another agent may still be using a fresh worktree).
# Never touches the main checkout. Usage: bash scripts/dev/prune-agent-worktrees.sh [--dry-run]
set -euo pipefail
case "${1:-}" in
  "") dry=0 ;;
  --dry-run) dry=1 ;;
  *) echo "usage: $0 [--dry-run]" >&2; exit 2 ;;
esac
# The main checkout, also when run from inside a worktree.
root="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")"
cd "$root"
[ "$dry" = 1 ] || git fetch -q --prune origin || true
repo="$(git remote get-url origin | sed -E 's#(\.git)?$##; s#.*github\.com[:/]##')"
# Only regenerable caches. Each pattern ends at a directory boundary (a trailing /) or is a full-name match, so
# `frontend/node_modules-notes.txt` is not a cache. backend/storage is NOT excluded as a whole: only its
# framework caches and logs, because backend/storage/app holds real data (repair snapshots, public uploads).
cache_re='^!! (frontend/node_modules/|node_modules/|backend/vendor/|frontend/dist/|frontend/dist_build/|backend/storage/framework/(cache|sessions|views|testing)/|backend/storage/logs/|frontend/test-results/|frontend/playwright-report/|frontend/\.vite_cache[^/]*/|frontend/src/lib/[A-Za-z]+\.generated\.js$|([^ ]*/)?__pycache__/|[^ ]*\.pyc$)'
removed=0; kept=()
shopt -s nullglob
for w in "$root"/.claude/worktrees/*/; do
  name="$(basename "$w")"
  [ -e "$w/.git" ] || continue
  if ! st="$(git --no-optional-locks -C "$w" status --porcelain --ignored --untracked-files=all 2>/dev/null)"; then kept+=("$name(status-failed)"); continue; fi
  dirty="$(printf '%s\n' "$st" | grep -v '^$' | grep -Ev "$cache_re" || true)"
  if [ -n "$dirty" ]; then kept+=("$name(changes)"); continue; fi
  # assume-unchanged / skip-worktree files hide edits from `git status`. Reuse the audit script's detection;
  # it also exits non-zero when `git ls-files` fails, which keeps the worktree (fail safe).
  if ! (cd "$w" && bash "$root/scripts/git-index-audit.sh" --all >/dev/null 2>&1); then kept+=("$name(hidden-index-flags)"); continue; fi
  # Recent use: a commit/checkout (logs/HEAD) or an index write by the agent. Status above uses
  # --no-optional-locks, so this script itself never refreshes the index.
  gd="$(git -C "$w" rev-parse --absolute-git-dir)"
  if [ -n "$(find "$gd/logs/HEAD" "$gd/index" -maxdepth 0 -newermt '-2 hours' -print -quit 2>/dev/null)" ]; then
    kept+=("$name(recent)"); continue
  fi
  head="$(git -C "$w" rev-parse HEAD 2>/dev/null || true)"
  [ -n "$head" ] || { kept+=("$name(no-head)"); continue; }
  safe="$(git branch -r --contains "$head" 2>/dev/null | head -1 || true)"
  if [ -z "$safe" ] && [ -n "${GH_TOKEN:-}" ] && [ -n "$repo" ]; then
    safe="$(curl -s -H @<(printf 'Authorization: Bearer %s' "$GH_TOKEN") "https://api.github.com/repos/$repo/commits/$head/pulls" \
      | python3 -c 'import sys,json; d=json.load(sys.stdin); print("merged" if isinstance(d,list) and any(p.get("merged_at") for p in d) else "")' 2>/dev/null || true)"
  fi
  [ -n "$safe" ] || { kept+=("$name(unpushed)"); continue; }
  if [ "$dry" = 1 ]; then echo "would remove $name"; else git worktree remove --force "$w" && removed=$((removed+1)); fi
done
[ "$dry" = 1 ] || git worktree prune
echo "removed $removed; kept ${#kept[@]}: ${kept[*]:-}"

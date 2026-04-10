#!/usr/bin/env bash
set -euo pipefail

# One-command Git sync helper:
# - stages all changes
# - creates a commit
# - pushes to current branch upstream
#
# Usage:
#   ./scripts/git-sync.sh "feat: your message"
#   ./scripts/git-sync.sh

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

branch="$(git branch --show-current)"
if [[ -z "$branch" ]]; then
  echo "Cannot detect current branch."
  exit 1
fi

if [[ $# -gt 0 ]]; then
  commit_msg="$*"
else
  commit_msg="chore: sync update $(date '+%Y-%m-%d %H:%M')"
fi

echo "[git-sync] Branch: $branch"
echo "[git-sync] Staging changes..."
git add -A

if git diff --cached --quiet; then
  echo "[git-sync] No staged changes. Nothing to commit."
  exit 0
fi

echo "[git-sync] Committing..."
git commit -m "$commit_msg"

echo "[git-sync] Pushing..."
if git rev-parse --abbrev-ref --symbolic-full-name "@{u}" >/dev/null 2>&1; then
  git push
else
  git push -u origin "$branch"
fi

echo "[git-sync] Done."


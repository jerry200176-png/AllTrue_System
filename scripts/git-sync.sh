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

# ── Pre-sync security scan: abort if suspicious binaries detected ──
_abort_if_suspicious() {
  local found=""
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    bname=$(basename "$f")
    if [[ "$bname" == .* ]] && [ "${#bname}" -gt 15 ]; then
      case "$bname" in \
        .xsession-errors*|.bash_history|.lesshst|.wget-hsts|.phpunit.result.cache| \
        .env.*.example|.env.example| \
        .cursor-managed-skills-manifest.json|.sync-manifest.json) ;; *)
        found="$found\n  SUSPICIOUS_HIDDEN: $f"
      ;; esac
    fi
    case "$f" in *.sh|*.py|*.php|*.js|*.ts|*.vue|*.json|*.md|*.sql|*.gz|*.css|*.html|*.yml|*.yaml|*.xml|*.txt|*.log|*.png|*.jpg|*.svg|*.ico|*.woff|*.woff2|*.ttf|*.eot|*.map) continue ;; esac
    if [ -x "$f" ] && file "$f" 2>/dev/null | grep -qiE 'ELF|ARM|Mach-O|executable'; then
      found="$found\n  SUSPICIOUS_BINARY: $f"
    fi
  done < <(git ls-files --others --exclude-standard 2>/dev/null)

  if [ -n "$found" ]; then
    echo "[SECURITY-ABORT] Suspicious files detected, backup HALTED:" >&2
    printf '%b\n' "$found" >&2
    echo "[SECURITY-ABORT] Remove these files before backup can proceed." >&2
    exit 99
  fi
  echo "[git-sync] Security scan passed."
}
_abort_if_suspicious

echo "[git-sync] Staging changes..."
git add -A

if git diff --cached --quiet; then
  echo "[git-sync] No staged changes. Nothing to commit."
  exit 0
fi

echo "[git-sync] Committing..."
git commit -m "$commit_msg"

echo "[git-sync] Pushing..."
# Local `main` tracks collaboration branch `jerry-sync-main` on origin (see README).
if [[ "$branch" == "main" ]]; then
  git push origin HEAD:jerry-sync-main
elif git rev-parse --abbrev-ref --symbolic-full-name "@{u}" >/dev/null 2>&1; then
  git push
else
  git push -u origin "$branch"
fi

echo "[git-sync] Done."


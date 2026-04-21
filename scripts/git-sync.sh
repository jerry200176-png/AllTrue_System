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

# ── CODE_REVERT_GUARD: reject commits that delete or heavily shrink code paths ──
# Protected paths: backend controllers, migrations, and tests.
# Triggers when any file in these paths is deleted OR has a net removal of ≥ 30 lines.
# Bypass: set ALLOW_CODE_REVERT=1 in the environment.
_check_code_revert() {
  local GUARD_LOG="${repo_root}/backups/code-revert-guard.log"
  local PROTECTED_PREFIXES=(
    "backend/app/Http/Controllers/"
    "backend/database/migrations/"
    "backend/tests/"
  )
  local NET_DELETE_THRESHOLD=30
  local triggered_files=()

  # Parse `git diff --cached --numstat` output: <added> <deleted> <filename>
  while IFS=$'\t' read -r added deleted filepath; do
    [ -z "$filepath" ] && continue

    local is_protected=0
    for prefix in "${PROTECTED_PREFIXES[@]}"; do
      if [[ "$filepath" == "$prefix"* ]]; then
        is_protected=1
        break
      fi
    done
    [ "$is_protected" -eq 0 ] && continue

    # File deleted entirely (git marks added/deleted as "-" for binary, treat 0 additions as deletion)
    if [[ "$added" == "-" && "$deleted" == "-" ]]; then
      triggered_files+=("DELETE:$filepath")
      continue
    fi

    # Numeric net deletion
    local net_deleted=$(( ${deleted:-0} - ${added:-0} ))
    if [ "$net_deleted" -ge "$NET_DELETE_THRESHOLD" ]; then
      triggered_files+=("NET_DELETE(-${net_deleted}):$filepath")
    fi
  done < <(git diff --cached --numstat)

  # Also catch fully-deleted files (status D in --name-status)
  while IFS=$'\t' read -r status filepath; do
    [ "$status" != "D" ] && continue
    local is_protected=0
    for prefix in "${PROTECTED_PREFIXES[@]}"; do
      if [[ "$filepath" == "$prefix"* ]]; then
        is_protected=1
        break
      fi
    done
    [ "$is_protected" -eq 0 ] && continue
    # Avoid duplicates
    local already=0
    for entry in "${triggered_files[@]}"; do
      [[ "$entry" == "DELETE:$filepath" ]] && already=1 && break
    done
    [ "$already" -eq 0 ] && triggered_files+=("DELETE:$filepath")
  done < <(git diff --cached --name-status)

  if [ "${#triggered_files[@]}" -eq 0 ]; then
    return 0
  fi

  local timestamp
  timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
  local log_entry="[${timestamp}] CODE_REVERT_GUARD triggered by: ${triggered_files[*]}"

  mkdir -p "$(dirname "$GUARD_LOG")"
  echo "$log_entry" >> "$GUARD_LOG"

  echo "[CODE_REVERT_GUARD] Commit BLOCKED at ${timestamp}" >&2
  echo "[CODE_REVERT_GUARD] Triggered by:" >&2
  for f in "${triggered_files[@]}"; do
    echo "  - $f" >&2
  done
  echo "[CODE_REVERT_GUARD] To bypass: ALLOW_CODE_REVERT=1 $0 \"$commit_msg\"" >&2
  echo "[CODE_REVERT_GUARD] Log: $GUARD_LOG" >&2
  return 1
}

if [ "${ALLOW_CODE_REVERT:-0}" != "1" ]; then
  if ! _check_code_revert; then
    exit 1
  fi
  echo "[git-sync] Code revert guard passed."
else
  echo "[git-sync] ALLOW_CODE_REVERT=1 — skipping code revert guard."
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


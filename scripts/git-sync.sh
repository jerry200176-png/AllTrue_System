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
  local NET_DELETE_THRESHOLD=30
  local _tmpfile
  _tmpfile=$(mktemp)
  local _triggered=""

  # Dump numstat to temp file to avoid subshell array-propagation issues
  git diff --cached --numstat > "$_tmpfile" 2>/dev/null || true

  while IFS=$'\t' read -r _added _deleted _filepath; do
    [ -z "$_filepath" ] && continue

    local _is_protected=0
    for _prefix in "backend/app/Http/Controllers/" "backend/database/migrations/" "backend/tests/"; do
      if [[ "$_filepath" == "$_prefix"* ]]; then
        _is_protected=1
        break
      fi
    done
    [ "$_is_protected" -eq 0 ] && continue

    # Binary or fully-deleted file shows "-" in both columns
    if [[ "$_added" == "-" && "$_deleted" == "-" ]]; then
      _triggered="${_triggered}DELETE:${_filepath};"
      continue
    fi

    local _net_deleted=$(( ${_deleted:-0} - ${_added:-0} ))
    if [ "$_net_deleted" -ge "$NET_DELETE_THRESHOLD" ]; then
      _triggered="${_triggered}NET_DELETE(-${_net_deleted}):${_filepath};"
    fi
  done < "$_tmpfile"

  # Also catch D-status (fully deleted committed file)
  git diff --cached --name-status > "$_tmpfile" 2>/dev/null || true
  while IFS=$'\t' read -r _status _filepath; do
    [ "$_status" != "D" ] && continue
    local _is_protected=0
    for _prefix in "backend/app/Http/Controllers/" "backend/database/migrations/" "backend/tests/"; do
      if [[ "$_filepath" == "$_prefix"* ]]; then
        _is_protected=1
        break
      fi
    done
    [ "$_is_protected" -eq 0 ] && continue
    # Avoid duplicates
    [[ "$_triggered" != *"DELETE:${_filepath};"* ]] && _triggered="${_triggered}DELETE:${_filepath};"
  done < "$_tmpfile"

  rm -f "$_tmpfile"

  if [ -z "$_triggered" ]; then
    return 0
  fi

  local timestamp
  timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
  local log_entry="[${timestamp}] CODE_REVERT_GUARD triggered by: ${_triggered}"

  mkdir -p "$(dirname "$GUARD_LOG")"
  echo "$log_entry" >> "$GUARD_LOG"

  echo "[CODE_REVERT_GUARD] Commit BLOCKED at ${timestamp}" >&2
  echo "[CODE_REVERT_GUARD] Triggered by: ${_triggered}" >&2
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


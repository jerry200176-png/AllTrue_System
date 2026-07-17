#!/usr/bin/env bash

# Inspect every outgoing commit before a push. This catches ignored runtime
# artifacts embedded in old branch history; checking only the current index or
# working tree cannot prevent that history from being republished.

set -euo pipefail

remote_name="${1:-origin}"
zero_sha='0000000000000000000000000000000000000000'

is_forbidden_path() {
  case "$1" in
    .cursor/projects/* | \
    .env.monitor | \
    TelegramWebHook/* | \
    'AllTrue (3).sql' | \
    backups/*.sql | backups/*.sql.gz | backups/**/*.sql | backups/**/*.sql.gz | \
    backend/storage/backups/* | \
    backend/bootstrap/cache/config.php | \
    docs/AllTrue_backup.sql | \
    docs/archive/control-plane-shadow-v1/config/platform/*.pem | \
    scripts/raspberry-pi/nppBackup/*)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

check_commit() {
  local commit="$1"
  local path

  while IFS= read -r -d '' path; do
    if is_forbidden_path "$path"; then
      printf 'ERROR: outgoing commit %.12s contains forbidden path: %s\n' "$commit" "$path" >&2
      return 1
    fi
  done < <(git diff-tree --root --no-commit-id --name-only -r -z "$commit")
}

block_push() {
  echo "Push blocked: remove the sensitive path from every outgoing commit before publishing." >&2
  echo "For old history, create a clean branch from current origin/main and cherry-pick only safe commits." >&2
  exit 1
}

while read -r local_ref local_sha remote_ref remote_sha; do
  [ -n "${local_ref:-}" ] || continue
  [ "$local_sha" != "$zero_sha" ] || continue

  if [ "$remote_sha" = "$zero_sha" ]; then
    while IFS= read -r commit; do
      if [ -n "$commit" ] && ! check_commit "$commit"; then
        block_push
      fi
    done < <(git rev-list "$local_sha" --not --remotes="$remote_name")
  else
    while IFS= read -r commit; do
      if [ -n "$commit" ] && ! check_commit "$commit"; then
        block_push
      fi
    done < <(git rev-list "$remote_sha..$local_sha")
  fi
done

echo "pre-push-security-guard: OK"

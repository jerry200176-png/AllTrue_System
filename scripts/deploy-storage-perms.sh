#!/usr/bin/env bash
# deploy.yml [2b/7]: keep Laravel's writable dirs at the historical 775 but never
# widen storage/app/private, which holds host-local secrets (POP machine key,
# reviewed financial manifest) whose writers set restrictive modes.
# Runs from the backend directory (default: /home/admin/backend). Idempotent.
set -uo pipefail
cd "${1:-/home/admin/backend}" || exit 1
P=storage/app/private

find storage bootstrap/cache -path "$P" -prune -o -exec chmod 775 {} + 2>/dev/null
if [ -d "$P" ]; then
  # Undo world access left by the old recursive chmod (files only; group bits
  # double as a named-user ACL mask, so they are left alone).
  find "$P" -type f -perm /o=rwx -exec chmod o-rwx {} + 2>/dev/null
  if [ -f "$P/pop-machine.key" ]; then chmod 600 "$P/pop-machine.key" 2>/dev/null; fi
  if [ -n "$(find "$P" -type f -perm /o=rwx -print -quit 2>/dev/null)" ]; then
    echo "::warning::$P still has world-accessible files (owner differs from deploy user)"
  fi
fi
exit 0

#!/usr/bin/env bash
# deploy.yml [2b/7]: keep Laravel's writable dirs at the historical 775 but never
# widen storage/app/private, which holds host-local secrets (POP machine key,
# reviewed financial manifest) whose writers set restrictive modes.
# Runs from the backend directory (default: /home/admin/backend). Idempotent.
# Exit 3: a private file is still world-accessible, a private directory is
# world-writable, the subtree cannot be fully scanned or contains a symlinked
# directory (or is one), or the key is not 600.
set -uo pipefail
cd "${1:-/home/admin/backend}" || exit 1
P=storage/app/private

# Only real files and directories: chmod follows a symlink named on its command
# line (unlike chmod -R during traversal), which could widen the link target.
find storage bootstrap/cache -path "$P" -prune -o \( -type f -o -type d \) -exec chmod 775 {} + 2>/dev/null
status=0
# find does not follow symlinks, so a linked private root or subdirectory would
# be skipped by the checks below and reported as hardened. Refuse it instead.
if [ -L "$P" ]; then
  echo "::error::$P is a symlink; refusing to report it as hardened"
  status=3
elif [ -d "$P" ] && [ -n "$(find "$P" -type l -xtype d -print -quit 2>/dev/null)" ]; then
  echo "::error::$P contains a symlinked directory; refusing to report it as hardened"
  status=3
fi
if [ "$status" = 0 ] && [ -d "$P" ]; then
  # Undo world access left by the old recursive chmod. Files lose all world
  # bits (group bits double as a named-user ACL mask, so they are left alone);
  # directories lose world-write so nobody else can replace a manifest.
  find "$P" -type f -perm /o=rwx -exec chmod o-rwx {} + 2>/dev/null
  find "$P" -type d -perm /o=w -exec chmod o-w {} + 2>/dev/null
  if [ -L "$P/pop-machine.key" ]; then
    echo "::error::$P/pop-machine.key is a symlink; refusing to follow it"
    status=3
  elif [ -f "$P/pop-machine.key" ] && ! chmod 600 "$P/pop-machine.key" 2>/dev/null; then
    echo "::error::$P/pop-machine.key could not be set to 600"
    status=3
  fi
  # The verification scan must itself succeed: an unreadable subdirectory
  # would otherwise look like "nothing exposed".
  if ! exposed="$(find "$P" \( -type f -perm /o=rwx -o -type d -perm /o=w \) -print -quit 2>/dev/null)"; then
    echo "::error::$P could not be fully scanned (unreadable subdirectory)"
    status=3
  elif [ -n "$exposed" ]; then
    echo "::error::$P still has world-accessible files or world-writable directories (owner differs from deploy user)"
    status=3
  fi
fi
# Non-zero only for the private subtree: the caller aborts the deploy rather
# than report storage as hardened when it is not.
exit "$status"

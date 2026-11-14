#!/usr/bin/env bash
# Regression test for scripts/dev/prune-agent-worktrees.sh. Builds a throwaway repo in a temp dir (never
# touches the real checkout's worktrees) and checks which worktrees --dry-run would remove.
# PRUNE_SCRIPT overrides the script under test.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${PRUNE_SCRIPT:-$ROOT/scripts/dev/prune-agent-worktrees.sh}"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null HOME="$T/home"; mkdir -p "$HOME"
G=(git -c user.name=t -c user.email=t@example.com)

git init -q --bare "$T/origin.git"
git init -q -b main "$T/repo"
cd "$T/repo"
git remote add origin "$T/origin.git"
printf 'node_modules/\nfrontend/node_modules/\nbackend/storage/\n' >.gitignore
echo x >a.txt
"${G[@]}" add -A
"${G[@]}" commit -qm init
git push -q origin main

mk() { # name: an old worktree whose HEAD is on the remote
  git worktree add -q --detach ".claude/worktrees/$1" main
  d="$(git -C ".claude/worktrees/$1" rev-parse --absolute-git-dir)"
  touch -d '5 hours ago' "$d/logs/HEAD" "$d/index"
}
W=.claude/worktrees

mk clean-cache-only
mkdir -p $W/clean-cache-only/node_modules/x $W/clean-cache-only/backend/storage/framework/cache
echo y >$W/clean-cache-only/node_modules/x/i.js
echo y >$W/clean-cache-only/backend/storage/framework/cache/c

mk untracked-hidden
echo z >$W/untracked-hidden/new.txt
git -C $W/untracked-hidden config --worktree status.showUntrackedFiles no 2>/dev/null \
  || git config status.showUntrackedFiles no

mk repair-snapshot
mkdir -p $W/repair-snapshot/backend/storage/app/repair-snapshots
echo s >$W/repair-snapshot/backend/storage/app/repair-snapshots/snap.json

mk notes-file
mkdir -p $W/notes-file/frontend
echo n >$W/notes-file/frontend/node_modules-notes.txt

mk storage-public
mkdir -p $W/storage-public/backend/storage/app/public
echo p >$W/storage-public/backend/storage/app/public/f.png

out="$(bash "$SCRIPT" --dry-run 2>&1)"; echo "$out"
fail() { echo "FAIL: $*"; exit 1; }
printf '%s\n' "$out" | grep -qx 'would remove clean-cache-only' || fail "cache-only worktree must be removable"
for k in untracked-hidden repair-snapshot notes-file storage-public; do
  ! printf '%s\n' "$out" | grep -qx "would remove $k" || fail "$k holds real files and must be kept"
done
echo OK

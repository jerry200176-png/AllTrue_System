#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GUARD="$ROOT/scripts/pre-push-security-guard.sh"
TMP="$(mktemp -d)"
zero_sha='0000000000000000000000000000000000000000'
trap 'rm -rf "$TMP"' EXIT

git -C "$TMP" init -q
git -C "$TMP" config user.name 'Guard Test'
git -C "$TMP" config user.email 'guard-test@example.invalid'
git -C "$TMP" remote add origin "$TMP/unused-remote"

echo safe > "$TMP/safe.txt"
git -C "$TMP" add safe.txt
git -C "$TMP" commit -qm 'test: safe base'
safe_sha="$(git -C "$TMP" rev-parse HEAD)"
git -C "$TMP" update-ref refs/remotes/origin/main "$safe_sha"

git -C "$TMP" checkout -qb safe-branch
echo more >> "$TMP/safe.txt"
git -C "$TMP" commit -qam 'test: safe change'
safe_head="$(git -C "$TMP" rev-parse HEAD)"
printf 'refs/heads/safe-branch %s refs/heads/safe-branch %s\n' "$safe_head" "$zero_sha" \
  | (cd "$TMP" && "$GUARD" origin) >/dev/null

mkdir -p "$TMP/.cursor/projects"
echo artifact > "$TMP/.cursor/projects/session.jsonl"
git -C "$TMP" add -f .cursor/projects/session.jsonl
git -C "$TMP" commit -qm 'test: forbidden history'
bad_head="$(git -C "$TMP" rev-parse HEAD)"

if printf 'refs/heads/bad %s refs/heads/bad %s\n' "$bad_head" "$zero_sha" \
  | (cd "$TMP" && "$GUARD" origin) >/dev/null 2>&1; then
  echo 'ERROR: guard allowed forbidden outgoing history' >&2
  exit 1
fi

if ! printf 'refs/heads/bad %s refs/heads/bad %s\n' "$zero_sha" "$bad_head" \
  | (cd "$TMP" && "$GUARD" origin) >/dev/null; then
  echo 'ERROR: guard blocked branch deletion' >&2
  exit 1
fi

echo 'pre-push-security-guard test: OK'

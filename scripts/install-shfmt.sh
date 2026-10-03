#!/usr/bin/env bash
# Install the pinned mvdan/sh shfmt (BSD-3) used by .claude/hooks/guard_bash.py
# to .claude/hooks/bin/shfmt (gitignored). Without it the guard falls back to
# the regex implementation (guard_bash_regex.py).
set -euo pipefail
VER=v3.14.1
# sha256 of shfmt_v3.14.1_linux_amd64 (GitHub release asset digest for mvdan/sh v3.14.1).
SHA=76e77641faa025814b77f153b29796b8e6fa2fca03e0c76a691608b86c7ea7bf
DEST="$(cd "$(dirname "$0")/.." && pwd)/.claude/hooks/bin/shfmt"
mkdir -p "$(dirname "$DEST")"
tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
curl -fsSL -o "$tmp" "https://github.com/mvdan/sh/releases/download/$VER/shfmt_${VER}_linux_amd64"
echo "$SHA  $tmp" | sha256sum -c - >/dev/null || { echo "shfmt sha256 mismatch" >&2; exit 1; }
install -m 0755 "$tmp" "$DEST"
echo "installed $DEST ($("$DEST" --version))"

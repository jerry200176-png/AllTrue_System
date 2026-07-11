#!/bin/bash
# MemPalace PreCompact hook for Claude Code — emergency save before context compression.
# Fail-safe: if the MemPalace CLI is missing (external tool not installed / path changed —
# see #996/#999), drain stdin and exit 0 so the hook never disrupts the session or emits noise.
BIN="/home/jerry/.local/bin/mempalace"
if [ -x "$BIN" ]; then
  cat - | "$BIN" hook run --hook precompact --harness claude-code 2>/dev/null || true
else
  cat - >/dev/null 2>&1 || true
fi
exit 0

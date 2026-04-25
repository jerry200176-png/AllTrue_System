#!/bin/bash
# MemPalace SessionStart hook for Claude Code — inject relevant past memories
cat - | /home/jerry/.local/bin/mempalace hook run --hook session-start --harness claude-code

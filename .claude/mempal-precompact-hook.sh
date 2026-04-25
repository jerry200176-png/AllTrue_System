#!/bin/bash
# MemPalace PreCompact hook for Claude Code — emergency save before context compression
cat - | /home/jerry/.local/bin/mempalace hook run --hook precompact --harness claude-code

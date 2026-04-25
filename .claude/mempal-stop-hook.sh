#!/bin/bash
# MemPalace Stop hook for Claude Code — every 15 messages, AI saves key decisions to palace
cat - | /home/jerry/.local/bin/mempalace hook run --hook stop --harness claude-code

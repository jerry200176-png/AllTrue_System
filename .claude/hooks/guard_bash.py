#!/usr/bin/env python3
"""PreToolUse Bash guard entry point. Delegates to the regex guard
(guard_bash_regex.py); kept as a stable path for .claude/settings.json."""
from guard_bash_regex import main

if __name__ == "__main__":
    main()

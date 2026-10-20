#!/usr/bin/env python3
"""Fail if pop-mount workflow remote regresses to world-readable or weak identity."""
from __future__ import annotations

import pathlib
import re
import sys

PATH = pathlib.Path(".github/workflows/pop-mount-reviewed-financial-once.yml")


def main() -> int:
    text = PATH.read_text(encoding="utf-8")
    for pat in (r"chmod\s+644", r"chmod\s+o\+r", r"else\s+chmod\s+64"):
        if re.search(pat, text):
            print(f"FAIL A1: forbidden pattern /{pat}/ in {PATH}", file=sys.stderr)
            return 1

    if "bash -s <<'REMOTE'" not in text and "bash -s <<\"REMOTE\"" not in text:
        # Still allow a single-quoted remote if it meets other guards.
        blob = text
    else:
        m = re.search(r"bash -s <<'REMOTE'\n(.*?\n)\s*REMOTE\n", text, re.S)
        blob = m.group(1) if m else text

    if re.search(r"sudo\s+-n\s+chown", blob) and not re.search(r"sudo\s+-n\s+chmod", blob):
        print("FAIL A2: sudo -n chown without sudo -n chmod in remote", file=sys.stderr)
        return 1
    if "/proc/" not in blob or "Uid:" not in blob:
        print("FAIL A3: remote lacks /proc worker Uid binding", file=sys.stderr)
        return 1
    if not re.search(r"world-accessible|mode % 10|refusing", blob, re.I):
        print("FAIL A4: no fail-closed other-read guard in remote", file=sys.stderr)
        return 1
    if "www-data:www-data" in blob and "pool_user" not in blob:
        print("FAIL A3b: name-guess chown without pool_user binding", file=sys.stderr)
        return 1

    print("OK pop-mount remote guards A1–A4")
    return 0


if __name__ == "__main__":
    sys.exit(main())

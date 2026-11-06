#!/usr/bin/env python3
"""Reads {"body", "files":[{"filename","patch"}]} on stdin; prints "r3" or "lower".

Used by .github/scripts/review-gate.mjs. R3 when the declared tier is R3/T3, the declaration is
missing/duplicated/non-ASCII, or the changed files' machine minimum is R3. One parser: autonomy_gate.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.governance.autonomy_gate import commit_effective_class  # noqa: E402

_DECL_LINE = re.compile(r"(?i)^.*(?:Risk-Class|Autonomy-Tier).*$", re.M)


def requires_security(body: str, files: list[dict]) -> bool:
    lines = _DECL_LINE.findall(body)
    for key in ("risk-class", "autonomy-tier"):
        if sum(key in x.lower() for x in lines) > 1:
            return True
    if any(not x.isascii() or " " in x or " " in x for x in lines):
        return True
    # One classifier: renames (previous_filename), missing patch/filename, the file cap and an invalid
    # declaration all come back None from commit_effective_class and count as R3.
    tier = commit_effective_class(body, files)
    return tier is None or tier >= 3


if __name__ == "__main__":
    data = json.load(sys.stdin)
    print("r3" if requires_security(str(data.get("body") or ""), data.get("files") or []) else "lower")

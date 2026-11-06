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
from scripts.governance.autonomy_gate import _RISK_RE, _TIER_RE, commit_effective_class  # noqa: E402

_BREAKS = re.compile("[\r\x0b\x0c\x1c-\x1e\x85\u2028\u2029]")


def requires_security(body: str, files: list[dict]) -> bool:
    # Only lines parse_declaration itself reads as fields (its own regexes) count; headings and checklist text do not.
    norm = _BREAKS.sub("\n", body)
    if len(_RISK_RE.findall(norm)) > 1 or len(_TIER_RE.findall(norm)) > 1:
        return True
    for m in (*_RISK_RE.finditer(body), *_TIER_RE.finditer(body)):
        line = body[m.start():].split("\n", 1)[0]
        if not line.isascii() or _BREAKS.search(line):
            return True
    # One classifier: renames (previous_filename), missing patch/filename, the 300-file cap and an invalid
    # declaration all come back None from commit_effective_class and count as R3.
    tier = commit_effective_class(body, files)
    return tier is None or tier >= 3


if __name__ == "__main__":
    data = json.load(sys.stdin)
    print("r3" if requires_security(str(data.get("body") or ""), data.get("files") or []) else "lower")

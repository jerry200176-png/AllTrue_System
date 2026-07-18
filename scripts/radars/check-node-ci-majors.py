#!/usr/bin/env python3
"""Warn (exit 1) if workflows pin more than one distinct Node major version."""
from __future__ import annotations

import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
majors: list[str] = []
for path in sorted((ROOT / ".github/workflows").glob("*.yml")):
    text = path.read_text(encoding="utf-8", errors="replace")
    for hit in re.finditer(r"node-version:\s*[\"']?([0-9.]+)", text):
        major = hit.group(1).split(".")[0]
        majors.append(major)
        print(f"{path.name}: node major {major}")

if not majors:
    print("no node-version found (ok)")
    sys.exit(0)

counts = Counter(majors)
print(f"node major counts: {dict(counts)}")
if len(counts) > 1:
    print(
        "multiple Node majors in CI — align workflows to one major to reduce drift",
        file=sys.stderr,
    )
    sys.exit(1)
sys.exit(0)

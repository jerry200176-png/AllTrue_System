#!/usr/bin/env python3
"""Fail if CI php-version majors.minor disagree with composer.json require.php."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
composer = json.loads((ROOT / "backend/composer.json").read_text(encoding="utf-8"))
req = str(
    (composer.get("config") or {}).get("platform", {}).get("php")
    or composer.get("require", {}).get("php")
    or ""
)
m = re.search(r"(\d+)\.(\d+)", req)
if not m:
    print(f"composer.php unparseable: {req!r}", file=sys.stderr)
    sys.exit(1)
want = f"{m.group(1)}.{m.group(2)}"

ci_versions: set[str] = set()
for path in (ROOT / ".github/workflows").glob("*.yml"):
    text = path.read_text(encoding="utf-8", errors="replace")
    for hit in re.finditer(r"php-version:\s*[\"']?([0-9.]+)", text):
        parts = hit.group(1).split(".")
        if len(parts) >= 2:
            ci_versions.add(f"{parts[0]}.{parts[1]}")
        else:
            ci_versions.add(parts[0])

if not ci_versions:
    print("no php-version found in .github/workflows", file=sys.stderr)
    sys.exit(1)

bad = sorted(v for v in ci_versions if v != want)
print(f"composer.php={req} want={want} ci={sorted(ci_versions)}")
if bad:
    print(f"mismatch: {bad}", file=sys.stderr)
    sys.exit(1)
sys.exit(0)

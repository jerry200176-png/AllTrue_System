#!/usr/bin/env python3
"""DEPRECATED — promotion checks merged into promotion-enforce.py (execution-only)."""
from __future__ import annotations

import sys

print(
    '{"deprecated":true,"reason":"Use promotion-enforce.py — sole promotion execution path"}',
    file=sys.stderr,
)
raise SystemExit(2)

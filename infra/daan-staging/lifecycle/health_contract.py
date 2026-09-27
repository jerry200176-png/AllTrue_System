#!/usr/bin/env python3
"""Canonical staging health payload contract (status=ok).

Matches production-identity / ops evidence: /api/v1/health returns
{"status":"ok", ...}. Do not accept legacy ok:true as the primary contract.
"""

from __future__ import annotations

import json
import sys
from typing import Any


def health_payload_is_ok(body: str | bytes | None) -> bool:
    """Return True only when body is JSON with status exactly \"ok\"."""
    if body is None:
        return False
    if isinstance(body, bytes):
        try:
            body = body.decode("utf-8")
        except UnicodeDecodeError:
            return False
    text = body.strip()
    if not text:
        return False
    try:
        payload: Any = json.loads(text)
    except json.JSONDecodeError:
        return False
    if not isinstance(payload, dict):
        return False
    return payload.get("status") == "ok"


def main(argv: list[str]) -> int:
    raw = sys.stdin.read() if not argv else argv[0]
    if health_payload_is_ok(raw):
        return 0
    print("ERROR: health payload must be JSON with status=ok (fail closed)", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))

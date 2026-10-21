#!/usr/bin/env python3
"""List credentials in docs/ops/credential-expiry.yml that expire within lead_days.

Prints JSON: {"due": [{name, owner, expires_on, rotate_at, days_left, title}], "undated": [names]}.
Reads names and dates only; the registry never holds secret values.
"""
from __future__ import annotations

import datetime as dt
import json
import sys
from pathlib import Path

import yaml

DEFAULT = Path(__file__).resolve().parents[1] / "docs" / "ops" / "credential-expiry.yml"


def evaluate(registry: dict, today: dt.date) -> dict:
    lead = int(registry.get("lead_days", 14))
    due, undated = [], []
    for c in registry.get("credentials") or []:
        name = str(c["name"])
        raw = c.get("expires_on")
        if raw is None:
            undated.append(name)
            continue
        exp = raw if isinstance(raw, dt.date) else dt.date.fromisoformat(str(raw))
        left = (exp - today).days
        if left <= lead:  # includes already expired (negative)
            due.append({
                "name": name, "owner": c.get("owner", ""), "expires_on": exp.isoformat(),
                "rotate_at": c.get("rotate_at", ""), "days_left": left,
                "title": f"[security] Credential {name} expires {exp.isoformat()}",
            })
    return {"due": sorted(due, key=lambda d: d["days_left"]), "undated": undated}


def main(argv: list[str]) -> int:
    path = Path(argv[1]) if len(argv) > 1 else DEFAULT
    today = dt.date.fromisoformat(argv[2]) if len(argv) > 2 else dt.datetime.now(dt.timezone.utc).date()
    print(json.dumps(evaluate(yaml.safe_load(path.read_text(encoding="utf-8")), today), ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

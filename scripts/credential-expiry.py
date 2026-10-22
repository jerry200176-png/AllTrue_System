#!/usr/bin/env python3
"""List credentials that expire within lead_days plus an optional polling buffer.

Prints JSON: {"due": [{name, owner, expires_on, rotate_at, days_left, title}], "undated": [names]}.
Reads names and dates only; the registry never holds secret values.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import sys
from pathlib import Path

import yaml

DEFAULT = Path(__file__).resolve().parents[1] / "docs" / "ops" / "credential-expiry.yml"


def evaluate(registry: dict, today: dt.date, poll_interval_days: int = 1) -> dict:
    if not isinstance(registry, dict) or not isinstance(registry.get("credentials"), list):
        raise ValueError("registry must contain a credentials list")
    lead = registry.get("lead_days", 14)
    if type(lead) is not int or lead < 0:
        raise ValueError("lead_days must be a nonnegative integer")
    if type(poll_interval_days) is not int or poll_interval_days < 1:
        raise ValueError("poll interval must be a positive integer")
    # A weekly check can first see a date six calendar days after it enters the
    # window. Adding six preserves lead_days on an on-time weekly schedule.
    threshold = lead + poll_interval_days - 1
    due, undated = [], []
    names = set()
    for c in registry["credentials"]:
        if not isinstance(c, dict) or not isinstance(c.get("name"), str) or not c["name"].strip():
            raise ValueError("each credential must have a nonempty name")
        name = c["name"]
        if name in names:
            raise ValueError("credential names must be unique")
        names.add(name)
        raw = c.get("expires_on")
        if raw is None:
            undated.append(name)
            continue
        exp = raw if isinstance(raw, dt.date) else dt.date.fromisoformat(str(raw))
        left = (exp - today).days
        if left <= threshold:  # includes already expired (negative)
            due.append({
                "name": name, "owner": c.get("owner", ""), "expires_on": exp.isoformat(),
                "rotate_at": c.get("rotate_at", ""), "days_left": left,
                "title": f"[security] Credential {name} expires {exp.isoformat()}",
            })
    return {"due": sorted(due, key=lambda d: d["days_left"]), "undated": undated}


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("registry", nargs="?", type=Path, default=DEFAULT)
    parser.add_argument("today", nargs="?", type=dt.date.fromisoformat, default=dt.datetime.now(dt.timezone.utc).date())
    parser.add_argument("--poll-interval-days", type=int, default=1)
    args = parser.parse_args(argv[1:])
    print(json.dumps(evaluate(yaml.safe_load(args.registry.read_text(encoding="utf-8")), args.today, args.poll_interval_days), ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

#!/usr/bin/env python3
"""Verify the successor Sunrise pin's provenance; never execute or adopt its text."""
import hashlib
import re
import sys
from urllib.request import urlopen

SUNRISE = "https://raw.githubusercontent.com/jerry200176-png/sunrise-cafe/main/governance/"
SOURCE_REPO = "jerry200176-png/portfolio-ops"
MAX_BYTES = 1024 * 1024


def read_url(url):
    with urlopen(url, timeout=20) as response:
        payload = response.read(MAX_BYTES + 1)
    if len(payload) > MAX_BYTES:
        raise ValueError("rulebook input exceeds 1 MiB")
    return payload


def parse_lock(payload):
    required = {"source_repo", "source_ref", "sha256"}
    fields = {}
    for line in payload.decode("utf-8").splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        key, separator, value = line.partition("=")
        if not separator:
            raise ValueError("malformed rulebook lock line")
        if key not in required:
            continue  # Scan settings are owned by Sunrise, not this provenance check.
        if key in fields:
            raise ValueError(f"duplicate lock field: {key}")
        fields[key] = value
    if fields.keys() != required:
        raise ValueError("missing rulebook provenance field")
    if fields["source_repo"] != SOURCE_REPO:
        raise ValueError("unexpected rulebook source repository")
    if not re.fullmatch(r"[0-9a-f]{40}", fields["source_ref"]):
        raise ValueError("rulebook source_ref must be an immutable full commit SHA")
    if not re.fullmatch(r"[0-9a-f]{64}", fields["sha256"]):
        raise ValueError("invalid rulebook sha256")
    return fields


def verify(fetch=read_url):
    fields = parse_lock(fetch(SUNRISE + "RULEBOOK.lock"))
    source = f"https://raw.githubusercontent.com/{SOURCE_REPO}/{fields['source_ref']}/governance/RULEBOOK.md"
    for label, url in [("vendored", SUNRISE + "RULEBOOK.md"), ("pinned source", source)]:
        digest = hashlib.sha256(fetch(url)).hexdigest()
        if digest != fields["sha256"]:
            raise ValueError(f"{label} rulebook sha256 mismatch")
    return fields["source_ref"]


def main():
    try:
        source_ref = verify()
    except Exception as error:
        # Avoid exception URLs/response bodies in logs; only bounded classifications.
        message = str(error) if isinstance(error, ValueError) else type(error).__name__
        print(f"overlay-pin: FAIL: rulebook provenance: {message}", file=sys.stderr)
        return 1
    print(f"overlay-pin: OK: sunrise rulebook source={source_ref}; AllTrue Constitution authority unchanged")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

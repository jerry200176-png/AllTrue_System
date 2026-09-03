#!/usr/bin/env python3
"""Extract and compare secret fingerprints without printing secret values."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path


PATTERNS = {
    "TELEGRAM": (
        re.compile(rb"(?<![A-Za-z0-9_-])\d{6,12}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9_-])"),
    ),
    "APP_KEY": (
        re.compile(rb"(?i)APP_KEY(?:\\?[\"']|\s)*[:=](?:\\?[\"']|\s)*(base64:[A-Za-z0-9+/]{40,}={0,2})"),
    ),
    "BEARER": (
        re.compile(rb"(?i)Bearer(?:\\?[\"']|\s)+([A-Za-z0-9._~+/=-]{20,})"),
        re.compile(rb"(?i)Authorization(?:\\?[\"']|\s)*[:=](?:\\?[\"']|\s)*(?:Bearer(?:\\?[\"']|\s)+)?([A-Za-z0-9._~+/=-]{20,})"),
    ),
    # Added for issue #1387 (AllTrue_System) — a hard-coded MySQL/CI database
    # password. Covers the two known real shapes (YAML `MYSQL_PASSWORD:` /
    # `DB_PASSWORD:`, and the XML `<env name="DB_PASSWORD" ... value="...">`
    # attribute pair used in phpunit.xml) plus a generic `.env`-style
    # `DB_PASSWORD=value` fallback.
    "DB_PASSWORD": (
        re.compile(rb"(?i)(?:DB_PASSWORD|MYSQL_PASSWORD)(?:\\?[\"']|\s)*:(?:\\?[\"']|\s)*([^\s\"'\\]{4,})"),
        re.compile(rb'(?i)name\s*=\s*"DB_PASSWORD"[^>]*?\bvalue\s*=\s*"([^"]{4,})"'),
        re.compile(rb"(?i)\bDB_PASSWORD(?:\\?[\"']|\s)*=(?:\\?[\"']|\s)*([^\s\"']{4,})"),
    ),
}

MAX_FILE_BYTES = 25 * 1024 * 1024


def fingerprint(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def extract(paths: list[Path]) -> set[tuple[str, str]]:
    findings: set[tuple[str, str]] = set()
    for root in paths:
        candidates = root.rglob("*") if root.is_dir() else (root,)
        for path in candidates:
            if not path.is_file():
                continue
            try:
                if path.stat().st_size > MAX_FILE_BYTES:
                    continue
                data = path.read_bytes()
            except OSError:
                continue
            for kind, patterns in PATTERNS.items():
                for pattern in patterns:
                    for match in pattern.finditer(data):
                        value = match.group(1) if match.lastindex else match.group(0)
                        findings.add((kind, fingerprint(value)))
    return findings


def load_fingerprints(path: Path) -> dict[str, set[str]]:
    result: dict[str, set[str]] = {}
    for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        if not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) != 2 or not re.fullmatch(r"[0-9a-f]{64}", parts[1]):
            raise ValueError(f"invalid fingerprint record at line {number}")
        result.setdefault(parts[0], set()).add(parts[1])
    return result


def compare(
    leaked_path: Path,
    production_path: Path,
    kinds: list[str] | tuple[str, ...] | None = None,
) -> int:
    leaked = load_fingerprints(leaked_path)
    production = load_fingerprints(production_path)
    selected_kinds = tuple(PATTERNS) if kinds is None else tuple(kinds)
    unknown_kinds = set(selected_kinds) - set(PATTERNS)
    if unknown_kinds:
        raise ValueError(f"unknown fingerprint kind(s): {sorted(unknown_kinds)}")
    matched = False
    incomplete = False
    for kind in selected_kinds:
        old = leaked.get(kind, set())
        live = production.get(kind, set())
        if not old:
            status = "LEAK_FINGERPRINT_NOT_FOUND"
            incomplete = True
        elif not live:
            status = "PRODUCTION_FINGERPRINT_NOT_FOUND"
            incomplete = True
        elif old & live:
            status = "MATCH_ROTATION_REQUIRED"
            matched = True
        else:
            status = "DIFFERENT"
        print(f"{kind}\t{status}\tleaked={len(old)}\tproduction={len(live)}")
    if matched:
        return 2
    if incomplete:
        return 3
    return 0


def select_blobs(
    metadata_paths: list[Path], anchors: set[str], exact_paths: set[str]
) -> set[str]:
    selected: dict[str, str] = {}
    for metadata_path in metadata_paths:
        payload = json.loads(metadata_path.read_text(encoding="utf-8"))
        for item in payload.get("files", []):
            filename = item.get("filename", "")
            anchor = hashlib.sha256(filename.encode("utf-8")).hexdigest()
            if anchor in anchors or filename in exact_paths:
                blob_sha = item.get("sha", "")
                if not re.fullmatch(r"[0-9a-f]{40}", blob_sha):
                    raise ValueError(f"invalid blob SHA for selected path: {filename}")
                selected[filename] = blob_sha
    expected = len(anchors) + len(exact_paths)
    if len(selected) != expected:
        raise ValueError(f"selected {len(selected)} incident blobs; expected {expected}")
    return set(selected.values())


def main() -> int:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)
    extract_parser = subparsers.add_parser("extract")
    extract_parser.add_argument("paths", nargs="+", type=Path)
    compare_parser = subparsers.add_parser("compare")
    compare_parser.add_argument("leaked", type=Path)
    compare_parser.add_argument("production", type=Path)
    compare_parser.add_argument(
        "--kind",
        dest="kinds",
        action="append",
        choices=tuple(PATTERNS),
        help="Only compare the selected fingerprint kind(s); default: all kinds",
    )
    select_parser = subparsers.add_parser("select-blobs")
    select_parser.add_argument("metadata", nargs="+", type=Path)
    select_parser.add_argument("--anchor", action="append", default=[])
    select_parser.add_argument("--path", action="append", default=[])
    args = parser.parse_args()

    if args.command == "extract":
        for kind, digest in sorted(extract(args.paths)):
            print(f"{kind}\t{digest}")
        return 0
    if args.command == "select-blobs":
        try:
            for blob_sha in sorted(
                select_blobs(args.metadata, set(args.anchor), set(args.path))
            ):
                print(blob_sha)
            return 0
        except (OSError, ValueError, json.JSONDecodeError) as exc:
            print(f"incident metadata error: {exc}", file=sys.stderr)
            return 5
    try:
        return compare(args.leaked, args.production, args.kinds)
    except (OSError, ValueError) as exc:
        print(f"audit input error: {exc}", file=sys.stderr)
        return 4


if __name__ == "__main__":
    raise SystemExit(main())

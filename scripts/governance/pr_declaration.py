#!/usr/bin/env python3
"""Print the machine-generated declaration for a local PR branch."""

from __future__ import annotations

import argparse
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.governance.autonomy_gate import machine_declaration


def _git_output(*args: str) -> str:
    # quotepath off: a non-ASCII docs path must reach the classifier as the path, not as a quoted string.
    return subprocess.check_output(["git", "-c", "core.quotepath=off", *args], text=True)


def _paths(*args: str) -> list[str]:
    # -z: NUL-separated raw names; without it git C-quotes a name holding a tab or a double quote, and a
    # quoted string matches no protected prefix (#5201 row 20).
    return [path for path in _git_output(*args, "-z").split("\0") if path]


def _changed_paths(*args: str) -> list[str]:
    # --no-renames: a rename is a delete plus an add, so the path a file left is classified too.
    return _paths("diff", "--name-only", "--no-renames", *args)


def _local_patch(base: str, head: str, untracked: list[str]) -> str:
    patch = _git_output("diff", "--no-renames", f"{base}...{head}")
    patch += _git_output("diff", "--no-renames")
    patch += _git_output("diff", "--no-renames", "--cached")
    for path in untracked:
        file_path = Path(path)
        if not file_path.is_file():
            continue
        content = file_path.read_text(encoding="utf-8")
        added = "".join(f"+{line}\n" for line in content.splitlines())
        patch += f"diff --git a/{path} b/{path}\n{added}"
    return patch


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="origin/main")
    parser.add_argument("--head", default="HEAD")
    args = parser.parse_args()
    names = _changed_paths(f"{args.base}...{args.head}")
    names.extend(_changed_paths())
    names.extend(_changed_paths("--cached"))
    untracked = _paths("ls-files", "--others", "--exclude-standard")
    names.extend(path for path in untracked if path not in names)
    names = list(dict.fromkeys(names))
    patch = _local_patch(args.base, args.head, untracked)
    declaration = machine_declaration(names, patch)
    print(f"Risk-Class: {declaration['risk_class']}")
    print(f"Autonomy-Tier: {declaration['autonomy_tier']}")
    print("Machine reasons:")
    for reason in declaration["reasons"]:
        print(f"- {reason}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

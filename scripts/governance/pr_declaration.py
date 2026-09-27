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
    return subprocess.check_output(["git", *args], text=True)


def _local_patch(base: str, head: str, untracked: list[str]) -> str:
    patch = _git_output("diff", f"{base}...{head}")
    patch += _git_output("diff")
    patch += _git_output("diff", "--cached")
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
    names = _git_output("diff", "--name-only", f"{args.base}...{args.head}").splitlines()
    names.extend(_git_output("diff", "--name-only").splitlines())
    names.extend(_git_output("diff", "--cached", "--name-only").splitlines())
    untracked = _git_output("ls-files", "--others", "--exclude-standard").splitlines()
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

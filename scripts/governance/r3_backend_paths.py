#!/usr/bin/env python3
"""Print the changed backend PHP files that autonomy_gate treats as R3/T3 (mutation testing scope, W12)."""
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts.governance.autonomy_gate import _T3_PREFIXES  # noqa: E402


def r3_backend_php(paths):
    return [p for p in paths if p.startswith("backend/app/") and p.endswith(".php")
            and any(p.startswith(x) for x in _T3_PREFIXES)]


if __name__ == "__main__":
    base = sys.argv[1] if len(sys.argv) > 1 else "origin/main"
    out = subprocess.check_output(["git", "diff", "--name-only", "--diff-filter=AM", f"{base}...HEAD"], text=True, cwd=ROOT)
    print("\n".join(r3_backend_php(out.split("\n"))))

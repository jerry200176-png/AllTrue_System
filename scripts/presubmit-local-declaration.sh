#!/usr/bin/env bash
# Local pre-push run of scripts/governance/check_pr_declaration.py. Needs the PR body, so it runs only when
# `gh` finds an open PR for this branch; otherwise it skips (CI still enforces it).
set -euo pipefail
command -v gh >/dev/null || { echo "skip: gh not installed"; exit 0; }
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
gh pr view --json body > "$tmp/pr.json" 2>/dev/null || { echo "skip: no open PR for this branch"; exit 0; }
base="$(git merge-base origin/main HEAD)"
python3 - "$base" > "$tmp/files.json" <<'PY'
import json, subprocess, sys
base = sys.argv[1]
git = lambda *a: subprocess.check_output(["git", *a], text=True)
print(json.dumps([{"filename": p, "patch": git("diff", f"{base}...HEAD", "--", p)} for p in git("diff", "--name-only", f"{base}...HEAD").split("\n") if p]))
PY
python3 scripts/governance/check_pr_declaration.py --pr-json "$tmp/pr.json" --files-json "$tmp/files.json"

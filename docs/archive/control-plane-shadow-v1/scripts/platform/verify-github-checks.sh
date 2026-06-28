#!/usr/bin/env bash
# verify-github-checks.sh — Fail closed unless commit has required GitHub check conclusions.
#
# Usage: verify-github-checks.sh <commit_sha> [--production]
#
# PR merge gate checks (always):
#   - Platform Gate
#   - Control Plane Verify
#
# Production promotion (--production):
#   - Deploy Staging workflow SUCCESS for commit on main

set -euo pipefail

COMMIT="${1:?commit sha required}"
MODE="${2:-}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if ! command -v gh >/dev/null 2>&1; then
  echo "::error::verify-github-checks: gh CLI required"
  exit 1
fi

REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner)"
MISSING=()

echo "::group::GitHub required checks for $COMMIT"

python3 - <<'PY' "$REPO" "$COMMIT" "$MODE"
import json, os, subprocess, sys

repo, commit, mode = sys.argv[1:4]
production = mode == "--production"

required_pr = (
    "platform gate",
    "control plane verify",
)

def gh_json(path: str):
    out = subprocess.check_output(["gh", "api", path], text=True)
    return json.loads(out) if out.strip() else {}

checks = gh_json(f"/repos/{repo}/commits/{commit}/check-runs").get("check_runs", [])
names = [(c.get("name") or "", c.get("conclusion") or c.get("status") or "") for c in checks]

missing = []
for req in required_pr:
    matched = [n for n, con in names if req in n.lower()]
    if not matched:
        missing.append(req)
        continue
    if not any(con == "success" for n, con in names if req in n.lower()):
        missing.append(f"{req} (not success)")

if production:
    runs = gh_json(
        f"/repos/{repo}/actions/workflows/deploy-staging.yml/runs?branch=main&per_page=30"
    ).get("workflow_runs", [])
    staging_ok = any(
        r.get("head_sha") == commit and r.get("conclusion") == "success"
        for r in runs
    )
    if not staging_ok:
        missing.append("deploy staging workflow success")

result = {
    "commit": commit,
    "repository": repo,
    "production_mode": production,
    "missing_checks": missing,
    "checks_found": [{"name": n, "conclusion": c} for n, c in names[:40]],
}
print(json.dumps(result, indent=2))
if missing:
    for m in missing:
        print(f"::error::required check missing or failed: {m}", file=sys.stderr)
    raise SystemExit(1)
PY

echo "::endgroup::"
echo '{"github_checks":"PASS","commit":"'"$COMMIT"'"}'

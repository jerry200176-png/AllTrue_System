#!/usr/bin/env bash
# github-ruleset-audit.sh — Validate GitHub branch protection (governance only).
#
# Usage: ./scripts/platform/github-ruleset-audit.sh [--branch main] [--enforce]
#
# Output: { "ruleset_status": "PASS|FAIL", "missing_checks": [] }
# With --enforce: exit 1 when ruleset missing (Phase 5 hard requirement).

set -euo pipefail

BRANCH="main"
ENFORCE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --branch) BRANCH="$2"; shift 2 ;;
    --enforce) ENFORCE=1; shift ;;
    *) shift ;;
  esac
done

if ! command -v gh >/dev/null 2>&1; then
  if [[ "$ENFORCE" -eq 1 ]]; then
    echo '{"ruleset_status":"FAIL","missing_checks":["gh CLI not available"],"enforce":true}'
    exit 1
  fi
  echo '{"ruleset_status":"SKIP","missing_checks":["gh CLI not available"],"enforce":false}'
  exit 0
fi

export RS_BRANCH="$BRANCH" RS_REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || echo "")" RS_ENFORCE="$ENFORCE"
python3 - <<'PY'
import json, os, subprocess

branch = os.environ.get("RS_BRANCH", "main")
repo = os.environ.get("RS_REPO", "")
enforce = os.environ.get("RS_ENFORCE", "0") == "1"
missing: list[str] = []

REQUIRED_CHECK_SUBSTRINGS = (
    "platform gate",
    "control plane verify",
)

if not repo:
    payload = {"ruleset_status": "FAIL", "missing_checks": ["cannot resolve repository"], "enforce": enforce}
    print(json.dumps(payload, indent=2))
    raise SystemExit(1 if enforce else 0)

def gh_api(path: str):
    try:
        out = subprocess.check_output(["gh", "api", path], text=True)
        return json.loads(out) if out.strip() else {}
    except subprocess.CalledProcessError:
        return None

prot = gh_api(f"/repos/{repo}/branches/{branch}/protection")
reviews = gh_api(f"/repos/{repo}/branches/{branch}/protection/required_pull_request_reviews")
required_checks: list[str] = []

if prot:
    required_checks = [
        c.get("context", c) if isinstance(c, dict) else c
        for c in (prot.get("required_status_checks") or {}).get("checks", [])
    ] or (prot.get("required_status_checks") or {}).get("contexts", []) or []
    if (prot.get("allow_force_pushes") or {}).get("enabled", True):
        missing.append("force push not blocked")
    review_count = (reviews or {}).get("required_approving_review_count", 0)
    if review_count < 1:
        missing.append("pull request required before merge (>=1 approval)")
    if not (prot.get("required_linear_history") or {}).get("enabled", False):
        missing.append("linear history not required")
else:
    missing.append(f"branch protection not configured for {branch}")

check_names = [str(c).lower() for c in required_checks]
for required in REQUIRED_CHECK_SUBSTRINGS:
    if not any(required in c for c in check_names):
        missing.append(required)

status = "PASS" if not missing else "FAIL"
payload = {
    "ruleset_status": status,
    "missing_checks": missing,
    "branch": branch,
    "repository": repo,
    "required_checks_found": required_checks,
    "enforce": enforce,
    "phase": "platform-enforcement-binding",
    "apply_command": "bash scripts/platform/apply-platform-enforcement.sh",
    "config": "config/github/platform-enforcement.json",
}
print(json.dumps(payload, indent=2))
if status == "FAIL" and enforce:
    print("::error::Platform enforcement binding incomplete — run apply-platform-enforcement.sh (admin)", file=sys.stderr)
    raise SystemExit(1)
raise SystemExit(0)
PY

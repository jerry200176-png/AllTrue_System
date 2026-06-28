#!/usr/bin/env bash
# apply-platform-enforcement.sh — Apply ADR-001 GitHub branch protection + environments.
#
# Requires: gh CLI authenticated with admin:repo scope.
# Usage: ./scripts/platform/apply-platform-enforcement.sh [--dry-run]
#
# Idempotent: safe to re-run after adding new required checks to config.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONFIG="$ROOT/config/github/platform-enforcement.json"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    *) echo "Usage: $0 [--dry-run]" >&2; exit 2 ;;
  esac
done

if ! command -v gh >/dev/null 2>&1; then
  echo "::error::gh CLI required" >&2
  exit 1
fi

if [[ ! -f "$CONFIG" ]]; then
  echo "::error::missing $CONFIG" >&2
  exit 1
fi

REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner)"
BRANCH="$(python3 -c "import json; print(json.load(open('$CONFIG'))['branch_protection']['branch'])")"

echo "Repository: $REPO"
echo "Branch: $BRANCH"
echo "Config: $CONFIG"
echo ""

python3 - <<'PY' "$CONFIG" "$REPO" "$BRANCH" "$DRY_RUN"
import json, subprocess, sys

config_path, repo, branch, dry_run = sys.argv[1:]
dry = dry_run == "1"
cfg = json.load(open(config_path))
bp = cfg["branch_protection"]

contexts = bp["required_status_checks"]["contexts"]
reviews = bp["required_pull_request_reviews"]

payload = {
    "required_status_checks": {
        "strict": bp["required_status_checks"]["strict"],
        "checks": [{"context": c} for c in contexts],
    },
    "enforce_admins": bp["enforce_admins"],
    "required_pull_request_reviews": reviews,
    "restrictions": None,
    "required_linear_history": bp["required_linear_history"],
    "allow_force_pushes": bp["allow_force_pushes"],
    "allow_deletions": bp["allow_deletions"],
    "required_conversation_resolution": bp["required_conversation_resolution"],
}

print("=== Branch protection payload ===")
print(json.dumps(payload, indent=2))

if dry:
    print("\n[DRY RUN] Skipping PUT /branches/{}/protection".format(branch))
else:
    subprocess.check_call(
        [
            "gh", "api", "-X", "PUT",
            f"/repos/{repo}/branches/{branch}/protection",
            "--input", "-",
        ],
        input=json.dumps(payload).encode(),
    )
    print("\n✅ Branch protection applied")

for env_name, env_cfg in cfg.get("environments", {}).items():
    env_payload = {
        "wait_timer": env_cfg.get("wait_timer", 0),
        "deployment_branch_policy": env_cfg.get("deployment_branch_policy"),
    }
    reviewers = env_cfg.get("reviewers") or []
    if env_cfg.get("reviewers_required"):
        env_payload["reviewers"] = [{"type": "User", "id": 0}]  # placeholder — set in UI or extend script
        print(f"\n⚠️  Environment {env_name}: configure reviewers in GitHub UI (reviewers_required={env_cfg['reviewers_required']})")
    elif reviewers == []:
        env_payload["reviewers"] = []

    print(f"\n=== Environment: {env_name} ===")
    print(json.dumps(env_payload, indent=2))

    if dry:
        print(f"[DRY RUN] Skipping PUT /environments/{env_name}")
        continue

    try:
        subprocess.check_call(
            [
                "gh", "api", "-X", "PUT",
                f"/repos/{repo}/environments/{env_name}",
                "--input", "-",
            ],
            input=json.dumps(env_payload).encode(),
        )
        print(f"✅ Environment {env_name} applied")
    except subprocess.CalledProcessError as exc:
        print(f"::warning::Environment {env_name} apply failed (may need org admin): {exc}")

print("\n=== Post-apply audit ===")
subprocess.call(["bash", "scripts/platform/github-ruleset-audit.sh", "--branch", branch, "--enforce"])
PY

echo ""
echo "Platform Enforcement Binding apply complete."
echo "Verify: bash scripts/platform/github-ruleset-audit.sh --branch main --enforce"

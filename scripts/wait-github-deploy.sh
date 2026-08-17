#!/usr/bin/env bash
# Wait for AllTrue deploy.yml (or a PR's checks) without jq.
# Python 3 parses `gh --json`. Do not SSH the Pi.
#
# Usage:
#   wait-github-deploy [--repo owner/repo] --sha <full-or-prefix>
#   wait-github-deploy [--repo owner/repo] --pr <number>
#   wait-github-deploy [--repo owner/repo] --run-id <id>
#
# Exit 0 when the matching Deploy to Pi run succeeded and (for SHA)
# production version.json hash matches. Exit 1 on fail/timeout.
set -euo pipefail

REPO="${WAIT_GITHUB_REPO:-jerry200176-png/AllTrue_System}"
WORKFLOW="${WAIT_GITHUB_WORKFLOW:-deploy.yml}"
PROD_VERSION_URL="${WAIT_GITHUB_VERSION_URL:-https://daan.lifenet.com.tw/version.json}"
TIMEOUT_SEC="${WAIT_GITHUB_TIMEOUT_SEC:-600}"
POLL_SEC="${WAIT_GITHUB_POLL_SEC:-15}"
SHA=""
PR=""
RUN_ID=""

usage() {
  sed -n '2,14p' "$0" | sed 's/^# \?//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo) REPO="$2"; shift 2 ;;
    --sha) SHA="$2"; shift 2 ;;
    --pr) PR="$2"; shift 2 ;;
    --run-id) RUN_ID="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "wait-github-deploy: unknown arg: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ -z "$SHA" && -z "$PR" && -z "$RUN_ID" ]]; then
  echo "wait-github-deploy: need --sha, --pr, or --run-id" >&2
  exit 2
fi

if [[ -n "$PR" && -z "$SHA" ]]; then
  SHA="$(gh pr view "$PR" --repo "$REPO" --json mergeCommit,state --jq .mergeCommit.oid 2>/dev/null || true)"
  if [[ -z "$SHA" || "$SHA" == "null" ]]; then
    SHA="$(python3 - "$REPO" "$PR" <<'PY'
import json, subprocess, sys
repo, pr = sys.argv[1], sys.argv[2]
raw = subprocess.check_output(
    ["gh", "pr", "view", pr, "--repo", repo, "--json", "mergeCommit,state"],
    text=True,
)
d = json.loads(raw)
oid = (d.get("mergeCommit") or {}).get("oid") or ""
print(oid)
PY
)"
  fi
  if [[ -z "$SHA" ]]; then
    echo "wait-github-deploy: PR #${PR} has no merge commit yet" >&2
    exit 1
  fi
fi

python3 - "$REPO" "$WORKFLOW" "$PROD_VERSION_URL" "$TIMEOUT_SEC" "$POLL_SEC" "$SHA" "$RUN_ID" <<'PY'
import json, os, subprocess, sys, time, urllib.request

repo, workflow, version_url, timeout_s, poll_s, sha, run_id = sys.argv[1:8]
timeout_s, poll_s = int(timeout_s), int(poll_s)
deadline = time.time() + timeout_s
sha = (sha or "").strip()
want_prefix = sha[:8] if sha else ""

def gh_json(args):
    raw = subprocess.check_output(["gh", *args], text=True)
    return json.loads(raw)

def list_runs():
    return gh_json([
        "run", "list",
        "--repo", repo,
        "--workflow", workflow,
        "--limit", "8",
        "--json", "databaseId,headSha,status,conclusion,url,displayTitle,createdAt",
    ])

def pick_run(runs):
    if run_id:
        rid = int(run_id)
        for r in runs:
            if int(r["databaseId"]) == rid:
                return r
        return None
    for r in runs:
        head = r.get("headSha") or ""
        if sha and (head == sha or head.startswith(want_prefix)):
            return r
    return None

def fetch_version():
    req = urllib.request.Request(version_url, headers={"User-Agent": "wait-github-deploy"})
    with urllib.request.urlopen(req, timeout=20) as resp:
        return json.load(resp)

chosen = None
while time.time() < deadline:
    runs = list_runs()
    chosen = pick_run(runs)
    if chosen is None:
        print(f"wait-github-deploy: no {workflow} run yet for {want_prefix or run_id}; retry")
        time.sleep(poll_s)
        continue
    status = chosen.get("status")
    conc = chosen.get("conclusion") or ""
    print(
        f"wait-github-deploy: run {chosen['databaseId']} "
        f"sha={str(chosen.get('headSha'))[:8]} status={status} conclusion={conc or '-'}"
    )
    if status == "completed":
        if conc != "success":
            print(f"wait-github-deploy: deploy failed {chosen.get('url')}", file=sys.stderr)
            sys.exit(1)
        break
    time.sleep(poll_s)
else:
    print("wait-github-deploy: timed out waiting for deploy run", file=sys.stderr)
    sys.exit(1)

if not sha:
    sys.exit(0)

# Confirm public frontend identity. Backend-only deploys may lag version.json.
live_deadline = time.time() + min(120, timeout_s)
while time.time() < live_deadline:
    try:
        ver = fetch_version()
    except Exception as exc:
        print(f"wait-github-deploy: version.json fetch failed: {exc}")
        time.sleep(poll_s)
        continue
    live_hash = str(ver.get("hash") or "")
    live_sha = str(ver.get("build_sha") or "")
    print(f"wait-github-deploy: live version.json hash={live_hash} build_sha={live_sha[:12]}")
    if live_sha.startswith(want_prefix) or live_hash.startswith(want_prefix):
        print("wait-github-deploy: production frontend matches")
        sys.exit(0)
    time.sleep(poll_s)

print(
    "wait-github-deploy: deploy run succeeded but version.json has not caught up yet "
    "(backend-only lag is possible)",
    file=sys.stderr,
)
sys.exit(1)
PY

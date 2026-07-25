#!/usr/bin/env bash
# Validate Agent / human PR provenance for AllTrue.
# Passes when .agent-session/manifest.json (agent-session) or human-authored.json exists.
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"
TASK_ROOT="/home/jerry/workspace/tasks/alltrue/"
EVENT_NAME="${GITHUB_EVENT_NAME:-local}"

fail() { echo "provenance: FAIL: $*" >&2; exit 1; }
ok() { echo "provenance: OK: $*"; }

MANIFEST=""
TYPE=""
if [[ -f .agent-session/manifest.json ]]; then
  MANIFEST=.agent-session/manifest.json
elif [[ -f .agent-session/human-authored.json ]]; then
  MANIFEST=.agent-session/human-authored.json
elif [[ -f .agent-session/founder-exception.json ]]; then
  MANIFEST=.agent-session/founder-exception.json
else
  fail "missing .agent-session/manifest.json, .agent-session/human-authored.json, or .agent-session/founder-exception.json"
fi

# founder-exception is the ONLY path that hits the network (gh api, to verify
# an external, pre-existing, content-bound approval record). It intentionally
# does not go through the same python validator as the other two types so
# that agent-session/human-authored validation is provably unchanged by this
# addition — see the branch further below for their original, untouched logic.
if [[ "$MANIFEST" == .agent-session/founder-exception.json ]]; then
  # Emergency/manual exception path for a Founder-approved ad-hoc branch that
  # did not go through the agent-control launcher. This is EXCEPTIONAL, not a
  # substitute for normal provenance — restrict to a visibly distinct branch
  # namespace so it can never quietly become the default path.
  FOUNDER_LOGIN="jerry200176-png"
  branch="${GITHUB_HEAD_REF:-$(git rev-parse --abbrev-ref HEAD)}"
  case "$branch" in
    exception/*) ;;
    *) fail "founder-exception manifest requires branch name to start with exception/ (got: $branch)" ;;
  esac

  python3 - "$MANIFEST" "$branch" <<'PY'
import json, re, sys
path, branch = sys.argv[1:3]
m = json.load(open(path))
if m.get("provenance_type") != "founder_approved_exception":
    raise SystemExit("provenance_type must be founder_approved_exception")
required = ["schema_version", "provenance_type", "repo", "branch", "head_sha", "purpose",
            "source_session", "tests_run", "reviewer", "founder_approval"]
for k in required:
    if k not in m:
        raise SystemExit(f"missing {k}")
if m["schema_version"] != "1.0":
    raise SystemExit("bad schema_version")
if m["branch"] != branch:
    raise SystemExit(f"manifest branch {m['branch']} != git branch {branch}")
if not re.match(r"^[0-9a-f]{40}$", m["head_sha"]):
    raise SystemExit("bad head_sha")
fa = m["founder_approval"]
for k in ("approval_issue", "approval_comment_id", "approved_by", "approved_at"):
    if k not in fa:
        raise SystemExit(f"founder_approval missing {k}")
blob = json.dumps(m)
if re.search(r"(api[_-]?key|token|password|secret|BEGIN PRIVATE)", blob, re.I):
    raise SystemExit("sensitive data in founder-exception manifest")
print("founder-exception-structure-ok")
PY

  # External verification: the approval must be a real, pre-existing GitHub
  # issue comment authored by exactly the Founder's account, whose body
  # names this exact branch and head_sha (so an old approval can't be
  # replayed for a different change), posted before this PR existed (so it
  # can't be fabricated after the fact once the PR is already under review).
  APPROVAL_ISSUE=$(python3 -c "import json; print(json.load(open('$MANIFEST'))['founder_approval']['approval_issue'])")
  APPROVAL_COMMENT_ID=$(python3 -c "import json; print(json.load(open('$MANIFEST'))['founder_approval']['approval_comment_id'])")
  APPROVED_BY=$(python3 -c "import json; print(json.load(open('$MANIFEST'))['founder_approval']['approved_by'])")
  HEAD_SHA=$(python3 -c "import json; print(json.load(open('$MANIFEST'))['head_sha'])")

  if [[ "$APPROVED_BY" != "$FOUNDER_LOGIN" ]]; then
    fail "founder_approval.approved_by ($APPROVED_BY) is not the designated Founder account"
  fi

  ACTUAL_HEAD=$(git rev-parse HEAD)
  [[ "$HEAD_SHA" == "$ACTUAL_HEAD" ]] \
    || fail "manifest head_sha ($HEAD_SHA) does not match actual git HEAD ($ACTUAL_HEAD)"

  test -n "${GH_TOKEN:-}" || fail "GH_TOKEN not available to verify the approval comment"
  COMMENT_JSON=$(gh api "repos/${GITHUB_REPOSITORY:?}/issues/comments/${APPROVAL_COMMENT_ID}" 2>&1) \
    || fail "could not fetch approval_comment_id ${APPROVAL_COMMENT_ID}: $COMMENT_JSON"

  COMMENT_AUTHOR=$(echo "$COMMENT_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['user']['login'])")
  COMMENT_ISSUE_URL=$(echo "$COMMENT_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['issue_url'])")
  COMMENT_BODY=$(echo "$COMMENT_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['body'])")
  COMMENT_CREATED_AT=$(echo "$COMMENT_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['created_at'])")

  [[ "$COMMENT_AUTHOR" == "$FOUNDER_LOGIN" ]] \
    || fail "approval comment author ($COMMENT_AUTHOR) is not $FOUNDER_LOGIN"
  [[ "$COMMENT_ISSUE_URL" == *"/issues/${APPROVAL_ISSUE}" ]] \
    || fail "approval comment is not on the declared approval_issue ($APPROVAL_ISSUE)"
  case "$COMMENT_BODY" in
    *"$branch"*) ;;
    *) fail "approval comment does not name branch $branch — an approval must be bound to this exact change" ;;
  esac
  case "$COMMENT_BODY" in
    *"$HEAD_SHA"*) ;;
    *) fail "approval comment does not name head_sha $HEAD_SHA — an approval must be bound to this exact change" ;;
  esac

  # Prevent backdating: the approval must exist strictly before this PR was
  # opened, so it cannot be authored after review has already begun.
  PR_JSON=$(gh api "repos/${GITHUB_REPOSITORY:?}/pulls/${GITHUB_PR_NUMBER:?PR number required for founder-exception}" 2>&1) \
    || fail "could not read PR metadata to check approval ordering: $PR_JSON"
  PR_CREATED_AT=$(echo "$PR_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['created_at'])")
  # ISO 8601 UTC ('...Z') timestamps compare correctly as plain strings.
  if [[ "$COMMENT_CREATED_AT" > "$PR_CREATED_AT" ]]; then
    fail "approval comment ($COMMENT_CREATED_AT) was posted after the PR was opened ($PR_CREATED_AT) — not externally pre-supplied"
  fi

  ok "founder-exception manifest validated: branch=$branch approved_by=$COMMENT_AUTHOR approval_issue=$APPROVAL_ISSUE comment=$APPROVAL_COMMENT_ID"
  exit 0
fi

python3 - "$MANIFEST" "$TASK_ROOT" "$EVENT_NAME" <<'PY'
import json,sys,re,os,subprocess
path, task_root, event = sys.argv[1:4]
m=json.load(open(path))
ptype=m.get("provenance_type")
if ptype=="human-authored":
    for k in ("schema_version","provenance_type","declared_at"):
        if k not in m: raise SystemExit(f'human manifest missing {k}')
    if m["schema_version"]!="1.0": raise SystemExit('bad schema')
    blob=json.dumps(m)
    if re.search(r'(api[_-]?key|token|password|secret|BEGIN PRIVATE)', blob, re.I):
        raise SystemExit('sensitive data in human manifest')
    print('human-authored-ok')
    raise SystemExit(0)

if ptype!="agent-session":
    raise SystemExit('provenance_type must be agent-session or human-authored')

required=["schema_version","session_id","project","task_id","repo_remote","base_sha",
          "branch","worktree_path","started_at","production_mutation","preflight_result","provenance_type"]
for k in required:
    if k not in m: raise SystemExit(f'missing {k}')
if m["schema_version"]!="1.0": raise SystemExit('bad schema_version')
if m["project"]!="alltrue": raise SystemExit('project must be alltrue')
if m["preflight_result"]!="pass": raise SystemExit('preflight_result must be pass')
if m["production_mutation"] is not False: raise SystemExit('production_mutation must be false')
if not re.match(r'^[0-9a-f]{40}$', m["base_sha"]): raise SystemExit('bad base_sha')
wp=m["worktree_path"]
if not wp.startswith(task_root):
    raise SystemExit(f'worktree_path not under {task_root}: {wp}')
# forbidden classes
for bad in ("/home/jerry/alltrue", "actions-runner", "workspace-backups", "/mnt/c/", "AllTrue_System-clean", "/workspace/repos/"):
    if bad == "/home/jerry/alltrue":
        if wp.rstrip("/") == "/home/jerry/alltrue":
            raise SystemExit('forbidden worktree')
    elif bad in wp:
        raise SystemExit(f'forbidden worktree class {bad}')

branch=subprocess.check_output(["git","rev-parse","--abbrev-ref","HEAD"], text=True).strip()
# On CI PR, GITHUB_HEAD_REF is the branch
branch=os.environ.get("GITHUB_HEAD_REF") or branch
if m["task_id"] not in branch:
    raise SystemExit(f'task_id {m["task_id"]} not reflected in branch {branch}')
if m.get("branch") and m["branch"] != branch:
    raise SystemExit(f'manifest branch {m["branch"]} != git branch {branch}')

# base_sha must be ancestor of HEAD
head=subprocess.check_output(["git","rev-parse","HEAD"], text=True).strip()
rc=subprocess.call(["git","merge-base","--is-ancestor", m["base_sha"], head])
if rc!=0:
    raise SystemExit('base_sha is not an ancestor of HEAD')

blob=json.dumps(m)
if re.search(r'(api[_-]?key|token|password|secret|BEGIN PRIVATE)', blob, re.I):
    # allow production_mutation key only
    for k,v in m.items():
        if k=="production_mutation":
            continue
        if re.search(r'(api[_-]?key|token|password|secret)', k, re.I):
            raise SystemExit(f'sensitive key {k}')
        if isinstance(v,str) and re.search(r'(api[_-]?key|token|password|secret|BEGIN PRIVATE)', v, re.I):
            raise SystemExit(f'sensitive value in {k}')

print('agent-session-ok')
PY

ok "manifest=$MANIFEST type validated"
exit 0

#!/usr/bin/env bash
# Validate a session claim in THIS change set.
#
# Security property: if this PR adds or updates an agent/human session file,
# that claim must be internally consistent with git (branch, task_id, base_sha
# ancestor) and must not enable production_mutation or embed secrets.
# An inherited .agent-session/manifest.json from main is leftover from a
# previous task. Treating it as this PR's evidence is a false invariant and
# forces unrelated PRs to rewrite a shared singleton. Leftover files are not
# validated as a session claim.
#
# This does not prove worktree path (self-authored). Path bans stay on
# agent-start / local preflight.
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"
TASK_ROOT="/home/jerry/workspace/tasks/alltrue/"
EVENT_NAME="${GITHUB_EVENT_NAME:-local}"

fail() { echo "provenance: FAIL: $*" >&2; exit 1; }
ok() { echo "provenance: OK: $*"; }

resolve_base() {
  if [ -n "${PR_BASE_SHA:-}" ]; then
    printf '%s\n' "$PR_BASE_SHA"
  elif [ -n "${GITHUB_BASE_SHA:-}" ]; then
    printf '%s\n' "$GITHUB_BASE_SHA"
  elif [ -n "${GITHUB_BASE_REF:-}" ]; then
    printf 'origin/%s\n' "${GITHUB_BASE_REF#refs/heads/}"
  else
    printf '%s\n' "origin/main"
  fi
}

file_in_diff() {
  local base="$1" file="$2"
  git rev-parse --verify "$base" >/dev/null 2>&1 || return 2
  git diff --name-only "${base}...HEAD" -- "$file" | grep -qx "$file"
}

BASE="$(resolve_base)"
AGENT_FILE=".agent-session/manifest.json"
HUMAN_FILE=".agent-session/human-authored.json"

AGENT_CLAIMED=0
HUMAN_CLAIMED=0
agent_rc=0
file_in_diff "$BASE" "$AGENT_FILE" || agent_rc=$?
if [ "$agent_rc" -eq 0 ]; then
  AGENT_CLAIMED=1
elif [ "$agent_rc" -eq 2 ]; then
  # Cannot see the merge-base: keep the old required-file behavior.
  if [ ! -f "$AGENT_FILE" ] && [ ! -f "$HUMAN_FILE" ]; then
    fail "missing $AGENT_FILE or $HUMAN_FILE (base $BASE unreadable)"
  fi
  AGENT_CLAIMED=1
fi
human_rc=0
file_in_diff "$BASE" "$HUMAN_FILE" || human_rc=$?
if [ "$human_rc" -eq 0 ]; then
  HUMAN_CLAIMED=1
fi

if [ "$AGENT_CLAIMED" -eq 0 ] && [ "$HUMAN_CLAIMED" -eq 0 ]; then
  ok "no session claim in ${BASE}...HEAD; inherited .agent-session/ is not evidence for this PR"
  exit 0
fi

MANIFEST=""
if [ "$AGENT_CLAIMED" -eq 1 ] && [ -f "$AGENT_FILE" ]; then
  MANIFEST="$AGENT_FILE"
elif [ "$HUMAN_CLAIMED" -eq 1 ] && [ -f "$HUMAN_FILE" ]; then
  MANIFEST="$HUMAN_FILE"
else
  ok "session path listed in diff but absent at HEAD (deleted leftover); no claim"
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
for bad in ("/home/jerry/alltrue", "actions-runner", "workspace-backups", "/mnt/c/", "AllTrue_System-clean", "/workspace/repos/"):
    if bad == "/home/jerry/alltrue":
        if wp.rstrip("/") == "/home/jerry/alltrue":
            raise SystemExit('forbidden worktree')
    elif bad in wp:
        raise SystemExit(f'forbidden worktree class {bad}')

branch=subprocess.check_output(["git","rev-parse","--abbrev-ref","HEAD"], text=True).strip()
branch=os.environ.get("GITHUB_HEAD_REF") or branch
if m["task_id"] not in branch:
    raise SystemExit(f'task_id {m["task_id"]} not reflected in branch {branch}')
if m.get("branch") and m["branch"] != branch:
    raise SystemExit(f'manifest branch {m["branch"]} != git branch {branch}')

head=subprocess.check_output(["git","rev-parse","HEAD"], text=True).strip()
rc=subprocess.call(["git","merge-base","--is-ancestor", m["base_sha"], head])
if rc!=0:
    raise SystemExit('base_sha is not an ancestor of HEAD')

blob=json.dumps(m)
if re.search(r'(api[_-]?key|token|password|secret|BEGIN PRIVATE)', blob, re.I):
    for k,v in m.items():
        if k=="production_mutation":
            continue
        if re.search(r'(api[_-]?key|token|password|secret)', k, re.I):
            raise SystemExit(f'sensitive key {k}')
        if isinstance(v,str) and re.search(r'(api[_-]?key|token|password|secret|BEGIN PRIVATE)', v, re.I):
            raise SystemExit(f'sensitive value in {k}')

print('agent-session-ok')
PY

ok "manifest=$MANIFEST type validated (claimed in ${BASE}...HEAD)"
exit 0

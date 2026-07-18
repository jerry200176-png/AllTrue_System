#!/usr/bin/env bash
# Agent preflight — fail-closed gate before any AllTrue write.
# Official writes: /home/jerry/workspace/tasks/alltrue/<task-id> via agent-start only.
# Modes: agent (default) | ci
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
CONFIG="${AGENT_PREFLIGHT_CONFIG:-${SCRIPT_DIR}/agent-preflight.config.json}"
MODE="${AGENT_PREFLIGHT_MODE:-agent}"

fail() {
  echo "agent-preflight: FAIL: $*" >&2
  echo "agent-preflight: recovery:" >&2
  echo "  agent-start alltrue <task-id> --dry-run" >&2
  echo "  # bare: /home/jerry/workspace/repos/AllTrue_System.git" >&2
  echo "  # tasks: /home/jerry/workspace/tasks/alltrue/<task-id>" >&2
  echo "  Never write in /home/jerry/alltrue, AllTrue_System-clean, runner _work, backups, /mnt/c, or bare *.git" >&2
  exit 1
}
ok() { echo "agent-preflight: OK: $*"; }

resolve() {
  local p="$1"
  if command -v realpath >/dev/null 2>&1; then
    realpath -m "$p" 2>/dev/null || readlink -f "$p" 2>/dev/null || echo "$p"
  else
    readlink -f "$p" 2>/dev/null || echo "$p"
  fi
}

json_get() {
  local key="$1"
  python3 - "$CONFIG" "$key" <<'PY'
import json,sys
cfg=json.load(open(sys.argv[1]))
key=sys.argv[2]
val=cfg
for part in key.split('.'):
    val=val[part]
if isinstance(val,bool):
    print('true' if val else 'false')
elif isinstance(val,list):
    print('\n'.join(str(x) for x in val))
else:
    print(val)
PY
}

[[ -f "$CONFIG" ]] || fail "missing config $CONFIG"
PROJECT="$(json_get project)"
[[ "$PROJECT" == "AllTrue_System" ]] || fail "project identity mismatch: $PROJECT"

if [[ "$MODE" == "ci" ]]; then
  ok "mode=ci project=$PROJECT"
  [[ -f "$SCRIPT_DIR/agent-preflight.sh" ]] || fail "preflight script missing"
  [[ -f "$SCRIPT_ROOT/docs/governance/WORKTREE_POLICY.md" ]] || fail "WORKTREE_POLICY.md missing"
  [[ -f "$SCRIPT_DIR/check-agent-provenance.sh" ]] || fail "provenance checker missing"
  python3 - "$CONFIG" <<'PY'
import json,sys
cfg=json.load(open(sys.argv[1]))
assert "/home/jerry/alltrue" in cfg["forbidden_exact"]
assert "/home/jerry/workspace/tasks/alltrue/" in cfg["allowed_path_prefixes"]
assert cfg.get("canonical_bare_repo","").endswith("AllTrue_System.git")
print("classifier-ok")
PY
  ok "ci preflight passed"
  exit 0
fi

CALLER_PWD="$(resolve "${PWD:-.}")"
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  fail "not inside a git work tree (pwd=$CALLER_PWD)"
fi
CALLER_GIT="$(resolve "$(git rev-parse --show-toplevel)")"
TARGET="$CALLER_GIT"

while IFS= read -r f; do
  [[ -z "$f" ]] && continue
  fr="$(resolve "$f")"
  if [[ "$TARGET" == "$fr" || "$CALLER_PWD" == "$fr" ]]; then
    fail "forbidden checkout: $TARGET"
  fi
done < <(json_get forbidden_exact)

while IFS= read -r sub; do
  [[ -z "$sub" ]] && continue
  if [[ "$TARGET" == *"$sub"* || "$CALLER_PWD" == *"$sub"* ]]; then
    fail "forbidden path class matched '$sub' in $TARGET"
  fi
done < <(json_get forbidden_path_substrings)

ALLOWED=0
while IFS= read -r prefix; do
  [[ -z "$prefix" ]] && continue
  if [[ "$TARGET" == "$prefix"* ]]; then
    ALLOWED=1
    break
  fi
done < <(json_get allowed_path_prefixes)
[[ "$ALLOWED" == "1" ]] || fail "path not on allowlist (use agent-start): $TARGET"

# Sentinel hard-deny if present on ancestors
if [[ -f /home/jerry/alltrue/AGENT_WRITES_FORBIDDEN ]] && [[ "$TARGET" == "/home/jerry/alltrue" ]]; then
  fail "AGENT_WRITES_FORBIDDEN sentinel"
fi

cd "$TARGET"
REMOTE="$(git remote get-url origin 2>/dev/null || true)"
[[ -n "$REMOTE" ]] || fail "origin remote missing"
REMOTE_OK=0
while IFS= read -r suf; do
  [[ -z "$suf" ]] && continue
  case "$REMOTE" in *"$suf") REMOTE_OK=1 ;; esac
done < <(json_get expected_remote_suffixes)
[[ "$REMOTE_OK" == "1" ]] || fail "unexpected origin URL: $REMOTE"

if [[ "${AGENT_PREFLIGHT_SKIP_FETCH:-0}" != "1" ]]; then
  git fetch origin main --quiet 2>/dev/null || fail "git fetch origin main failed"
fi
git rev-parse --verify origin/main >/dev/null 2>&1 || fail "origin/main missing"
ORIGIN_MAIN="$(git rev-parse origin/main)"
HEAD_SHA="$(git rev-parse HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"

if [[ "$(json_get forbid_main_branch_writes)" == "true" ]]; then
  [[ "$BRANCH" != "main" && "$BRANCH" != "master" ]] || fail "writes forbidden on $BRANCH — use agent-start"
fi

BRANCH_RE="$(json_get branch_prefix_regex)"
python3 - "$BRANCH" "$BRANCH_RE" <<'PY' || fail "branch '$BRANCH' lacks type/slug identity"
import re,sys
sys.exit(0 if re.match(sys.argv[2], sys.argv[1]) else 1)
PY

if [[ "$(json_get require_base_equals_origin_main)" == "true" ]]; then
  MB="$(git merge-base HEAD origin/main)"
  [[ "$MB" == "$ORIGIN_MAIN" ]] || fail "stale base: merge-base=$MB origin/main=$ORIGIN_MAIN — recreate via agent-start"
fi

if [[ "$(json_get require_clean_worktree)" == "true" ]]; then
  [[ -z "$(git status --porcelain)" ]] || fail "dirty worktree"
fi

GIT_DIR="$(git rev-parse --git-dir)"
if [[ -d "$GIT_DIR/rebase-merge" || -d "$GIT_DIR/rebase-apply" || -f "$GIT_DIR/MERGE_HEAD" || -f "$GIT_DIR/CHERRY_PICK_HEAD" ]]; then
  fail "unresolved merge/rebase/cherry-pick"
fi

MUT_ENV="$(json_get production_mutation_env)"
MUT_VAL="${!MUT_ENV:-0}"
[[ "$MUT_VAL" == "0" || -z "$MUT_VAL" ]] || fail "$MUT_ENV must stay disabled"
ok "production_mutation=disabled"

command -v gh >/dev/null || fail "gh CLI not installed"
gh auth status >/dev/null 2>&1 || fail "gh not authenticated"
ok "gh authenticated"

ok "project=$PROJECT path=$TARGET branch=$BRANCH"
ok "head=${HEAD_SHA:0:12} origin/main=${ORIGIN_MAIN:0:12}"
ok "preflight passed"
exit 0

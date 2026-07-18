#!/usr/bin/env bash
# Agent preflight — fail-closed gate before any AllTrue write.
# Policy: docs/governance/WORKTREE_POLICY.md
# Modes:
#   default / AGENT_PREFLIGHT_MODE=agent  — full local write gate
#   AGENT_PREFLIGHT_MODE=ci               — validate script+config on CI runners
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
CONFIG="${SCRIPT_DIR}/agent-preflight.config.json"
MODE="${AGENT_PREFLIGHT_MODE:-agent}"

fail() {
  echo "agent-preflight: FAIL: $*" >&2
  echo "agent-preflight: recovery:" >&2
  echo "  1) cd /home/jerry/workspace/AllTrue_System-clean && git fetch origin main" >&2
  echo "  2) git worktree add -b <type>/<issue-or-slug> /home/jerry/wt/alltrue-<slug> origin/main" >&2
  echo "  3) cd that worktree; re-run: make agent-preflight" >&2
  echo "  4) Never write in /home/jerry/alltrue, runner _work, backups, or /mnt/c clones" >&2
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
  # tiny jq-less reader for flat string/bool/array values we need
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

# --- CI mode: structural only ---
if [[ "$MODE" == "ci" ]]; then
  ok "mode=ci project=$PROJECT"
  [[ -x "$SCRIPT_DIR/agent-preflight.sh" || -f "$SCRIPT_DIR/agent-preflight.sh" ]] || fail "preflight script missing"
  [[ -f "$SCRIPT_ROOT/docs/governance/WORKTREE_POLICY.md" ]] || fail "WORKTREE_POLICY.md missing"
  # Self-test: forbidden path classifier must reject legacy tree string
  if python3 - "$CONFIG" <<'PY'
import json,sys
cfg=json.load(open(sys.argv[1]))
path="/home/jerry/alltrue"
assert path in cfg["forbidden_exact"]
assert any(s in "/home/jerry/actions-runner-alltrue/_work/x" for s in cfg["forbidden_path_substrings"])
print("classifier-ok")
PY
  then
    ok "forbidden-path classifier"
  else
    fail "forbidden-path classifier broken"
  fi
  ok "ci preflight passed"
  exit 0
fi

# --- Agent write mode ---
CALLER_PWD="$(resolve "${PWD:-.}")"
CALLER_GIT=""
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  CALLER_GIT="$(resolve "$(git rev-parse --show-toplevel)")"
else
  fail "not inside a git work tree (pwd=$CALLER_PWD)"
fi

TARGET="$CALLER_GIT"

# Forbidden exact
while IFS= read -r f; do
  [[ -z "$f" ]] && continue
  fr="$(resolve "$f")"
  if [[ "$TARGET" == "$fr" ]] || [[ "$CALLER_PWD" == "$fr" ]]; then
    fail "forbidden checkout: $TARGET (exact match $fr)"
  fi
done < <(json_get forbidden_exact)

# Forbidden substrings
while IFS= read -r sub; do
  [[ -z "$sub" ]] && continue
  if [[ "$TARGET" == *"$sub"* ]] || [[ "$CALLER_PWD" == *"$sub"* ]]; then
    fail "forbidden path class matched '$sub' in $TARGET"
  fi
done < <(json_get forbidden_path_substrings)

# Allowlist
ALLOWED=0
while IFS= read -r prefix; do
  [[ -z "$prefix" ]] && continue
  # Exact forbidden /home/jerry/alltrue must not match /home/jerry/alltrue- prefix incorrectly:
  # prefixes already use alltrue- with trailing hyphen for task worktrees.
  if [[ "$TARGET" == "$prefix" || "$TARGET" == "$prefix"* ]]; then
    # Special-case: prefix /home/jerry/alltrue- must NOT allow /home/jerry/alltrue
    if [[ "$prefix" == "/home/jerry/alltrue-" && "$TARGET" == "/home/jerry/alltrue" ]]; then
      continue
    fi
    ALLOWED=1
    break
  fi
done < <(json_get allowed_path_prefixes)
[[ "$ALLOWED" == "1" ]] || fail "path not on allowlist: $TARGET"

cd "$TARGET"

# Remote URL
REMOTE="$(git remote get-url origin 2>/dev/null || true)"
[[ -n "$REMOTE" ]] || fail "origin remote missing"
REMOTE_OK=0
while IFS= read -r suf; do
  [[ -z "$suf" ]] && continue
  case "$REMOTE" in
    *"$suf") REMOTE_OK=1 ;;
  esac
done < <(json_get expected_remote_suffixes)
[[ "$REMOTE_OK" == "1" ]] || fail "unexpected origin URL: $REMOTE"

# Fetch tip for base check (read-only network)
if [[ "${AGENT_PREFLIGHT_SKIP_FETCH:-0}" != "1" ]]; then
  git fetch origin main --quiet 2>/dev/null || fail "git fetch origin main failed — cannot verify base SHA"
fi
git rev-parse --verify origin/main >/dev/null 2>&1 || fail "origin/main missing after fetch"

ORIGIN_MAIN="$(git rev-parse origin/main)"
HEAD_SHA="$(git rev-parse HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"

if [[ "$(json_get forbid_main_branch_writes)" == "true" ]]; then
  if [[ "$BRANCH" == "main" || "$BRANCH" == "master" ]]; then
    fail "writes forbidden on long-lived $BRANCH — create task worktree from origin/main"
  fi
fi

# Unique task / issue identity in branch name
BRANCH_RE="$(json_get branch_prefix_regex)"
if ! python3 - "$BRANCH" "$BRANCH_RE" <<'PY'
import re,sys
branch,pat=sys.argv[1],sys.argv[2]
sys.exit(0 if re.match(pat, branch) else 1)
PY
then
  fail "branch '$BRANCH' lacks required type/slug identity (expected /$BRANCH_RE/)"
fi

# Base must equal latest origin/main (fully up to date merge-base)
if [[ "$(json_get require_base_equals_origin_main)" == "true" ]]; then
  MB="$(git merge-base HEAD origin/main)"
  if [[ "$MB" != "$ORIGIN_MAIN" ]]; then
    fail "stale base: merge-base=$MB origin/main=$ORIGIN_MAIN — rebase/remake worktree from origin/main"
  fi
fi

# Clean worktree
if [[ "$(json_get require_clean_worktree)" == "true" ]]; then
  if [[ -n "$(git status --porcelain)" ]]; then
    fail "dirty worktree — commit, stash, or discard before agent writes"
  fi
fi

# No unresolved merge/rebase
if [[ -d .git/rebase-merge || -d .git/rebase-apply || -f .git/MERGE_HEAD || -f .git/CHERRY_PICK_HEAD ]]; then
  # when .git is a file (worktree), check common dir
  :
fi
GIT_DIR="$(git rev-parse --git-dir)"
if [[ -d "$GIT_DIR/rebase-merge" || -d "$GIT_DIR/rebase-apply" || -f "$GIT_DIR/MERGE_HEAD" || -f "$GIT_DIR/CHERRY_PICK_HEAD" ]]; then
  fail "unresolved merge/rebase/cherry-pick in progress"
fi

# Production mutation default disabled
MUT_ENV="$(json_get production_mutation_env)"
MUT_VAL="${!MUT_ENV:-0}"
if [[ "$MUT_VAL" != "0" && "$MUT_VAL" != "" ]]; then
  fail "$MUT_ENV=$MUT_VAL — production mutation mode must stay disabled for normal agent work (set 0)"
fi
ok "production_mutation=disabled"

# GitHub auth usable for this repo (non-fatal network errors → fail closed on missing auth)
if command -v gh >/dev/null 2>&1; then
  if ! gh auth status >/dev/null 2>&1; then
    fail "gh not authenticated — run gh auth login"
  fi
  ok "gh authenticated"
else
  fail "gh CLI not installed"
fi

ok "project=$PROJECT path=$TARGET branch=$BRANCH"
ok "head=${HEAD_SHA:0:12} origin/main=${ORIGIN_MAIN:0:12} (base current)"
ok "preflight passed"
exit 0

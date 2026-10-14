#!/usr/bin/env bash
# Agent preflight — fail-closed gate before any AllTrue write.
# Works from any clone (cloud session or local). The local task-path allowlist
# was retired by Founder 2026-10-14（三）#6; known-bad legacy checkouts stay denied.
# Modes: agent (default) | ci
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
CONFIG="${AGENT_PREFLIGHT_CONFIG:-${SCRIPT_DIR}/agent-preflight.config.json}"
MODE="${AGENT_PREFLIGHT_MODE:-agent}"

fail() {
  echo "agent-preflight: FAIL: $*" >&2
  echo "agent-preflight: recovery: fresh branch from origin/main with a scripts/ci/branch-policy.mjs prefix, clean tree" >&2
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
  [[ -f "$SCRIPT_DIR/ci/branch-policy.mjs" ]] || fail "branch policy missing"
  python3 - "$CONFIG" <<'PY'
import json,sys
cfg=json.load(open(sys.argv[1]))
assert "/home/jerry/alltrue" in cfg["forbidden_exact"]
assert "allowed_path_prefixes" not in cfg, "local path allowlist is retired (Founder 2026-10-14)"
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
  [[ "$BRANCH" != "main" && "$BRANCH" != "master" ]] || fail "writes forbidden on $BRANCH — create a branch"
fi

# Same branch policy as Presubmit CHECK 1 / ci-preflight (scripts/ci/branch-policy.mjs).
command -v node >/dev/null || fail "node not installed (needed for branch policy)"
node --input-type=module -e '
const { validateBranchName } = await import(process.argv[1]);
const v = validateBranchName(process.argv[2]);
if (!v.ok) { console.error(v.message); process.exit(1); }
' "$SCRIPT_DIR/ci/branch-policy.mjs" "$BRANCH" || fail "branch '$BRANCH' rejected by scripts/ci/branch-policy.mjs"

if [[ "$(json_get require_base_equals_origin_main)" == "true" ]]; then
  MB="$(git merge-base HEAD origin/main)"
  [[ "$MB" == "$ORIGIN_MAIN" ]] || fail "stale base: merge-base=$MB origin/main=$ORIGIN_MAIN — rebase on origin/main"
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

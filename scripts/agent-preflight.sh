#!/usr/bin/env bash
# Tool-neutral agent preflight — refuse writes on forbidden worktrees.
# Canonical policy: docs/governance/WORKTREE_POLICY.md
set -euo pipefail

FORBIDDEN_EXACT="/home/jerry/alltrue"
SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() { echo "agent-preflight: FAIL: $*" >&2; exit 1; }
ok() { echo "agent-preflight: OK: $*"; }

resolve() {
  local p="$1"
  if command -v realpath >/dev/null 2>&1; then
    realpath -m "$p" 2>/dev/null || readlink -f "$p" 2>/dev/null || echo "$p"
  else
    readlink -f "$p" 2>/dev/null || echo "$p"
  fi
}

# 1) Caller's cwd / git toplevel (what the agent is about to edit)
CALLER_PWD="$(resolve "${PWD:-.}")"
CALLER_GIT=""
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  CALLER_GIT="$(resolve "$(git rev-parse --show-toplevel)")"
fi

if [[ "$CALLER_PWD" == "$FORBIDDEN_EXACT" ]] || [[ "$CALLER_GIT" == "$FORBIDDEN_EXACT" ]]; then
  fail "caller worktree is forbidden dirty tree ($FORBIDDEN_EXACT). See docs/governance/WORKTREE_POLICY.md"
fi

if [[ -e "${HOME:-}/alltrue" ]]; then
  HOME_ALLTRUE="$(resolve "${HOME}/alltrue")"
  if [[ "$HOME_ALLTRUE" == "$FORBIDDEN_EXACT" ]]; then
    if [[ "$CALLER_PWD" == "$HOME_ALLTRUE" ]] || [[ "$CALLER_GIT" == "$HOME_ALLTRUE" ]]; then
      fail "~/alltrue resolves to forbidden path $FORBIDDEN_EXACT"
    fi
  fi
fi

# 2) Script package tree should also not be the forbidden path
SCRIPT_RES="$(resolve "$SCRIPT_ROOT")"
if [[ "$SCRIPT_RES" == "$FORBIDDEN_EXACT" ]]; then
  fail "script lives inside forbidden tree $FORBIDDEN_EXACT"
fi

cd "$SCRIPT_ROOT"

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  WT="$(resolve "$(git rev-parse --show-toplevel)")"
  BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
  ok "caller_pwd=$CALLER_PWD script_worktree=$WT branch=$BRANCH"
  if [[ -n "$(git status --porcelain 2>/dev/null | head -1)" ]]; then
    ok "script worktree has local changes"
  else
    ok "script worktree clean"
  fi
else
  fail "script root is not a git work tree"
fi

if git rev-parse --verify origin/main >/dev/null 2>&1; then
  ok "origin/main present"
else
  echo "agent-preflight: WARN: origin/main missing — run git fetch origin main" >&2
fi

ok "preflight passed (policy: docs/governance/WORKTREE_POLICY.md)"
exit 0

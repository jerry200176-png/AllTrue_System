#!/usr/bin/env bash
# Fast pre-push checks: the one list for lefthook (people) and .claude/hooks/guard_bash.py (agents, on
# every `git push`, no install needed). Heavy suites (PHPUnit, vitest, vite build) stay in CI.
# Add a new fast check here, not in the guard. Skip once: LEFTHOOK=0 git push.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"
base="$(git merge-base origin/main HEAD 2>/dev/null)" || { echo "agent-prepush: skip (no origin/main; git fetch origin main)"; exit 0; }
files="$(git diff --name-only "$base"..HEAD)"
branch="$(git branch --show-current)"
fail=0

# step <name> <path regex, '' = always> <command...>: run when a changed path matches; show output only on failure.
step() {
  local name="$1" pattern="$2" out
  shift 2
  if [ -n "$pattern" ] && ! grep -qE "$pattern" <<<"$files"; then return 0; fi
  if out="$("$@" 2>&1)"; then echo "ok    $name"; else echo "FAIL  $name"; tail -n 25 <<<"$out"; fail=1; fi
}

size_gate() {
  # CI's own CHECK 2 body (cannot drift). Without PyYAML the body cannot be read; ci-preflight above
  # already applied the same size limit, so say so instead of blocking the push.
  python3 -c 'import yaml' 2>/dev/null || { echo "skip: PyYAML missing (pip install pyyaml)"; return 0; }
  local body
  body="$(python3 scripts/presubmit-step.py '[CHECK 2] PR size')" || return 1
  GITHUB_HEAD_REF="$branch" GITHUB_ENV=/dev/null bash -e -c "$body"
}

eslint_unused() {
  [ -d frontend/node_modules ] || { echo "skip: frontend/node_modules missing (npm --prefix frontend ci)"; return 0; }
  node scripts/check-eslint-unused-baseline.mjs
}

step preflight '' env AGENT_PREFLIGHT_MODE=ci bash scripts/agent-preflight.sh
# Branch name, generated release notes (staff card limits), workflow YAML, secrets, PR size, route registration.
step ci-preflight '' node scripts/ci-preflight.mjs
step size-gate '' size_gate
step ui-ratchet '^(frontend/|scripts/ui-legacy-pages\.json$|scripts/check-ui-ratchet\.mjs$)' \
  node scripts/check-ui-ratchet.mjs --vs-base "$base"
step ratchet-baselines '^(docs/design-style-baseline\.json|scripts/controller-db-baseline\.json)$' \
  node scripts/check-ratchet-baseline-touch.mjs --base "$base" --branch "$branch"
step eslint-unused-baseline '^(frontend/src/|frontend/eslint-unused-baseline\.json$|frontend/eslint\.config\.js$)' eslint_unused
step pr-declaration '' bash scripts/presubmit-local-declaration.sh

[ "$fail" = 0 ] || { echo "agent-prepush: fix the FAIL lines above, commit, push again."; exit 1; }

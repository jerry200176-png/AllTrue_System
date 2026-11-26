#!/usr/bin/env bash
# Fast pre-push checks: the one list for lefthook (people) and .claude/hooks/guard_bash.py (agents, on
# every `git push`, no install needed). Heavy suites (PHPUnit, vitest, vite build) stay in CI.
# Add a new fast check here, not in the guard. Skip once: LEFTHOOK=0 git push.
# The guard runs the committed copy, and only if it can verify every command in it: keep commands
# literal (no "$@", no `bash -c "$var"`, no eval), or the guard skips the whole list.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"
base="$(git merge-base origin/main HEAD 2>/dev/null)" || { echo "agent-prepush: skip (no origin/main; git fetch origin main)"; exit 0; }
files="$(git diff --name-only "$base"..HEAD)"
branch="$(git branch --show-current)"
log="$(mktemp)"
said="$(mktemp)"
trap 'rm -f "$log" "$said"' EXIT
fail=0
touched() { grep -qE "$1" <<<"$files"; }
bad() { echo "FAIL  $1"; tail -n 25 <"$log"; fail=1; }

AGENT_PREFLIGHT_MODE=ci bash scripts/agent-preflight.sh >"$log" 2>&1 || bad preflight
# Branch name, generated release notes (staff card limits), workflow YAML, secrets, route registration and
# the PR size limit of presubmit CHECK 2.
node scripts/ci-preflight.mjs >"$log" 2>&1 || bad ci-preflight
if touched '^(frontend/|scripts/ui-legacy-pages\.json$|scripts/check-ui-ratchet\.mjs$)'; then
  node scripts/check-ui-ratchet.mjs --vs-base "$base" >"$log" 2>&1 || bad ui-ratchet
fi
if touched '^(docs/design-style-baseline\.json|scripts/controller-db-baseline\.json)$'; then
  node scripts/check-ratchet-baseline-touch.mjs --base "$base" --branch "$branch" >"$log" 2>&1 || bad ratchet-baselines
fi
if touched '^(frontend/src/|frontend/eslint-unused-baseline\.json$|frontend/eslint\.config\.js$)' && [ -d frontend/node_modules ]; then
  node scripts/check-eslint-unused-baseline.mjs >"$log" 2>&1 || bad eslint-unused-baseline
fi
bash scripts/presubmit-local-declaration.sh >"$log" 2>&1 || bad pr-declaration
# Presubmit CHECK 0d, before the PR exists: changing a line another PR merged in the last 7 days needs
# `Rewrites: #N — reason`. CI reads it from the PR body; here a commit message counts too, so the first push
# can pass. Put the same line in the PR body when you open it.
git log --format=%B "$base"..HEAD >"$said"
gh pr view --json body --jq .body >>"$said" 2>/dev/null || :
python3 scripts/check-recent-work-revert.py --base origin/main --head HEAD --body-file "$said" >"$log" 2>&1 \
  || bad 'rewrites (add the Rewrites line to a commit message now, and to the PR body)'

[ "$fail" = 0 ] || { echo "agent-prepush: fix the FAIL lines above, commit, push again."; exit 1; }
echo "agent-prepush: OK"

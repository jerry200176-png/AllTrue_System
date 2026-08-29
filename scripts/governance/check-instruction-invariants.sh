#!/usr/bin/env bash
# Small deterministic instruction invariant checks (not a NLP parser).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
errors=0
warn() { echo "invariants: WARN: $*"; }
fail() { echo "invariants: FAIL: $*"; errors=$((errors + 1)); }
ok() { echo "invariants: OK: $*"; }

[[ -f docs/governance/WORKTREE_POLICY.md ]] || fail "missing WORKTREE_POLICY.md"
[[ -f docs/governance/COMPANY_CONSTITUTION.md ]] || fail "missing COMPANY_CONSTITUTION.md"
[[ -f AGENTS.md ]] || fail "missing AGENTS.md"
[[ -f CLAUDE.md ]] || fail "missing CLAUDE.md"
[[ -f scripts/agent-preflight.sh ]] || fail "missing agent-preflight.sh"
[[ -f scripts/governance/autonomy_gate.py ]] || fail "missing autonomy_gate.py"

grep -q '/home/jerry/alltrue' docs/governance/WORKTREE_POLICY.md || fail "WORKTREE_POLICY missing forbidden path"
grep -q 'WORKTREE_POLICY' AGENTS.md || fail "AGENTS.md must cite WORKTREE_POLICY"
grep -q 'WORKTREE_POLICY' CLAUDE.md || fail "CLAUDE.md must cite WORKTREE_POLICY"

# Forbidden: CLAUDE recommending ~/alltrue as the place for all code changes
if grep -E '所有程式碼改動在這裡' CLAUDE.md | grep -q '~/alltrue'; then
  fail "CLAUDE.md still recommends ~/alltrue as primary code root"
fi
if grep -qE '`~/alltrue` — 所有' CLAUDE.md; then
  fail "CLAUDE.md legacy ~/alltrue primary path"
fi

# .cursorrules must not say only ~/alltrue without 禁止
if [[ -f .cursorrules ]]; then
  if grep -q '~/alltrue' .cursorrules && ! grep -q '禁止.*/home/jerry/alltrue\|禁止.*`/home/jerry/alltrue`' .cursorrules; then
    # allow if WORKTREE_POLICY cited with 禁止
    if ! grep -q 'WORKTREE_POLICY' .cursorrules; then
      fail ".cursorrules mentions ~/alltrue without WORKTREE_POLICY"
    fi
  fi
  ok ".cursorrules present (Cursor adapter)"
fi

grep -qE '\*\*Version:\*\*' docs/governance/COMPANY_CONSTITUTION.md || fail "Constitution missing Version"
grep -q 'CI green alone\|CI green' docs/governance/EVIDENCE_CONTRACT.md || fail "Evidence Contract missing CI-green anti-metric"

# Skills referenced exist
for sk in agent-preflight knowledge-graph-link; do
  [[ -f ".cursor/skills/$sk/SKILL.md" ]] || fail "missing skill .cursor/skills/$sk/SKILL.md"
done

if [[ -f scripts/governance/autonomy_gate.py ]]; then
  if ! python3 scripts/governance/autonomy_gate.py --check-instructions; then
    fail "stale/conflicting autonomy instructions detected"
  fi
fi

if [[ "$errors" -gt 0 ]]; then
  echo "invariants: FAIL count=$errors"
  exit 1
fi
ok "all deterministic checks passed"
exit 0

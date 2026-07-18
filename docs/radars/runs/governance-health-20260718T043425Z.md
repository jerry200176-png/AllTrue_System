# Governance Health — latest run

- revision: `36dba1f9`
- ran_at: `20260718T043425Z`
- overall: **PASS** (fail=0 warn=0)
- owner: Founder / CTO Agent
- cadence: weekly
- machine output: `docs/radars/runs/governance-health-20260718T043425Z.json`

## Checks

```
=== instruction_invariants ===
invariants: OK: .cursorrules present (Cursor adapter)
invariants: OK: all deterministic checks passed
result: PASS
=== capability_registry ===
capability-registry: OK warnings=0
result: PASS
=== overlay_pin ===
overlay-pin: OK: constitution=0.1.0 overlay=0.1.0 file=/home/jerry/workspace/sunrise-cafe/docs/governance/OVERLAY.md
result: PASS
=== agent_preflight_self ===
agent-preflight: OK: caller_pwd=/home/jerry/alltrue-prD script_worktree=/home/jerry/alltrue-prD branch=feat/governance-freshness
agent-preflight: OK: script worktree has local changes
agent-preflight: OK: origin/main present
agent-preflight: OK: preflight passed (policy: docs/governance/WORKTREE_POLICY.md)
result: PASS
=== canonical_entrypoints ===
result: PASS
=== cursor_adapters_present ===
result: PASS
```

## False positives
Overlay fetch may fail offline — set OVERLAY_FILE= to a local sunrise OVERLAY.md.

## Remediation
Fix failing script; re-run `make governance-health`.

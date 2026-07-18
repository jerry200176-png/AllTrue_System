# Agent Capability Registry

**Machine source (canonical for CI):** [`agent_capabilities.json`](./agent_capabilities.json)  
**Validator:** `python3 scripts/governance/validate-capability-registry.py`  
**Rule:** Never assume PR merge, deploy, or in-app write without a **non-stale** Proven row (or safer status).

## Required fields (Proven)

`last_verified_at`, `verification_method`, `environment`, `verifier`, `review_after`, `status`, `risk`, `evidence`

- **high** risk Proven past `review_after` → **CI fail**
- **low/medium** → warning (radar) / fail only if validator configured strict

## Status legend

| Status | Meaning |
|--------|---------|
| Proven | Verified with reproducible evidence before `review_after` |
| Partial | Works with caveats |
| Unsafe | Must not use |
| Missing | Not available |

Do **not** refresh Proven by agent prose alone — re-run the listed `verification_method` and update YAML.

Human notes: see YAML `evidence` fields; forbidden worktree `/home/jerry/alltrue` remains never-edit.

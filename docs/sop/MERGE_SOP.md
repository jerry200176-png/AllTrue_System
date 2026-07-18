# Merge SOP (Risk-Based)

**Owner:** Founder / CTO Agent  
**Canonical policy:** [`docs/governance/RISK_BASED_MERGE_POLICY.md`](../governance/RISK_BASED_MERGE_POLICY.md)  
**Last verified:** 2026-07-18  

## Before opening a PR

1. Run `make agent-preflight` in a **non-forbidden** worktree.  
2. Classify **Risk-Class** (R0–R3); when unsure, pick higher.  
3. Ensure tests match risk (R1+ needs regression coverage for the bug/path).

## Before merge

| Class | Checklist |
|-------|-----------|
| R0 | CI green; no production behavior change |
| R1 | CI green; test; independent verifier comment; rollback one-liner |
| R2 | All R1 + independent approval + prod verification plan in PR |
| R3 | Founder approval + dry-run/Manifest + recovery point + execution gate |

## After merge

1. Confirm deploy / Actions if deployable.  
2. For product bugs: public in-app reply + Evidence Contract before `resolved`.  
3. Update Knowledge Graph / Lessons when high-risk.

## Do not

- Force-push `main`.  
- Bypass required checks.  
- Re-enable known CI-storm / autonomous-loop workflows without R3.  
- Use a fake second identity to satisfy R2/R3.  

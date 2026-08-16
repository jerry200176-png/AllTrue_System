# Merge SOP (Risk-Based)

**Owner:** Founder / CTO Agent  
**Canonical policy:** [`docs/governance/RISK_BASED_MERGE_POLICY.md`](../governance/RISK_BASED_MERGE_POLICY.md)  
**Fleet capability:** [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md) — AllTrue does not add a Founder rubber-stamp.  
**Last verified:** 2026-08-15  

## Before opening a PR

1. Run `make agent-preflight` in a **non-forbidden** worktree.  
2. Classify **Risk-Class** (R0–R3); when unsure, pick higher.  
3. Ensure tests match risk (R1+ needs regression coverage for the bug/path).

## Before merge

| Class | Checklist |
|-------|-----------|
| R0 | CI green; no production behavior change |
| R1 | CI green; test; independent verifier comment; rollback one-liner |
| R2 | CI green (all required checks) + documented self-review checklist in PR body + resolve all bot/reviewer threads + prod verification plan in PR; solo mode: **no separate verifier needed** (Founder Decision 2026-08-14) |
| R3 | Required checks + Repair Manifest in the PR + recovery point + verifier Agent note; implementing Agent merges |

## After required checks (R0–R3)

The implementing Agent squash-merges. Do not wait for a human click.

```bash
gh pr merge --squash --delete-branch
```

Never `--admin`. If a **code** merge to `main` starts `deploy.yml`, that is the product control plane (I1). Docs-only still skips deploy. Extra mutation uses committed `workflow_dispatch`, never SSH / artisan / phpunit on the Pi.

## After merge

1. Confirm deploy / Actions if deployable.  
2. For product bugs: public in-app reply + Evidence Contract before `resolved`.  
3. Update Knowledge Graph / Lessons when high-risk.

## Do not

- Force-push `main`.  
- Bypass required checks (`--admin`).  
- Wait for a human merge click when checks are already green.  
- Re-enable known CI-storm / autonomous-loop workflows without R3.  
- Use a fake second identity to satisfy R2/R3.  

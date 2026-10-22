# Merge SOP (Risk-Based)

**Owner:** Founder / CTO Agent  
**Canonical policy:** [`docs/governance/RISK_BASED_MERGE_POLICY.md`](../governance/RISK_BASED_MERGE_POLICY.md)  
**Fleet capability:** [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md) — AllTrue applies its stricter T3/protected product boundary.
**Last verified:** 2026-08-29

## Before opening a PR

1. Local WSL worktree only: run `make agent-preflight` in a **non-forbidden** worktree. Cloud sessions skip this ([`WORKTREE_POLICY.md`](../governance/WORKTREE_POLICY.md)).  
2. Classify **Risk-Class** (R0–R3) and **Autonomy-Tier** (T0–T3); when unsure, pick higher.
3. Ensure tests match risk (R1+ needs regression coverage for the bug/path).

## Before merge

| Class | Checklist |
|-------|-----------|
| R0/T0 | Required checks and docs/link checks; no production behavior change |
| R1/T1 | Required CI, regression test, review, and rollback one-liner |
| R2/T2 | Exact-target required CI, rollback readiness, risk/production-verification checklist, and resolved bot/reviewer threads |
| R3/T3 | Evidence package, dry-run/recovery plan, and Repair Manifest where applicable; stop before protected execution or activation for Founder approval |

All classes also need the exact final-head review evidence described in the canonical policy: a completed clean Codex summary, or an actual independent review recorded by the exact owner for the full head when Codex has not reviewed it. Current-head running/incomplete review or findings cannot be overridden by fallback. Missing evidence is `review-pending`, not merge clearance.

## After required checks (R0–R2 only)

When the applicable risk gates and exact-head review pass without a T3/protected boundary, add `queue`; do not wait for a blanket human approval. The land queue re-reads threads and review before every serial/batch squash. A changed head needs fresh CI and review; `review-pending` retains the queue label while waiting.

```bash
gh pr edit <PR-number> --add-label queue
```

Native auto-merge stays disabled until `review/final-head` is required by the main ruleset and pinned to GitHub Actions. The Founder changes that setting only after the default-branch gate has published checks. This SOP does not authorize a ruleset edit or a fake owner review marker.

Never `--admin`. If a **code** merge to `main` starts `deploy.yml`, that is the product control plane (I1). Docs-only still skips deploy. Extra mutation uses committed `workflow_dispatch`, never SSH / artisan / phpunit on the Pi.

## Continue an approved task

Apply [approval continuity](../governance/RISK_BASED_MERGE_POLICY.md#approval-continuity-within-the-authorized-task): after explicit Founder GO, execute the approved scope without asking again at every merge/deploy/verification step. Recheck exact-head evidence and the approved boundary; request a new decision only for a material scope change or a genuinely missing authorization. Existing platform gates remain mandatory.

## After merge

1. Confirm deploy / Actions if deployable.  
2. For product bugs: public in-app reply + Evidence Contract before `resolved`.  
3. Update Knowledge Graph / Lessons when high-risk.

## Do not

- Force-push `main`.  
- Bypass required checks (`--admin`).  
- Wait for a human merge click when checks are already green and no T3/protected boundary is involved.
- Re-enable known CI-storm / autonomous-loop workflows without R3.  
- Use a fake second identity to satisfy R2/R3.  

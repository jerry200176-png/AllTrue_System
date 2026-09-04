# Merge SOP (Risk-Based)

**Owner:** Founder / CTO Agent  
**Canonical policy:** [`docs/governance/RISK_BASED_MERGE_POLICY.md`](../governance/RISK_BASED_MERGE_POLICY.md)  
**Fleet capability:** [portfolio-ops `AUTONOMY_POLICY`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md) — AllTrue applies its stricter T3/protected product boundary.
**Last verified:** 2026-08-29

## Before opening a PR

1. Run `make agent-preflight` in a **non-forbidden** worktree.  
2. Classify **Risk-Class** (R0–R3) and **Autonomy-Tier** (T0–T3); when unsure, pick higher.
3. Ensure tests match risk (R1+ needs regression coverage for the bug/path).
4. For every director/teacher-facing feature or UX change, add the user-visible
   version record before merge: a dated `CHANGELOG.md` entry, a matching
   `STAFF_UPDATES.yml` item, and PR fields stating what changed, where it is,
   and how to use it. Presubmit CHECK 4A verifies this fail-closed.

## Before merge

| Class | Checklist |
|-------|-----------|
| R0/T0 | Required checks and docs/link checks; no production behavior change |
| R1/T1 | Required CI, regression test, review, and rollback one-liner |
| R2/T2 | Required CI, independent review, risk/rollback/production-verification checklist, and resolved bot/reviewer threads |
| R3/T3 | Evidence package, dry-run/recovery plan, and Repair Manifest where applicable; stop before protected execution or activation for Founder approval |

## After required checks (R0–R2 only)

The implementing Agent may squash-merge when the applicable risk gates pass and
the merge does not cross a T3/protected boundary. T2 is eligible for the same
autonomous production path only when it is a validated, reversible,
non-protected change; otherwise it remains held. Do not wait for a blanket
human approval, and do not treat a release note as a post-deploy substitute.

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
- Wait for a human merge click when checks are already green and no T3/protected boundary is involved.
- Re-enable known CI-storm / autonomous-loop workflows without R3.  
- Use a fake second identity to satisfy R2/R3.  

---
owner: Ops / Principal Architect
status: normative SOP (L3)
derives_from: CONSTITUTION.md Article VII; ADR-0009
review_cycle: quarterly
---

# RUNBOOK — Break Glass (Emergency Bypass) SOP

> Authority: **Constitution Article VII**. This SOP is the *only* legitimate way to bypass a normal admission gate. Using it without a qualifying trigger is itself a violation.

## When this applies (trigger — must be true)
- Active **production outage** (health failing, users blocked) **or** imminent **data-loss risk**, **and**
- the normal path is unavailable (e.g. CI/deploy capacity exhausted).
- ❌ Convenience, deadline pressure, or "it's a small change" are **not** triggers.

## Hard prohibitions (never, even under break-glass)
`git push --force` to `main` · running tests/`migrate`/`config:clear` on prod data (incidents B/C) · permanent divergence between deployed artifact and `origin/main` · entering credentials into fields · skipping signing/hooks.

## Procedure
1. **Declare** the trigger in the incident channel (one line: what is down / at risk).
2. **Emit the break-glass record BEFORE acting** (immutable, queryable):
   ```
   break-glass: actor=<id> at=<ts> target_sha=<sha-or-"unmerged:branch@sha">
   ci_status=<passed|unavailable> reason=<trigger> scope=<frontend-assets|backend|data>
   ```
   Store in the audit log / issue; this is the auditability obligation (Article VI).
3. **Minimum action only.** Prefer the most reversible mechanism:
   - Frontend-only: build+verify locally → `rsync dist_build` → Pi `copy-to-backend.cjs` (integrity guard) → health + asset content-type check. Back up the current bundle first (`backups/emergency/pre*`). *(Grounded precedent: in-app #174.)*
   - Backend/data: avoid; if unavoidable, backup first, do not `git reset --hard` over dirty tracked storage.
4. **Verify**: `curl -sk https://daan.lifenet.com.tw/api/v1/health` = `ok`; referenced assets 200 `text/javascript`; the specific fix observable.
5. **Reconciliation obligation (ADR-0009)** — within SLA (target: next CI availability, ≤ the freeze window):
   - Open a **P1 "reconcile to main"** issue automatically.
   - Land the change on `origin/main` via normal PR + CI so the deployed artifact == SoT.
   - Restore the normal gate; confirm no artifact↔SoT divergence remains.
6. **Postmortem**: log in `CHANGELOG` + `AI_REGRESSION_LESSONS`; add an action item to remove the need for the bypass (e.g. the gate-availability SPOF, ADR-0009 / fact F-16).

## Rollback
Restore the backed-up bundle/asset set (or `git revert` once on main). Frontend asset restore is immediate; data changes require the backup taken in step 3.

## Definition of Done for a break-glass event
record emitted ✓ · minimum action ✓ · health verified ✓ · reconcile-to-main issue open ✓ · postmortem logged ✓.

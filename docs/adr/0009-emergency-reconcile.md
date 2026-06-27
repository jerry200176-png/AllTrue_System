---
status: Accepted
date: 2026-06-27
owner: Principal Architect
---
# ADR-0009: Emergency changes must reconcile to the source of truth

## Constitution link
Derives from CONSTITUTION.md Article VII (Break Glass) + Article VI.1 (truthful state).

## Context
`OPERATIONS_RUNBOOK §139` permits an emergency manual frontend deploy when CI/deploy is unavailable. Exercised this period for in-app #174, it ships **un-merged, un-CI'd** code directly to `backend/public` — a secondary admission path that bypasses the CI Decision Kernel (the single deployment PDP, fact F-16). The Pi git tree was also found on a stale branch with dirty tracked storage — artifact↔SoT divergence.

## Decision
Any out-of-band production change must: (1) emit an immutable break-glass record *before* acting; (2) be the minimum action; (3) **reconcile `origin/main` to the deployed state** and restore the normal gate within SLA; (4) auto-open a P1 reconcile issue. Permanent divergence between the deployed artifact and `origin/main` is forbidden.

## Consequences
+ The secondary deploy path becomes auditable and self-healing; F-16 returns to a single effective PDP after reconciliation.
− Requires the break-glass SOP + record tooling (`RUNBOOK_BREAK_GLASS.md`).

## References
Fact F-16. SOP `RUNBOOK_BREAK_GLASS.md`. Constitution Article VII. Precedent: in-app #174 §139 deploy.

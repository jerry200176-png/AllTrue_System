# ADR-POP-010: CONTROL_PLANE_CONTRACT I1 Amendment

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 75% |
| Risk | **High** |
| Revisit | 2026-08 |

## Context

Contract I1 states **only `deploy.yml` may execute production changes**. POP introduces a unified execution path for data repairs, backfills, reconciles, and (eventually) deploy-as-strategy. Without amendment, POP violates I1 and K3/K9 resolutions.

INCIDENT stack must remain authoritative for **incident FINAL_ACTION selection** (I3–I4). POP executes **approved production operations**, not incident triage.

## Decision

1. **Amend I1** to recognize **two execution authorities** under POP:
   - **`application-deploy`** strategy via `deploy.yml` (code + migration + frontend)
   - **POP Executor** via self-hosted runner / approved token (data operations, reconciles, etc.)
2. Both must route through POP Approval + Policy when `approval_required: true` in catalog.
3. **Runbooks remain non-executing** (helpers + evidence only).
4. Register **K11** in CONTRADICTION_REGISTRY: legacy one-off repair workflows are **deprecated**, not parallel authority.
5. Bump `contract-version` to **2**.

## Alternatives

- Keep I1 literal; run all repairs via deploy.yml: rejected (wrong blast radius, couples deploy to data repair).
- POP without contract change: rejected (P0 governance conflict).
- Third execution path (SSH workflows): rejected (deprecated).

## Trade-offs

| Pro | Con |
|-----|-----|
| Single POP framework | Contract migration risk |
| Clear deploy vs data ops split | Operators learn new path |

## Consequences

- Update `docs/CONTROL_PLANE_CONTRACT.md` (this PR).
- Sunset `.github/workflows/173-supersede-repair.yml` in Phase 3.
- INCIDENT FINAL_ACTION may **request** a POP operation; POP does not replace inference/policy.
- Phase 1: no production execute — contract + catalog + schema only.

# Architecture Decision Records — POP (Production Operations Platform)

> **Status:** Architecture Freeze (2026-07-16). POP is the sole Production Control Plane.  
> **Governance:** New operations add Strategy / Policy / Catalog / Invariant Pack / ADR only.  
> **Breaking changes:** Architecture Breaking ADR + Review required.

## Index

| ADR | Title | Status |
|-----|-------|--------|
| [ADR-POP-001](ADR-POP-001-control-plane.md) | Control Plane (Desired State + Reconciliation) | Accepted |
| [ADR-POP-002](ADR-POP-002-approval-sot.md) | Approval SoT = Database | Accepted |
| [ADR-POP-003](ADR-POP-003-execution-plane.md) | Execution Plane Interface | Accepted |
| [ADR-POP-004](ADR-POP-004-policy-engine.md) | Policy Engine (Config) | Accepted |
| [ADR-POP-005](ADR-POP-005-catalog-capability.md) | Catalog + Capability Model | Accepted |
| [ADR-POP-006](ADR-POP-006-event-model.md) | Event Model + Outbox | Accepted |
| [ADR-POP-007](ADR-POP-007-state-machine.md) | Operations State Machine | Accepted |
| [ADR-POP-008](ADR-POP-008-executors.md) | Executors (Self-hosted Primary) | Accepted |
| [ADR-POP-009](ADR-POP-009-desired-observed-state.md) | Desired / Observed State Stores | Accepted |
| [ADR-POP-010](ADR-POP-010-contract-i1.md) | CONTROL_PLANE_CONTRACT I1 Amendment | Accepted |
| [ADR-POP-011](ADR-POP-011-meta-controller.md) | Meta Controller (CP Self-Health) | Accepted |
| [ADR-POP-012](ADR-POP-012-dlq.md) | Dead Letter Queue | Accepted |
| [ADR-POP-013](ADR-POP-013-versioning.md) | Full Versioning for Replay | Accepted |
| [ADR-POP-014](ADR-POP-014-fitness-functions.md) | Architecture Fitness Functions | Accepted |

## Lifecycle (all ADRs)

`draft` → `active` → `deprecated` → `frozen` → `retired` → `archived`

## Phase map

| Phase | Scope |
|-------|--------|
| **1** | ADRs, contract I1, catalog v0, schema draft, interfaces, fitness skeleton (read-only) |
| **2** | Approval API, execution-record, dashboard RO, shadow mode |
| **3** | First production execute (`supersede-renewal`) |
| **4+** | Strategy batch migration |

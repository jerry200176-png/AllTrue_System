# ADR-POP-005: Catalog + Capability Model

| Field | Value |
|-------|-------|
| Status | Accepted |
| Lifecycle | active |
| Date | 2026-07-16 |
| Confidence | 90% |
| Risk | Low |
| Revisit | 2027-01 |

## Context

Operators and AI need discoverable metadata: risk, owners, capabilities, invariants, lifecycle — not scattered runbooks.

## Decision

- **Catalog:** `operations/catalog.yaml` — authoritative strategy registry.
- **Capabilities:** Repair, Migration, Deploy, Reconcile, Rollback, Verify, Snapshot, Plan.
- **Domain ownership** (required per strategy): domain, business_owner, technical_owner, approver_roles, runbook, alert_group, escalation_policy.
- **Lifecycle:** draft | active | deprecated | frozen | retired | archived.

## Alternatives

- README-only docs: rejected (not machine-discoverable).
- DB-only catalog: rejected (Git review for strategy metadata).

## Trade-offs

| Pro | Con |
|-----|-----|
| AI/UI discover via API | Catalog drift if not CI-gated |
| Clear ownership | More fields to maintain |

## Consequences

- `GET /pop/catalog` and `GET /pop/capabilities` in Phase 2+.
- New strategy = catalog entry + ADR if new pattern.

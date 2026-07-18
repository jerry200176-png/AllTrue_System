# Governance changelog

## 2026-07-18 — Risk-Based Merge Policy + Service Catalog + #173 Repair Manifest

- Constitution (same 0.1.0 pin): R0–R3 merge policy; R3 data-repair gate hard ban.
- `RISK_BASED_MERGE_POLICY.md`, `MERGE_SOP.md`, PR template Risk-Class.
- Machine-readable `docs/catalog/services.yaml` + validators.
- Dependabot triage 2026-07-18; Actions checkout SHA pin pilot on docs-integrity.
- Product discovery: leave append-vs-shift (no implementation).
- Immutable Repair Manifest `RM-173-SUPERSEDE-B-2026-07-18` (executed).

## 2026-07-18 — Freshness + Governance Health radar

- `agent_capabilities.json` + validators; overlay pin check; instruction invariants; operational Governance Health radar (first real run artifact under `docs/radars/runs/`).
- KG #203 uses `n/a (in-app-only)` formal Issue notation.


## 2026-07-18 — WORKTREE_POLICY + agent-preflight

- Canonical path policy; adapters cite it; `scripts/agent-preflight.sh` + `make agent-preflight`.


| Version | Date | Change |
|---------|------|--------|
| 0.1.0 | 2026-07-18 | Risk-Based Merge Policy + R3 repair gate (overlay pin stays 0.1.0; no constitution bump) |
| 0.1.0 | 2026-07-18 | Initial Company Core MVP: Constitution, Evidence, Capability Registry, KG stub, Lessons index, Preflight, Handoff, Radars scaffold |

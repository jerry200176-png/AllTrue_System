# Governance changelog

## 2026-08-15 — Fleet merge capability (constitution pin stays 0.1.0)

- Portfolio-ops is the fleet authority. Agents squash-merge AllTrue R0–R2 when
  required GitHub checks are green (`AUTONOMY_POLICY` / `fleet-merge-policy`).
- AllTrue keeps domain P0 (no Pi tests, campus isolation) and Control Plane I1
  (`deploy.yml` is still the only production execute path). Docs-only still
  skips deploy; a **code** merge to `main` may start deploy, and that is accepted.
- Product overlays must not re-ban merge. R3 and extra production mutation stay
  Founder-only.
- Overlay pin remains 0.1.0 (sunrise `OVERLAY.md`); no constitution version bump
  until a coordinated sunrise pin PR.

## 2026-08-09 — Hermes production-agent temporary exception (#1676)

- Recorded the Founder-approved temporary exception for the colleague-owned
  Hermes gateway on the production Pi.
- Added live evidence, explicit unknowns, non-actions, a 2026-08-23 review
  date, and a least-privilege/systemd hardening follow-up.
- No production host, service, credential, database, or network configuration
  was changed by this governance pass.

## 2026-07-26 — CI failure intelligence + fast preflight (G1)

- Canonical docs: `docs/governance/CI_GOVERNANCE.md`
- Scripts: `scripts/ci-preflight.mjs`, `scripts/ci/gov-codes.mjs`, `scripts/ci/branch-policy.mjs`, `scripts/sync-generated.mjs`
- Presubmit CHECK 1 uses shared branch policy (`sec`/`design`/`revert`/…; reject `agent`/`ops`)
- Legacy ≤700 size gate unchanged (risk-based reviewability + report workflow = G2)
- Root `package.json`: `ci:preflight`, `sync:generated`, `test:gov`

## 2026-07-26 — Repository hygiene pass (metadata + docs)

- Docs/status sync after Parent Binding ADR (#1434) + PB-00 (#1446): PB-00 = **IMPLEMENTED / DEPLOYED — PRODUCTION ACTIVATION PENDING** (code merged/deployed; #1436 closed by merge; Pi ops activation / `effective=true` / 7-day baseline pending). Not full operational completion.
- Branch hygiene in `OPERATIONS_RUNBOOK.md` §B1: every delete records tip SHA; `archive/<branch>` tags are **not** default (only unique unmerged keep-value). Session-created blanket archive tags removed after audit.
- Broken relative links fixed in `INCIDENT_POLICY_ENGINE.md` and `RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md`.
- No production code; no deploy; PB-01–PB-09 not started. GitHub PR/Issue write actions may require Founder token (see cleanup PR body).

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

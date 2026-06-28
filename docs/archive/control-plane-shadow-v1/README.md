# Archived Shadow Control Plane v1

> **STATUS: ARCHIVED SHADOW CONTROL PLANE (NON-RUNTIME)**  
> **REASON:** Superseded by [`CONTROL_PLANE_CONTRACT.md`](../../CONTROL_PLANE_CONTRACT.md) (I1–I5)  
> **DO NOT** execute, enable in GitHub Actions, or treat as production authority.

---

## What this archive contains

| Path | Original role | Disposition |
|------|---------------|-------------|
| `adr/` | ADR-001 single deploy authority | Historical — contradicted contract I1 |
| `docs/` | engineering-system, release-flow, layer docs | Historical SOP stack |
| `workflows/` | deploy-production, platform-gate, etc. | **Non-runnable** — not under `.github/workflows/` |
| `scripts/platform/` | PDP v3 policy engine + promotion | Shadow code — never wired to `main` |
| `scripts/misc/` | release-exec, decision-engine, sop-enforce | Shadow execution layer |
| `config/platform/` | PDP signing keys config | Shadow infra |
| `reviews/` | Engineering audit 2026-06-27 | Historical findings (superseded) |
| `refactor/` | Refactor phase reports | Planning history only |

---

## Production authority (active — outside this archive)

| Layer | File |
|-------|------|
| Governance | `docs/CONTROL_PLANE_CONTRACT.md` |
| Execution | `.github/workflows/deploy.yml` |
| Incident | `docs/INCIDENT_*.md` |
| Enforcement | `scripts/control-plane-lint.mjs` + CI |

---

## Re-enabling any artifact

Requires explicit PR titled `[contract-change]` updating contract I1–I5 first.

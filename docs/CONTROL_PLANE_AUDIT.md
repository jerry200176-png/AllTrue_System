# Control Plane Audit

> **Static verification mode** — run before merging ops doc changes or after incidents.  
> **Authority:** [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) · **Conflicts:** [`CONTRADICTION_REGISTRY.md`](CONTRADICTION_REGISTRY.md)

---

## Audit checklist

### A1 — No dual authority

| Check | Pass |
|-------|------|
| A1.1 | Exactly one execution file: `.github/workflows/deploy.yml` in git |
| A1.2 | Decision stack = 5 INCIDENT docs only (START_HERE, INFERENCE, STATE_MACHINE, POLICY, RUNTIME_LOOP) |
| A1.3 | INDEX declares registry-only; no "SSOT", "decision authority" for itself |
| A1.4 | Demoted docs have reference-only banner (SEVERITY, SMOKE, ROLLBACK, CONSTRAINTS) |
| A1.5 | No untracked doc in INDEX incident catalog (`git ls-files`) |

### A2 — No ambiguous state transitions

| Check | Pass |
|-------|------|
| A2.1 | STATE_MACHINE lists allowed next states only |
| A2.2 | Inference engine has signal priority P0–P7 |
| A2.3 | Policy resolver Rules 1–3 + SH-1–SH-3 present |
| A2.4 | No "investigating/waiting" without STATE mapping |
| A2.5 | Override only via ESCALATED_FAILURE explicit rule |

### A3 — No undocumented execution path

| Check | Pass |
|-------|------|
| A3.1 | FINAL_ACTION table maps to deploy.yml or named runbook sections only |
| A3.2 | RUNBOOK_ROLLBACK does not claim deploy authority |
| A3.3 | DEPLOYMENT.md banner: setup reference only |
| A3.4 | DANGEROUS_OPERATIONS listed; no Pi test/config:clear in incident flow |

### A4 — No hidden decision rules

| Check | Pass |
|-------|------|
| A4.1 | No "operator intuition" / "feeling-based" / "interpret freely" in incident docs |
| A4.2 | SEVERITY_MATRIX: mapping reference only banner |
| A4.3 | OPERATIONAL_CONSTRAINTS: does not override policy |
| A4.4 | MemPalace frozen statement identical in INDEX, README, handbook, architecture health |

### A5 — Contract integrity

| Check | Pass |
|-------|------|
| A5.1 | CONTROL_PLANE_CONTRACT.md I1–I5 present |
| A5.2 | CONTRADICTION_REGISTRY K1–K10 present |
| A5.3 | Contract change process documented |
| A5.4 | Precedence: CONTRACT > CONTRADICTION_REGISTRY > INCIDENT stack > demoted refs |
| A5.5 | `node scripts/control-plane-lint.mjs` exits 0 |

---

## Printable audit run

```
[ ] A1  Single decision stack + single execution file
[ ] A2  Deterministic states + explicit override only
[ ] A3  deploy.yml sole executor
[ ] A4  No hidden decision prose
[ ] A5  Contract + registry intact
```

**Pass:** all checked. **Fail:** fix per CONTRADICTION_REGISTRY; update contract if invariant changed.

---

## Quick grep (optional)

```bash
# Should return only demoted docs or contract forbidding list — not incident decision paths
rg -l 'operator intuition|feeling-based|interpret.*freely|manual override except' docs/

# INDEX must not describe deploy behavior
rg 'rollback|SSH|deploy steps' docs/INDEX.md | rg -v 'see deploy.yml|registry|REFERENCE'

# MemPalace unified statement (4 files)
rg -F 'MemPalace is a non-production, best-effort local system' docs/INDEX.md README.md \
  docs/MEMPALACE_OPERATIONS_HANDBOOK.md docs/MEMPALACE_ARCHITECTURE_HEALTH.md
```

---

## Audit outcome log

| Date | Auditor | Result | Notes |
|------|---------|--------|-------|
| 2026-06-28 | consolidation pass | — | Initial audit template |

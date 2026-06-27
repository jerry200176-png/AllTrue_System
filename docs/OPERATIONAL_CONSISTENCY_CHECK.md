# Operational Consistency Check

> Manual checklist — detect doc drift before incidents. **Not automated code.**  
> **Registry:** [`docs/INDEX.md`](INDEX.md) (catalog only)  
> **Truth:** committed files on `origin/main` only

---

## When to run

- Weekly with `node scripts/docs-integrity-check.mjs --strict`
- Before merging ops doc or workflow changes
- After every production incident

---

## Rule 0 — No hidden systems

| Check | Pass |
|-------|------|
| R0.1 | No doc treats uncommitted / working-tree files as production truth |
| R0.2 | No doc treats unmerged ADR / `deploy-production.yml` as **active** unless file exists in `git ls-files` |
| R0.3 | INDEX does not describe deploy steps or system behavior — structured registry only |
| R0.4 | ADR / draft docs not on `main` treated as historical only (see OPERATIONAL_CONSTRAINTS C7) |

**Fail:** Delete or downgrade ambiguous text. Prefer removal over addition.

---

## Rule 1 — Deploy authority is unique

| Check | Pass |
|-------|------|
| R1.1 | Exactly one production deploy workflow in git: `.github/workflows/deploy.yml` |
| R1.2 | `INCIDENT_START_HERE.md`, `RUNBOOK_ROLLBACK.md` reference `deploy.yml` only |
| R1.3 | No committed doc claims another workflow is active production deploy |

**Verify:** `git ls-files .github/workflows/deploy.yml`

---

## Rule 3 — Incident system is singular

| Check | Pass |
|-------|------|
| R3.1 | Production incidents → `INCIDENT_RUNTIME_LOOP.md` → inference engine |
| R3.2 | Severity derived from STATE + signal — [`SEVERITY_MATRIX.md`](SEVERITY_MATRIX.md) lookup only |
| R3.3 | STOP-THE-WORLD 15-minute rule + deterministic Rules 1–3 |
| R3.4 | Control plane binding: incident MAY trigger deploy; deploy MUST NOT decide state |
| R3.5 | Rollback Safety Exception present |
| R3.6 | `INCIDENT_STATE_MACHINE.md` — DETECT→RESOLVE + ESCALATED_FAILURE; auto-transition from inference |
| R3.7 | `INCIDENT_INFERENCE_ENGINE.md` — symptom→state→action; Rules 1–5 |
| R3.8 | `INCIDENT_RUNTIME_LOOP.md` — 7-step closed loop |
| R3.9 | No prose "operator decides state" / "manual triage" / free severity — inferred or escalated only |
| R3.10 | FINAL_ACTION from policy; deploy.yml only when FINAL_ACTION maps to deploy |
| R3.11 | `INCIDENT_POLICY_ENGINE.md` — P0–P3 + SH-1–SH-3; POLICY > STATE > SIGNAL |
| R3.12 | Adaptive loop 4–5 steps when policy matches (SH-3) |

---

## Rule 2 — INDEX is structured catalog only

| Check | Pass |
|-------|------|
| R2.1 | INDEX service catalog uses schema: name, role, execution owner, incident linkage, SLO |
| R2.2 | INDEX does not describe deploy steps or runtime behavior |
| R2.3 | INDEX declares no decision authority + links OPERATIONAL_CONSTRAINTS |
| R2.4 | Every catalog link resolves: `git ls-files <path>` |

---

## Rule 4 — MemPalace isolated from production

| Check | Pass |
|-------|------|
| R4.1 | INDEX, README, MEMPALACE_OPERATIONS_HANDBOOK, MEMPALACE_ARCHITECTURE_HEALTH use **identical** MemPalace exclusion statement |
| R4.2 | No production runbook routes P0 to MemPalace |
| R4.3 | `mempalace-monthly.yml` = reminder only in INDEX |

---

## Rule 5 — Rollback path is unique

| Check | Pass |
|-------|------|
| R5.1 | Auto-rollback = inside `deploy.yml` |
| R5.2 | Manual rollback = revert PR → CI → `deploy.yml` OR re-run successful deploy |
| R5.3 | `RUNBOOK_ROLLBACK.md` matches R5.1–R5.2 |

---

## Rule 6 — CI matches deploy trigger

| Check | Pass |
|-------|------|
| R6.1 | `deploy.yml` triggers on `CI — PHPUnit Tests` success |
| R6.2 | INDEX catalog links to `ci.yml` separately from `deploy.yml` |

```bash
grep 'workflows:' .github/workflows/deploy.yml
```

---

## Printable checklist

```
[ ] R0  No uncommitted truth / no INDEX deploy logic
[ ] R1  deploy.yml sole deploy authority
[ ] R2  INDEX structured catalog (schema columns)
[ ] R3  Policy engine + inference + runtime loop + C11
[ ] R4  MemPalace identical exclusion statement (4 files)
[ ] R5  Single rollback path
[ ] R6  CI trigger matches deploy.yml
```

**Pass:** all checked. **Fail:** fix docs on `main`; do not add parallel authorities.

---

## Items NOT in git (ignore for ops — do not catalog as active)

If these appear in working tree only, **do not** reference from INDEX or incident docs:

- `deploy-production.yml`, `docs/adr/*`, execution-layer scripts/docs  
- Merge to `main` first, then add to service catalog

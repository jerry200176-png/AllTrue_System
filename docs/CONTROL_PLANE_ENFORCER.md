# Control Plane Enforcer

> **Machine enforcement spec** for [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md).  
> **Runner:** `node scripts/control-plane-lint.mjs` · **Canonical CI:** [`ci.yml` control_plane job](../.github/workflows/ci.yml)

---

## Output format

```
CONTROL PLANE LINT: PASS | FAIL
violations: N
  [I2] docs/INDEX.md:42 — INDEX contains deploy behavior prose
  ...
```

| Exit code | Meaning |
|-----------|---------|
| `0` | PASS — no violations |
| `1` | FAIL — contract violation(s) |

---

## Enforcement rules (maps to contract I1–I5)

| Rule | ID | Machine check |
|------|-----|-----------------|
| E1 | I1 | `deploy.yml` exists in git; no committed doc claims alternate production deploy authority |
| E2 | I2 | `INDEX.md` has no decision logic / deploy behavior prose / self-SSOT |
| E3 | I3 | INCIDENT stack files present; demoted modules not labeled decision authority in decision path |
| E4 | I4 | Contract + policy doc contain `POLICY > STATE > SIGNAL`; policy doc cites I4 |
| E5 | I5 | No forbidden intuition phrases in decision path; demoted docs have `REFERENCE ONLY` banner |
| E6 | — | `CONTRADICTION_REGISTRY.md` contains K1–K10 |
| E7 | — | MemPalace frozen statement in 4 canonical files |
| E8 | — | Contract modification in CI requires `CONTRACT_CHANGE=1` or PR title `[contract-change]` |
| E9 | I1 | No tracked workflow other than `deploy.yml` performs the ordinary production deploy (SSH + git reset origin/main) |
| E10 | I2 | INDEX must not contain governance logic phrases (`decision logic`, `must deploy`, …) |
| E11 | I3/I4 | INCIDENT stack binds FINAL_ACTION execution to `deploy.yml` only |

## Phase 1 gate mapping

The consolidation keeps one executable source for each invariant. The old
workflow names below are historical references, not additional required
contexts.

| Old executable path | Protected invariant | Canonical executable path | Equivalence evidence |
|---|---|---|---|
| `control-plane-enforce.yml` | Control-plane I1–I5 plus cost and lint self-tests | `ci.yml` → `control_plane` | Same `control-plane-lint`, cost guard, and self-test commands; CI runs on every PR/main push that the old workflow covered |
| `presubmit.yml` CHECK 6 | Golden path-to-section traceability | `ci.yml` → `golden_scenarios` | Same `.github/scripts/golden-ci-report.sh`, same `origin/main...HEAD` diff inputs, same required job context |
| Presubmit checks 0–5, 7–10 | Presubmit-specific branch, size, architecture, smoke, and isolation invariants | `presubmit.yml` → `gate` | Presubmit gate remains executable and unchanged except for removing the duplicate Golden invocation |

Production-capable workflow classification is maintained in
[`PRODUCTION_WORKFLOW_INVENTORY.json`](PRODUCTION_WORKFLOW_INVENTORY.json).
The CI inventory check requires every current marker-bearing workflow to be
classified and blocks new direct-write additions without a confirmed entry.

---

## Forbidden patterns (decision path)

Decision path = `CONTROL_PLANE_CONTRACT.md` + `INCIDENT_*.md`

| Pattern | Violation |
|---------|-----------|
| Demoted module as `decision authority` | E3 — bypass INCIDENT stack |
| `operator intuition` / `feeling-based` / `interpret freely` | E5 — I5 |
| `Operational SSOT` in INDEX | E2 — I2 |
| INDEX catalogs untracked workflow as active deploy | E1 — dual execution authority |
| Demoted file missing `REFERENCE ONLY` in header | E5 |

---

## Demoted modules (must not enter decision path as authority)

| File | Allowed in decision path |
|------|--------------------------|
| `SEVERITY_MATRIX.md` | Link as "lookup" / "reference only" |
| `RUNBOOK_ROLLBACK.md` | Link as "execution helper" |
| `SMOKE_TEST_RUNBOOK.md` | Link as "execution reference" |
| `OPERATIONAL_CONSTRAINTS.md` | Link as "checklist" — not override |

---

## Code import barrier (JS/TS/Python)

If application code imports decision logic from demoted doc paths → **FAIL** (grep scan).

Production SSH/deploy references outside `deploy.yml` + `RUNBOOK_ROLLBACK` helper links → **FAIL**.

---

## Self-test

```bash
node scripts/control-plane-lint.mjs --self-test
```

Injects a synthetic violation and asserts exit code `1`.

---

## Contract change gate

When `docs/CONTROL_PLANE_CONTRACT.md` changes in a PR:

```bash
CONTRACT_CHANGE=1 node scripts/control-plane-lint.mjs
# OR PR title contains [contract-change]
```

Without tag → CI **FAIL** (prevents silent contract drift).

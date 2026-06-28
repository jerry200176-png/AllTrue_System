> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# Execution Layer — Enforcement System

> **Core principle**: *Nothing reaches production without passing through a deterministic executor.*  
> **Companion**: Governance in [`engineering-system.md`](engineering-system.md); observability in [`release-check.sh`](../scripts/release-check.sh).

---

## 1. Architecture

```
┌─────────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│ Change request  │────▶│  release-exec.sh     │────▶│ Allowed action  │
│ (PR / local)    │     │  (single executor)   │     │ or rejection    │
└─────────────────┘     └──────────┬───────────┘     └─────────────────┘
                                   │
                    ┌──────────────┼──────────────┐
                    ▼              ▼              ▼
            release-check.sh  Policy engine   Audit log
            (drift)           (class + CI)    (.cursor/.local/
                                               release-exec-audit.jsonl)
                    ▲
                    │
         pre-commit-exec-guard.sh     ai-write-gate.sh
         (local commit gate)           (agent output gate)
```

| Component | Role |
|-----------|------|
| **`scripts/release-exec.sh`** | **Only** entry point for `validate`, `merge`, `deploy`, `rollback`, `status` |
| **`scripts/pre-commit-exec-guard.sh`** | Blocks main commits & critical paths without classification |
| **`scripts/ai-write-gate.sh`** | Blocks AI text that skips governance markers or claims deploy |
| **`.github/workflows/execution-gate.yml`** | Advisory PR comment (does not block merge) |
| **`scripts/lib/release-exec-lib.sh`** | Shared classification, audit, CI/drift helpers |

---

## 2. Rules (enforceable)

| Rule | Enforcement |
|------|-------------|
| No direct deploy | `release-exec.sh deploy` requires `ALLTRUE_DEPLOY_APPROVED=yes` |
| No direct merge to main | `release-exec.sh merge` requires `ALLTRUE_MERGE_APPROVED=yes`; hooks block main commits |
| No bypass of executor | Local `git merge` on main blocked unless override; PR merge via `gh` only through executor |
| All actions logged | Append-only JSONL at `.cursor/.local/release-exec-audit.jsonl` |
| AI untrusted | `ai-write-gate.sh` + agent policy; AI env vars cannot grant approval |
| Humans same gate | CEO sets approval env vars; no separate “admin skip” except documented overrides |

---

## 3. Commands

```bash
# Observability
./scripts/release-exec.sh status
./scripts/release-exec.sh validate --pr 937

# CEO-only (AI agents MUST NOT run with approval flags)
ALLTRUE_MERGE_APPROVED=yes ./scripts/release-exec.sh merge --pr 937
ALLTRUE_DEPLOY_APPROVED=yes ./scripts/release-exec.sh deploy --pr 937

# Dry run (validate + audit without side effects)
ALLTRUE_EXEC_DRY_RUN=1 ALLTRUE_MERGE_APPROVED=yes ./scripts/release-exec.sh merge --pr 937

# Agent output check
./scripts/ai-write-gate.sh --file .cursor/.local/agent-handoff.md
```

---

## 4. Approval environment variables (CEO / human only)

| Variable | Grants |
|----------|--------|
| `ALLTRUE_MERGE_APPROVED=yes` | `merge` |
| `ALLTRUE_DEPLOY_APPROVED=yes` | `deploy` |
| `ALLTRUE_RISKY_APPROVED=yes` | RISKY merge/deploy; CI waiver |
| `ALLTRUE_DRIFT_ACK=yes` | Proceed despite PROD_AHEAD / DIVERGED |
| `ALLTRUE_EXEC_APPROVED=yes` | Master override (incident) |
| `ALLTRUE_MAIN_OVERRIDE=yes` | Commit directly on `main` (emergency) |

**AI agents must never set these.**

---

## 5. JSON output contract

Every `release-exec.sh` invocation prints:

```json
{
  "action": "validate",
  "status": "allowed | blocked",
  "risk_level": "low | medium | high",
  "release_class": "SAFE | DEPLOY | RISKY",
  "reasons": [],
  "next_steps": []
}
```

Exit code: `0` = allowed, `1` = blocked, `2` = usage error.

---

## 6. Install local hooks

```bash
bash scripts/install-git-hooks.sh
```

This chains `pre-commit-exec-guard.sh` into the existing pre-commit hook.

---

## 7. Relationship to CI

- CI remains **observability** (see [`release-flow.md`](release-flow.md)).
- `release-exec.sh` treats **required check failures** as blocking for DEPLOY/RISKY unless `ALLTRUE_RISKY_APPROVED=yes`.
- `execution-gate.yml` adds advisory PR comments only.

---

## 8. Related documents

- [`engineering-system.md`](engineering-system.md)
- [`ai-agent-policy.md`](ai-agent-policy.md)
- [`production-truth-model.md`](production-truth-model.md)
- [`release-flow.md`](release-flow.md)

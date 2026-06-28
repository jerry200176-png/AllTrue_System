# Operational Constraints (Hard Invariants)

> **REFERENCE ONLY — NO DECISION OR EXECUTION AUTHORITY.**  
> Mirrors [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) I1–I5. On conflict → contract wins.

---

## Invariants (non-negotiable)

| ID | Invariant |
|----|-----------|
| **C1** | **Only committed `origin/main` defines system truth.** Working tree, unmerged PRs, and untracked files are not valid for execution decisions. |
| **C2** | **No untracked docs are valid for execution.** If a file is not in `git ls-files`, it cannot be cataloged as active or cited in incident flow. |
| **C3** | **No dual authority.** Decision = INCIDENT stack (contract I3). Execution = `deploy.yml` (contract I1). |
| **C4** | **No ambiguous incident state.** Every incident maps to one state via inference + state machine. |
| **C5** | **No undocumented execution path.** `deploy.yml` runs only when FINAL_ACTION maps to deploy. |
| **C6** | **INDEX is registry only.** |
| **C7** | **ADR / unmerged docs = historical only** until on `main`. |
| **C8** | **Runbooks = execution reference only.** |
| **C9** | **MemPalace frozen statement** — contract; no incident authority, no SLO, no execution impact. |
| **C10** | **State inferred or escalated** (ESCALATED_FAILURE only) — never arbitrary. |
| **C11** | **Policy modifies FINAL_ACTION only** — not execution layer (contract I4). |

**Authoritative invariants:** [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) I1–I5 only.

---

## Enforcement (pointers only)

| Check | Where |
|-------|-------|
| Manual drift audit | [`OPERATIONAL_CONSISTENCY_CHECK.md`](OPERATIONAL_CONSISTENCY_CHECK.md) |
| Service registry | [`INDEX.md`](INDEX.md) § Structured service catalog |
| Incident flow | [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) → policy → inference |

---

## Stop condition

If an operator cannot name (1) current inferred STATE, (2) signal IDs matched, (3) execution owner file → **stop**. Run [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) from step 1.

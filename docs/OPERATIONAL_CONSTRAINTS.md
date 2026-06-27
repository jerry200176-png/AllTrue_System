# Operational Constraints (Hard Invariants)

> **Contract layer for the Operational Control Plane.** Violation = process failure; stop and fix before acting.

---

## Invariants (non-negotiable)

| ID | Invariant |
|----|-----------|
| **C1** | **Only committed `origin/main` defines system truth.** Working tree, unmerged PRs, and untracked files are not valid for execution decisions. |
| **C2** | **No untracked docs are valid for execution.** If a file is not in `git ls-files`, it cannot be cataloged as active or cited in incident flow. |
| **C3** | **No dual authority.** Exactly one decision authority (`INCIDENT_START_HERE` + state machine) and one execution authority (`deploy.yml`). |
| **C4** | **No ambiguous incident state.** Every incident maps to one state in [`INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md) via [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md). |
| **C5** | **No undocumented execution path.** `deploy.yml` runs only when FINAL_ACTION (policy-resolved or inference fallback) maps to deploy. |
| **C10** | **State is inferred or escalated** — never arbitrarily chosen. |
| **C11** | **Policy modifies execution only.** POLICY > STATE > SIGNAL; policy MUST NOT assign STATE (except SH-2). |
| **C6** | **INDEX is registry only.** INDEX cannot override incident system or describe runtime behavior. |
| **C7** | **ADR / unmerged design docs are historical or draft only** until merged to `main` and added to service catalog. |
| **C8** | **Runbooks are execution reference only** — they do not decide incident state. |
| **C9** | **MemPalace is isolated** — excluded from SLO, alerting, incident detection, and production inference. |

---

## Authority contract (system-wide)

1. **INDEX** = registry only (no authority)
2. **INCIDENT system** = decision authority (+ state machine controller)
3. **`deploy.yml`** = execution authority
4. **INCIDENT system overrides INDEX**
5. **Deploy system executes only policy-resolved FINAL_ACTION**

Full binding: [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) · [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)

---

## Enforcement

| Check | Where |
|-------|-------|
| Manual drift audit | [`OPERATIONAL_CONSISTENCY_CHECK.md`](OPERATIONAL_CONSISTENCY_CHECK.md) |
| Service registry | [`INDEX.md`](INDEX.md) § Structured service catalog |
| Incident flow | [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) → policy → inference |

---

## Stop condition

If an operator cannot name (1) current inferred STATE, (2) signal IDs matched, (3) execution owner file → **stop**. Run [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) from step 1.

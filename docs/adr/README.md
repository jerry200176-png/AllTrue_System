---
owner: Principal Architect
status: normative
---

# Architecture Decision Records (L2)

Every architecturally-significant decision is recorded here. ADRs are **immutable once Accepted** — to change a decision, write a new ADR that `Supersedes` the old one (never edit the old text). Each ADR must cross-reference the **[Constitution](../CONSTITUTION.md)** (the decision must derive from it) and related ADRs. The `arch-fitness-check.mjs` FIT-4 verifies each ADR has a Status and a Constitution reference.

## Status legend
`Proposed` → `Accepted` → (`Superseded by NNNN` | `Deprecated`).

## Index
| ADR | Title | Status | Owns facts | Supersedes |
|---|---|---|---|---|
| [0001](0001-occurrence-single-sor.md) | Occurrence is the single SoR for class timing | Accepted | F-05, F-06 | — |
| [0002](0002-billing-ledger-sor.md) | Billing ledger is SoR for money & units | Accepted | F-07, F-08 | — |
| [0003](0003-course-core-consistency-boundary.md) | Course Core is one consistency boundary | Accepted | — | — |
| [0004](0004-cross-context-events.md) | Cross-context effects are domain events | Accepted | — | — |
| [0005](0005-single-session-writer.md) | All occurrence creation via one SessionWriter | Accepted | F-05 | — |
| [0006](0006-single-party-identity.md) | One identity model (Party) | Accepted | F-01 | — |
| [0007](0007-read-models-off-write-path.md) | Read models live off the write path | Accepted | F-10 | — |
| [0008](0008-shared-decision-contract.md) | Shared FE/BE decision-contract package | Accepted | — | — |
| [0009](0009-emergency-reconcile.md) | Emergency changes must reconcile to SoT | Accepted | F-16 | — |
| [0010](0010-decompose-studentclass.md) | Decompose the StudentClass god-row | Accepted | F-04 | — |

## Template
```markdown
---
status: Proposed|Accepted|Superseded by NNNN|Deprecated
date: YYYY-MM-DD
owner: <context owner>
---
# ADR-NNNN: <title>
## Constitution link
Derives from CONSTITUTION.md Article <n> / Handbook <principle>.
## Context
<forces, the problem, evidence>
## Decision
<the choice>
## Consequences
<+/-, what becomes impossible, what debt is created/retired>
## References
<related ADRs, facts (F-nn), invariants (DI-n)>
```

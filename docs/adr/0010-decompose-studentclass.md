---
status: Accepted
date: 2026-06-27
owner: Enrollment context
---
# ADR-0010: Decompose the StudentClass god-row

## Constitution link
Derives from CONSTITUTION.md Article V (no column encodes two contexts' facts); Handbook SO-3.

## Context
A single `StudentClass` row encodes **four contexts' facts**: contract terms (Enrollment, F-04), schedule template `week*/time*` (Scheduling, F-05/F-06), billing `Paid/Charge/Rate` (Billing, F-07/F-08), and session counters `Remaining*` (Billing). `StudentClassController` is 5156 LOC. This is the structural cause behind ADR-0001/0002 contests.

## Decision
Decompose: contract terms stay in **Enrollment** (`Contract`); schedule template moves to **Scheduling** (recurrence rule feeding ADR-0001); billing/counters become **Billing** projections (ADR-0002). The row is split along ownership.

## Consequences
+ Each fact gets one owner; enables ADR-0001/0002; shrinks the god-controller.
− Largest single migration; sequence after ADR-0002 ledger flip (Handbook §9 Phases 1–2).

## References
ADR-0001, ADR-0002. Facts F-04..F-08. Debt TD-SC.

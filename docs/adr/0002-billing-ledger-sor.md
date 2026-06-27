---
status: Accepted
date: 2026-06-27
owner: Billing context
---
# ADR-0002: Billing ledger is the source of truth for money and session-units

## Constitution link
Derives from CONSTITUTION.md Article V + Article VI.2 (no silent money mutation); Handbook P5, invariants DI-2/DI-3.

## Context
"Is it paid / how much" and "units remaining" each have two writers: `StudentClass.{Paid,Charge,Rate,rate_unit,Remaining*}` (denormalized on the course) **and** `Invoice/Payment/payment_reports` + `*_ledger`. Resolved today by an OR rule (G-009) and a `preservedDelta` write-back that creates states unrecoverable from the UI (in-app #172/#159/#798/#799).

## Decision
`Invoice/Payment` (money) and an append-only `SessionLedger` (units) are authoritative. `StudentClass.{Paid,Charge,Remaining*}` become **read-only projections** rebuilt from them. `preservedDelta` is removed. Billing-mode change triggers explicit reconciliation (an event), never a silent overwrite.

## Consequences
+ Money/units have one writer; double-deduction and dual-truth become impossible; amounts are reproducible.
− Requires projection rebuild + historical reconciliation before flipping; P0 billing care (`DIRECTOR_PAYMENT_ALERT_RULES`).

## References
ADR-0004 (`ContractModeChanged`), ADR-0010. Facts F-07/F-08. Invariants DI-2/DI-3. Debt TD-BILL.

## Evidence
- #613 / #1337: 1:1 makeup deducts actual minutes via `session_deduction_ledger.minutes`.
- `PackageDeductionService` still mirrors pool as **±1 whole session** (no minutes column) — `docs/TECH_DEBT.md` TD-059, §R59.
- Closeout: `docs/incidents/leave-cascade-slot-times-closeout-2026-07-19.md`

## User impact
Shared package members taking longer/shorter makeup may see pool remaining sessions diverge from personal minute balances — wrong entitlement display or over/under consumption.

## Root-cause confidence
Code-path confirmed; **production drift volume unknown** (investigation first).

## Scope (investigation before schema)
1. Active shared package usage (read-only).
2. Longer/shorter makeup on package members.
3. Compare personal minutes ledger vs pool ±1 — drift yes/no + magnitude.
4. Paths: attendance, reverse, cancel, refund, manual adjust, multi-student share.
5. ROI + integer-minutes model + migration/dual-read/backfill/rollback design.
6. Implement **only** after impact proven + ARCH/Founder go.

## Non-goals
- Production schema change before impact evidence.
- Changing 1:1 makeup minute deduction (already shipped).

## Acceptance criteria
- [ ] Read-only prod audit numbers published on this Issue
- [ ] Design note (minutes model, migration, rollback)
- [ ] Explicit go/no-go before any migration PR

## Verification plan
SSH/Actions read-only queries; no writes. If drift=0 and usage≈0 → close as deferred with evidence.

## Priority rationale
P1 if drift exists on active packages; else P3 defer. Founder: no schema until impact proven.

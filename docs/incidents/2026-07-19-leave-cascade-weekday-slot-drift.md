# Incident draft — leave cascade weekday slot drift + longer makeup entitlement

| Field | Value |
|---|---|
| Status | Draft — code fix in PR; historical data repair dry-run ready |
| Date | 2026-07-19 |
| Severity | P1 data correctness (slot times) / P2 entitlement (longer makeup) |
| Modules | Leave cascade, Session deduction |

## Summary

Two independent defects reported by a teacher:

1. **Slot drift (Problem B):** After leave on weekday A, weekday B sessions showed A's clock.
2. **Longer makeup (Problem A):** Makeup longer than contract still deducted one whole session.

## Root cause

| Item | Classification | Evidence |
|---|---|---|
| B: date-only cascade keeps row clocks | **Fact** | `CourseLeaveCascadeService::shiftAndAppendAfterLeave` set `SessionDate` only |
| A: `mins >= perSession → null` (whole session) | **Fact** | `SessionDeductionService::resolvePartialMakeupMinutes` |
| Same root cause for A+B | **Rejected** | Independent code paths |

## Fix

- Remap Start/End to contract slot for **target** weekday on shift/undo/append (§R77).
- Makeup `type=extra` with duration ≠ perSession records actual minutes (§R59).
- Repair command (default dry-run): `php artisan repair:leave-cascade-slot-times`.

## Historical data

- **Slot times:** run dry-run on Pi after deploy; apply only with Founder approval + `ALLOW_PROD_REPAIR=1`.
- **Past longer makeups already deducted as whole session:** not auto-rewritten (ledger rewrite needs case-by-case Founder gate).

## Rollback

- Revert PR commit; repair command is idempotent and snapshots before write.

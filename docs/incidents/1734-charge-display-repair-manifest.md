# Repair Manifest — GitHub #1734 / in-app #230

**Class:** R3 display-only financial field  
**Target:** `StudentClass.ID=1052` `Charge` 24750 → 13200  
**Must not change:** Invoice 539, Payment 514, receipts, ledger

## Evidence (read-only, 2026-08-15 run 31871154331)

- Rate 1650 × 8 sessions = 13200
- `StudentClass.Charge=24750` (stale preservedDelta, MDate 2026-04-28, before PR #801)
- Invoice 539 `TotalAmount=13200` `PaidAmount=13200` `Status=paid`
- Payment 514 `Amount=13200` cash 2026-05-13

## Execute path

Committed workflow `1734-charge-display-repair.yml` only.

1. `mode=dry-run` — no mutation
2. mysqldump StudentClass/Invoice/Payment
3. `mode=execute` + `I_APPROVE_1734_CHARGE_DISPLAY` + `ALLOW_PROD_REPAIR=1`
4. Post-verify Charge=13200 and invoice/payment still 13200
5. Rollback: same workflow `mode=rollback`

## Recovery

Restore Charge to 24750 via rollback, or restore the gzip dump.

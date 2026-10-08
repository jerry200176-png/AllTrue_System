# Repair Manifest — 帳單 1997 三個過時數值 2026-10-08

Status: POP operation `invoice1997-stale-values-20261008`; dry-run only until Founder approves the printed digest.
Risk: R3/T3. Founder decision 2026-10-08 (GitHub #3797), source in-app #369 / GitHub #3356.

Invoice 1997 (StudentClass 4099, 2026-09) already totals 7500 and is paid by Payment 2068 (7500): five attended lessons x 1500.
Only these three stale values are written: InvoiceItem 101 Amount 6000 to 7500; Invoice 1997 billing_snapshot
(charge 6000, 4 lessons) to (charge 7500, 5 lessons, adds ClassSession 42099); StudentClass 4099 Charge 6000 to 7500.

G-009: `Charge - Rate x count` is -1500 before (a fake adjustment) and 0 after; verify fails with `preserved_delta_nonzero` otherwise.

Guards (plan, and again under lock; any difference aborts): invoice/payments/course rate, count, charge, Paid; the five attended
session IDs; snapshot lists the first four. Dry-run: `pop-invoice1997-stale-values.yml` `mode=dry-run`,
`confirm=DRY_RUN_INVOICE1997_STALE_VALUES_20261008`, deployed backend SHA; expect `ok=true state=before`, digest and from/to pairs.
Approve (`APPROVE_INVOICE1997_STALE_VALUES_20261008`) is outside this PR. Inverse: the stored snapshot, only while the rows are in the
repaired state and payments still equal the total.

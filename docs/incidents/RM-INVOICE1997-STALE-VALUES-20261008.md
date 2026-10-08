# Repair Manifest — 帳單 1997 三個過時數值 2026-10-08

Status: POP operation `invoice1997-stale-values-20261008`; dry-run only until Founder approves the printed digest.
Risk: R3/T3. Founder decision 2026-10-08 (GitHub #3797), source in-app #369 / GitHub #3356.

## Purpose

Invoice 1997 (StudentClass 4099, period 2026-09) already totals 7500 and is paid by one confirmed Payment 2068 (7500): five attended lessons x 1500.
Three side values still say 4 lessons / 6000. The op sets exactly these:

| Table | Row | Field | From | To |
|---|---|---|---|---|
| InvoiceItem | 101 | Amount | 6000 | 7500 |
| Invoice | 1997 | billing_snapshot | charge 6000, 4 sessions | charge 7500, 5 sessions (adds ClassSession 42099) |
| StudentClass | 4099 | Charge | 6000 | 7500 |

Never written: Invoice total/paid/status, Payment rows, StudentClass.Paid, any session.

## G-009 (preservedDelta)

StudentClass update() preserves `Charge - Rate x count` as a manual adjustment. Before: 6000 - 1500x5 = -1500 (a fake adjustment).
After: 7500 - 1500x5 = 0. Verify fails with `preserved_delta_nonzero` otherwise.

## Guards (plan, and again under lock; any difference aborts with an error code)

Invoice 1997 belongs to 4099, total 7500, paid 7500, status paid; payments exactly [2068] summing 7500; item 101 amount 6000;
course Rate 1500, SessionCount 5, Charge 6000, Paid 1; attended sessions in 2026-09 exactly [33562, 31548, 33563, 32430, 42099];
snapshot charge 6000 / 4 sessions listing the first four.

## Dry-run

Dispatch `.github/workflows/pop-invoice1997-stale-values.yml` `mode=dry-run`, `confirm=DRY_RUN_INVOICE1997_STALE_VALUES_20261008`, deployed backend SHA.
Expect `ok=true state=before`, the digest, and a manifest of from/to pairs. `state=after` means already applied.

## Approve / execute / verify

`mode=approve` with `APPROVE_INVOICE1997_STALE_VALUES_20261008` (not part of this PR's scope). One transaction, strict audit event `pop.invoice1997_stale_values`.
Verify: three values updated, delta 0, total / paid / payments unchanged.

## Rollback

Stored snapshot restores the three old values if the rows are still in the repaired state and payments still equal the total.

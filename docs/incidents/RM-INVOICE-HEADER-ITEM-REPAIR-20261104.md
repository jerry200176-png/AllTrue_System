# Repair Manifest — 帳單標頭與明細金額 2026-11-04

Status: POP operation `invoice-header-item-repair-20261104`; dry-run only until Founder approves the printed digest.
Risk: R3/T3. Source GitHub #4850 / #4863; evidence: read-only case dumps run 41714126199 and the latest billing_student_numbers run (student 30).

Closed allowlist by invoice id (code: `InvoiceHeaderItemRepairManifest`):

| invoice | item | course | before (total / item amount / item course) | after |
|---|---|---|---|---|
| 1015 | 46 | 2520 | 0 / 0 / NULL (net paid 8000, paid) | 8000 / 8000 / 2520 |
| 1016 | 47 | 2521 | 0 / 0 / NULL (net paid 8000, paid) | 8000 / 8000 / 2521 |
| 1601 | 76 | 3379 | 4400 / 4400 / NULL (net paid 0, unpaid) | 5200 / 5200 / 3379 |

Only Invoice.TotalAmount, InvoiceItem.Amount and InvoiceItem.StudentClassID are written. PaidAmount, Status, Payment rows and StudentClass are never written.
Guards (plan, and again under row locks in apply): the item belongs to the invoice and is its only item; the invoice course is NULL or the manifest course; the course exists;
`Invoice::netPaidSql()` and status equal the manifest. Live equal to before is state `before`, equal to after is `after` (no-op); anything else fails naming the invoice and field.
Dry-run: `pop-invoice-header-item-repair.yml` `mode=dry-run`, `confirm=DRY_RUN_INVOICE_HEADER_ITEM_REPAIR_20261104`, deployed backend SHA. Approve is outside this PR.
Inverse: the stored snapshot restores the before values, only for rows still in the after state.

# Controlled maintenance operation: reject duplicate PaymentReport #1489

This is an audited one-time correction authorized by Founder decision for in-app Bug **#249**.

## Authorization and evidence

- Authorized purpose: mark `PaymentReport #1489` as `rejected` with an audited rejection note. Record must NOT be deleted.
- Student: 樓兆瑄 (StudentID: 2219, CampusID: 17).
- Target record: `PaymentReport #1489` (StudentClassID: 3328 英文, reported_amount: NT$36,000, reported payment_date: 2026-08-20, created_at: 2026-08-22 12:23:19).
- Canonical confirmed settlements:
  - `PaymentReport #1490` / `Invoice #1559` / `Payment #1563`: NT$12,000 cash confirmed for Math #2655 on 2026-08-22.
  - `PaymentReport #1605` / `Invoice #1628` / `Payment #1615`: NT$24,000 transfer confirmed for Math #3327 on 2026-08-30 (settling `CoursePackage #170`).
- Pre-mutation verification confirmed that `#1489` is a duplicate/superseded aggregate report of the exact same commercial obligation.

## Rejection note specification

```
重複/已被取代之 NT$36,000 總額回報：學生應繳費用已由正式入帳款項全額結清，包含 2026-08-20 現金 12,000 元（回報單 #1490 / 發票 #1559）及 2026-08-30 轉帳 24,000 元（回報單 #1605 / 發票 #1628）。依 Founder 決策作廢/退回此筆重複回報，保留稽核軌跡。
```

## Fail-closed contract

The workflow checks all 16 preconditions atomically under a row lock before writing:
- `#1489` exists, belongs to Student 2219 and Class 3328, amount 36,000, status pending, zero invoice/payment links.
- Canonical reports #1490 and #1605 exist, confirmed, invoices paid, payments exact.
- Package #170 remains paid.
- Any mismatch aborts with `no_write_performed: true`.
- If already rejected, reports `skip_already_rejected` idempotently.
- Post-mutation verifies `#1489` status is `rejected`, no financial records deleted or modified, and Class 3328 pending warning is cleared.

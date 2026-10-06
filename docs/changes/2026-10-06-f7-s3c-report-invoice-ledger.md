## 2026-10-06 — refactor(billing): F7 S3c payment-report list, invoice detail and ledger use the one invoice kernel
<!-- release-notes: silent_ship=silent-2026-10-06-f7-s3c -->
<!-- silent-reason: 欄位與數字口徑不變，只移除重複計算 -->
- The invoice kernel now also returns the capped applied, overpaid, outstanding and voided amounts, and the payment-report list, invoice detail and accounting ledger read them instead of recomputing.

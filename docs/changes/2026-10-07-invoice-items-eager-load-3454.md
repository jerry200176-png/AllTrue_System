## 2026-10-07 — perf(billing): eager-load invoice items for amount reconciliation (#3454)
<!-- release-notes: silent_ship=silent-2026-10-07-invoice-items-eager-load -->
<!-- silent-reason: 只減少資料庫查詢次數，金額與畫面完全不變 -->
- 帳務中心（已結清／帳務明細）、續費提醒、收款回報列表、課程帳單列表與 `BillingPayableResolver::byStudentClassIds` 的帳單迴圈預先載入 `items`，`InvoiceAmountReconciliationService::resolve()` 不再每張帳單多查一次明細。

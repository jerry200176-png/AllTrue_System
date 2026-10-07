## 2026-10-07 — fix(billing): 回報確認建立帳單時加上同課程同月份重複防護
<!-- release-notes: silent_ship=silent-2026-10-07-payment-report-period-guard -->
<!-- silent-reason: 內部防護；正常流程本就會掛到既有帳單，畫面與功能不變。 -->
- `PaymentReportController` 確認回報時經 `InvoiceIssuer` 開帳單改啟用 `guardPeriod`；若同課程同月份已有未作廢帳單則回 409 `billing_period_invoice_exists`，不再建立第二張。

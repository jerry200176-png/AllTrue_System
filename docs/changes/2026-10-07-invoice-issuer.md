## 2026-10-07 — refactor(billing): 新建帳單統一走 InvoiceIssuer
<!-- release-notes: silent_ship=silent-2026-10-07-invoice-issuer -->
<!-- silent-reason: 內部重構，新建帳單的欄位與重複月份檢查行為完全不變，畫面與功能不變。 -->
- 4 處手工組裝「未繳帳單 + 明細」（`BillingController::store`、`PaymentReportController`、`StudentClassController::renewMonthly`、`MonthlyAccountingCorrectionService`）改由 `App\Services\Billing\InvoiceIssuer` 建立；同月份重複帳單檢查為選用，仍只有 `BillingController::store` 啟用。`BackfillLegacyPayments`（一次性、建立已繳帳單）不動。新增 `InvoiceIssuerTest` 固定欄位預設與 409 守門。

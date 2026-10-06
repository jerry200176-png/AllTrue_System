## 2026-10-06 — fix(finance): AR aging reads invoices + payments, keeps closed contracts' debt (plan C)
<!-- release-notes: silent_ship=silent-2026-10-06-ar-aging-on-invoices -->
<!-- silent-reason: finance/ar-aging 目前沒有畫面使用；只修正後端數字來源 -->
- `FinanceController::arAging` 改用 `BillingPayableResolver::courseStatusesByStudentClassIds()`：應收以帳單＋收款為準（不再用 `Charge − Pay`），已結案／暫停合約的欠款也列入，確認不收與輔導課排除，無帳單的合約不算應收；帳齡依最舊未繳帳單 IssueDate，並回傳 `oldest_unpaid_date`。

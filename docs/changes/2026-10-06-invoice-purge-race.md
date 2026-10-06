## 2026-10-06 — fix(billing): invoice creation and student deletion lock the student first (#3593)
<!-- release-notes: silent_ship=silent-2026-10-06-invoice-purge-race -->
<!-- silent-reason: 只補同時操作的防護，畫面行為不變 -->
- `BillingController::store` 交易內先鎖學生列（學生已被刪除回 404），學生刪除（單筆／批量）也先鎖學生列；統一鎖順序 學生→合約→帳單，不再出現指向已刪學生的帳單。

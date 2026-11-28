## 2026-11-27 — fix(billing): 堂次移轉的兩支修復路徑改成依 ID 由小到大鎖課程 (#4263)
<!-- release-notes: silent_ship=silent-2026-11-27-transfer-lock-order -->
<!-- silent-reason: 只改資料庫鎖的取得順序，使用者看不到差別；避免極少見的互卡（deadlock）。 -->
- `SessionEntitlementTransferService::transfer()`／`rollback()` 與 `SessionContractRecoveryService::recoverAndTransfer()` 原本先鎖來源課程再鎖目標課程；收款（`recordPayment`）、一般堂次移轉、月結更正都是依 ID 由小到大鎖。來源 ID 比目標大時，兩邊順序相反，可能互卡。
- 現在三處都先用一句 `whereIn(...)->orderBy('ID')->lockForUpdate()` 把兩門課一起鎖好，之後的邏輯不變。
- #4263 列的其他兩處（`transferSessions`、`MonthlyContractCorrectionService::execute`）在 main 上已經是由小到大，這次沒動。

## 2026-10-11 — fix(attendance): 點名頁操作被伺服器拒絕時說明原因，不再顯示「網路錯誤／未知錯誤」 (in-app #375 同族)
<!-- release-notes: silent_ship=silent-2026-10-11-attendance-http-failure-copy -->
<!-- silent-reason: 只改失敗時的提示文字，成功流程與資料不變，不另發卡。 -->
- 點名頁的手動登記、刪除、轉換到班、請假、修改狀態被拒絕時，改顯示伺服器給的原因；沒有原因時說明是 HTTP 幾號，不再一律顯示「網路錯誤」或「未知錯誤」。

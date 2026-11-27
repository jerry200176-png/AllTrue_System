## 2026-11-27 — fix(billing): 課程列表的最近繳費日不再顯示已全數作廢的收款 (#4150)
<!-- release-notes: staff_update=staff-2026-11-27-last-paid-reversed -->
- `GET /student-classes` 的 `last_paid_at`：合約只要有任何 Payment 紀錄，就不再退回舊欄位 `PayDate`。收款全數沖銷後回傳 null；完全沒有 Payment 紀錄的舊資料照舊顯示 `PayDate`。和繳費提醒列表（`AlertController`，#4123）同一條規則。
- `paid_at`（舊欄位原值）不變。
- #4150 另外兩點已在 main：`FinanceController` 已無此退回；舊資料補登收款的沖銷比對已有測試（`ContractMoneyStateTest`）。

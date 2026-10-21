## 2026-10-21 — fix(billing): 新建試聽課填了繳費日期不再顯示「需核對」（#4330／in-app #428）
<!-- release-notes: silent_ship=silent-2026-10-21-428-trial-legacy-paid-flag -->
<!-- silent-reason: 回歸修正，試聽課卡片回到 #4174 之前的「已收」顯示；不新增功能、不改操作流程 -->
- #4174 的 `legacy_paid_flag_only`（需核對：舊資料標記已收，缺付款紀錄）只針對舊資料；試聽課依設計以繳費日期記錄、不開帳單，標記後卡片沒有任何可做的動作。
- `GET /api/v1/student-classes` 與 `GET /api/v1/alerts/tuition` 對有繳費日期的試聽（課程 `PayDate`，或試聽方案 `paid_at`）不再回傳 `legacy_paid_flag_only = true`；沒有日期的試聽仍標記，正式課程規則不變。
- 帳務面板（`GET /api/v1/accounting/ledger`）課程列新增伺服器判定的 `trial_fee_recorded_by_date`（只看課程本身 `Paid=1` 的試聽）；面板改顯示「繳費日＋試聽費」，不再顯示「需核對」或「還沒繳費」。
- 試聽方案只認同一位學生、同一分校的方案；`PackageID` 指到別人或別分校的方案仍標「需核對」。
- 以 bind-courses 綁進試聽方案的正式課程，課程管理改依方案的試聽類型與 `paid_at` 判斷，與學費提醒一致。
- 只改顯示：不寫帳務、不改 `Paid`、不需 migration。

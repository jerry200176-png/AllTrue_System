## 2026-10-21 — fix(billing): 新建試聽課填了繳費日期不再顯示「需核對」（#4330／in-app #428）
<!-- release-notes: silent_ship=silent-2026-10-21-428-trial-legacy-paid-flag -->
<!-- silent-reason: 回歸修正，試聽課卡片回到 #4174 之前的「已收」顯示；不新增功能、不改操作流程 -->
- #4174 的 `legacy_paid_flag_only`（需核對：舊資料標記已收，缺付款紀錄）只針對舊資料；試聽課依設計以繳費日期記錄、不開帳單，標記後卡片沒有任何可做的動作。
- `GET /api/v1/student-classes` 與 `GET /api/v1/alerts/tuition` 對 `ClassType = trial` 不再回傳 `legacy_paid_flag_only = true`；正式課程規則不變。
- 只改顯示：不寫帳務、不改 `Paid`、不需 migration。

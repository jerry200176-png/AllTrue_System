## 2026-10-15 — fix(billing): 只有舊「已收」標記、沒有付款紀錄的合約改顯示「需核對」（#4114／in-app #407）
<!-- release-notes: staff_update=staff-2026-10-15-4114-legacy-paid-flag-needs-review -->
- Founder Q3：`StudentClass.Paid=1`（或方案 `paid`）但合約與同方案課程的非作廢帳單上沒有任何有效收款的合約，不再顯示綠色「已收」，改顯示「需核對：舊資料標記已收，缺付款紀錄」（琥珀色）。
- 只改顯示：`GET /api/v1/alerts/tuition` 與 `GET /api/v1/student-classes` 新增唯讀欄位 `legacy_paid_flag_only`；`payment_status`、金額、提醒與通知都不變，不算欠款、不催繳，也不改 `StudentClass.Paid`。
- 帳務中心學生清單、收款佇列狀態欄、課程管理與學生課程卡都讀同一欄位。

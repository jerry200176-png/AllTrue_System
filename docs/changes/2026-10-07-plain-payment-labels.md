## 2026-10-07 — feat(billing): plain-language payment status labels (PRD v2 §0.2)
<!-- release-notes: staff_update=staff-2026-10-07-plain-payment-labels -->
- 繳費狀態顯示文字改白話，由 `courseMoneyState.js` 統一提供：未繳／繳了一部分／家長說繳了，等你確認／已收／不收了／課已結束，等你確認收款；帳單與回報狀態同步（已收、等你確認）。
- 帳務中心（分頁、卡片、提示訊息、「不收」按鈕）、課程查找與學生列表的繳費狀態改讀共用文字，不再各自寫死舊說法。家長端不變。
- 狀態值、金額、判斷邏輯與 API 都不變。

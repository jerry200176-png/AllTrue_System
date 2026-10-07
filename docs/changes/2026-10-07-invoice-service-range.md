## 2026-10-07 — fix(billing): 學生帳務帳單顯示實際服務期間（in-app #378）
<!-- release-notes: silent_ship=silent-2026-10-07-invoice-service-range -->
<!-- silent-reason: 只把帳單列的期間從「2026/09」改成實際起迄日，避免 9/28–10/27 這期被看成 9 月；功能不變。 -->
- ledger 帳單列多回 `period_start`／`period_end`（取自帳單項目），學生帳務面板有起迄日就顯示「2026/09/28–2026/10/27」，沒有才退回月份。PRD v2 D11。

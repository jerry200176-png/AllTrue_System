## 2026-10-26 — fix(triage): 支援回報者歷史的唯讀篩選
<!-- release-notes: silent_ship=silent-2026-10-26-triage-reporter-history-filter -->
<!-- silent-reason: 分診工具補齊既有回報查詢篩選，教職員畫面與操作不變。 -->
- 既有 AI 分診回報列表新增可選的 `reporter_user_id` 篩選，保留游標分頁、狀態篩選及附件／留言數量，讓 Agent 能查同一回報者跨分校、包含已結案的歷史，不必掃全站或改用其他憑證。
- 不變更 token scope、權限、寫入路徑或帳務；新增跨分校歷史分頁、其他回報者隔離與不合法參數回歸。

## 2026-10-06 — fix(class-sessions): reject invalid date filters before projection (#3493)
<!-- release-notes: silent_ship=silent-2026-10-06-class-sessions-date-validation -->
<!-- silent-reason: 僅修正 API 無效日期由伺服器錯誤改為驗證錯誤，介面、有效查詢與操作流程未變，不另公告 -->
- `/api/v1/class-sessions` 的 `start`／`end` 若不是有效的 YYYY-MM-DD 日期，現在回傳 422 驗證錯誤；有效日期的閉區間查詢不變。

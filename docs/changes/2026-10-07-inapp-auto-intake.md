## 2026-10-07 — feat(inapp): hourly auto-intake for new reports (F17)
<!-- release-notes: silent_ship=silent-2026-10-07-inapp-auto-intake -->
<!-- silent-reason: 後台自動收件流程；回報者只會多收到一則「收到，我們正在查」確認回覆，畫面不變。 -->
- 新增 `bug-auto-intake.yml`（每小時）與 `bugs:auto-intake` 指令：每筆 `new` 回報自動開 IDs-only GitHub issue、回覆「收到，我們正在查，查到原因會再回覆你」並轉 `triaged`；重跑不會重複開單或重複留言。

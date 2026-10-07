## 2026-10-07 — feat(inapp): hourly auto-intake for new reports (F17)
<!-- release-notes: staff_update=staff-2026-10-07-inapp-auto-ack -->
- 新增 `bug-auto-intake.yml`（每小時）與 `bugs:auto-intake` 指令：每筆 `new` 回報自動開 IDs-only GitHub issue、回覆「收到，我們正在查，查到原因會再回覆你」；狀態維持 `new`（一般確認不算分診，SLA 照算），由 Phase-A 正式分類轉 `triaged`。重跑不會重複開單或重複留言。

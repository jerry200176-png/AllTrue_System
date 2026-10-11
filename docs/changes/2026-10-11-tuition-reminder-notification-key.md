## 2026-10-11 — fix: 催繳提醒的主任通知改為每校區每日一筆
<!-- release-notes: silent_ship=silent-2026-10-11-tuition-reminder-notification-key -->
<!-- silent-reason: tuition:send-reminders 目前未排程（Kernel 已註解），此修正只讓日後啟用時不會寫入失敗。 -->
- 通知表沒有 UserID／CreatedAt 欄位且 SourceKey 必填唯一，原本逐位主任 `Notification::create` 會直接丟錯。改成每校區每日一筆（`tuition-reminder:<校區>:<日期>`），重跑不重複。Closes #3376

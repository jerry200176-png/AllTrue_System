## 2026-10-12 — fix: 催繳提醒改走既有逐課通知流，不再另建彙總通知
<!-- release-notes: silent_ship=silent-2026-10-12-tuition-reminder-sync-readstate -->
<!-- silent-reason: tuition:send-reminders 目前未排程，此修正只讓日後啟用時通知生命週期正確。 -->
- 催繳指令不再寫入獨立的每日彙總通知（避免被同步清掉、與逐課通知重複、已讀狀態卡住），改為觸發既有同步；通知欠款金額變動或重新開啟時會清除已讀。Refs #3877

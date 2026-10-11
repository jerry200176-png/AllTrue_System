## 2026-10-12 — fix: 催繳提醒主任通知不被同步清掉、不重複、內容變動會重新顯示未讀
<!-- release-notes: silent_ship=silent-2026-10-12-tuition-reminder-sync-readstate -->
<!-- silent-reason: tuition:send-reminders 目前未排程，此修正只讓日後啟用時通知生命週期正確。 -->
- 通知同步把當日催繳彙總視為有效來源；彙總涵蓋的課程不再另出單課通知；同日內容變動時清除已讀。Refs #3877

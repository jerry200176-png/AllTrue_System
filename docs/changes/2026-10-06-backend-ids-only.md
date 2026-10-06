## 2026-10-06 — chore(privacy): backend diagnose/repair commands print ids only (#3626)
<!-- release-notes: silent_ship=silent-2026-10-06-backend-ids-only -->
<!-- silent-reason: 只改內部 artisan 診斷／修復指令的 console 輸出（不再印姓名、電話、備註），教職員看不到差異 -->
- `guardians:sync-from-legacy`、`teacher-signin:diagnose`、`teacher-signin:recover-rfid-collisions`、`repair:merge-renewal-learning-record`、`repair:leave-vacated-weeks`：預設只印 id／狀態／長度；`--with-names` 才印姓名（僅供人工，workflow 不使用）。`guardians:cutover-audit` 移除 LINE id／電話尾碼。

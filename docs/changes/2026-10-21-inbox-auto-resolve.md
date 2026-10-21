## 2026-10-21 — feat(notifications): 通知中心自動清掉已處理的扣堂失敗與家長回饋（預設關閉）
<!-- release-notes: silent_ship=silent-2026-10-21-inbox-auto-resolve -->
<!-- silent-reason: dark launch behind PERF_NOTIFICATION_SOURCE_RESOLVE (default off); directors see no change until product approves the lanes and it is enabled -->

- in-app #413 / GitHub #4154：「刷卡出席但扣堂失敗」與「家長學習回饋」通知原本要主任手動按已讀才會離開待辦。
- 旗標開啟後，通知同步會重新檢查來源資料：該筆出席已扣堂、已作廢、改為請假，或該堂已有扣堂紀錄 → 自動結案；家長回饋已有老師／主任回覆、已標記不需回覆或超過等待期 → 自動結案。來源又變回未處理（扣堂被沖回、家長再追問）時重新出現並顯示未讀。
- 代課通知、系統警示、匯入與升年級通知維持手動；代課通知的「已結案」代表撤銷代課，不能拿來表示已處理。
- 預設關閉（`PERF_NOTIFICATION_SOURCE_RESOLVE`），待 `docs/plans/2026-10-20-inapp-closeout-product-decisions.md` #413 的產品決定後再開；關閉即完整回滾。不新增資料表或欄位，不改扣堂、帳務或權限規則。

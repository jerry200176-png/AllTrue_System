## 2026-10-21 — feat(notifications): 通知中心自動清掉已處理的扣堂失敗通知（預設關閉）
<!-- release-notes: silent_ship=silent-2026-10-21-inbox-auto-resolve -->
<!-- silent-reason: dark launch behind PERF_NOTIFICATION_SOURCE_RESOLVE (default off); directors see no change until product approves the lanes and it is enabled -->

- in-app #413 / GitHub #4154：「刷卡出席但扣堂失敗」通知原本要主任手動按已讀才會離開待辦。
- 旗標開啟後，通知同步會重新檢查來源：該筆出席已扣堂、已作廢、改為不扣堂狀態（請假、缺席、試聽等），或該堂已有扣堂紀錄 → 自動結案，並記下結案原因；扣堂被沖回時重新出現並顯示未讀（只回看 14 天內結案的通知）。
- 家長學習回饋維持原本的事件機制（老師／主任回覆或標記不需回覆即結案）；旗標開啟後，家長再追問即使在合併推播的 10 分鐘內也會重新出現並顯示未讀。
- 代課通知、系統警示、匯入與升年級通知維持手動；代課通知的「已結案」代表撤銷代課。
- 預設關閉（`PERF_NOTIFICATION_SOURCE_RESOLVE`），待 `docs/plans/2026-10-20-inapp-closeout-product-decisions.md` #413 的產品決定後再開。不新增資料表或欄位，不改扣堂、帳務或權限規則。

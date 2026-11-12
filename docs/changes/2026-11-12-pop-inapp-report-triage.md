## 2026-11-12 — feat(pop): in-app 回報「分診」改走 Pi 本機 POP 操作（ADR-POP-015 a）
<!-- release-notes: silent_ship=silent-2026-11-12-pop-inapp-report-triage -->
<!-- silent-reason: 只新增 POP 內部操作 inapp-report-triage；教職員畫面與家長入口不變，回報者看到的仍是一則公開回覆與「已分診」狀態 -->

- 新增 POP 操作 `inapp-report-triage`：一筆 `new` 回報在同一個交易裡標為 `triaged`、記下 `bug` 分類與 GitHub issue 連結、貼一則公開回覆，只由 Pi 本機 `pop:execute-approved` 執行，不走 runner SSH。寫入用的是 Phase A workflow 同樣的 `BugReportService::changeStatus`／`addComment`。常設政策升 v3 涵蓋此操作。不新增資料表或欄位。
- 檢查缺一不可（人工核准也不能略過）：回報仍是 `new` 且在指定分校、安全篩檢未標記、issue 仍開啟且 SourceRef 對得上、回覆含 issue 連結且無個資或外部連結（≤600 字元）、15 分鐘審閱證明在列鎖下仍相符。已分診的回報一律拒絕，不會重複回覆。
- 只處理判定為需工程修正的 bug；建議、重複、不做的項目仍走人工 `close_as_logged`。常設政策每台北日最多核准 20 筆，超過或任何檢查不過就退回 super_admin 人工核准。
- `issueOwnsReport` 多一個「issue 必須仍開啟」選項，只有分診使用；結案操作的行為不變。

## 2026-11-13 — feat(pop): in-app 回報固定範本進度回覆（#4991）
<!-- release-notes: silent_ship=silent-2026-11-13-pop-inapp-progress-reply -->
<!-- silent-reason: 只新增 POP 內部操作 inapp-report-progress-reply；教職員畫面與家長入口不變，回報者只會多看到一則固定白話公開回覆 -->

- 新增 POP 操作 `inapp-report-progress-reply`：只收回報編號與範本代碼（`received`／`in_progress`／`deployed_please_confirm`），貼一則固定白話公開回覆，不改狀態、負責人或其他欄位；不接受任何自由文字。
- 拒絕條件：回報不存在或不在該分校、已關閉、安全篩檢標記、同範本已貼過、同一回報已有 3 則進度回覆、`deployed_please_confirm` 只能用在 `resolved` 或 `triaged`。以回覆文字辨識，不新增資料表或欄位。
- 與分診同一條常設政策（升為 v4）、同一套核准與稽核；不可還原，與分診一致。

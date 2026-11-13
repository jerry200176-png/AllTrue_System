## 2026-11-13 — feat(pop): in-app 回報固定範本進度回覆（#4991）
<!-- release-notes: silent_ship=silent-2026-11-13-pop-inapp-progress-reply -->
<!-- silent-reason: 只新增 POP 內部操作 inapp-report-progress-reply；教職員畫面與家長入口不變，回報者只會多看到一則固定白話公開回覆 -->

- 新增 POP 操作 `inapp-report-progress-reply`：只收回報編號與範本代碼（`received`／`in_progress`／`deployed_please_confirm`），貼一則固定白話公開回覆，不改狀態、負責人或其他欄位；不接受任何自由文字。
- 拒絕條件：回報不存在或不在該分校、已關閉、安全篩檢標記、同範本已貼過、同一回報已有 3 則進度回覆、回覆會記在回報者名下（`reply_would_be_reporters_own`）、回報者的訊息是最後一則公開留言（`reporter_reply_pending`）、`received` 遇到收件自動回覆已存在（`already_acknowledged`）、`received` 只在 `new`／`triaged`，`in_progress` 只在 `triaged`，`deployed_please_confirm` 只在 `resolved`。以回覆文字辨識，不新增資料表或欄位。
- 只新增一則留言；在 `resolved` 的回報上，`deployed_please_confirm` 同時算回報者重測請求（符合既有重測／等待回覆的文字規則），影響排程自動結案的計時，這是預期效果（自己名下的回覆一律被拒，所以一定成立）。要貼 `received` 就先貼，再取分診回寫證據：新增公開留言會讓進行中的分診證明失效，分診會被拒絕。
- 與分診同一條常設政策（升為 v4）、同一套核准與稽核；不可還原，與分診一致。

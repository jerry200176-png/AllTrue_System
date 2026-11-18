## 2026-11-19 — feat(billing): 主任可以把記在錯帳單的收據移到正確帳單（畫面）(#5113 step 4, in-app #522)
<!-- release-notes: staff_update=staff-2026-11-19-receipt-move -->

- 學生帳務「要先處理」的收據卡（收據對不到帳單）多一顆「移到正確帳單」，收款紀錄每筆已確認收據多一個「收錯帳單？」。按下去：選帳單（預設同一位學生，可「換一位學生」，限同分校）、看原帳單與正確帳單移動前後的未繳金額、填原因（必填）、按「確認移動」。手機上是從下面升起的面板，電腦是對話框。
- 後端 #5304／#5305 早已上線；這個 PR 只加畫面。每一種拒絕都有白話說明與下一步；金額超過主任上限、收據太舊、主任上限尚未設定時，畫面直接說「這筆要由總部處理」，並給「回報給總部」鈕。
- 伺服器提示：學生帳務回應在該分校開啟時多一個 `can_reassign_receipt: true`（沒開時不出現這個鍵，畫面沒有按鈕）；`reassign-targets` 每筆多 `subject`（課程名稱）、`start_date`、`issue_date`（讓同科目的帳單分得出來）。沒有任何寫入、資料表或旗標預設值變動。
- 開通方式（本 PR 不開）：後端環境變數 `FEATURE_RECEIPT_REASSIGN_CAMPUS_<分校id>=true`（大安 15、新店 9、木柵 16），並設 `BILLING_RECEIPT_REASSIGN_DIRECTOR_MAX_AMOUNT`、`BILLING_RECEIPT_REASSIGN_DIRECTOR_MAX_AGE_DAYS`（不設＝主任一律走總部）。回復：移除環境變數。

Release-Impact: user-visible

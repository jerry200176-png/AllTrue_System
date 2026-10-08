## 2026-10-08 — feat(billing): 學生帳務面板 Esc／焦點管理，退回改面板內視窗 (UX B-PR4)
<!-- release-notes: staff_update=staff-2026-10-08-ledger-panel-keyboard -->
- 學生帳務面板：Esc 關閉、Tab 焦點限制在面板內、開啟時焦點移入並在關閉後回到原按鈕、關閉鈕有名稱且 44px。合約卡片「退回」由 `window.prompt` 改為面板內 AtDialog（原因必填、送出內容 `rejection_note` 不變）。純顯示，金額與 API 不變。
- 合約卡片「確認入帳」先跳出確認視窗（預設焦點在「取消」），確認後才送出原本的 `confirm` 請求；入錯仍走既有「撤銷收款」。

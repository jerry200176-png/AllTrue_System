## 2026-10-21 — fix(billing): 課程查找「登記繳費回報」直接開登記表；學生帳務可看繳費單與收據（in-app #398 / #425）
<!-- release-notes: staff_update=staff-2026-10-21-inapp-398-425-ledger-shortcuts -->

- 課程查找的「登記繳費回報」開啟學生帳務後，會直接打開這門課的登記表（`AccountingLedgerModal` 新增 `initialAction='record'`）。只有卡片本來就會出現「登記這筆」的課才會自動打開（待核對回報、免繳、輔導課、多筆帳單的共用方案成員只開帳務）。取消後留在學生帳務；不能直接登記時，帳務上方用一行字說明原因。
- 學生帳務的收款紀錄，已確認的每一筆都有「查看收據」（沿用 `ReceiptModal`）；合約卡多一顆「繳費單」（沿用 `PaymentSlipModal`：有未結清帳單用最舊那張，還沒開帳單用課程估算的繳費單，同帳務中心），可用條件與帳務中心共用 `lib/billingDocState.js`（`slipAvailability`／`receiptAvailability`，從 `TuitionDocActions` 原樣搬出）；合約卡只在能開、或原因需要處理（金額待確認）時才顯示，已收清／待核對／共用方案的卡不再多一顆停用按鈕。
- `GET accounting/ledger` 的 `courses[]` 多一個唯讀欄位 `amount_pending_confirmation`（同催繳清單的金額待確認判斷，只對已停課合約計算），讓合約卡的繳費單在金額待確認時停用。
- `PaymentSlipModal` 疊在學生帳務上時會取得焦點、Tab 留在裡面、Esc 只關繳費單（同 `ReceiptModal`）。
- 只限主任／超級管理員（同帳務中心 `canViewBillingDocs`）。沒有新 API，不改收款、收據編號或金額規則。

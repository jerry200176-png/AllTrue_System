## 2026-10-21 — fix(billing): 課程查找「登記繳費回報」直接開登記表；學生帳務可看繳費單與收據（in-app #398 / #425）
<!-- release-notes: staff_update=staff-2026-10-21-inapp-398-425-ledger-shortcuts -->

- 課程查找的「登記繳費回報」開啟學生帳務後，會直接打開這門課的登記表（`AccountingLedgerModal` 新增 `initialAction='record'`）。只有卡片本來就會出現「登記這筆」的課才會自動打開（待核對回報、免繳、輔導課、多筆帳單的共用方案成員只開帳務）。取消後留在學生帳務。
- 學生帳務的收款紀錄，已確認的每一筆都有「查看收據」（沿用 `ReceiptModal`）；合約卡多一顆「繳費單」（沿用 `PaymentSlipModal`，依最舊未結清帳單），不能開時停用並寫一行原因，用詞同帳務中心 `TuitionDocActions`。
- 只限主任／超級管理員（同帳務中心 `canViewBillingDocs`）。沒有新 API，不改收款、收據編號或金額規則。

## 2026-10-07 — refactor(billing): one payment-actions module for money writes
<!-- release-notes: silent_ship=silent-2026-10-07-payment-actions-module -->
<!-- silent-reason: 只把確認入帳、退回、撤銷、作廢、登記收款的呼叫集中成一個模組，畫面與行為不變。 -->
- 新增 `frontend/src/lib/paymentActions.js`：所有帳務寫入（登記、確認、退回、撤銷收款、作廢帳單）同一處處理登入憑證與白話錯誤。帳務中心頁、學生帳務面板、合約卡片、登記表單改用它（原本三種寫法：localStorage token、authedFetch、raw fetch）。架構檢視 #2。

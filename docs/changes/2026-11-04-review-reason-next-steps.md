## 2026-11-04 — fix(billing): 待核對的每個原因都附上怎麼處理（in-app #469）
<!-- release-notes: staff_update=staff-2026-11-04-review-reason-next-steps -->
- 帳務中心「已結清」的待核對課程：每個原因多一行「怎麼處理」，原因和處理說明放在同一個對照表（`REVIEW_REASON_FIX`，`courseMoneyState.js`）。
- 需要時多兩個按鈕：沒開帳單的月份顯示「開帳單」（打開「要開帳單」），合約日期不對顯示「到課程改合約」（打開課程管理的這門課）。
- 「繳費明細」裡，金額不一致的帳單會同時列出帳單金額和照這個月堂數算出的金額。

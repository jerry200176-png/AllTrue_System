## 2026-10-07 — fix(billing): 已結束月結合約依自己的月份計價（in-app #377/#378）
<!-- release-notes: silent_ship=silent-2026-10-07-monthly-default-period -->
<!-- silent-reason: 只修正已結束月結合約的繳費單與登記金額計算，畫面與操作不變；主任端回覆走 in-app。 -->
- 月結合約沒有未繳帳單時，繳費單、付款連結、主任登記的計費月份改用 `MonthlyBillingService::defaultPeriodFor()`（今天／繳費日夾在合約期間內），不再用今天的月份；已結束的 9 月合約照 9 月已上堂數計價並可登記（R144）。

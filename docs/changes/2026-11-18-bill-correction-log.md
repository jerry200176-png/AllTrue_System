## 2026-11-18 — feat(billing): HQ 更正紀錄 log (step 2, PR 3; nothing to see while 更正帳單 is off)
<!-- release-notes: silent_ship=silent-2026-11-18-bill-correction-log -->
<!-- silent-reason: 更正帳單預設關閉，紀錄表在啟用前是空的；只有總部（super_admin）看得到這個分頁，教職員卡隨啟用 PR 一起發。 -->

- 新增 `GET accounting/bill-corrections`（只有 super_admin），分頁列出「更正帳單」紀錄：日期、分校、原帳單與新帳單、更正前後金額、原因、操作人；可依分校與月份篩選。
- 系統管理新增「更正紀錄」分頁，沒有紀錄時顯示「目前還沒有任何更正紀錄」。

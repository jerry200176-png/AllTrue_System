## 2026-11-06 — fix(dates): "today" means Taipei, not UTC, before 08:00 (#4991 step 3)
<!-- release-notes: staff_update=staff-2026-11-06-today-taipei-not-utc -->
- 前端新增 `ymdInTaipei()`（`lib/sessionDates.js`），收款日預設、帳務逾期判斷、請假／調課／代課預設日、點名日期、補課查詢區間等「今天」不再用 `toISOString().slice(0, 10)`（UTC 日期，台北早上 8 點前是昨天）。
- ESLint 新增 `no-restricted-syntax` 禁止該寫法（F30）。

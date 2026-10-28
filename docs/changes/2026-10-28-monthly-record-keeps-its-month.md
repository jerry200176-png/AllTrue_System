## 2026-10-28 — fix(billing): 月結登記收款記住當時收的月份，確認入帳時月份變了就擋下（#4574，in-app #432 後續）
<!-- release-notes: staff_update=staff-2026-10-28-monthly-record-keeps-its-month -->
- 跨月的月結合約登記收款時，系統記下這筆要收哪個月；確認入帳時，如果因為出席或帳單變動，最早要收的月份已經不同，就不入帳，請退回後重新登記，不會把錢記到別的月份。
- 新增 `payment_reports.billing_period`（可空、無預設值、不回填）；這次上線前已登記的回報照舊規則確認。

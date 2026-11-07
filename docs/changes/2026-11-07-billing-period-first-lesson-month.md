## 2026-11-07 — fix(billing): a monthly cycle's 期別 is the month its lessons fall in (in-app #514)
<!-- release-notes: staff_update=staff-2026-11-07-billing-period-lesson-month -->
- 新一期月結帳單的 `billing_period` 由 `Billing\BillingPeriod::forCycle()` 決定：週期內第一堂課的月份，沒有課才用開始日月份；同學生同科目該月已有有效帳單時順延到週期內下一個有課的月份。續約與預覽共用 `MonthlyRenewalPeriodService::inspect`，不再各自取開始日月份。金額不變。
- 登記收款側欄的「期別」旁、「帳務期間」顯示完整週期（`billing_period_start/end`）。
- 帳單編號改由 `Billing\InvoiceNumber::for()` 產生：已開立的編號不隨期別改動（Note 帶 `[invno_period:YYYY-MM]` 時沿用原期別），不需 migration。

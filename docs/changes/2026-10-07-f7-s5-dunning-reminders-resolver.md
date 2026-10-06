## 2026-10-07 — fix(billing): dunning + tuition reminders follow the bills (F7 S5, paths not scheduled)
<!-- release-notes: silent_ship=silent-2026-10-07-f7-s5-dunning-reminders -->
<!-- silent-reason: paths not scheduled -->
- 催繳（DunningService）與 `tuition:send-reminders` 的「誰還欠費」改依帳單判斷（BillingPayableResolver）：免費／帳單已全額繳清（舊 Paid 旗標過期）不再催繳；部分繳費、需人工確認者維持舊判斷；`billing.paid_status_outbound_notifications=false` 可還原。兩條路徑目前未排程，不影響現行使用者。

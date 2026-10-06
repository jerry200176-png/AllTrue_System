## 2026-10-07 — fix(notifications): tuition notifications follow the bills (F7 S5, notification center only)
<!-- release-notes: silent_ship=silent-2026-10-07-f7-s5-notifications -->
<!-- silent-reason: 上線並在正式站驗證後與帳務卡一起公告；只少了免費／已結清課的誤提醒 -->
- 通知中心的「未繳費」改依帳單判斷（BillingPayableResolver）：免費／試上課、帳單已全額繳清（舊 Paid 旗標過期）不再產生通知，部分繳費顯示剩餘金額；`billing.paid_status_outbound_notifications=false` 可還原；催繳與繳費提醒不變。

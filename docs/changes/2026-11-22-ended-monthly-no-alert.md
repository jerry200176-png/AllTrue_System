## 2026-11-22 — fix(alerts): paid monthly course past its end date leaves the tuition alert list (in-app #589)
<!-- release-notes: staff_update=staff-2026-11-22-ended-monthly-alert -->

- `AlertController::mapMonthlyAlert`：月結課程已繳且結束日（`EndDate`）早於今天時，不再列入「月結將到期」；未繳的照常列入逾期，不會漏收。Founder 2026-11-22 同意改規則，`docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 已同步。
- 測試：`TuitionAlertsApiTest::test_monthly_paid_excluded_after_end_date_but_unpaid_still_listed`。

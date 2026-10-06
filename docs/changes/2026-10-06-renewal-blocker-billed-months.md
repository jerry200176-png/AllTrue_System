## 2026-10-06 — fix(billing): a month already billed by a catch-up no longer blocks renewal
<!-- release-notes: silent_ship=silent-2026-10-06-renewal-blocker-billed-months -->
<!-- silent-reason: 補開後合約可以正常續期；補開上線後與教職員卡一起說明 -->
- `MonthlyRenewalPeriodService::inspect`：合約日期外的已上課堂次，若同學生同科目在該月已有未作廢帳單（`billing_period`，如補開帳單）就不算阻擋；續下一期與「本月待開帳單」不再因已補開的月份卡在「需補開」。

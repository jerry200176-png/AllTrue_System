## 2026-10-11 — test: freeze the clock in the Muzha fixed-schedule preflight test
<!-- release-notes: silent_ship=silent-2026-10-11-muzha-test-frozen-clock -->
<!-- silent-reason: 只修測試時間，產品沒有變化。 -->
- `MuzhaFixedScheduleStrategyTest` started failing on every PR after 2026-10-10 because the slot guard ignores past dates; the test now pins the clock to 2026-10-01.

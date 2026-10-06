## 2026-10-06 — chore(billing): F7 S5 shadow mode — reminders/dunning/notifications log resolver disagreements
<!-- release-notes: silent_ship=silent-2026-10-06-f7-s5-shadow -->
<!-- silent-reason: 只記錄比對結果，發送行為完全不變 -->
- Dunning, tuition reminders and tuition notifications now log one `paid_status_shadow` line per run comparing the legacy paid predicate with BillingPayableResolver; who is messaged is unchanged (`billing.paid_status_shadow`, default on).

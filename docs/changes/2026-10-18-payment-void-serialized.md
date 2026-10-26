## 2026-10-18 — fix(billing): Serialize payment-report voids against fresh money state
<!-- release-notes: silent_ship=silent-2026-10-18-payment-void-serialized -->
<!-- silent-reason: Internal concurrency/ownership refusal guards; no new payment or correction capability. -->

- Void locks the contract then re-reads the report in the same transaction, refusing a second void and invalid invoice/student ownership before any write.
- Preserve the saved #4248 API matrix, delayed-double-void and deterministic correction-versus-void tests on separate MySQL connections.
- Reuse #4254's validated worker bootstrap, parent signing key and MySQL/MariaDB isolation helper; the saved source branch remains intact.
- Confirm still follows its existing behavior in this slice. Its course-first RR repair and two-partial-payment regression are a separate follow-up, not an acceptance claim here.

Code rollback is a reviewed revert; no production data repair, schema operation, feature enable or in-app send is included.

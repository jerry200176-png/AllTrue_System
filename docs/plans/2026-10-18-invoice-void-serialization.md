# Invoice void serialization

Goal: protect single-course fee corrections from overlapping normal/exception
invoice voids, recheck current ownership, and preserve consolidated void behavior.
Repository AllTrue_System; task chore/task-exception-void-fee-lock-20261018;
initial base75ae398c, main1fab98b8 incorporated. Risk R3/T3 existing-rule billing
code, no new financial policy. No production activation/schema/account changes.

## Evidence and implementation

- Held-source #4247 CI39094727324: exception void/fee race expected200, got409.
  Initial isolated RED completed HTTP200 while parent held the course mutex.
- #4262 first review found stale bound owners, a new multi-course lock-order
  inversion, and insufficient proof of server-side blocking. Current code reads
  current header ownership, revalidates fresh invoice/item owners and returns409
  on drift. Reparenting RED observed200 instead of409 before this repair.
- Single-course voids take the course mutex; consolidated voids keep invoice-only
  serialization. Fee correction already refuses foreign/shared items (API test
  test_pending_reports_shared_items_and_ambiguous_reversal_provenance_block).
  Do not introduce multi-course fee support or change session-transfer semantics.
  Direct payments keep their prior sorted lock set and gain owner revalidation.
- Race tests observe the worker CONNECTION_ID in server PROCESSLIST and an
  absent post-query marker while parent owns the row, rather than a pre-SQL signal.
  Own-user visibility needs no PROCESS grant: [MySQL8.0 reference](https://dev.mysql.com/doc/refman/8.0/en/information-schema-processlist-table.html).

## Verification

1. Observed-blocking regressions: header/item, stale bound header, ownership drift
   while waiting, both voids, direct payment and consolidated reverse-ID overlap.
2. Preserve existing BillingInvoiceVoidTest and InvoiceRecordPaymentProjectionTest.
3. First hosted #4262 CI39095906152 completed4146tests with one unrelated fixture
   error: a unique-index test leaked APPLY_CLASS_SESSION_UNIQUE_INDEX into later
   migrations. A focused three-case sequence (D1→void→ensure-past leave) reproduced
   the same1062. Fix the six flag producers to restore native/ENV/SERVER channels;
   retain their actual index-enforcement assertions. PHPUnit global-state backup
   does not restore native putenv, so scoped test-only restoration is needed.
4. Expanded focused GREEN passed49tests/341assertions in160seconds: all12 race
   cases (including direct payment), all six index-flag producers, the failing
   D1→void→leave sequence, flag lifecycle unit and existing money regressions.
   No assertion, unique-index check, CI deadline or coverage threshold removed.
5. After targeted GREEN: final-head hosted CI and independent review; reply/resolve
   all3 review findings; normal queue only after exact-head gates. Revalidate held
   combined source separately before claiming integration or production acceptance.

Rollback: revert code, retain all financial history. Stops: new billing/access
semantics, protected release/schema/data operations need their concrete decision.
Three real cases remain unaccepted; synthetic tests are not production evidence.

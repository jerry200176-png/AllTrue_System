# Invoice void serialization

Goal: make normal and exception invoice voids share the existing course-first
money-writer mutex, so an in-flight correction cannot lose to an unlocked void.

Repository: AllTrue_System; base 75ae398c; task branch
chore/task-exception-void-fee-lock-20261018. Risk R3/T3 billing code, existing
serialization rule only. No production activation, account repair or schema change.

Evidence: combined candidate CI 39094727324 failed at
AgreedFeeConcurrencyTest::test_invoice_void_writers_and_fee_cannot_overwrite_each_other
(exception_void: expected 200, got 409). Both controller void paths lock invoices
without first locking their courses; recordPayment already does course-first.

Candidates: missing writer serialization; insufficient race barrier; fixture/schema
interference. Reproduce with independent two-process evidence before changing code.

1. Add a regression using the maintained PaymentReportRaceWorker and guarded
   synthetic loopback database. Hold a course, start a void, supersede the invoice,
   then release the course; the void must recheck and refuse without another write.
2. Run RED; if missing serialization is demonstrated, align both void paths with
   recordPayment, including item-only/multiple course references in sorted order.
3. Run targeted GREEN and existing void/payment regressions; independent review
   and exact-head hosted checks, then normal queue under existing-rule code policy.
4. Revalidate the held combined source separately; never count isolated tests as
   full integration or production acceptance.

Stops: new accounting semantics, access expansion, protected release/schema/data
operations require their concrete decision. Retain all original financial history.
Rollback: revert code; no data rollback or migration is involved in this change.

RED: isolated InvoiceVoidSerializationTest data set #0 on unchanged production
code completed with HTTP 200 while the parent still held the course lock. The
test failed with the actual response, not a setup/DDL error. An initial test
probe consumed proc_get_status exit status; it was replaced with non-consuming
pipe readiness before treating the final RED as evidence.

Targeted GREEN: six independent-process serialization cases passed (78 assertions),
including item-only and multiple-course references. Existing BillingInvoiceVoidTest
also passed 11 tests / 42 assertions, including paid/partial and actor/campus guards.
Direct payment projection regression passed 9 tests / 47 assertions. The header
fixtures were then tightened to have no InvoiceItem; the final targeted run
passed all 6 cases / 78 assertions in 67 seconds. Hosted checks and review remain
pending; no release or case acceptance is claimed.

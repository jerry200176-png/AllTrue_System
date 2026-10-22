## 2026-10-22 — Charge correction refuses NT$0
<!-- release-notes: silent_ship=silent-2026-10-22-charge-correction-refuses-zero -->
<!-- silent-reason: closes an invalid edit path; the refusal message tells directors which existing flow to use -->

`POST /student-classes/{id}/charge-correction` now answers 422 `charge_correction_zero_forbidden` when `new_charge` is 0, before touching the course or its open bill. Correcting to zero used to rewrite the bill into an unmarked NT$0 bill, which #3991 forbids; the free-gift path (void the bill, then 「登記收款」→「免費／贈送」) is the only way to a zero bill. The refusal is written to the edit-blocked audit like the other charge-correction refusals. Regression test: `StudentClassBillingCorrectionTest::test_charge_correction_to_zero_is_refused_and_leaves_the_bill_unchanged`. Rollback is a normal revert. Refs #4017.

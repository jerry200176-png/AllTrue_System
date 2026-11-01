## 2026-11-01 — fix(billing): a monthly contract may not leave taught lessons outside its dates (#4765)
<!-- release-notes: staff_update=staff-2026-11-01-lessons-outside-contract -->
- `MonthlyBillingService::taughtLessonsOutsideContract()` is the one predicate: countable lessons outside StartDate..EndDate of a date-mode, non-package contract.
- `StudentClassController::update` refuses a date change that strands a taught lesson (422 `contract_dates_strand_lessons`, audit `edit_blocked`); no write.
- `PaymentReportController` (`directorRecord`, `confirm`, legacy backfill via confirm) refuses a no-bill collection with 422 `lessons_outside_contract` instead of pricing an empty month from the stored Charge.
- Ledger course cards carry `lessons_outside_contract` and `end_date`; the contract card shows 需核對 with the dates and hides 登記這筆 / 繳費單.

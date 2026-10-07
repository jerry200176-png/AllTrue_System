# Glossary

## Contract Money Verdict

The single answer to "what does this contract (StudentClass) owe and what has been applied": amount owed, amount applied,
status (free / unbilled / unpaid / partial / paid / review_required), the current billing period, and per-lesson coverage.

**Truth rule (Founder 1A, 2026-10-07):** invoices and their Payment rows decide. The legacy `StudentClass.Paid` flag counts
only as a fallback when the course has no non-void invoice at all. Stored `Invoice.PaidAmount` is never the source of an applied amount.

Implemented by `BillingPayableResolver` + `InvoiceAmountReconciliationService`; `ContractMoneyState` keeps status labels (plus the waived guard and `hasActivePayment`, which are not money-amount logic).
Pinned today by `tests/Feature/Billing/ContractMoneyVerdictCharacterizationTest.php`.

## Effective Teacher

The teacher who actually taught (or will teach) one lesson occurrence — a substitute, a rescheduled slot's teacher or a
makeup's LearningRecord teacher, else the contract teacher (`StudentClass.TeacherID`). Pay, stats, LR attribution and
the calendar all read it; nothing re-derives it. Resolved only by `SubstituteScheduleService::teacherForOccurrence()` /
`teachersForOccurrences()` (SQL: `substituteTeacherSql()`). See `docs/adr/ADR-EFFECTIVE-TEACHER.md`.

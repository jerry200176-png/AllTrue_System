---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-08-08
---

# Sensitive Action Audit Log Coverage Matrix

> Addresses [#890](https://github.com/jerry200176-png/AllTrue_System/issues/890). Reference model: SOX/SOC2 admin activity audit trail. Companion: [`docs/security/AUDIT_LOG_POLICY.md`](AUDIT_LOG_POLICY.md) (existing policy doc), [`PII_DATA_INVENTORY.md`](PII_DATA_INVENTORY.md).

Pulled from the live codebase (`grep` over `backend/app/Http/Controllers`, `backend/app/Models`, `backend/app/Services`), 2026-08-08 — not asserted from memory.

## Existing audit log mechanisms found

| Model | Covers |
|---|---|
| `ScheduleAuditLog` | Schedule/reschedule changes (`StudentClassController`, `ClassSessionObserver`, `ManualSessionBookingService`) |
| `PayrollAuditLog` | Payroll rate/calculation changes (`FinanceController`) |
| `StudentIdentityAuditLog` | Student identity merges/changes (`StudentIdentityService`) |
| `BugReportStatusLog` / `bug_report_comments` | Full bug-report lifecycle (this session's own SOP work relies on this) |
| Inline `voided_by`/`voided_at`/`void_reason` columns | `payment_reports`, and void-capable rows in `PaymentReportController`, `ScheduleController`, `AccountingController`, `LearningRecordFeedbackController`, `AttendanceController`, `ClassSessionController`, `LearningRecordController`, `SubstituteController` — actor + timestamp + reason recorded inline on the row itself rather than a separate audit table. This is real coverage (who/when/why is captured), just structured differently from a dedicated log table. |

## Coverage matrix — sensitive action categories

| Category | Covered? | Evidence |
|---|---|---|
| Schedule/reschedule changes | ✅ Yes | `ScheduleAuditLog` |
| Payroll rate changes | ✅ Yes | `PayrollAuditLog` |
| Student identity merge | ✅ Yes | `StudentIdentityAuditLog` |
| Bug report status/comment changes | ✅ Yes | `BugReportStatusLog` |
| Payment/invoice void | ✅ Yes (inline) | `voided_by`/`voided_at`/`void_reason` columns |
| **PIN set/reset** (`PinVerificationController.php`) | ✅ Yes (#2332) | `SecurityAuditEvent` `staff.pin.changed` (`pin_set`, `pin_reset`, refused `pin_reset_password_invalid`); hashed actor/subject, no PIN, hash or password |
| Role/type changes (`User.type` director approve/reject) | ✅ Yes (#1810) | `SecurityAuditEvent` `director.account.approved` / `director.account.rejected` on `DirectorAccountController` (hashed actor/subject, old→new type codes; no names) |
| PII export (students.xlsx) | ✅ Yes (#1812) | `SecurityAuditEvent` `pii.export.students` on `GET /api/v1/students/export` (hashed actor, row/campus scope counts; no names/phones) |
| StudentClass SessionCount / RemainingSessions manual edit | ✅ Yes (#1811) | `SecurityAuditEvent` `student_class.session_balance_adjust` on `StudentClassController::update` when counts change (old→new ints only) |
| super_admin director password reset | ✅ Yes (#1813) | `SecurityAuditEvent` `director.password.reset` on `DirectorAccountController::resetPassword` (hashed actor/subject; temp password never in metadata) |
| director/super_admin sets or resets another staff password | ✅ Yes (2026-10-22) | `SecurityAuditEvent` `staff.password.admin_changed` on `ProfileController::update` / `resetPassword`; the target's sessions are revoked in the same transaction (`revoked_session_count`) |
| Sensitive admin session/impersonation (if any exists) | ⚠️ **Not verified this pass** | Out of scope for this grep pass |

## Critical gaps → tracked as follow-up

1. **PIN set/reset is covered (#2332).** `PinVerificationController` writes `staff.pin.changed` for a set, a reset and a reset refused for a wrong account password.
2. **PII student Excel export is covered (#1812).** `ExportController::students` writes `security_audit_events` (`pii.export.students`). Broader "who viewed a single student record in UI" query logging remains open if needed later.
3. **Director approve/reject role changes are covered (#1810).** Other `User.type` writes (if any) remain unverified.

## Acceptance against #890

- [x] Audit coverage matrix — above, evidence-based (grep results cited, not memory).
- [x] Critical gaps identified with concrete next steps.
- [ ] "轉成逐模組 issue" — not yet opened as separate GitHub issues; recommend opening one for the PIN-reset gap (clearest, smallest, most concrete) before the broader PII-export-logging one (needs more design first).

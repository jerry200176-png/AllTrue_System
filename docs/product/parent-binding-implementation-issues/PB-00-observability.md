# PB-00 — Observability & reason codes

| Field | Value |
|-------|-------|
| Phase / Risk | 0 / T1 |
| Board | **in progress / PR** · [#1436](https://github.com/jerry200176-png/AllTrue_System/issues/1436) |
| Blocks | PB-01,02,03 |
| Status | Code landed — observability only; identity security **not** complete |

**Scope:** stable `reason_code` on LINE+Portal failures; PII-safe `parent_binding_attempts`; correlation id; flag; KPI + missing-contact report.  
**Non-scope:** OTP; copy/success-path change; pairing/Inbox/GSR; PB-01～09.

**Rollback:** `PARENT_BINDING_OBSERVABILITY=false` (do not drop table first-line).  
**Codes:** `STUDENT_NOT_FOUND` · `CONTACT_PHONE_MISSING` · `PHONE_MISMATCH` · `AMBIGUOUS_MATCH` · `CAMPUS_MISMATCH` · `ALREADY_BOUND` · `INVALID_INPUT` · `AUTHORIZATION_DENIED` · `INTERNAL_ERROR`. Outcomes: `success` / `failure` / `noop`.  
**Ops:** `php artisan parent-binding:report --days=7|--missing-contact --format=json`

Still pending: PB-01 safe copy · PB-02 completeness UI · PB-03 Inbox · pairing/GSR not started.

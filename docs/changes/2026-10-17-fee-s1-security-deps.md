## 2026-10-17 — fix(billing): S1 security deps (void history / required audit / correction history)
<!-- release-notes: silent_ship=silent-2026-10-17-fee-s1-security-deps -->
<!-- silent-reason: Additive optional/required flags and history guard only; no staff UI; fee service lands separately with its own tests. -->

Independent security dependencies for agreed-fee S1: optional `blocksUnpaidCorrection(..., includeVoided)`, `SecurityAuditEvent::append(..., required)`, and `hasOperationalHistory` treating `invoice_corrections` as delete-blocking history. Each change ships with focused tests on this slice. Fresh void writer remains #4248. Fee service + full fee suites stay on #4247 (HOLD oversized) until these land.

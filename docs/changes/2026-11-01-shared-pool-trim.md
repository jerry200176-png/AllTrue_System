## 2026-11-01 — fix(scheduling): lowering a shared plan trims the pool
<!-- release-notes: staff_update=staff-2026-11-01-shared-pool-trim -->
- Lowering a shared count plan's total used to cancel only per subject. A 2-subject plan cut from 6 to 4 kept all 6 lessons. A cut now cancels the latest planned lessons of the whole pool past what is left. `SharedPackagePlanningService::sharedPoolExcess` uses the same count as the plan summary. A slot that something points at (a sign-in, ledger, note, substitute or payroll row) is never touched.
- The pools pre-planned before #4730 are cleaned by a separate Founder-approved POP operation that deletes the same `sharedPoolExcess` rows. It deletes rather than cancels, so a renewal re-fills those dates (#4734).

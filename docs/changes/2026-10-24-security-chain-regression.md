## 2026-10-24 — Preserve the combined authorization regression from #4423
<!-- release-notes: silent_ship=silent-2026-10-24-security-chain-regression -->
<!-- silent-reason: synthetic test coverage only; no runtime or user-facing behavior change -->

- After the source authorization PRs merged, retain their combined real-login escalation-chain regression on current main. Pending public director applications and legacy directors with an unapproved campus must fail every attempted account/campus escalation without writes; valid same-campus, legacy teacher-link and super-admin paths remain covered.
- Reuses the existing test-only legacy-table fixture. No application, migration, permission or workflow change. The original integration branch stays unmerged; revert the test commit to roll back coverage only.

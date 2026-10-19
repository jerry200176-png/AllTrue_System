## 2026-10-19 — Invoice-backed AR acceptance controls (#4197)
<!-- release-notes: silent_ship=silent-2026-10-19-invoice-backed-acceptance-controls -->
<!-- silent-reason: Read-only canonical verdict projection and acceptance correction; no staff-visible workflow or financial write changes. -->

The production tutoring acceptance previously compared AR with active-course Charge minus Pay. AR deliberately includes stopped invoice debt and excludes unbilled estimates, waived courses, tutoring and unresolved monthly verdicts. Keep total and per-student equality, nonempty regular controls, tutoring exclusion and the real read-only UI journey; derive the control population from the canonical invoice-backed verdict instead.

StudentClass list exposes only status/source/outstanding from its existing BillingPayableResolver batch as billing_verdict for director/super_admin. No extra queries, new financial calculation, auth scope, ledger mutation, schema or flag change. Teachers receive no new projection. Missing/invalid/duplicate/cross-campus verdict evidence fails acceptance rather than falling back to Charge-Pay. Package contracts remain individually counted without alert grouping.

Regression checks cover stopped debt, partial payments, void invoices, waived/tutoring/unbilled exclusions, foreign-campus exclusion, retained ledger rows and synthetic incomplete evidence. The acceptance reader requires this API projection to be deployed first; an older runtime fails explicitly. Synthetic regression and code merge are not production acceptance or in-app #325 resolution. Rollback reverts the projection and acceptance together; financial history is unchanged.

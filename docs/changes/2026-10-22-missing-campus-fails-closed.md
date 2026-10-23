## 2026-10-22 — Invoice payments and pending swipes fail closed without a campus
<!-- release-notes: silent_ship=silent-2026-10-22-missing-campus-fails-closed -->
<!-- silent-reason: authorization hardening for orphan records; normal records behave as before -->

Recording a payment on an invoice whose student cannot be loaded skipped the campus check; it now answers 404 like voiding does. A pending swipe without a campus skipped the check too; it now uses its student's campus, and a swipe with neither is refused for campus-scoped directors (the list already hides it from them). Regression: `MissingCampusFailsClosedTest`. Rollback is a normal revert.

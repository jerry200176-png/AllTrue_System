## 2026-10-23 — Production acceptance prints where a failed attempt failed
<!-- release-notes: silent_ship=silent-2026-10-23-acceptance-failure-location -->
<!-- silent-reason: CI/ops log line only; no product change -->

`calendar-course-acceptance.yml` keeps the Playwright report encrypted and prints only statuses, so a flaky first attempt (for example the in-app 404 test on run 39890471276: failed, then passed) could not be root-caused from the public log. `acceptance-report-gate.mjs` now adds `failed-attempt=N status=<status> at=<spec>:<line>` for every non-passing attempt: the spec file basename and line number from Playwright's error location, never the error text (which can hold page text). The gate's verdict is unchanged: a flaky test still fails the run. Self-test covers the location line and that no message text leaks.

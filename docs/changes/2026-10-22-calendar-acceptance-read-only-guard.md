## 2026-10-22 — Enforce the calendar acceptance read-only boundary
<!-- release-notes: silent_ship=silent-2026-10-22-calendar-acceptance-read-only-guard -->
<!-- silent-reason: acceptance harness safety only; no product runtime change -->

Calendar/course acceptance installs the existing browser-context guard before each test, blocks service workers, and fails when an app-origin write is attempted. GET/HEAD/OPTIONS remain reads, adoption telemetry is answered locally without reaching the app, and third-party requests retain their existing behavior. Popup pages share the same boundary.

This integrates #4139's source into current main while retaining the newer course-payment ledger entry checks and sidebar-search navigation assertions. Unit and two-local-server browser self-tests exercise the guard without a production session or credential. No production acceptance, application deployment or reporter verification is implied. The exact T3 smoke-control merge decision and fresh final-head CI/review remain required; rollback is a normal revert of this acceptance-only change.

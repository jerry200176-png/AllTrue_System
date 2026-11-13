## 2026-11-13 — fix: nightly invariant check takes "today" from the app clock
<!-- release-notes: silent_ship=silent-2026-11-13-nightly-invariants-app-date -->
<!-- silent-reason: internal read-only nightly check and its tests; no user-visible change -->

`bugs:verify-reproductions` compared lesson dates with MySQL `CURDATE()`/`NOW()` (database session timezone) instead of the application clock (Asia/Taipei), so its counts shifted by a day between 16:00 and 24:00 UTC. The conditions now bind today/now from the app clock. Read-only; rollback is a revert.

## 2026-10-22 — Deleting a schedule row is limited to the row's campus directors
<!-- release-notes: silent_ship=silent-2026-10-22-schedule-destroy-scope -->
<!-- silent-reason: authorization hardening on an endpoint the app UI does not call -->

`DELETE /schedules/{id}` checked only the approved-assessment lock. It now applies the same caller rule as `cancel-makeup`: a director of the row's campus or super_admin, checked before the assessment is read (#3938 item 2); teachers and other campuses get 403. The app's own screens do not call this endpoint. Regression: `ScheduleDestroyScopeTest`; the two lock tests that delete as a same-campus director are unchanged. Rollback is a normal revert. Refs #3938.

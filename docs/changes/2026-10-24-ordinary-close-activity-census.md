## 2026-10-24 — feat(billing): bounded ordinary-close activity census
<!-- release-notes: silent_ship=silent-2026-10-24-ordinary-close-activity-census -->
<!-- silent-reason: internal read-only evidence helper and synthetic database tests only; no existing close path is wired -->

- Adds an unwired, transaction-bound census for attendance, learning records, schedules, correction history, contract groups and same-student successor courses.
- Returns owner-bound IDs and keyed table digests; source row text remains transient and is not returned.

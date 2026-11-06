## 2026-11-06 — feat(courses): restore a cancelled lesson from course management (in-app #507, #4946)
<!-- release-notes: staff_update=staff-2026-11-06-restore-cancelled-lesson -->
- Cancelled-lesson chips in course management (list and course drawer) now open a restore dialog: safe-recovery check, reason, restore, then the course reloads. Directors only; moved lessons get no action; the backend refusal is shown as-is. The recovery check and restore call moved into `useSessionRecovery`, shared with SmartCalendar. Existing endpoints only, no backend or data change.

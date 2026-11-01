## 2026-11-01 — fix(attendance): the 出缺勤 badge counts earlier-day lessons still waiting for attendance (#4770)
<!-- release-notes: staff_update=staff-2026-11-01-attendance-badge-makeup -->
- `NotificationController::unreadCount` `by_type.attendance` now adds a director's ended, unmarked lessons from the 待補點名 list's default window (the last 7 days, before today) to today's count. Before, a lesson left unmarked on an earlier day never lit the badge (in-app #456).
- The ended-and-unmarked rule is one `ClassSession::endedUnmarked()` scope, shared by `AttendanceController::endedSessions` and the badge.

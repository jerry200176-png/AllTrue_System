## 2026-11-04 — fix(schedule): 改固定時段時，課程自己的學生不佔自己的名額（in-app #498）
<!-- release-notes: staff_update=staff-2026-11-04-capacity-self-seat -->
- `ScheduleGuardService::collectConcreteRecurringOverlaps()` no longer returns the edited course's own ClassSession/schedules rows as capacity occupants (teacher and room capacity count distinct students, and the editor is never one). An own row the sync leaves in place that overlaps the new slot is reported as `self_overlap` instead of 「已達…上限」. Other students' counting is unchanged; the single-occurrence path already excludes the course.

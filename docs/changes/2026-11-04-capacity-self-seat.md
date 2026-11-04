## 2026-11-04 — fix(schedule): 改固定時段被擋時，說清楚是自己的哪一堂（in-app #498）
<!-- release-notes: staff_update=staff-2026-11-04-capacity-self-seat -->
- `ScheduleGuardService::validateRecurringCourse()` blocking is unchanged. When the edited course's own retained lesson (locked, exception or makeup) is what fills the slot and the slot would otherwise be under the limit, the `teacher_capacity`/`room_capacity` conflict keeps its type but its message names that lesson (date and time) instead of 「已達…上限」.

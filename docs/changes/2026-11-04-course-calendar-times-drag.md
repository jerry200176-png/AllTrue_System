## 2026-11-04 — feat(course): 課程月曆顯示上課時間、可拖拉改期（in-app #497）
<!-- release-notes: staff_update=staff-2026-11-04-course-calendar-times-drag -->
- `CourseSessionCalendar.vue` shows each lesson's start time (first time plus `+N` on multi-lesson days) and a state word from the parent's existing `getSessionStateLabel`. Native HTML5 drag of a single movable future lesson (`isLessonMovable`) onto another future day emits `move-session`; `CourseManagement.vue` opens the existing 調課 form with the new date prefilled. Nothing saves without the existing 確認調課 dialog. No API or backend change.

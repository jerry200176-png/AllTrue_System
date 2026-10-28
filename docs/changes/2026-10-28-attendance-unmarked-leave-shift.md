## 2026-10-28 — fix(attendance): a director's leave on an unmarked lesson previews the renewal shift (#4578)
<!-- release-notes: silent_ship=silent-2026-10-28-attendance-unmarked-leave-shift -->
<!-- silent-reason: Behind LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED (off): a director who marks 請假 on an unmarked lesson sees the same preview and blocker list as the calendar and course page; the flag-on PR carries the staff card. -->
- The attendance page's unmarked-lesson and make-up lists run the same preview, confirm, apply pass as the calendar and course management when a director marks 請假 and the make-up lesson would land in a pre-created renewal (#4578).
- Lessons whose make-up lesson fits, teachers, and every other status keep the ordinary attendance request; edits of existing attendance rows are unchanged.

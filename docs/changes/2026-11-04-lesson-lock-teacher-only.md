## 2026-11-04 — fix(scheduling): only a teacher-written evaluation locks a lesson's teacher（#4907）
<!-- release-notes: staff_update=staff-2026-11-04-lesson-lock-teacher-only -->
- Founder: 如果還沒填評量表可以換老師，有填寫的話才不能換老師. A director comment on a blank record no longer counts as filled (PHP `isFilledByTeacher` and SQL twin `filledByTeacherSql`); the SQL twin now treats whitespace-only bodies as blank like PHP. Substitute undo is allowed when it restores the record's author. Stale texts and an unused import cleaned up.

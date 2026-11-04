## 2026-11-04 — fix(learning): 已上過的評量不再被改成現任老師（in-app #499）
<!-- release-notes: staff_update=staff-2026-11-04-record-teacher-keeps-history -->
- `ensure-past` / mirror self-heal (`syncRecordWithClassSession`) restamped `LearningRecord.TeacherID` to the current contract teacher for every row that was not filled+approved, including approved blank rows and authored pending rows of taught sessions. It now restamps only a substitute row or a mutable unperformed placeholder (`canFollowCurrentCourseTeacher`). Existing rows are not repaired by this change.

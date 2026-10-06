## 2026-10-06 — feat(scheduling): TD-076 Track B PR-C 單一「這堂誰教」解析器（旗標關閉）
<!-- release-notes: silent_ship=silent-2026-10-06-td076-pr-c-resolver -->
<!-- silent-reason: 全部在 schedule-occurrence-v2 旗標後面，正式站旗標關閉，教職員看到的畫面與功能完全不變。 -->
- `SubstituteScheduleService::teacherForOccurrence()`：旗標開啟的分校以該堂唯一的有效 schedules 列（身分 student_course_id + 原日期 + 原時間）決定授課老師，沒有則用 `StudentClass.TeacherID`；補課（extra）改看未作廢的 LearningRecord 老師（#3590 item 8）。旗標關閉＝原本的 `effectiveInstructorUserId`。
- 行事曆／課程查找、出缺勤權限、兼職薪資在旗標開啟時讀同一個答案。


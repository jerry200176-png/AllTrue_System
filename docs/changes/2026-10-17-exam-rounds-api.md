## 2026-10-17 — feat(exam): 段考場次 API（主任開考／老師登分／待辦摘要）
<!-- release-notes: silent_ship=silent-2026-10-17-exam-rounds-api -->
<!-- silent-reason: backend API only; no staff-facing nav or TeacherHome UI in this slice -->

- 新增 `exam_rounds`／`exam_round_subjects`／`exam_scores`／`exam_score_audit_logs`（additive）。
- 主任可建立／發布／關閉分校段考場次（多科＋適用年級）；老師僅能對自己學生登錄分數；關閉後僅主任可更正。
- `GET /api/v1/me/exam-pending-summary` 供後續 TeacherHome 待辦；不寫入 LearningRecord／Assessment／扣堂。
- 計畫：`.cursor/plans/midterm_exam_grades_prd_2026-10-17.md`（PRD 在 docs PR）。

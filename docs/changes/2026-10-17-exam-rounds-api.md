## 2026-10-17 — feat(exam): 段考場次 API（登分／進度／待辦摘要）
<!-- release-notes: silent_ship=silent-2026-10-17-exam-rounds-api -->
<!-- silent-reason: staff nav and TeacherHome UI land in a follow-up PR -->

- 主任建立／發布／關閉分校段考場次；老師對自己學生批次登分；關閉後僅主任可更正。
- `GET /api/v1/me/exam-pending-summary` 供後續 TeacherHome 待辦。
- 依賴 schema PR（`exam_rounds` 等 additive 表）；不寫入 LearningRecord／Assessment／扣堂。

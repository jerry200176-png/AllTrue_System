## 2026-11-13 — feat(study-tasks): 讀書任務資料表與 API (#5269 step 1)
<!-- release-notes: silent_ship=silent-2026-11-13-study-tasks-api -->
<!-- silent-reason: 只新增後端資料表與 API，尚無畫面使用，教職員與家長看不到任何變化 -->

- New table `student_study_tasks` (additive) and `students/{id}/study-tasks` (list, create) plus `study-tasks/{id}` (edit, tick done, delete) for directors and teachers; no screen uses it yet.
- Same campus and teacher rules as the learning plans, now in the shared `ScopesLearningPlanAccess` trait. Parent read view and the week view come in later steps.

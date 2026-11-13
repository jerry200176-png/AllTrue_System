## 2026-11-13 — feat(exams): 學生學習計畫 API (#4970 step 4a)
<!-- release-notes: silent_ship=silent-2026-11-13-student-learning-plan-api -->
<!-- silent-reason: 只新增後端資料表與 API，畫面（甘特圖）在下一個 PR 才上線，教職員目前看不到差異 -->
- New tables `student_learning_plans` and `student_plan_units` (additive, no unique/foreign keys). One non-archived plan per (student, course) is enforced in code under a row lock on the course.
- New `GET/POST students/{student}/learning-plans`, `PATCH learning-plans/{plan}`, `PATCH learning-plans/{plan}/units/{unit}`, `POST learning-plans/{plan}/recalculate`. POST copies an active same-campus template's units (content copy) and dates them with `LearningPlanScheduler` from the student's school exam periods (default plan end = start + 18 weeks).
- Directors and super admins read and write any plan in their campus; a teacher reads and writes only courses visible to them (`StudentClass::visibleToTeacher`). Responses add derived `percent_done`, `units_behind`, `next_exam`.
- Scheduling rules: see `LearningPlanScheduler`. Units whose dates were edited by hand (`dates_manual`) are never re-dated; `dates_manual: false` hands them back.
- Refactor: the school exam period lookup for a student moved from `SchoolExamPeriodController::forStudent` into `StudentExamPeriodResolver` so the plan reuses it; the endpoint's behaviour is unchanged.

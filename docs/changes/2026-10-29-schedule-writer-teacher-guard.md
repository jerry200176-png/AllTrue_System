## 2026-10-29 — fix(schedule): 調課／排課不再接受不是該分校老師的 teacher_id（in-app #441）
<!-- release-notes: silent_ship=silent-2026-10-29-schedule-writer-teacher-guard -->
<!-- silent-reason: 只擋 API 收到不是老師的 id（現有畫面不會送出這種值），教職員看不到差異 -->
- `POST /api/v1/learning-records/reschedule-session`、`POST /api/v1/schedules`、`PUT /api/v1/schedules/{id}` 以前會把任何整數存成這堂課的授課老師；正式站曾有一筆存成課程編號，該堂課就從所有老師的工作台消失、主任端仍看得到。現在 `teacher_id` 必須是呼叫者管理分校綁定的老師（沿用代課 API 的同一條規則，正班老師與原本的老師不變），否則回 422 `teacher_not_assignable`，什麼都不寫。不需 migration；回滾＝還原這個 PR。

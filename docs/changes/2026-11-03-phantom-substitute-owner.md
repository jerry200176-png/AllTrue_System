## 2026-11-03 — fix(schedule): 不是老師的代課紀錄不再把課從老師的課表拿走（in-app #468）
<!-- release-notes: staff_update=staff-2026-11-03-phantom-substitute-owner -->
- 正式資料有幾筆 `schedules` 代課紀錄，`teacher_id` 存的是課程 ID 而不是老師（#441 修了寫入端，這些舊紀錄留著）。讀取時它們被當成「別人代課」，任課老師的「我的課表」就看不到那幾堂。
- `SubstituteScheduleService::substituteScheduleDerivedSql` 現在只採用 `teacher_id` 是任課老師、或有綁任一分校（`UserCampus`）的紀錄，跟寫入端 `SubstituteService::teacherBoundToAny` 同一個規則。class-sessions 列表、projection 與學習紀錄的代課判斷都走這裡。

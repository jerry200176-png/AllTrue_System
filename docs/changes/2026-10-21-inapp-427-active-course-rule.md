## 2026-10-21 — 學生管理「已有進行中課程」提示與課程列表一致（in-app #427）
<!-- release-notes: staff_update=staff-2026-10-21-inapp-427-active-course-rule -->

- `GET students/{id}/active-courses` 改用學生管理課程列表同一份資料（`student-classes` 投影：分校、校正後剩餘堂數、共用方案池剩餘、伺服器繳費狀態），再以單一規則 `EnrollmentActiveCourseRule` 篩選。
- 不再列出：已結案／已轉正的試聽（`closed_reason` 有值）、已上完且已收（或免費）的課、已停課的課；剩餘堂數改顯示與課程卡相同的數字。範圍與展開學生時的課程清單相同（你可看到的所有分校）；可選 `branch_id` 限縮單一分校。
- 送出建課時的重複課程守門（`EnrollmentService::store`）同樣不再把上述已完課／已結案的 Stop=0 課程視為重複；投影看不到的課程仍照舊計入。
- 學生頁：仍在進行（未停課）但已有結案原因的舊資料列，歸入歷史課程。
- 前端課程列表的歷史判斷移入 `courseMoneyState.isHiddenHistoricalCourse`／`isStudentPageActiveCourse`；前後端共用 `backend/tests/Fixtures/active_course_parity.json` 驗證一致。僅影響顯示與提示，不改任何收費或扣堂規則。

- Review 修正：建課重複檢查的初始候選列包含 `Stop IS NULL` 的舊資料，與既有課程投影的未停課定義一致。此為查詢相容修正；不修改 schema。現行測試 migration 將 Stop 定為 NOT NULL，NULL 舊資料端到端 fixture 尚未覆蓋。

- 老師的課程列表仍只顯示其可見課程；若既有建課守門還會計入其他不可見的候選課程，提前給不含課程識別／科目／老師／堂數的泛化提醒。繼續填寫不代表強制建立，原送出守門與學生校區權限不變。新增隱私／跨校拒絕 backend regression 與前端取消／繼續測試；本機 16 frontend tests 與 lint 通過，backend 待 CI。

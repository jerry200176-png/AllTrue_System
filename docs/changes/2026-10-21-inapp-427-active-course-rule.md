## 2026-10-21 — 學生管理「已有進行中課程」提示與課程列表一致（in-app #427）
<!-- release-notes: staff_update=staff-2026-10-21-inapp-427-active-course-rule -->

- `GET students/{id}/active-courses` 改用學生管理課程列表同一份資料（`student-classes` 投影：分校、校正後剩餘堂數、共用方案池剩餘、伺服器繳費狀態），再以單一規則 `EnrollmentActiveCourseRule` 篩選。
- 不再列出：已結案／已轉正的試聽（`closed_reason` 有值）、已上完且已收（或免費）的課、已停課、他校教室的課；剩餘堂數改顯示與課程卡相同的數字。`branch_id` 預設學生本校，前端帶目前分校。
- 前端課程列表的歷史判斷移入 `courseMoneyState.isHiddenHistoricalCourse`／`isStudentPageActiveCourse`；前後端共用 `backend/tests/Fixtures/active_course_parity.json` 驗證一致。僅影響顯示與提示，不改任何收費或扣堂規則。

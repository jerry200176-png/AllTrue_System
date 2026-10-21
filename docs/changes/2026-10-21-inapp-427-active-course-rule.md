## 2026-10-21 — 「進行中課程」改由伺服器單一判定（in-app #427）
<!-- release-notes: staff_update=staff-2026-10-21-inapp-427-active-course-rule -->

- 新增 `StudentClassActivity`（後端唯一判定）：`student-classes` 每一列帶 `history_reason`／`is_active_course`；學生管理、課程管理直接讀這兩個欄位，前端不再自行推導（移除 `isHistoryCourse` 等客端規則）。
- `GET students/{id}/active-courses`（新增課程前的「已有進行中課程」提示）與建課重複守門（`EnrollmentService::store`）呼叫同一個 `evaluate()`：同一套自我校正剩餘堂數（`selfHealedUsage`，列表也改用它）與 `BillingPayableResolver` 收款判定。已結案／已轉正的試聽、已上完且已收（或免費）的課、已停課的課不再列入，也不再擋建課；剩餘堂數與課程卡相同（共用方案顯示方案池）。
- 行為變更（守門放寬）：Stop=0 但已上完且已收清的舊課，不再被視為重複課程。課程管理仍把「待對帳結案」留在工作清單。未改任何收費或扣堂規則。

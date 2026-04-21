---
name: backfill-continuation-weekdays
overview: 讓補登流程改為「先補歷史日期，再從最後補登日之後依固定星期續排剩餘堂數」，並同步到智慧課表顯示。
todos:
  - id: backfill-weekday-ui
    content: 調整補登模式 UI，固定星期改為必填可複選，並以最後補登日計算 futureFirstClassDate
    status: completed
  - id: backend-last-consumed-date
    content: 在 StudentClassController index 回傳 last_consumed_date（已核准且已扣堂最大日期）
    status: completed
  - id: session-date-algorithm
    content: 更新 sessionDates.js 以 remaining_sessions + last_consumed_date 推算未來剩餘堂次
    status: completed
  - id: smart-calendar-integration
    content: SmartCalendar 接入新欄位與新推算，驗證補登後同步顯示正確
    status: completed
isProject: false
---

# 補登續排與智慧課表同步

## 目標

- 補登時保留你選的「總購買堂數（含已上）」。
- 歷史日期補登後，剩餘堂數從「最後補登日期之後的下一個固定上課日」開始續排。
- 智慧課表顯示與剩餘堂數計算一致，不再混入補登前歷史區間。

## 變更範圍

- 前端補登表單：[CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)
- 前端堂次日期推算：[sessionDates.js](/home/admin/frontend/src/lib/sessionDates.js)
- 智慧課表讀取/顯示：[SmartCalendar.vue](/home/admin/frontend/src/pages/SmartCalendar.vue)
- 後端課程資料回傳（提供續排錨點）：[StudentClassController.php](/home/admin/backend/app/Http/Controllers/StudentClassController.php)

## 實作方向

- 在補登模式改為「必選固定上課星期（可複選）」：
  - 不再用歷史日期自動推斷未來星期。
  - 送出前計算：`lastBackfillDate = max(已選歷史日期)`。
  - 計算：`futureFirstClassDate = lastBackfillDate 之後第一個符合固定星期的日期`。
  - 建課 payload 使用：`days_of_week=固定星期`、`first_class_date=futureFirstClassDate`、`sessions_purchased=總購買堂數`。
- 補登歷史日期仍走 `bulk-backdoor-approve`（維持已核准與扣堂），讓 `UsedSessions/RemainingSessions` 照既有機制更新。
- 後端 `student-classes` 回傳加上「最後已扣堂日期」欄位（例如 `last_consumed_date`，來源為該課已核准且已扣堂評量最大 `SessionDate`），前端用它作為續排錨點。
- `computeSessionDatesForCourse()` 改為：
  - 若有 `last_consumed_date` 與 `remaining_sessions`：從 `last_consumed_date` 之後第一個固定星期開始，僅生成 `remaining_sessions` 堂。
  - 否則走既有 fallback（維持舊資料相容）。
- `SmartCalendar` 使用上述新推算結果，確保本週可見堂次與剩餘堂數一致。

## 驗證

- 手動情境：
  - 補登 3 個歷史日期、固定星期選「二/四」、總購買 10 堂。
  - 預期：剩餘 7 堂，且未來堂次從最後補登日後下一個「二或四」開始。
- 交叉頁面：
  - 補登完成後切到智慧課表，應即時刷新且不顯示補登前區間的未來堂次漂移。
- 回歸：
  - 非補登一般新課程仍可正常顯示與調課/請假。


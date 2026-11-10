---
name: teacher-attendance-checkin
overview: Enable teachers to access attendance management, mark their own students as arrived immediately, and close/deduct sessions without waiting for class end.
todos:
  - id: teacher-nav-access
    content: Expose attendance page to teacher role in App navigation and page mounting.
    status: completed
  - id: teacher-attendance-ui
    content: Implement teacher-focused attendance flow in AttendancePage with today-session list and one-click arrival marking.
    status: completed
  - id: arrival-mark-backend
    content: Extend AttendanceController store logic to support teacher immediate arrival check-in and deduction before class end.
    status: completed
  - id: authorization-guard
    content: Enforce own-class and campus restrictions for teacher attendance actions, including duplicate protection.
    status: completed
  - id: regression-verify
    content: Run build/lint and validate teacher/director attendance scenarios for regressions.
    status: completed
isProject: false
---

# Teacher Attendance Check-in

## Goal
讓老師帳號可使用出缺勤管理，且可在學生到班時立即點名並核掉該堂（立即扣堂），範圍僅限自己的課程。

## Scope
- 前端入口與權限：在老師側欄顯示「出缺勤管理」。
- 老師點名流程：顯示老師本人今日可點名堂次，提供到班快速操作。
- 後端規則：允許老師在課程未結束前進行「到班即核掉」，並執行扣堂。
- 風險控管：不可跨老師/跨分校操作，避免重複扣堂。

## Planned Changes
- 前端導航與頁面掛載
  - 更新 [frontend/src/App.vue](frontend/src/App.vue)
    - 老師側欄加入 `attendance` 項目。
    - `AttendancePage` 掛載條件改為教師與主任皆可進入（仍維持既有密碼鎖與分校限制）。

- 老師出缺勤介面（以「今日可點名堂次」為主）
  - 更新 [frontend/src/pages/AttendancePage.vue](frontend/src/pages/AttendancePage.vue)
    - 針對 `userRole === 'teacher'` 顯示老師版主流程（今日自己的堂次 + 一鍵到班）。
    - 前端讀取今日 `ClassSession`（透過既有 `/api/v1/class-sessions?start=today&end=today`）。
    - 點名送出時附帶 `mark_mode: 'arrival'`（或等價旗標）呼叫 `/api/v1/attendance`。
    - 保留主任流程，不影響既有「下課後點名/補登」。

- 後端點名邏輯（老師到班即核掉）
  - 更新 [backend/app/Http/Controllers/AttendanceController.php](backend/app/Http/Controllers/AttendanceController.php)
    - `store()` 新增「老師到班模式」分支：
      - 允許未到下課時間時，老師對自己課程進行 `present/late` 點名。
      - 立即將該堂 `ClassSession.Status` 更新為已完成狀態（沿用現行語意 `attended`）。
      - 立即執行 `SessionDeductionService::deductOnAttendance(...)`。
      - 維持重複點名防護（同一 `ClassSessionID` 只能有一筆有效點名）。
    - 保留原本「下課後點名」邏輯給主任/既有流程使用。

- 路由與契約對齊
  - 檢查 [backend/routes/api.php](backend/routes/api.php)（既有 `role:director,teacher` 可沿用）。
  - 規範新增請求欄位（如 `mark_mode`）僅為擴充，不破壞舊 payload。

- 驗證與回歸
  - 後端手動驗證：
    - 老師可點自己的課、不可點他人課。
    - 到班即扣堂、不可重複扣堂。
    - 分校隔離不被破壞。
  - 前端手動驗證：
    - 老師登入可看見「出缺勤管理」。
    - 今日堂次列表正確、點名後狀態與剩餘堂數更新。
    - 主任流程不回歸。

## Critical Files
- [frontend/src/App.vue](frontend/src/App.vue)
- [frontend/src/pages/AttendancePage.vue](frontend/src/pages/AttendancePage.vue)
- [backend/app/Http/Controllers/AttendanceController.php](backend/app/Http/Controllers/AttendanceController.php)
- [backend/routes/api.php](backend/routes/api.php)
- [backend/app/Services/SessionDeductionService.php](backend/app/Services/SessionDeductionService.php)
---
name: 補請假底層重構計畫
overview: 改採完整底層重構：以狀態機 + 扣堂台帳取代刪除式 rollback，讓「已上課補請假」可追溯、可沖回、可重算，並一次性統一單筆請假、連假批次與堂數計算。
todos:
  - id: schema-ledger-void
    content: 新增 void 欄位與 session_deduction_ledger migration + model
    status: completed
  - id: deduction-service-refactor
    content: 重構 SessionDeductionService 為台帳驅動並提供重算
    status: completed
  - id: retro-leave-flow
    content: 改寫補請假流程：作廢點名/評量 + reverse ledger + leave_adjusted
    status: completed
  - id: query-and-ui-align
    content: 統一 StudentClass API 與 CourseManagement UI 讀取新邏輯
    status: completed
  - id: feature-tests
    content: 補齊補請假/冪等/權限/堂數一致性的 Feature 測試
    status: completed
isProject: false
---

# 補請假底層重構計畫

## 目標
一次解決「已上課改請假」與堂數漂移問題：不再刪資料，改用可追溯的狀態流與扣堂台帳，讓課程歷程、稽核與統計一致。

## 核心設計
- **Session 狀態機**
  - `ClassSession.Status` 僅走可控轉移：`scheduled -> attended/late/absent/excused -> leave_adjusted`
  - 補請假不刪堂次，改狀態為 `leave_adjusted`（前端顯示「請假」）。
- **資料不硬刪**
  - `StudentSingIn`、`LearningRecord` 新增作廢欄位（`VoidedAt`, `VoidedByUserID`, `VoidReason`），補請假時標記作廢。
- **扣堂台帳**
  - 新增 `session_deduction_ledger`（建議）：
    - `event_type`: `deduct` / `reverse`
    - `student_class_id`, `class_session_id`, `source`(attendance/learning_record/manual_adjust), `created_by`
  - `RemainingSessions/UsedSessions` 由台帳彙總（可同步回寫快取欄位）。

## 主要檔案
- 後端
  - [backend/app/Http/Controllers/ScheduleController.php](backend/app/Http/Controllers/ScheduleController.php)
  - [backend/app/Http/Controllers/AttendanceController.php](backend/app/Http/Controllers/AttendanceController.php)
  - [backend/app/Http/Controllers/LearningRecordController.php](backend/app/Http/Controllers/LearningRecordController.php)
  - [backend/app/Services/SessionDeductionService.php](backend/app/Services/SessionDeductionService.php)
  - [backend/app/Http/Controllers/StudentClassController.php](backend/app/Http/Controllers/StudentClassController.php)
- 前端
  - [frontend/src/pages/CourseManagement.vue](frontend/src/pages/CourseManagement.vue)
- Migration
  - `StudentSingIn` 增加作廢欄位
  - `LearningRecord` 增加作廢欄位
  - 新增 `session_deduction_ledger` 表

## 實作步驟
1. **Schema 與模型**
   - 增加作廢欄位與 ledger 表；建立 Eloquent model。
2. **扣堂服務重寫**
   - `SessionDeductionService` 改為寫 ledger；提供 `deductForSession()`、`reverseForSession()`、`recomputeCounters()`。
3. **點名/評量接入台帳**
   - 點名成功寫 `deduct`；評量核准如需扣堂也寫 `deduct`（防重複 key: `class_session_id+event_type`）。
4. **補請假流程**
   - `ScheduleController` 允許主任對已上課堂次補請假：
     - 將該堂點名/評量標記作廢
     - 寫 `reverse` ledger
     - `ClassSession` 標記 `leave_adjusted`
     - 執行順延補堂
5. **查詢一致化**
   - `StudentClassController` 的 `sessions_used/remaining_sessions` 改由台帳彙總結果輸出，避免來源競爭。
6. **前端呈現**
   - `CourseManagement` 請假選單允許已上課堂次；標示「補請假（將沖回堂數）」提示。

## 權限與稽核
- 僅 `director/admin/super_admin` 可補請假；teacher 禁止。
- 每筆補請假需記錄操作者、原因、時間（VoidReason + ledger metadata）。

## 測試
- 新增/更新 [backend/tests/Feature/ScheduleLeaveCascadeTest.php](backend/tests/Feature/ScheduleLeaveCascadeTest.php)
  - 已上課補請假：
    - 堂次保留且狀態變更
    - 點名/評量標記作廢
    - ledger 產生 `reverse`
    - `RemainingSessions/UsedSessions` 正確回補
  - 權限：teacher 403。
  - 冪等：同堂重複補請假不可重複 reverse。

## 風險控管
- 舊資料兼容：先保留原欄位，台帳彙總結果回寫快取欄位，逐步切換讀取來源。
- 交易邊界：補請假採單次 transaction，確保狀態、作廢與台帳一致提交。
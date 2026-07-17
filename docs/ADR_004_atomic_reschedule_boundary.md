# ADR-004 — 調課以單一原子操作為寫入邊界

> **Status:** Accepted in branch (effective after merge).
> **Date:** 2026-07-18
> **Scope:** `schedules`、`ClassSession`、`LearningRecord`、`StudentSingIn` 與堂數回復。

## Context

調課原由前端依序執行三次寫入：建立原堂 `rescheduled` marker、建立目標 `scheduled` exception、再呼叫 `reschedule-session` 移動 `ClassSession`。第三步錯誤曾被吞掉，造成 UI 顯示成功，但課程管理、行事曆、點名與評量各自看到不同狀態。這屬復發家族 F1，並重疊 R13、R43、R47、R52。

## Decision

1. 調課的 canonical write boundary 是 `RescheduleSessionService::execute()`；controller 只做輸入驗證、角色／分校授權與錯誤轉譯。
2. 原堂 anchor、目標 exception、實體 `ClassSession`、評量時間、未來堂次的點名作廢與扣堂回復，必須在同一個 `DB::transaction(..., 3)` 內完成。
3. 每次調課必須用 `student_class_id + old_date + old_start_time` 精準定位 occurrence；不得只用日期猜第一堂。
4. 相同 payload 重試時，若 anchor、target 與目標 ClassSession 已一致，回傳 `committed=true + idempotent_replay=true`，不可新增第二組 rows。
5. 前端只有收到 `committed=true` 才能顯示成功；網路、409、422 或不完整回應一律保留 modal 並顯示可處理錯誤。
6. 分校由課程所屬學生推導，後端比對登入者 campus scope；不信任前端 `branch_id`。

## Consequences

- 失敗時不再留下半套 schedule marker 或幽靈目標堂。
- 三個調課入口共用 `commitReschedule()`，不再以 Supabase 直寫作降級路徑。
- `RecordedByUserID` 與 `recorded_by_name` 成為判斷人工誤點或系統／刷卡建立的第一層稽核證據；`schedule_audit_logs` 保留 ClassSession 前後快照與 operator。
- 舊 API payload 未帶 `ensure_schedule_exception=true` 時維持相容；待所有外部 consumer 盤點完成後，可移除 controller 內舊實作。

## Verification

- `RescheduleSessionPrecisionTest`：原子提交與相同請求重試。
- `RescheduleOccupiedSlotTest`：晚期衝突時兩筆 schedules 與 ClassSession 全部回滾。
- `AttendanceRangeTest`：人工／自動建立來源可查。
- `rescheduleApi.test.js`：只有 committed response 可進入成功狀態。

## Rollback

程式回滾不需 migration。既有資料不自動改寫；production 個案修復仍須依備份、dry-run、精準 row 條件與 deploy workflow 執行。

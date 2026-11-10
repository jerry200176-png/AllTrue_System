---
name: Merge excused into leave
overview: 將 `excused` 狀態完全合併進 `leave`，並移除 SessionEditModal 的「公假」按鈕。不需 schema 變更（欄位是 varchar），只需資料 migration + 程式修改。
todos:
  - id: migration
    content: 新增 DB migration：UPDATE class_sessions + StudentSingIn 把 excused → leave
    status: completed
  - id: class-session-ctrl
    content: ClassSessionController：移除 excused 自 STATUS_TRANSITIONS / ATTENDED_STATUSES / teacherAllowed
    status: completed
  - id: attendance-ctrl
    content: AttendanceController：applyAttendanceEffects 改 excused→leave，supplemental query 改 Status，保留 label backward compat
    status: completed
  - id: schedule-ctrl
    content: ScheduleController：3 處 StudentSignIn::create Status 改為 leave
    status: completed
  - id: cascade-service
    content: CourseLeaveCascadeService：移除 excused 自 target session 過濾條件
    status: completed
  - id: learning-record-model
    content: LearningRecord.php scope：移除 excused（migration 已清舊資料）
    status: completed
  - id: frontend-modal
    content: SessionEditModal.vue + useSessionEditFlow.js：更新 STATUS_TRANSITIONS，移除公假按鈕確認
    status: completed
  - id: attendance-page
    content: AttendancePage.vue：API 呼叫改送 leave，option value 改 leave
    status: completed
  - id: tests
    content: AttendanceExcusedLeaveCascadeTest：斷言 Status === 'leave'
    status: completed
  - id: deploy
    content: npm run deploy 前端上線
    status: completed
isProject: false
---

# 合併 `excused` → `leave`

## 核心原則

- `excused` 在 `ClassSession.Status` 和 `StudentSignIn.Status` 都用 `leave` 取代
- 保留 label map 中的 `excused: '請假'` 作為歷史資料顯示用（不破壞舊記錄呈現）
- `leave` 的可轉換狀態需擴充（原本 `excused` 能轉去 `attended/late/absent`，合併後 `leave` 也要支援）
- `leave_adjusted`（補請假）完全不動

## 影響範圍

**後端（5 個檔案 + 1 個 migration）**

- [`backend/app/Http/Controllers/ClassSessionController.php`](backend/app/Http/Controllers/ClassSessionController.php)
- [`backend/app/Http/Controllers/AttendanceController.php`](backend/app/Http/Controllers/AttendanceController.php)
- [`backend/app/Http/Controllers/ScheduleController.php`](backend/app/Http/Controllers/ScheduleController.php)
- [`backend/app/Services/CourseLeaveCascadeService.php`](backend/app/Services/CourseLeaveCascadeService.php)
- [`backend/app/Models/LearningRecord.php`](backend/app/Models/LearningRecord.php)
- 新增 migration

**前端（3 個檔案）**

- [`frontend/src/components/course-management/SessionEditModal.vue`](frontend/src/components/course-management/SessionEditModal.vue)
- [`frontend/src/composables/course-management/useSessionEditFlow.js`](frontend/src/composables/course-management/useSessionEditFlow.js)
- [`frontend/src/pages/AttendancePage.vue`](frontend/src/pages/AttendancePage.vue)

**測試（1 個檔案）**

- [`backend/tests/Feature/AttendanceExcusedLeaveCascadeTest.php`](backend/tests/Feature/AttendanceExcusedLeaveCascadeTest.php)

## 步驟一：DB Migration（清舊資料）

新建 `2026_04_13_400000_merge_excused_into_leave.php`：

```php
DB::statement("UPDATE class_sessions SET Status = 'leave' WHERE Status = 'excused'");
DB::statement("UPDATE StudentSingIn SET Status = 'leave' WHERE Status = 'excused'");
```

## 步驟二：ClassSessionController.php

**STATUS_TRANSITIONS** — 移除 `excused` 列，並擴充 `leave` 的允許目標：

```
// 移除整行：
'excused' => ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'absent', 'cancelled'],

// 修改 leave：
'leave' => ['scheduled', 'attended', 'late', 'absent', 'cancelled'],

// 其餘各狀態移除 'excused'（不再列入可轉換目標）
'scheduled' => ['attended', 'late', 'absent', 'leave', 'cancelled'],
'attended'  => ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
// …以此類推
```

**ATTENDED_STATUSES** — 移除 `excused`：
```php
private const ATTENDED_STATUSES = ['attended', 'completed', 'late', 'absent'];
```

**老師允許清單**（line 335）— 移除 `excused`：
```php
$teacherAllowed = ['attended', 'late', 'absent', 'leave'];
```

## 步驟三：AttendanceController.php

**applyAttendanceEffects**（line 734）：
```php
'excused' => 'leave',  // 改這行（原本是 'excused' => 'excused'）
```

**補充查詢（supplemental rows）**（line 151）：
```php
DB::raw("'leave' as Status"),  // 改（原 'excused' as Status）
```

**label map 保留（backward compat）**：
```php
'excused' => '請假',  // 保留，讓舊記錄還能顯示
'leave'   => '請假',
```

**validation 保留 `excused`**（API input backward compat，不改）。

## 步驟四：ScheduleController.php

共 3 處 `StudentSignIn::create(['Status' => 'excused', ...])` 全改為 `'leave'`：
- `store()` leave 路徑（line ~251）
- `retroLeave()`（line ~403）
- `leaveBySession()`（line ~479）

## 步驟五：CourseLeaveCascadeService.php

line 42 的 target session 過濾：移除 `excused`（migration 後不會再有 excused，但保留 `leave` 即可）。

## 步驟六：LearningRecord.php scope

`excludeLeaveSessionPendingReview` 的 whereIn 保留 `excused` 作為 backward compat（舊記錄可能還有），或直接移除（migration 已清）。建議移除，因為 migration 已把舊資料改掉。

## 步驟七：前端 SessionEditModal.vue + useSessionEditFlow.js

兩個檔案的 `STATUS_TRANSITIONS` 做相同修改：
- 移除 `excused` 列
- 擴充 `leave` 目標：`['scheduled', 'attended', 'late', 'absent', 'cancelled']`
- `SESSION_STATUS_LABELS` 保留 `excused: '請假'`（歷史顯示）
- 移除「公假」按鈕（上一輪已做，確認保持移除）

## 步驟八：AttendancePage.vue

- API 呼叫的 `Status: 'excused'` 改為 `Status: 'leave'`（發送點名時）
- `statusLabelMap` 保留 `excused: '請假'`（顯示舊記錄）
- 統計 `stats.excused` 保留（舊資料顯示），可選擇合入 `stats.leave`
- `<option value="excused">請假</option>` 改為 `value="leave"`

## 步驟九：測試更新

`AttendanceExcusedLeaveCascadeTest.php` — 把對 `Status === 'excused'` 的斷言改為 `Status === 'leave'`；測試名稱可保留原名或更名。

## 注意事項

- `leave_adjusted` 完全不動
- 不需 schema migration（varchar 欄位，無 ENUM 限制）
- `CourseLeaveCascadeService` 本身的核心邏輯不需改，只是清理 comment 中對 `excused` 的提及
- `PaymentSlipModal.vue`、`SmartCalendar.vue`、`DirectorDashboard.vue` 等顯示元件保留 `excused` label map 即可，不需主動改動（migration 後不再寫入新的 `excused`）
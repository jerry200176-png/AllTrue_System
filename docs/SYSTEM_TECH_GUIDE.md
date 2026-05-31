# AllTrue 系統技術實作指南

> **目標讀者**：AI Agent 與工程師。用來快速理解「為什麼這樣設計」，  
> 縮短 bug 偵查和功能開發的上下文建立時間。  
> **更新原則**：每次 `[REVIEW]` 發現新的非顯而易見事實 → 補充到對應節。

---

## 目錄（快速跳轉）
1. [Identity & Auth 層](#1-identity--auth-層)
2. [出缺勤域（Attendance Domain）](#2-出缺勤域attendance-domain)
3. [ClassSession 狀態機](#3-classsession-狀態機)
4. [RFID 刷卡流程（Swipe Flow）](#4-rfid-刷卡流程swipe-flow)
5. [堂次扣除系統（Session Deduction）](#5-堂次扣除系統session-deduction)
6. [Teacher 可見性規則](#6-teacher-可見性規則)
7. [前端 API 串接要點](#7-前端-api-串接要點)
8. [已知非直覺行為（Gotchas）](#8-已知非直覺行為gotchas)
9. [核心 Services 職責](#9-核心-services-職責)
10. [資料庫 ID 對應關係](#10-資料庫-id-對應關係)
11. [採用率與流程治理（Adoption v1.1）](#11-採用率與流程治理adoption-v11)

---

## 1. Identity & Auth 層

### 1.1 Teacher.id === User.id（同一個人的兩張表）

```
Teacher table  (id=17, T_Name='鄭翔祐', CampusID=1)
                 ↕ id 相同
User table     (id=17, Name='鄭翔祐', type='T', LoginName='...')
```

**實際驗證（2026-04-23）**：SELECT u.id, t.id FROM User u JOIN Teacher t ON t.id = u.id WHERE u.type='T' → 全部吻合，無例外。

**後果**：
- `StudentClass.TeacherID` / `StudentSingIn.TeacherID` 存的是 `User.id`（因為前端選老師從 `/api/v1/teachers` → `ProfileController` → 查 `User` table）
- `AttendanceController` 用 `auth_teacher_id` 過濾，等同於過濾 `User.id`

### 1.2 auth_teacher_id 的解析路徑

**檔案**：`app/Http/Middleware/AttachAuthUser.php`

```php
// 簡化邏輯：
$teacherId = $teacherHeader ?: ($role === 'teacher' ? $user->id : null);
$request->attributes->set('auth_teacher_id', $teacherId);
```

- `role = teacher` 時：`auth_teacher_id = User.id`（就是登入的 User ID）
- `role = director / super_admin` 時：`auth_teacher_id = null`（不限老師）
- Bearer Token 解析位置：`AttachAuthUser.php` → 從自建 Supabase-compatible JWT/session 取 `user_id`

### 1.3 teacher_branches vs UserCampus（雙重分校綁定）

老師可見分校有兩個來源（任一滿足即可見）：
- `UserCampus` table：`UserID + CampusID`（統一授權）
- `teacher_branches` table：`teacher_id + branch_id`（細粒度支援跨分校）

ProfileController 查詢時 OR 兩個來源：見 `ProfileController::index()` L30-70。

---

## 2. 出缺勤域（Attendance Domain）

### 2.1 兩條記錄路徑

```
路徑 A：老師手動點名
  AttendanceController::store()
    → StudentSingIn::create(TeacherID = auth_teacher_id)
    → applyAttendanceEffects(classSession, status)  ← 更新 ClassSession.Status
    → SessionDeductionService::deductOnAttendance()

路徑 B：學生 RFID 自刷卡（2026-04-23 修復前只做前兩步）
  SwipeRfidController::handleStudentSwipe()
    → findMatchingClass(student, swipeAt)  ← 找對應 ClassSession
    → StudentSingIn::create(TeacherID = studentClass.TeacherID)
    → AttendanceEffectsService::applySessionStatus()  ← 修復後新增
    → SessionDeductionService::deductOnAttendance()
```

### 2.2 老師查詢出缺勤的可見性過濾

```php
// AttendanceController::index()
if ($role === 'teacher') {
    $query->where('si.TeacherID', $auth_teacher_id);
}
```

**隱含限制**：老師只看到 `StudentSingIn.TeacherID = 自己 User.id` 的記錄。  
若 `StudentSingIn.TeacherID = NULL`（因 `StudentClass.TeacherID = null` 或自修記錄），  
則老師查不到（WHERE null = X → false）。

### 2.3 self_study vs swipe-rfid 的判斷

`SwipeRfidController` 的 `Memo` 欄位設定：
```php
'Memo' => $studentClass ? 'swipe-rfid' : 'self_study'
```

- `swipe-rfid`：找到匹配的 StudentClass（有課程對應）
- `self_study`：無匹配課程（超出時間窗口、今日無排課）
- `presence-window`：刷退時 backfill 補建的記錄（刷進到刷出之間有課）

---

## 3. ClassSession 狀態機

### 3.1 狀態一覽

| 狀態 | 含義 | 進入條件 |
|---|---|---|
| `scheduled` | 已排課，待執行 | 建立課程時預設 |
| `attended` | 準時出席 | 老師點名 present / 學生刷卡 ≤ StartTime+15min |
| `late` | 遲到出席 | 老師點名 late / 學生刷卡 > StartTime+15min |
| `absent` | 缺席 | 老師點名 absent |
| `leave` | 請假 | 學生請假流程 |
| `leave_adjusted` | 請假+補課 | leave + 已安排補課 |
| `cancelled` | 取消 | 課程終止 |

### 3.2 狀態轉換 Guard Rule

`AttendanceEffectsService::applySessionStatus()`（2026-04-23 建立）：
```php
if ($session->Status !== 'scheduled') {
    return; // 不覆寫人工決策
}
```

**原因**：老師已手動標記（attended/absent/leave）後，學生補刷卡不應覆蓋人工決策。

### 3.3 前端如何判斷「待點名」

`AttendancePage.vue` 的 `fetchPendingSessions`：
```javascript
// 只有 status === 'scheduled' 才列入待點名
.filter(r => String(r?.status || '').toLowerCase() === 'scheduled')
```

**結論**：任何非 `scheduled` 狀態都不會出現在「今日待點名」清單。

---

## 4. RFID 刷卡流程（Swipe Flow）

### 4.1 公開端點

```
POST /api/v1/swipe-rfid
Headers: Authorization: Bearer <Campus.Token>
Body: { branch_code: string, rfid: string }
```

**認證**：用 `Campus.Token`（硬體機器的 token），**非使用者 JWT**。

### 4.2 findMatchingClass 邏輯（SwipeRfidController）

```
Step 1: 查今日 ClassSession（Status ≠ leave）中屬於該學生的課
        時間窗口：(StartTime - 30min) ≤ swipeAt ≤ EndTime

        優先選 ongoing（StartTime ≤ swipeAt ≤ EndTime）中最近啟動的
        其次選 upcoming（最近即將開始）
        → 返回 [StudentClass, hours, classSessionId]

Step 2（fallback）: 若無匹配 ClassSession，
        查 StudentClass 的 week/time 排課欄位（week1-6, time1-6）
        若 dayOfWeek 和時間差 ≤ 30min → 返回 [StudentClass, hours, null]

Step 3: 若都無 → 返回 [null, null, null] → Memo='self_study'
```

### 4.3 刷進 vs 刷退判斷

```
同一學生今日已有未簽退的記錄（SignOutDT = NULL）?

  ├── Yes，且距上次刷卡 ≤ 60s  → debounce，忽略（duplicate_ignored）
  ├── Yes，且超過 60s           → 簽退：update SignOutDT + backfillPresenceWindow()
  └── No                        → 簽進：create new StudentSingIn
```

### 4.4 backfillPresenceWindow 補建邏輯

刷退時，回溯刷進到刷退之間有哪些 ClassSession 未被記錄：
- 查 `ClassSession` where StartTime 在 [signInDT, signOutDT] 之間
- 且 `whereDoesntHave('signIns', ...)` 保護冪等（已有記錄不重建）
- 補建的 StudentSingIn：Memo='presence-window'，Status='present'
- 同步更新對應 ClassSession.Status（applySessionStatus）

### 4.5 遲到判斷閾值

```php
// AttendanceEffectsService::resolveSwipeStatus()
const LATE_GRACE_MINUTES = 15;
// swipeAt > (StartTime + 15min) → 'late'
// swipeAt ≤ (StartTime + 15min) → 'present'
```

---

## 5. 堂次扣除系統（Session Deduction）

### 5.1 扣堂觸發點

```
StudentSignIn 建立成功後：
SessionDeductionService::deductOnAttendance($studentClass, $signIn)
  → deductForSession() → 寫入 SessionDeductionLedger
  → PackageDeductionService::syncFromStudentClassDeduction() （課程包同步）
  → recomputeCounters() → 更新 StudentClass.UsedSessions / RemainingSessions
```

**注意**：Session Deduction 與 ClassSession.Status **完全獨立**，互不依賴。

### 5.2 SessionDeductionLedger 事件類型

| event_type | 觸發 |
|---|---|
| `deduct` | 出席點名（attend/late） |
| `reverse` | 請假後回滾（leave），或管理員手動補堂 |

每筆 ledger 另有 nullable `minutes` 欄（#613）：`null` = 整堂（依 `perSessionMinutes`）；非 null = 該事件的實際分鐘數。

### 5.3 分鐘制權威餘額（#613 A1）

扣堂權威單位為「分鐘」，`RemainingSessions` 為衍生顯示值：

- **權威欄位**：`StudentClass.PurchasedMinutes`（= `SessionCount × perSessionMinutes`）、`StudentClass.RemainingMinutes`、`session_deduction_ledger.minutes`。
- **`perSessionMinutes()`**：`StudentClass.SessionDuration`，未設則 fallback `DEFAULT_SESSION_MINUTES`（60）。
- **recomputeCounters() 雙模式**：
  - 課程**無**「部分時數」事件（無 `minutes != perSession` 的 ledger 列）→ 完全沿用舊 count-based 邏輯（byte-identical），僅補寫衍生分鐘欄。
  - 課程**有**部分時數事件 → 分鐘為權威：`RemainingMinutes = PurchasedMinutes − 淨用分鐘`，`RemainingSessions = ROUND_HALF_UP(RemainingMinutes / perSession)`（整數運算 `floor((2a+b)/(2b))`，無浮點），`UsedSessions = SessionCount − RemainingSessions`。
- **比例扣堂範圍**：只對 `schedules.type='extra'` 補課且實際時長 < 每堂分鐘（chokepoint `deductOnAttendance` 自載 ClassSession 算 `clamp(EndTime−StartTime, 0..perSession)`，完整時長傳 `null`）。正常課堂、完整時長補課一律整堂。
- **reverse 一致性**：`reverseForSession` 未指定 minutes 時，沖回對應 `deduct` 列的 `minutes`，避免淨值漂移。
- **讀取端守門**：`StudentClassController::index` 對 fractional 餘額不可用 count-based observed 覆寫（`hasFractionalBalance`），並回傳精確 `remaining_minutes`。
- **已知限制**：`PackageDeductionService` 池鏡像仍 `delta=±1` 整堂（TD-059）；`ClassSessionController::recalculateSessionCounters` 為死碼（TD-060）。

詳見 `docs/AI_REGRESSION_LESSONS.md §R59`。

---

## 6. Teacher 可見性規則

### 6.1 各 API 的老師過濾邏輯

| API | 過濾邏輯 | 備註 |
|---|---|---|
| `GET /attendance` | `WHERE si.TeacherID = auth_teacher_id` | 若 TeacherID=null → 不可見 |
| `GET /class-sessions` | `WHERE sc.TeacherID = auth_teacher_id OR schedule.teacher_id = auth_teacher_id` | 含代課 |
| `GET /learning-records` | `WHERE lr.TeacherID = auth_teacher_id` | 同樣依 User.id |
| `GET /student-classes` | `WHERE sc.TeacherID = auth_teacher_id` | - |
| `POST /swipe-rfid` | 公開端點，無 teacher filter | 刷卡機打，不限角色 |

### 6.2 director 與 super_admin 可見性

`director` 角色的可見性由 **CampusID** 控制（只看自己分校）：
```php
if (!empty($campusIds)) {
    $query->whereIn('si.CampusID', $campusIds);
}
```

`super_admin` 無任何 campus 或 teacher 過濾。

---

## 7. 前端 API 串接要點

### 7.1 Token 獲取路徑

```javascript
// 大多數頁面
const { data: { session } } = await supabase.auth.getSession();
const token = session?.access_token;

// SubjectUnitsPage / DirectorDashboard（等價，直接讀 localStorage）
const token = JSON.parse(localStorage.getItem('alltrue_session'))?.access_token;
```

### 7.2 AttendancePage 的雙查詢架構

AttendancePage 有兩個並行查詢：

```
fetchPendingSessions()  →  GET /api/v1/class-sessions?start=today&end=today
  → 顯示「今日待點名」（status='scheduled' 的 ClassSession）

fetchRecords()          →  GET /api/v1/attendance?date=today
  → 顯示「今日出缺勤紀錄」（StudentSingIn 記錄）
```

**已知前端與後端不一致的地方**：
- `markedSessionsCount` 計算來自 `records.value`（StudentSingIn 的 ClassSessionID 集合）
- `pendingSessions.length` 來自 ClassSession 的 scheduled 數量
- 兩者獨立計算，2026-04-23 前刷卡後兩數字互相矛盾（ClassSession 未更新）

### 7.3 TeacherHomePage 的打卡狀態卡片

`fetchPendingAttendance()` → `GET /api/v1/class-sessions?start=today&end=today`，過濾 `status='scheduled'`，結果存入 `pendingAttendanceCount`。

**和 AttendancePage 共用同一 API，但前端分開管理**。

---

## 8. 已知非直覺行為（Gotchas）

### G-001：StudentSingIn / TeacherSingIn 表名 typo（Sing≠Sign）

```php
// 正確的 Model 名稱：
class StudentSignIn extends Model  // app/Models/StudentSignIn.php
{
    protected $table = 'StudentSingIn';  // 表名是 typo，但已是 production 資料
}
```

一律用 Model class，勿直接寫 SQL 用 `StudentSignIn`（表名是 `StudentSingIn`）。

### G-002：Teacher table 與 User table 共用同一 id（歷史遺留）

**來源**：系統早期使用獨立 Teacher table，後遷移到 User table 管理帳號，但 Teacher table 未廢棄，兩表 id 值保持一致（migration 同步寫入）。

**影響**：任何 join Teacher 或 User 用同一個 ID 都能命中對應記錄。

### G-003：StudentSingIn.TeacherID = null 記錄老師看不見

當刷卡時 `StudentClass.TeacherID = null`（課程尚未分配老師）→ `StudentSingIn.TeacherID = null`。  
老師查詢 `WHERE si.TeacherID = auth_teacher_id` → `null = X` → false → 記錄消失。  
Director 可見（他們用 CampusID 過濾，不用 TeacherID）。

### G-004：backfillPresenceWindow 的 ClassSession.Status 更新

2026-04-23 修復前：backfill 補建的 StudentSingIn 不更新 ClassSession.Status。  
修復後：每個補建記錄都呼叫 `AttendanceEffectsService::applySessionStatus(session, 'present')`。

### G-005：PHPUnit 測試必須用 now() 相對時間建立 Session

`SwipeRfidController::swipe()` 使用 `$swipeAt = now()`，不接受 `swipe_at` request 參數。  
測試中的 ClassSession 必須用 `now()` 相對時間（如 `now()->subMinutes(10)`）才能匹配。  
詳見 Y2 規則（p0-gate.mdc）與 `tests/Feature/SwipeClassSessionSyncTest.php`。

### G-006：ClassSession.Status guard — 不覆寫人工決策

`AttendanceEffectsService::applySessionStatus()` 有 guard：  
`if ($session->Status !== 'scheduled') return;`  
只更新 `scheduled` 狀態，`attended/late/absent/leave` 不可被刷卡覆寫。

### G-007：SwipeWindowMinutes 可由分校設定覆寫

`Campus.SwipeWindowMinutes` 欄位（若設定）覆蓋預設 30 分鐘窗口。  
`AttendanceController::resolveSwipeWindowMinutes(campusId)` 讀取此欄位。  
**但 SwipeRfidController::findMatchingClass() 目前硬寫 30min，未讀取 campus 設定（TD pending）**。

### G-008：presence-window backfill 不做 late 判斷

backfill 補建的 StudentSingIn `SignInDT` 設為 session.StartTime（非實際刷卡時間），  
因此統一設為 `attended`（非 `late`），即使實際刷卡時已遲到。  
這是 open question（bugfix plan §13 記錄）。

---

## 9. 核心 Services 職責

| Service | 檔案 | 職責 | 注意 |
|---|---|---|---|
| `SessionDeductionService` | `app/Services/SessionDeductionService.php` | 扣堂 / 退堂 ledger 寫入 + 計數器更新 | 與 ClassSession.Status 完全獨立 |
| `AttendanceEffectsService` | `app/Services/AttendanceEffectsService.php` | ClassSession.Status 更新邏輯（guard + 狀態解析） | 2026-04-23 建立，從 AttendanceController private method 提取 |
| `PackageDeductionService` | `app/Services/PackageDeductionService.php` | 課程包扣堂同步 | 由 SessionDeductionService 呼叫 |
| `EnrollmentService` | `app/Services/EnrollmentService.php` | 建立 StudentClass + 初始 ClassSession 批次生成 | - |
| `TeacherScopeService` | `app/Services/TeacherScopeService.php` | 解析老師可見的 CampusID / StudentClass 範圍 | - |
| `CourseLeaveCascadeService` | `app/Services/CourseLeaveCascadeService.php` | 請假連鎖效應（ClassSession 狀態、堂次回滾） | - |

### 9.1 AttendanceEffectsService 與 AttendanceController 的重複（TD-012）

`AttendanceController` 仍有 private `resolveSwipeStatus()` + `applyAttendanceEffects()`，  
與 `AttendanceEffectsService` 邏輯重複。  
手動點名路徑用 AttendanceController private methods；刷卡路徑用 Service。  
→ 計畫清償：TD-012（docs/TECH_DEBT.md）。

---

## 10. 資料庫 ID 對應關係

| 概念 | DB 欄位 | 對應到哪個 table | 備註 |
|---|---|---|---|
| 登入老師 | `User.id` = `Teacher.id` | User + Teacher（兩表 ID 相同） | 詳見 §1.1 |
| auth_teacher_id | `User.id` | User table | 非 Teacher table 的 T_Name 欄位 |
| StudentClass.TeacherID | `User.id` | User（前端從 `/api/v1/teachers` 取，實際查 User table） | 若 null → StudentSingIn.TeacherID 也 null |
| StudentSingIn.TeacherID | `User.id` | User | null 時老師查不到 |
| ClassSession.StudentClassID | `StudentClass.ID` | StudentClass | 注意是 ID 非 id |
| StudentSignIn Model | `StudentSingIn` table | - | typo（Sing≠Sign），Model 名稱是 SignIn，表名是 SingIn |

### 10.1 命名別名速查

| 前端用語 | 後端/DB 用語 |
|---|---|
| `student_course_id` | `StudentClassID`（StudentClass.ID） |
| `class_session_id` | `ClassSession.id` |
| `teacher_id`（API filter） | `TeacherID`（DB column, = User.id） |
| `branch_id` | `CampusID`（Campus.id） |

---

## 11. 採用率與流程治理（Adoption v1.1）

### 11.1 API 與資料流

| API | 主要用途 | 主要欄位 |
|---|---|---|
| `GET /api/v1/adoption/task-tracker` | 首頁任務聚合與 SLA 分級 | `sla_level`, `blocked_count`, `owner_role`, `due_at` |
| `GET /api/v1/adoption/activity-log` | 主任最近操作履歷 | `actor`, `action`, `at` |
| `GET /api/v1/adoption/weekly-metrics` | 7 日採用率與流程摘要 | `workflow_daily`, `comparison`, `teacher_open_rate_pct`, `director_open_rate_pct` |
| `POST /api/v1/adoption/events` | 非阻塞追蹤事件 | `event`, `branch_id`, `meta` |

### 11.2 SLA 分級口徑

- `breached`：`due_at` 早於現在（逾期）。
- `warning`：距截止 <= 4 小時（預警）。
- `normal`：其餘狀態。

### 11.3 每日流程摘要口徑（workflow_daily）

- `due_total`：今日仍需處理的學習評量 + 補課案件 + 課表回報。
- `done_total`：今日完成（核准評量 + 已確認補課 + 已解決課表回報）。
- `breached_total`：截至今日仍逾期未處理的項目數。

### 11.4 週對比口徑（comparison）

- `previous_window`：前一個 7 日視窗（非本週）。
- `delta_teacher_open_rate_pct` / `delta_director_open_rate_pct`：本週開啟率 - 前週開啟率。
- 用於主任首頁 KPI 顯示「較上週 +x%/-x%」。

### 11.5 前端治理規則

- `DirectorDashboard`：任務列表按 `sla_level` 優先；`breached`/`warning` 顯著標記。雙檢視（`focus`/`full`），預設 `focus`，使用者偏好寫入 `localStorage.alltrue.director_dashboard_view_mode.v1`。
- `TeacherHome`：登入後有未完成事項只提醒一次；支援「提示音開關」與「今日靜音」避免疲勞提醒。
- `NotificationsCenter`：支援企業視圖（待處理優先 / SLA 優先 / 高風險），並可同類通知聚合降噪。
- `ParentPortal`：頂部 Progress Hub 顯示四卡（本週學習、下次課程、待處理事項、繳費狀態）；資料來源 `GET /api/v1/parent/dashboard.progress_summary`。

### 11.6 家長進度中心（Parent Progress Hub）

| 卡片 | 主要欄位 | 點擊行為 |
|---|---|---|
| 本週學習 | `progress_summary.week_progress.attended/scheduled` | 切到 `learning` 分頁 |
| 下次課程 | `progress_summary.next_session.{date,start_time,subject,is_today}` | 切到 `schedule` 分頁 |
| 待處理事項 | `progress_summary.pending_actions[]` 與 `pending_total` | 依 `pending_actions[0].cta_target` 跳分頁 |
| 繳費狀態 | `progress_summary.payment.{status,paid_courses,total_courses}` | 切到 `billing` 分頁 |

`pending_actions` 來源：`payment_alerts`、今日 `next_session`、待回饋的已核准評量；用於降低家長端認知負擔（PRD: enterprise dashboard parent portal v2）。

### 11.7 家長互動狀態流與事件追蹤（P1）

- `progress_summary.interaction_statuses`：統一輸出 `submitted / in_progress / resolved`，並附 `updated_at`。
- `progress_summary.notifications`：依 `target` 聚合提醒（`learning` / `schedule` / `billing`），避免同類提醒重複卡片。
- `POST /api/v1/parent/events`：家長端非阻塞 telemetry（記錄於 daily log `parent_adoption_event`）。
- 前端 `ParentPortal` 事件：`parent.dashboard_opened`、`parent.progress_card_clicked`、`parent.release_note_opened`、`parent.leave_submitted`、`parent.learning_feedback_submitted`。

---

## 修訂記錄

| 日期 | 變更 | 相關 PR |
|---|---|---|
| 2026-04-23 | 初版建立：Identity、Attendance、ClassSession、Swipe Flow、Gotchas | PR #23 |
| 2026-05-09 | 新增 Adoption v1.1：SLA 分級、每日摘要與週對比口徑 | - |
| 2026-05-09 | 主任雙檢視（focus/full）+ 家長 Progress Hub 與 `progress_summary` 摘要 | - |

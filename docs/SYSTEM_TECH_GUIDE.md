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
12. [2026-08-06 bug 群回顧：業界作法對照與已落地的架構加固](#12-2026-08-06-bug-群回顧業界作法對照與已落地的架構加固)
13. [「調課」與「備註 / 時段」按鈕混淆案：UX 措辭與新手引導的根因分析](#13-調課與備註--時段按鈕混淆案ux-措辭與新手引導的根因分析)

---

## 1. Identity & Auth 層

### 1.1 老師身份權威：User + UserCampus

```
User table       (id=17, Name='鄭翔祐', type='T', LoginName='...', LineID='...')
UserCampus table (UserID=17, CampusID=1, RFID='...')
```

**決策（2026-06-06）**：`Teacher` table 已退為 legacy/backfill 來源；runtime 老師資料不可再 join 或寫入 `Teacher` table。

**後果**：
- `StudentClass.TeacherID` / `StudentSingIn.TeacherID` / `TeacherSingIn.TeacherID` / `schedules.teacher_id` 存的是 `User.id`
- 老師姓名、手機、LINE ID 取 `User`
- 老師分校、RFID 取 `UserCampus`
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
- **比例扣堂範圍**：只對 `schedules.type='extra'` 補課且實際時長 ≠ 每堂分鐘（chokepoint `deductOnAttendance` → `resolvePartialMakeupMinutes`；剛好完整時長傳 `null`）。短於或長於契約的補課皆記實際分鐘；正常課堂一律整堂。禁止 clamp 回 perSession。
- **reverse 一致性**：`reverseForSession` 未指定 minutes 時，沖回對應 `deduct` 列的 `minutes`，避免淨值漂移。
- **讀取端守門**：`StudentClassController::index` 對 fractional 餘額不可用 count-based observed 覆寫（`hasFractionalBalance`），並回傳精確 `remaining_minutes`。
- **已知限制**：`PackageDeductionService` 池鏡像仍 `delta=±1` 整堂（TD-059）。
- **TD-060（已清償 2026-07-28）**：`ClassSessionController::recalculateSessionCounters` 曾是與 `SessionDeductionService::recomputeCounters` 並存的死碼（無 caller），已直接刪除；legacy `attended` 相容性已確認由權威引擎涵蓋。

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

### G-002：Teacher table 是 legacy archive / backfill source

**來源**：系統早期使用獨立 `Teacher` table，後遷移到 `User` / `UserCampus` 管理帳號、分校與 RFID。2026-06-06 Phase 2 後，runtime 不再依賴 `Teacher` table。

**強制規則**：
- 老師身份與姓名：`User.id` / `User.Name`
- 老師 LINE：`User.LineID`
- 老師分校與 RFID：`UserCampus.UserID` / `UserCampus.CampusID` / `UserCampus.RFID`
- `TeacherSingIn.TeacherID` 仍是 `User.id`，不代表 `Teacher.id`
- `Teacher` table 只可在 migration/one-time backfill 中讀取，不可在 controller/service/runtime 測試 fixture 中建立新依賴。

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
| 登入老師 | `User.id` | User | 詳見 §1.1 |
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

## 12. 2026-08-06 bug 群回顧：業界作法對照與已落地的架構加固

2026-08-06 一天內修的 6 個 in-app bug（#216–#221）＋ 1 個主任直接回報的架構問題（陳禹慈案，堂數超排），逐一對照後發現全部落在同一組、業界成熟工程團隊有明確對策的反模式（anti-pattern）之下。本節記錄對照結果與**已經落地**的加固，供之後改動這幾塊程式碼時參考，避免重蹈覆轍。

### 12.1 逐一對照

| Bug | 反模式 | 業界對策（做法名稱） | 本次落地 |
|---|---|---|---|
| #216 分校篩選失效 | 授權/多租戶範圍判斷寫死在單一 controller 分支，漏了 super_admin 帶 `campus_id` 的情境 | **集中式租戶範圍收斂**（single scoping choke point）——大型 SaaS（如 Salesforce 的 sharing rules、GitHub 的 org-scoped policy middleware）把「這個請求能看哪些租戶資料」收斂成單一、可測試的函式，禁止每個 endpoint 各自土法重寫 | `AdminDuplicateSessionController::effectiveCampusIds()`（PR #1638），把「有沒有帶 campus_id、是不是 super_admin」的判斷收斂成一個函式 |
| #217/#218 VoidReason 亂碼比對 | 業務關鍵字串（'一般請假'）在同一份程式碼裡被重複打了 6 次字面值，其中一份被手殘/編碼問題打壞就永久失配 | **消滅 magic string**（Google styleguide、Stripe 工程部落格都明確要求：跨檔案比對用的業務常數只能定義一次）——本專案自己在 `NON_BILLABLE_STATUSES` 已經示範過這個做法，但 VoidReason 字串沒跟上 | 新增 `CourseLeaveCascadeService::VOID_REASON_LEAVE` 常數，6 處使用點與 `LearningRecordResurrectionPolicy`（本身就在文件裡警告過「同一個決策被複製兩份，一份可能悄悄比較寬鬆」）全部改成引用同一個常數（本次） |
| #219 暫停/恢復未還原 | 狀態機只寫了「取消」半邊，沒對稱補「恢復」半邊 | **對稱狀態轉移**（symmetric state transitions）——凡是設計「A→B」的動作，狀態機設計規範要求同時交付「B→A」並各自測試，不能假設使用者不會走回頭路 | `StudentClassController::restorePauseCancelledSessions()`（PR #1639），resume 動作明確找回 pause 動作打的標記並還原 |
| #220/#221 行事曆快取整包覆蓋 | 前端把「切換週次抓到的新資料」直接覆蓋整個快取物件，而不是按 key 合併 | **正規化狀態存放 + 按 key 合併**（normalized store，Redux/Vuex 系官方文件的核心建議：集合類資料要用 id 當 key 存放，新資料進來是 merge 不是 replace） | `mergeSessionsByCourse()`（PR #1641，本來就是専案裡現成、之前沒被用到的函式，此次接上） |
| 陳禹慈案（堂數超排） | 同一條業務規則（「請假/取消後自動補一堂」）被兩支程式各自獨立重寫一份，兩份對「已計入堂數」的定義不同步 | **單一權威實作 / DRY**（Don't Repeat Yourself；金流/計費系統尤其嚴格——Stripe 的 idempotency + ledger 設計核心精神就是「任何會影響餘額的操作只能有一個入口」，不允許兩條路徑各自算一次） | `ClassSessionController::tryExtendOnLeave()` 改為純委派 `CourseLeaveCascadeService::appendTailAfterLeave()`，並把原本只存在其中一份的 Stop=1 防呆搬進共用服務（PR #1644） |

### 12.2 共通結論

六個 bug 沒有一個是「單純打錯字」層級的意外——每一個底下都對應到一個有名字、業界公認的反模式：**授權判斷分散**、**業務字串未收斂成常數**、**狀態機不對稱**、**集合資料整包覆蓋而非按 key 合併**、**同一條規則重複實作**。這組 bug 之所以能同一天集中冒出來，是因為這套系統早期為求快速迭代，同一類決策常常「先在 A 處理，需要時在 B 再抄一份」，而不是一開始就收斂成共用函式；只要新增功能繼續延用這個習慣，同一組反模式會不斷復發。

### 12.3 給之後改這幾塊的守則

- 新增任何「跨校區資料範圍判斷」，一律呼叫既有的 scoping 函式（如 `effectiveCampusIds()`／`allowedCampusIds()`），不要在 controller 裡另起一段。
- 任何會被**多處比對**的業務字串（狀態、原因代碼、note 標記），一律先定義成 class const，不寫第二次字面值。
- 設計任何「A 動作」時，先問「B（回復）動作要怎麼寫、誰負責測」，不要等使用者回報才補。
- 前端集合類資料（列表、月曆、快取）優先用「按 id/key 合併」，只有明確要清空重抓時才整包 replace。
- 同一條業務規則如果發現在兩支 controller/service 裡都有實作，**先合併成一份再繼續改**，不要兩邊分別修。

> 本節的 review 缺口描述截至 2026-08-29 前；現行 T2 PR 必須通過 `scripts/governance/autonomy_gate.py` 的 distinct current-head review 驗證。以下 duplicate-logic detector 的技術債仍未清償。

### 12.4 更深層的根因：為什麼是這裡、大公司又是靠什麼機制不常見

12.1 只回答了「每個 bug 對應哪個反模式」，這節回答更根本的問題：**為什麼這幾個反模式會在這個專案發生、卻不常見於成熟工程組織？**

先說清楚一個容易誤解的前提：**大公司工程師並不會少寫重複邏輯或 magic string**——這是任何規模的程式碼庫都會自然發生的事，跟寫程式的人是誰無關。真正的差異不在「有沒有寫出來」，而在**有沒有東西在合併前把它攔下來**。成熟組織通常同時具備三層攔截，這個專案目前三層都不完整：

1. **強制的第二人 code review（且審查者有跨域記憶）**。一個沒參與這次改動、但看過相鄰程式碼的人，天然會問「這個 `tryExtendOnLeave` 跟 `appendTailAfterLeave` 是不是同一件事？」——這正是 code review 存在的核心價值之一，不是抓格式，是抓「這是不是已經有人做過」。本段記錄的是 2026-08-29 前的 solo-mode review 缺口；現行 T2 PR 由 `scripts/governance/autonomy_gate.py` 驗證 distinct identity 對 current head SHA 的 `APPROVED` review。這仍不能自動偵測所有語意重複——PHPStan、Bugbot 也不是重複邏輯分析器——所以本節的 duplicate-logic 技術債仍成立。
2. **自動化的重複邏輯／magic string 偵測，且是合併門檻而非文件建議**。SonarQube、CodeClimate、`phpcpd`（PHP Copy/Paste Detector）等工具在成熟 CI pipeline 裡是標配，能在合併前直接標出「這段程式碼跟另一個檔案裡的某段高度相似」，把「請不要重複實作」從一句寫在文件裡容易被忘記的提醒，變成建置會失敗、人力無法略過的硬門檻。本專案目前完全沒有這一層——`phpstan-baseline.neon` 能防型別誤用，防不了兩支語意重複但寫法不同的函式。
3. **明確的領域歸屬（domain ownership）**。在有清楚模組 owner 的組織，「請假／排課」這類核心業務規則只有一個團隊有權改動，新人要改這塊必須先過那個團隊，重複實作在審查階段就會被「這是我們的地盤，你怎麼沒找我們」攔下來。本專案沒有這種邊界——任何一次修 bug 都可能直接在最近的 controller 裡加一段新邏輯，不會有人被動觸發「這裡是不是已經有人管」的警覺。

再往下一層看，這個專案還有一個更具體的結構性成因，值得誠實寫下來：**這個程式碼庫有很大比例的變更是由不同時間、彼此沒有上下文延續的 AI agent session 完成的**（`docs/AI_REGRESSION_LESSONS.md`、`CLAUDE.md` 龐大的 Gotchas 清單、以及本節本身，都是這個事實的直接證據——如果有一位長期跟案的資深工程師，這些「非直覺行為」多半會留在他腦子裡，不需要另外寫成文件）。每個 session 拿到的是「這次要修的那個 bug」與有限的既有文件，不是完整的、活的專案記憶；除非明確去搜尋，否則沒有天然管道知道「這個問題的另一半解法三個月前已經在別的檔案寫過了」。今天這兩個 PR（#1644 的重複實作、#1645 的字串複製）幾乎可以確定就是這個模式的直接產物：兩個看起來都合理、獨立存在的解法，只是分別誕生在不同時間點、沒有交叉檢查過。

**結論**：不是要「寫得更小心」——這句話對任何工程師、任何 AI agent 都成立但沒有操作性。真正能防住這一整類 bug 復發的，是把「找找看是不是已經有人做過」從*仰賴個人記性*的建議，變成*機器會擋下來*的門檻。已記錄為 `TD-073`（見 `docs/TECH_DEBT.md`），供後續排入清償。

### 12.5 同一天第三個實例：「已繳費」判斷沒有單一權威實作（R94）

12.4 寫完當天，同一組反模式又出現一次——這次落在金流顯示邏輯，比前兩次更能說明問題的規模。

**這次的 bug**：主任回報何昀佳的課程在課程管理頁面正確顯示「已繳費」，帳務中心卻仍列「未繳費」——課程已用帳單收款紀錄結清（`paid_amount === charge`），但 `AlertController::computePaymentStatus()` 只看 `StudentClass.Paid` 這個旗標，沒把「帳單足額收款」算進去。修好之後（PR #1648）盤點了一次「這個判斷到底在幾個地方被重寫」，結果比預期嚴重：

- `backend/app/Models/StudentClass.php`、`Invoice.php` 完全沒有 `isPaid()`／`isFullyPaid()` 這類集中的存取器或方法。
- 至少 **8 個檔案**各自獨立判斷「這筆課程是否已繳費」：`StudentClassController`（課程管理，`Paid==1` 或**任一筆**收款存在）、`AlertController` 自己內部另外兩處（`mapCountModeAlert`／`monthlyAlertRow`，只認 `Paid==1`，但這兩處是刻意保留給「列入提醒條件」用、依 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 明文規定不可與顯示用的 `payment_status` 混為一談）、`NotificationSyncService`、`DunningService`（明文凍結，需核准才能改）、`PaymentReportController`、`ParentPortalController`（同一個檔案內部就有三種寫法）、`NotificationController`、`AccountingController`、`SendTuitionReminders`。
- 光是「已繳費」這一個概念，至少存在 **4 種互不相同的變體**：`Paid` 旗標單一判斷／`Paid` 或任一筆收款（不論金額）／`Paid` 或足額收款／舊制 `Pay >= Charge` 欄位直接比較。四種聽起來都「合理」，但拿去互相比對就會像這次一樣互相打架。

**為什麼這正是大公司會有「單一權威實作」的地方**：Stripe 的 `Invoice.status`、Shopify 的 `Order.financial_status` 都是同一個模式——「這筆錢收了沒」是整個系統裡最常被查詢、也最常被拿來做決策（要不要發提醒、要不要允許續約、要不要擋点名）的欄位，所以絕不允許每個消費端各自從 raw 資料重新推導。做法通常是：這個判斷只活在 aggregate root（Invoice／Order 這個 model 本身）的一個方法或 computed 欄位上，其他任何 controller、service、報表，一律呼叫這個方法，不得自己重新拼一次條件。這不是「多此一舉的抽象」，而是**把一個金流正確性攸關的決策，鎖進唯一一個可以被單獨測試、單獨審查、單獨修正的地方**——出錯只可能錯一次，不會有「這裡改了那裡忘記改」的空間。

這個專案目前完全相反：8 個檔案各自從 `StudentClass.Paid`／`Invoice.PaidAmount`／`Charge` 這些原始欄位重新推導，等於同一個決策被獨立寫了 8 次、還各自加了不同的假設。這正是 TD-073 的論點在**同一天、第三次**得到驗證：不是特定一次 AI session 或特定一位工程師的疏漏，而是這個 codebase 目前沒有任何東西——不論人工 review 還是自動化門檻——會在合併前指出「這個判斷是不是已經在別的地方寫過」。

**本次已落地的收斂（範圍刻意限定）**：`AlertController.php` 內部原本也重複了兩次同一組條件（`computePaymentStatus()` 與 `computePackageCountPaymentStatus()`），本次一併抽成單一私有方法 `isFullyPaid()`，兩處呼叫同一份實作（同一 PR #1648）。**沒有**跨檔案把其餘 6+ 處也收斂成單一 model 方法——那是一次會橫跨通知、催繳、帳單、家長入口、報表等多個業務流程的變更，其中 `DunningService.php` 又被明文凍結需要產品方核准，貿然一次性大範圍改動金流判斷邏輯風險與變更範圍都超出本次回報的問題本身，故列為 TD-073 的具體子項，待明確排入清償時再由產品方逐一核准範圍後分批進行，而非未經確認一次全部重寫。

### 12.6 同一天第四個實例：把「資料剛好存在」當成「業務上該不該算」的隱性假設（in-app #222）

還是同一天，但這次的反模式跟 12.1–12.5 略有不同，值得獨立記一筆：**把「這筆資料目前有沒有」當成了「這件事該不該發生」的判斷依據**，而這兩者其實是兩個獨立的問題。

**bug 本身**：課程管理頁面的「預排」（未來會出現、但還沒真正建立成堂次的日期）計算，寫法上等於「先找出這次查詢範圍內*已經有實體堂次紀錄*的課程，再對這些課程去算未來還有哪些日期」——`array_keys($materializedByClass)` 直接當成了「要不要幫這門課算預排」的候選清單。一門剛排好、第一堂都還沒發生的新課程，因為範圍內**還沒有任何一筆歷史紀錄**，連候選資格都沒有，結果是完全不會顯示任何預排日期，跟這門課排課星期/時段本來該投影幾筆完全無關。

**為什麼這是「資料可得性」跟「業務資格」被誤接在一起**：這門課「有沒有歷史堂次」只是**這次剛好查到什麼**的技術副產物（今天資料庫裡有什麼），跟「這門課排課上是不是該有預排日期」是完全不同層次的問題（這門課的排課星期/時段設定決定它該不該有）。把前者拿來當後者的閘門，等於用「我今天剛好看到什麼」決定「這件事實際上存不存在」——大公司軟體（尤其是任何有 read-model／projection 概念的系統，例如行事曆類產品的「重複事件展開」邏輯）會把「這個實體有哪些應該存在的未來 occurrence」設計成完全獨立於「目前資料庫裡已經寫了哪些列」的純函式（輸入是排課規則 + 日期範圍，不是「目前查詢剛好抓到誰」），確保 empty result 語意上等於「這個範圍內沒有任何一筆」，而不是「這個實體今天剛好沒被抓進候選清單」。兩者在程式碼上看起來很像（都回傳空陣列），語意上完全不同，這正是本次 bug 沒有測試涵蓋、也沒有被發現的原因。

**修法**：候選課程清單改用「請求方明確要查的課程 ID」（`student_class_id`/`student_class_ids`）聯集「已有歷史紀錄的課程」，不再讓「有沒有歷史紀錄」單獨決定資格。

**測試**：`SessionProjectionSplitTest::test_class_sessions_index_projects_course_with_no_materialized_rows_in_range`——刻意建一門「零歷史堂次、但排課規則本該投影出多筆未來日期」的課程，鎖住這個語意分界。

---

## 13. 「調課」與「備註 / 時段」按鈕混淆案：UX 措辭與新手引導的根因分析

2026-08-06 同一天，主任回報（非 in-app 工單，直接對話）：興隆分校一名學生原本星期六 13:00–15:00 的課，想改成星期四 15:30–17:30，主任操作「改不過去」，但 CEO 自己操作卻成功。這不是後端邏輯錯誤——同一組後端 API（調課）在兩人手上行為完全一致，差異純粹發生在「使用者點了哪個按鈕」。

### 13.1 根因：兩個名稱相近、能力完全不同的按鈕擺在一起

課程管理的「單堂操作」選單裡，同一個按鈕格線中並排放著：

| 按鈕（修復前） | 實際能力 | 資料庫欄位 |
|---|---|---|
| 「調課」 | 換到**任何**日期＋任何時段，原堂次紀錄保留、自動同步點名／評量 | 走 `commitReschedule`，接受 `new_date` |
| 「備註 / 時段」 | **只能**調整**同一天**的上課時間或加備註 | 走 `PATCH /class-sessions/{id}`，驗證規則裡完全沒有 `session_date` 這個欄位，物理上不可能換日期 |

兩個按鈕視覺樣式接近（同樣大小、同樣灰階邊框），文字都圍繞「時間／時段」，沒有任何提示告訴使用者「換日期」只有其中一個做得到。若使用者想「星期六改星期四」卻點了「備註 / 時段」，會打開一個**完全沒有日期欄位**的表單——連嘗試都無從嘗試，很容易就放棄並回報「改不過去」，而不會意識到自己點錯了按鈕（因為畫面上完全沒有跡象顯示「這裡不能換日期，請去別的地方」）。

### 13.2 大公司怎麼避免這類問題：這是 UX writing／內容設計的專業分工，不是工程師順手能顧到的

**先破除一個常見誤解**：不是大公司的工程師比較會取名字。差異在於成熟產品團隊把「這個按鈕該怎麼命名、使用者會不會誤解」當成一個**有專人負責、有審查流程的獨立工作**（UX writer / content designer），而不是工程師寫完功能後隨手打兩個字。Google Calendar、Calendly 這類行事曆產品處理「移動一個事件」時，一律只有**一個**入口能同時改日期跟時間（拖曳或單一「編輯」面板，日期欄位永遠可見），不會把「換日期」跟「同一天內調整時間」拆成兩個並排、視覺分不出主次的按鈕——因為使用者的心智模型裡，「調整這堂課的時間」本來就沒有「只能同一天」這種隱性限制，若功能上真有限制，一定要在使用者做選擇**之前**就用文字或圖示講清楚（Nielsen Norman 十大可用性原則之一：「Recognition rather than recall」——讓系統把差異攤在眼前，不要靠使用者自己記得或猜測）。

**本專案原本違反這條原則**：「備註 / 時段」這個名稱本身就沒有排除「時段＝哪一天的哪個時段」這個合理誤讀；兩個按鈕又沒有任何 tooltip、說明文字或視覺區隔去補這個語意落差。

### 13.3 本次修法（範圍刻意限定在文案與提示，不改後端行為）

- 按鈕文字明確化：「調課」→「🔄 調課（換日期）」；「備註 / 時段」→「備註 / 當天時段」。
- 兩個按鈕都加上 `title` tooltip，明講各自「能不能換日期」。
- 選單下方加一行提示文字，直接告訴使用者該按哪一個。
- 「調課」改用品牌主色系（`--primary`）取代原本的中性灰階，讓它在視覺上不輸旁邊的「備註 / 時段」（原本反而是「備註 / 時段」用了較搶眼的綠色）。

**沒有做的事**：沒有合併兩個按鈕成單一表單（那是更大範圍的互動重設計，需要重新設計「單堂操作」選單的資訊架構，風險與範圍都超出本次回報範圍）；沒有幫「備註 / 時段」加上日期欄位（那等於讓它變成第二套調課邏輯，會重新製造 12.1–12.6 那種「同一件事兩份實作」的問題）。文案與提示是能立即、低風險止血的第一步；若之後要做，應該是「拿掉『備註 / 時段』單獨存在的必要性，統一併入調課流程」，而不是讓兩條路徑並存並各自補強。

### 13.4 為什麼這類回報會一直找到人工修復，而不是使用者自己排除

主任本人完全沒有做錯任何事——她精確描述了操作跟結果（「13:00-15:00 改星期四 15:30-17:30，改不過去」），問題出在系統沒有給她足夠線索去發現「按錯按鈕」這個事實本身。這正是「沒有新手教學」與「UX 措辭不清」共同放大的結果：對一個第一次遇到這個選單的人來說，兩個按鈕看起來都合理，選錯了也沒有任何回饋告訴她「妳可能要找別的按鈕」，唯一的訊號就是「日期改不了」，但她沒有工具去判斷這是系統限制還是自己操作錯誤。修好措辭與提示後，這類「看起來像 bug、其實是選錯入口」的回報才有機會在使用者自己嘗試的當下就被解決，不必每次都升級成工程調查。

---

## 修訂記錄

| 日期 | 變更 | 相關 PR |
|---|---|---|
| 2026-04-23 | 初版建立：Identity、Attendance、ClassSession、Swipe Flow、Gotchas | PR #23 |
| 2026-05-09 | 新增 Adoption v1.1：SLA 分級、每日摘要與週對比口徑 | - |
| 2026-05-09 | 主任雙檢視（focus/full）+ 家長 Progress Hub 與 `progress_summary` 摘要 | - |
| 2026-08-06 | 新增第 12 節：6 個 in-app bug + 陳禹慈堂數超排案的業界作法對照與加固守則 | PR #1638/#1639/#1640/#1641/#1644 |
| 2026-08-06 | 第 12 節新增 12.4 更深層根因分析（為什麼是這裡、大公司靠什麼機制避免），並登記 TD-073 | PR #1646 |
| 2026-08-06 | 第 12 節新增 12.5：同一天第三個「重複實作」實例（帳務中心繳費狀態 R94），盤點出 8 個檔案、4 種變體重複判斷「已繳費」，TD-073 調升為 P1 | PR #1648 |
| 2026-08-06 | 第 12 節新增 12.6：同一天第四個實例（in-app #222，「資料可得性」誤當「業務資格」判斷） | PR #1651 |
| 2026-08-06 | 新增第 13 節：「調課」／「備註 / 時段」按鈕混淆案的 UX 措辭根因分析 | PR #1652 |

# [ARCH] 老師出缺勤打卡整合 — 技術設計文件

- 版本：v1.0
- 狀態：✅ 完成，等待 Q1/Q2 決策批准
- 日期：2026-04-23
- 關聯 PRD：[`phase1_teacher_attendance_integration_prd_2026-04-23.md`](./phase1_teacher_attendance_integration_prd_2026-04-23.md)

---

## 📊 資料庫變更

### 1. 修改現有表：`TeacherSingIn`

**現有欄位**（確認自 migration）：
`id, TeacherID, CampusID, SignInDT, SignOutDT(nullable), MDT`

**新增欄位：**

| 欄位 | 型別 | Default | 說明 |
|---|---|---|---|
| `Source` | `enum('rfid','manual')` | `'rfid'` | 打卡來源 |
| `Status` | `enum('normal','late','early_leave','missed','adjusted','pending_review','source_only')` | `'pending_review'` | 異常狀態，RFID 寫入時立即計算 |

**Migration 檔名**：`2026_04_23_100000_add_source_status_to_teacher_sing_in.php`
- `up()`：`Schema::table('TeacherSingIn', fn($t) => $t->enum(...)->default(...))`
- `down()`：`dropColumn(['Source', 'Status'])`
- 兩欄位皆有 default，不造成全表 lock；建議非尖峰時段（下課後）執行

---

### 2. 新增審計表：`teacher_signin_adjustments`

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | bigint PK autoincrement | |
| `teacher_signin_id` | bigint not null | FK → `TeacherSingIn.id` |
| `adjusted_by_user_id` | int not null | FK → `User.id`（操作者） |
| `adjust_reason` | text not null | 補卡原因（必填，不可空） |
| `original_signin_dt` | datetime not null | 原始簽到時間快照 |
| `original_signout_dt` | datetime nullable | 原始簽退時間快照 |
| `new_signin_dt` | datetime not null | 補正後簽到時間 |
| `new_signout_dt` | datetime nullable | 補正後簽退時間 |
| `created_at` | datetime | 補卡操作時間 |

> ⚠️ 此表為 **append-only**，無 `updated_at`，不允許 UPDATE/DELETE。

**Migration 檔名**：`2026_04_23_100001_create_teacher_signin_adjustments_table.php`
- `down()`：`Schema::dropIfExists('teacher_signin_adjustments')`

**索引設計：**
| 表 | 索引欄位 | 用途 |
|---|---|---|
| `TeacherSingIn` | `(CampusID, SignInDT)` composite | 主任日期+分校過濾 |
| `TeacherSingIn` | `(TeacherID, SignInDT)` composite | 老師自查 |
| `teacher_signin_adjustments` | `(teacher_signin_id)` | JOIN 效能 |

---

## 🔌 API 合約

### Controller：新增 `TeacherAttendanceController`
路徑：`app/Http/Controllers/TeacherAttendanceController.php`

所有路由掛在現有 `role:director,teacher` + `require_campus` + `require_password_change` middleware 群組下。

---

### A. 老師今日打卡狀態（teacher + director 均可）

```
GET /api/v1/teacher-attendance/today
Middleware: role:director,teacher + require_campus
```

Query Params：
- `teacher_id` int — 老師查自己時省略（JWT 自動帶）；director 查特定老師時傳入

Response 200：
```json
{
  "date": "2026-04-23",
  "teacher_id": 5,
  "teacher_name": "王小明",
  "sign_in_dt": "2026-04-23 08:55:00",
  "sign_out_dt": null,
  "status": "normal",
  "source": "rfid",
  "first_class_start_time": "09:00",
  "late_threshold_minutes": 10
}
```

---

### B. 主任打卡總覽（director 限定）

```
GET /api/v1/teacher-attendance
Middleware: role:director + require_campus
```

Query Params：
- `date` YYYY-MM-DD（預設今日）
- `teacher_id` int（選填）
- `status` string（選填，過濾狀態）
- `page` int（每頁 50）

Response 200：
```json
{
  "data": [
    {
      "id": 42,
      "teacher_id": 5,
      "teacher_name": "王小明",
      "campus_id": 2,
      "sign_in_dt": "2026-04-23 08:55:00",
      "sign_out_dt": "2026-04-23 18:30:00",
      "status": "normal",
      "source": "rfid",
      "first_class_start_time": "09:00",
      "latest_adjustment": null
    }
  ],
  "meta": { "total": 8, "per_page": 50, "current_page": 1 }
}
```

---

### C. 補卡（director 限定）

```
POST /api/v1/teacher-attendance/{id}/adjust
Middleware: role:director + require_campus
```

Request Body：
```json
{
  "new_signin_dt": "2026-04-23 09:05:00",
  "new_signout_dt": "2026-04-23 18:00:00",
  "adjust_reason": "RFID 未感應，人已到場（主任確認）"
}
```

Response 200：
```json
{
  "ok": true,
  "signin_id": 42,
  "adjustment_id": 7,
  "new_status": "adjusted"
}
```

Error Cases：
- `adjust_reason` 空 → 422
- 操作者非所屬分校 → 403
- 嘗試直接修改主表原始欄位 → 405（API 設計上不提供此操作）

> ⚠️ 主表 `SignInDT`/`SignOutDT` **不修改**；僅在 `teacher_signin_adjustments` 新增審計列，並更新 `Status = 'adjusted'`

---

### D. 每日結班未簽退清單（director 限定）

```
GET /api/v1/teacher-attendance/unclosed
Middleware: role:director + require_campus
```

Query Params：
- `date` YYYY-MM-DD（預設今日）
- `cutoff_time` HH:mm（預設 20:00）

Response 200：
```json
{
  "data": [
    {
      "teacher_id": 3,
      "teacher_name": "陳老師",
      "sign_in_dt": "2026-04-23 09:10:00",
      "sign_out_dt": null
    }
  ]
}
```

---

### E. 匯出（director 限定）

```
GET /api/v1/teacher-attendance/export
Middleware: role:director + require_campus
```

Query Params：
- `date_from`, `date_to` YYYY-MM-DD
- `format` csv|json（預設 csv）

Response：CSV 串流，`Content-Disposition: attachment; filename="teacher-attendance-{date_from}-{date_to}.csv"`

---

### F. 修改現有：`SwipeRfidController::handleTeacherSwipe()`

**修改範圍**：簽到寫入後，立即計算並寫入 `Source` 和 `Status`。簽退不重新判定異常。

**異常判定流程**：
```
查 schedules where teacher_id = $teacher->id
              AND schedule_date = today
              AND status != 'cancelled'
ORDER BY start_time ASC LIMIT 1

若無排課      → Status = 'source_only'
若有排課：
  SignInDT <= first_class.start_time + 10 分鐘 → Status = 'normal'
  SignInDT >  first_class.start_time + 10 分鐘 → Status = 'late'

Source 永遠 = 'rfid'
```

**防禦機制**：整個判定包在 try/catch 中，異常判定失敗只 log，**不中斷打卡寫入**（fallback `Status = 'pending_review'`）。

Breaking Change：**無**（原有 response 結構不變，只新增欄位值）

---

## 🖥️ 前端元件規劃

### 1. `AttendancePage.vue`（修改）

**新增：Tab 切換列**
- 位置：`att-stats` 區塊之前（頁面 header 下方）
- 狀態管理：`activeTab` ref — `'student' | 'teacher'`
- 預設：director 預設 `'student'`
- 權限：`isTeacher === true` 時不顯示 teacher tab（老師透過 `TeacherHomePage` 自查）
- 切換行為：不重置 `branchId`；切換時清空對方 tab 的列表暫存

**Teacher Tab 內容（`v-if="activeTab === 'teacher'"`）：**
1. 今日統計卡片：已打卡人數 / 遲到人數 / 漏刷人數
2. 異常待處理清單（卡片式列表，每張有「補卡」按鈕）
3. 完整打卡記錄列表（table：老師 / 簽到 / 簽退 / 狀態 / 第一堂課 / 操作）
4. 補卡 Modal（表單：新簽到時間、新簽退時間、原因文字方塊）

---

### 2. `TeacherHomePage.vue`（修改）

**新增：今日打卡狀態卡片**
- 位置：現有「今日待辦」卡片之上（最頂端）
- 資料來源：`GET /api/v1/teacher-attendance/today`（onMounted 撈取）
- 顯示邏輯：

| 狀態 | 顯示內容 |
|---|---|
| 已簽到 + 已簽退 | 綠色 badge「已完成」+ 簽到/簽退時間 |
| 已簽到 + 未簽退 | 藍色 badge「上班中」+ 簽到時間 + 遲到提示（若 `status === 'late'`） |
| 未打卡（無記錄） | 橘色 badge「尚未打卡」+ 警示文字 |

- 點擊行為：`$emit('navigate', 'attendance')`（沿用現有無 Router 切頁機制）

---

### 3. 新增獨立元件

| 元件名 | 用途 | Props |
|---|---|---|
| `TeacherAttendanceTable.vue` | 打卡記錄 table（含分頁） | `records`, `loading`, `branchId` |
| `TeacherAdjustModal.vue` | 補卡 Modal | `record`, `visible`，emit：`submit`, `close` |

---

## 🔗 模組依賴圖

```
TeacherAttendanceController
  ├── TeacherSignIn (Model) — 查詢/Status更新
  ├── TeacherSignInAdjustment (新 Model) — 審計寫入
  ├── schedules (查詢第一堂課時間)
  ├── User (調整者姓名)
  └── middleware: role:director,teacher + require_campus

SwipeRfidController::handleTeacherSwipe (修改)
  ├── TeacherSignIn (Model) — 新增 Source/Status 寫入
  └── schedules (異常判定)

AttendancePage.vue (修改)
  ├── GET /api/v1/teacher-attendance (主任總覽)
  ├── POST /api/v1/teacher-attendance/{id}/adjust (補卡)
  └── branchId prop 不重置

TeacherHomePage.vue (修改)
  └── GET /api/v1/teacher-attendance/today (老師自查)
```

---

## ⚠️ 待決設計問題（需使用者回答後才能批准）

### Q1：`early_leave`（早退）判定時機 — ✅ 確定：B（本期不自動判定）

本期不自動判定早退。`early_leave` 保留為主任手動補卡時可選的狀態。
原因：避免補課/調課時誤判；MVP 先保守。

### Q2：老師能否查看其他老師打卡狀態 — ✅ 確定：A（只能看自己）

`GET /api/v1/teacher-attendance/today` 的 `teacher_id` 從 JWT 自動取得，老師不可覆寫。
主任才有 index/unclosed/export 等跨人員 API。符合最小權限原則。

---

## 📋 DEV 實作順序

```
Step 1  後端 Migration
        ├── 2026_04_23_100000_add_source_status_to_teacher_sing_in.php
        └── 2026_04_23_100001_create_teacher_signin_adjustments_table.php

Step 2  後端 Model
        ├── 更新 TeacherSignIn：新增 Source/Status 至 $fillable
        └── 新增 TeacherSignInAdjustment Model（protected $table, $fillable）

Step 3  後端 TeacherAttendanceController
        ├── today()   — GET /api/v1/teacher-attendance/today
        ├── index()   — GET /api/v1/teacher-attendance
        ├── adjust()  — POST /api/v1/teacher-attendance/{id}/adjust
        ├── unclosed() — GET /api/v1/teacher-attendance/unclosed
        └── export()  — GET /api/v1/teacher-attendance/export

Step 4  後端 SwipeRfidController 修改
        └── handleTeacherSwipe() 加入 Source/Status 計算（含 try/catch）

Step 5  後端 Routes（api.php）
        └── 在 role:director,teacher 群組新增 5 條路由

Step 6  前端 TeacherHomePage.vue
        └── 新增今日打卡狀態卡片

Step 7  前端 AttendancePage.vue
        └── 新增 Tab 切換 + Teacher Tab 內容區塊

Step 8  前端新元件
        ├── TeacherAttendanceTable.vue
        └── TeacherAdjustModal.vue
```

---

## 回歸影響評估

| 現有功能 | 影響 |
|---|---|
| 學生 RFID 刷卡 `swipe-rfid` | 無（`handleStudentSwipe` 不動） |
| 學生出缺勤 `AttendancePage` | 無（只新增 tab，舊邏輯原封不動） |
| `ProfileController` 中 `TeacherSingIn` 刪除 | 無（新欄位有 default，不影響） |
| 多校區隔離 | 已確認：所有新 API 均帶 `CampusID`/`require_campus` |

---

> ✅ ARCH 完成。請回答 **Q1**（早退判定）和 **Q2**（老師視角）後批准，即可進入 Phase 2b [UX] + Phase 2c [DBA] 並行執行。

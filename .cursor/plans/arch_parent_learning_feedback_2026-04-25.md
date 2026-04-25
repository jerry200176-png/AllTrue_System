# [ARCH] 家長評量回饋功能技術設計

## 1. 背景與現況

對應 PRD：`.cursor/plans/parent_learning_feedback_prd_2026-04-25.md`

本次功能讓家長在 ParentPortal / LINE 入口看完已核准學習評量後，能對單筆評量留下文字回饋；老師與主任可查看。

### 已確認現有架構

| 項目 | 現況 |
|---|---|
| 家長登入 | `ParentPortalController::login()` / `loginWithLine()` 建立 `ParentSession` |
| ParentSession | PascalCase 表 `ParentSession`，欄位 `StudentID`, `TokenHash`, `ExpiresAt` |
| 家長 dashboard | `GET /api/v1/parent/dashboard`，用 Bearer parent token，自行 `resolveSession()` |
| 家長可見評量 | Parent dashboard 只回 `LearningRecord::active()->where(Status=approved)` |
| 評量核心表 | PascalCase `LearningRecord`，已有 `StudentClassID`, `ClassSessionID`, `TeacherID`, `Status`, `VoidedAt` |
| 老師查評量 | `LearningRecordController::index()` 以 `auth_teacher_id` / 代課條件 scope |
| 主任查評量 | `require_campus` + `auth_campus_ids` / `branch_id` 過濾學生課程 |
| 前端家長頁 | `ParentPortal.vue` 以 expand card 顯示 `learning_records` |

## 2. 設計原則

1. 不修改 `LearningRecord` 核心審核 / 扣堂 / 狀態欄位。
2. 新增回饋資料表保存家長文字，降低對評量主流程影響。
3. Parent API 不依賴前端傳入 `StudentID` 判斷所有權，後端以 `ParentSession.StudentID` 查證。
4. 老師查詢必走 `TeacherID` scope；主任查詢必走 `CampusID` scope。
5. 回饋內容不進 URL query、一般 log、console log。

## 3. DB Schema

### 新表：`learning_record_feedbacks`

使用 snake_case 新表；避免新增 PascalCase 歷史表。

| 欄位 | 型別 | Null | 說明 |
|---|---:|---|---|
| `id` | bigint unsigned PK | No | 主鍵 |
| `learning_record_id` | bigint unsigned | No | 對應 `LearningRecord.id` |
| `student_id` | bigint unsigned | No | 快取學生 ID，供 ownership / 查詢 |
| `student_class_id` | bigint unsigned | No | 快取 `LearningRecord.StudentClassID` |
| `class_session_id` | bigint unsigned nullable | Yes | 快取 `LearningRecord.ClassSessionID` |
| `teacher_id` | bigint unsigned | No | 快取 `LearningRecord.TeacherID` |
| `campus_id` | bigint unsigned | No | 快取 `Student.CampusID`，主任查詢隔離 |
| `content` | text | No | 家長回饋內容，後端 trim 後 1-500 字 |
| `parent_session_id` | bigint unsigned nullable | Yes | 建立 / 最後更新所用 parent session |
| `last_read_by_teacher_at` | datetime nullable | Yes | 老師讀取時間 |
| `last_read_by_director_at` | datetime nullable | Yes | 主任讀取時間 |
| `created_at` | timestamp | No | 建立時間 |
| `updated_at` | timestamp | No | 更新時間 |

### Unique / Index

| 名稱 | 欄位 | 用途 |
|---|---|---|
| `lrf_learning_record_unique` | `learning_record_id` unique | v1 一筆評量只保留一筆家長回饋 |
| `lrf_student_idx` | `student_id`, `created_at` | 家長 dashboard / ownership 輔助 |
| `lrf_teacher_unread_idx` | `teacher_id`, `last_read_by_teacher_at`, `updated_at` | 老師未讀提示 / 列表 |
| `lrf_campus_unread_idx` | `campus_id`, `last_read_by_director_at`, `updated_at` | 主任未讀 / 分校列表 |
| `lrf_record_idx` | `learning_record_id` | join / 單筆查詢 |

### Migration 安全

- `up()` 只 create new table，不改既有表、不回填、不觸碰 production data。
- `down()` 僅 drop `learning_record_feedbacks` 新表。
- 不執行 `php artisan migrate`；只在 PR merge 後由合法 deploy workflow / OPS 流程處理。

## 4. Model / 關聯

### 新 Model：`LearningRecordFeedback`

| 屬性 | 設計 |
|---|---|
| table | `learning_record_feedbacks` |
| fillable | 不開放由 request 全量 mass assign；Controller 白名單填入 |
| relations | `learningRecord`, `student`, `studentClass`, `teacher` |

### LearningRecord 關聯

- 新增 `feedback()` hasOne `LearningRecordFeedback`。
- `LearningRecordController::decorateRecords()` 可在後續實作中選擇 eager load feedback summary，避免 N+1。

## 5. API 合約

### 5.1 Parent：讀單筆回饋

`GET /api/v1/parent/learning-records/{learningRecord}/feedback`

Auth:
- Bearer parent token，沿用 `ParentPortalController::resolveSession()`。

Authorization:
- `ParentSession.StudentID` 必須對應該 `LearningRecord` 的學生。
- `LearningRecord.Status = approved`。
- `LearningRecord.VoidedAt IS NULL`。

Response 200:

```json
{
  "feedback": {
    "id": 123,
    "learning_record_id": 456,
    "content": "老師您好...",
    "created_at": "2026-04-25T14:00:00+08:00",
    "updated_at": "2026-04-25T14:05:00+08:00"
  }
}
```

無回饋時：

```json
{ "feedback": null }
```

### 5.2 Parent：建立 / 更新回饋

`PUT /api/v1/parent/learning-records/{learningRecord}/feedback`

Request:

```json
{
  "content": "老師您好，想請問..."
}
```

Validation:
- `content`: required string, trim 後 1-500 chars。

Authorization:
- 同 5.1。

Behavior:
- `updateOrCreate` by `learning_record_id`。
- 更新時覆蓋 content，保留同一筆有效回饋。
- 寫入 `student_id`, `student_class_id`, `class_session_id`, `teacher_id`, `campus_id` 快取欄位。
- 更新後將 `last_read_by_teacher_at` / `last_read_by_director_at` 設回 null，代表新內容未讀。

Response 200/201:

```json
{
  "feedback": {
    "id": 123,
    "learning_record_id": 456,
    "content": "老師您好，想請問...",
    "created_at": "2026-04-25T14:00:00+08:00",
    "updated_at": "2026-04-25T14:05:00+08:00"
  },
  "message": "已送出給老師"
}
```

### 5.3 Teacher / Director：列表

`GET /api/v1/learning-record-feedbacks`

Middleware:
- `role:director,teacher`
- `require_campus`
- `require_password_change`

Query:

| 參數 | 說明 |
|---|---|
| `branch_id` | director/admin 可指定分校；teacher 忽略或僅輔助 |
| `student_name` | 模糊搜尋 |
| `teacher_id` | director/admin 篩選老師 |
| `unread` | `teacher` / `director` / `any` |
| `start_date`, `end_date` | 依 feedback updated_at 或 LearningRecord.SessionDate；實作前需固定為一種 |
| `per_page` | 預設 50，上限 100 |

Authorization:
- Teacher：`teacher_id = auth_teacher_id`。
- Director/Admin：`campus_id IN auth_campus_ids`；若指定 `branch_id` 必須可存取。
- Super admin：可依 branch_id 查；若未指定，需限制 per_page 並避免全表掃描。

Response:

```json
{
  "data": [
    {
      "id": 123,
      "learning_record_id": 456,
      "student_id": 10,
      "student_name": "王小明",
      "teacher_id": 8,
      "teacher_name": "芝琳老師",
      "campus_id": 1,
      "campus_name": "大安",
      "session_date": "2026-04-24",
      "subject": "英文",
      "content": "謝謝老師...",
      "updated_at": "2026-04-25T14:05:00+08:00",
      "unread_for_teacher": true,
      "unread_for_director": true
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 50,
    "total": 1
  }
}
```

### 5.4 Teacher / Director：標記已讀

`POST /api/v1/learning-record-feedbacks/{feedback}/read`

Behavior:
- Teacher：僅可標記 `teacher_id = auth_teacher_id` 的 feedback，寫 `last_read_by_teacher_at`。
- Director/Admin：僅可標記自己可存取 campus 的 feedback，寫 `last_read_by_director_at`。
- 可由打開詳情時自動呼叫。

## 6. Controller 邊界

建議新增 `LearningRecordFeedbackController`，避免擴大 `LearningRecordController`。

| 方法 | 使用者 | 職責 |
|---|---|---|
| `parentShow` | parent | 單筆評量回饋讀取 |
| `parentUpsert` | parent | 建立 / 更新回饋 |
| `index` | teacher/director | 列表與篩選 |
| `markRead` | teacher/director | 標記已讀 |

共用 private helpers:
- `resolveParentSession(Request)`：可先從 `ParentPortalController` 複製 / 提取共用 service；ARCH 建議在 DEV 時用最小方式，避免大重構。
- `assertParentOwnsLearningRecord(ParentSession, LearningRecord)`。
- `scopeFeedbacksForStaff(Request)`。

## 7. 權限與多校區隔離

### Parent Ownership

驗證鏈：

1. Authorization Bearer token -> `ParentSession.TokenHash`。
2. `ExpiresAt > now()`。
3. `LearningRecord.active()` + `Status=approved`。
4. `LearningRecord.StudentClassID` -> `StudentClass.StudentID`。
5. `StudentClass.StudentID === ParentSession.StudentID`。
6. 若未來支援 LINE 多學生切換，家長需透過 `parent/switch-student` 取得對應學生 token；不要讓同一 token 任意跨學生。

### Teacher Scope

- `teacher_id = auth_teacher_id`。
- v1 不把代課老師 feedback scope 擴大到 schedules；理由：家長評量回饋對應已核准 `LearningRecord.TeacherID`，責任老師是 record teacher。
- 若業務要求代課老師也看到，需另開 v1.1 設計。

### Director / Admin Scope

- `campus_id` 必須在 `auth_campus_ids`。
- 指定 `branch_id` 時先驗證是否可存取。
- 未指定 branch 時，director/admin 查自己所有 campus；super_admin 若未指定 branch 可查全部但必須分頁。

## 8. 前端元件規劃

### `ParentPortal.vue`

在 learning record expanded detail 內新增：

- feedback display card。
- textarea（未送出 / 編輯）。
- 字數提示 0/500。
- 送出 / 更新按鈕。
- loading / error / success 狀態。

資料策略：
- 初版可把 feedback summary 併入 `parent/dashboard` 的 `learning_records`，避免每張卡 expand 後再打 API。
- 但送出/更新仍使用獨立 `PUT /parent/learning-records/{id}/feedback`。
- 若要降低 dashboard payload，可改為 expand 時 lazy load；v1 建議併入，評量分頁每頁最多 10 筆，payload 可控。

### `frontend/src/api.js`

新增：
- `getParentLearningRecordFeedback(token, learningRecordId)`
- `upsertParentLearningRecordFeedback(token, learningRecordId, content)`
- `getLearningRecordFeedbacks(token, params)`
- `markLearningRecordFeedbackRead(token, feedbackId)`

### `LearningRecordsPage.vue`

MVP：
- 在 record list / detail 顯示 `parent_feedback` 摘要與未讀 badge。
- 點開 detail 顯示完整內容。
- Teacher 打開 detail 後標記 teacher read。
- Director 打開 detail 後標記 director read。

Director 進階列表：
- 可先放在 `LearningRecordsPage` 篩選區新增「只看有家長回饋 / 未讀」。
- 不建新 page，避免導航與權限擴張。

## 9. 測試設計

### Feature Tests

| 測試 | 期望 |
|---|---|
| parent can upsert feedback for own approved learning record | 200/201，DB 一筆 |
| parent cannot upsert feedback for other student | 403 |
| parent cannot upsert feedback for pending record | 403 或 409 |
| expired parent session rejected | 401 |
| content required and <= 500 chars | 422 |
| teacher can list own feedbacks | 200，包含 own，不含 other teacher |
| director can list campus feedbacks | 200，包含 same campus，不含 other campus |
| director branch_id outside scope rejected | 403 |
| mark read updates correct read column only | teacher/director 各自欄位 |

### Test Data 注意

- `Campus` 使用 factory，避免 NOT NULL 欄位踩雷。
- `Student` 必填 `name`, `CampusID`, `SchoolName`。
- `StudentClass` 必填 `StudentID`, `TeacherID`, `GradeID`, `SubjectID`, `Rate`。
- 今日日期若涉及 future session，`start_time` 用 `23:00`；本功能可使用過去日期避免 timing 問題。

## 10. 風險與取捨

| 風險 | 處理 |
|---|---|
| Parent endpoint 是 public route 但用 Bearer token 自管 session | 沿用既有 parent/dashboard 模式；每支 feedback parent API 必須先 resolveSession |
| 回饋內容進入 log | Controller 不 log content；錯誤只 log ids / status |
| 多家長同學生情境 | v1 一筆評量一筆回饋；後送覆蓋更新，降低複雜度 |
| Teacher 代課 scope | v1 只依 LearningRecord.TeacherID；另需業務批准才擴大 |
| Dashboard payload 增加 | 每頁 10 筆評量，併入 feedback summary 可接受；必要時改 lazy load |

## 11. 設計問題 Q&A

### Q1. 為什麼不直接在 `LearningRecord` 加 `ParentFeedback` 欄位？
因為回饋有讀取狀態、更新時間、權限查詢與索引需求；新表較不影響評量審核 / 扣堂核心流程。

### Q2. 家長可以留多筆嗎？
v1 不做。每筆評量一筆可更新回饋，避免變成聊天與 spam。

### Q3. 老師可以回覆嗎？
v1 不做。這會變成雙向客服/訊息系統，需通知與 moderation 設計。

### Q4. 主任需要獨立頁嗎？
v1 建議先整合在 `LearningRecordsPage`，用 filter / badge 解決。若回饋量變大再獨立頁。

### Q5. 是否需要 LINE 推播？
v1 不做主動推播。家長本來是在 LINE / ParentPortal 情境中查看與送出；老師端先用系統內未讀提示。

## 12. Phase 2 Exit Checklist

- [x] DB 異動清單：新增 `learning_record_feedbacks`，不改既有表。
- [x] API 合約：parent show/upsert，staff index/read。
- [x] 前端元件規劃：ParentPortal + LearningRecordsPage + api.js。
- [x] 多校區隔離：`campus_id` 快取 + `auth_campus_ids` scope。
- [x] 高風險邏輯標記：parent auth / PII / teacher scope / director scope。
- [x] 測試重點：parent ownership、teacher scope、director branch isolation。

等待使用者批准進入下一 Phase：`[UX]`（前端 UI 規格細化）與 `[DBA]`（migration 設計確認），再進 `[DEV]`。

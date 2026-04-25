# [DBA] 家長評量回饋 DB / Migration 設計

對應文件：
- PRD：`.cursor/plans/parent_learning_feedback_prd_2026-04-25.md`
- ARCH：`.cursor/plans/arch_parent_learning_feedback_2026-04-25.md`
- UX：`.cursor/plans/ux_parent_learning_feedback_2026-04-25.md`

## 1. DBA 結論

本功能需要新增一張表 `learning_record_feedbacks`。

不修改既有 `LearningRecord` / `Student` / `StudentClass` / `ParentSession` 欄位。

DB 風險等級：中低。

原因：
- 只新增新表。
- 無 backfill。
- 無資料搬移。
- 無既有資料 update/delete。
- rollback 可直接 drop 新表。

## 2. Schema 詳細規格

### Table：`learning_record_feedbacks`

| 欄位 | 型別 | Null | Default | 說明 |
|---|---:|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | No | N/A | PK |
| `learning_record_id` | BIGINT UNSIGNED | No | N/A | 對應 `LearningRecord.id` |
| `student_id` | BIGINT UNSIGNED | No | N/A | 快取學生 ID |
| `student_class_id` | BIGINT UNSIGNED | No | N/A | 快取課程 ID |
| `class_session_id` | BIGINT UNSIGNED | Yes | NULL | 快取堂次 ID |
| `teacher_id` | BIGINT UNSIGNED | No | N/A | 快取老師 User.id |
| `campus_id` | BIGINT UNSIGNED | No | N/A | 快取分校 ID |
| `content` | TEXT | No | N/A | 家長回饋，應用層限制 1-500 字 |
| `parent_session_id` | BIGINT UNSIGNED | Yes | NULL | 最後建立/更新使用的 ParentSession id |
| `last_read_by_teacher_at` | DATETIME | Yes | NULL | 老師已讀時間 |
| `last_read_by_director_at` | DATETIME | Yes | NULL | 主任已讀時間 |
| `created_at` | TIMESTAMP | Yes | Laravel | 建立時間 |
| `updated_at` | TIMESTAMP | Yes | Laravel | 更新時間 |

### 為什麼存快取欄位

`student_id`、`teacher_id`、`campus_id` 其實可由 `LearningRecord -> StudentClass -> Student` 推導，但回饋列表會常用：

- 老師未讀列表：`teacher_id`
- 主任分校列表：`campus_id`
- 家長 ownership：`student_id`

直接快取可避免每次列表查詢做多層 join，並讓權限 scope 更清楚。

## 3. Index 設計

| Index | 欄位 | 類型 | 用途 |
|---|---|---|---|
| `lrf_learning_record_unique` | `learning_record_id` | UNIQUE | v1 一筆評量一筆回饋 |
| `lrf_student_updated_idx` | `student_id`, `updated_at` | INDEX | parent dashboard / ownership 查詢 |
| `lrf_teacher_unread_idx` | `teacher_id`, `last_read_by_teacher_at`, `updated_at` | INDEX | 老師未讀與列表 |
| `lrf_campus_unread_idx` | `campus_id`, `last_read_by_director_at`, `updated_at` | INDEX | 主任分校未讀與列表 |
| `lrf_record_idx` | `learning_record_id` | INDEX | 單筆 record lookup；若 unique 已覆蓋可省略 |

備註：
- MySQL unique index 已可支援 `learning_record_id` lookup；DEV 可不另建 `lrf_record_idx`，避免重複索引。
- 若 Laravel migration 對 index 命名過長，需明確指定短 index name。

## 4. Foreign Key 策略

建議 v1 不加 DB-level FK，採 application-level integrity。

理由：
- 既有系統多張歷史 PascalCase 表與 legacy ID 型別混用。
- `LearningRecord` 已有多個歷史 migration 與 soft void 行為。
- 本表是附屬回饋，不應因 FK constraint 阻擋既有評量 / 學生維護流程。

替代方案：
- 若 [REVIEW] 要求 DB FK，可加 `learning_record_id -> LearningRecord.id ON DELETE CASCADE`，但需確認 production engine / collation / type 完全一致。

本次 DBA 建議：**不加 FK，靠測試與 controller guard 保證一致性。**

## 5. Migration 寫作要求

檔名建議：

`2026_04_25_140000_create_learning_record_feedbacks_table.php`

Migration up:
- 先 `Schema::hasTable('learning_record_feedbacks')` guard。
- 建立新表。
- 不新增帶 business default 的欄位。
- timestamps 可用 Laravel 預設。
- 不做任何資料查詢或 backfill。

Migration down:
- `Schema::dropIfExists('learning_record_feedbacks')`。

### Migration 專屬規則檢查

| 規則 | 本案處理 |
|---|---|
| M1 chunkById | 不適用，無 backfill / update |
| M2 DEFAULT 回填 | 不新增帶 business default 的既有表欄位；不觸發舊資料回填 |
| M3 migrate 時機 | 僅 CI / PR merge 後部署流程執行；禁止 feature branch 手動 migrate production |
| hasTable guard | 必須加 |
| down() | 可完整 drop 新表 |

## 6. Query Pattern

### Parent dashboard / single record

條件：
- `learning_record_id IN (...)`
- 或 `student_id = ParentSession.StudentID`

回傳：
- 每筆 LearningRecord 附一個 feedback summary。

### Teacher list

條件：
- `teacher_id = auth_teacher_id`
- optional `last_read_by_teacher_at IS NULL OR last_read_by_teacher_at < updated_at`
- order by `updated_at DESC`

### Director list

條件：
- `campus_id IN auth_campus_ids`
- optional `campus_id = branch_id`
- optional unread: `last_read_by_director_at IS NULL OR last_read_by_director_at < updated_at`
- order by `updated_at DESC`

## 7. Consistency Rules

建立 / 更新 feedback 時，Controller 必須從 LearningRecord 重新推導欄位，不信任 request：

| 欄位 | 來源 |
|---|---|
| `learning_record_id` | route model |
| `student_class_id` | `LearningRecord.StudentClassID` |
| `class_session_id` | `LearningRecord.ClassSessionID` |
| `teacher_id` | `LearningRecord.TeacherID` |
| `student_id` | `LearningRecord.StudentID` 若可靠；否則 `StudentClass.StudentID` |
| `campus_id` | `Student.CampusID` |
| `parent_session_id` | resolved `ParentSession.id` |

若推導不到 `student_id` 或 `campus_id`：
- 不建立 feedback。
- 回 409 或 422。
- 不寫 content 到 log。

## 8. 資料保留與刪除

v1 保留策略：
- 跟隨 LearningRecord 生命週期。
- LearningRecord 被 void 時，回饋不自動刪除，但 parent / teacher / director 查詢 active record 時不顯示。

原因：
- 回饋可能是家長溝通紀錄，直接刪除可能造成稽核斷裂。
- 不新增 deletion UI，避免誤刪。

後續若需要個資刪除權：
- 另開資料清除流程，針對 student archive / delete 一併處理。

## 9. Migration 測試重點

### Schema 測試

- table exists。
- unique index on `learning_record_id`。
- indexes exist: teacher unread, campus unread, student updated。
- down() 可 drop 新表。

### Feature 測試支援

DBA 建議 Feature tests 至少驗：
- 第二次 parent submit 不新增第二筆，仍只有一筆 feedback。
- 更新後 `updated_at` 變動，read columns reset null。
- teacher/director list 使用 index-friendly columns 查詢。

## 10. 上線風險與回滾

### 上線前

- CI migration on test DB must pass。
- PHPUnit feature tests pass。
- Vite build pass。
- 不在 Pi 手動跑 migrate。

### 上線後

- health check `/api/v1/health` must return `status=ok`。
- smoke test parent dashboard 不 500。
- smoke test teacher learning page 不 500。

### 回滾

情境 1：前端 UI 有問題，API / DB 正常：
- revert frontend commit 或關閉 UI 入口。
- 不 rollback DB。

情境 2：API 造成 500：
- revert merge commit。
- 若 migration 已建立表但無害，可保留空表，後續清理。

情境 3：必須 rollback migration：
- `down()` drop 新表。
- 會刪除家長回饋資料，需先確認是否已有 production 回饋資料。
- 若已有資料，先 export 新表再 drop。

## 11. DBA Exit Checklist

- [x] DB 異動清單明確：新增 `learning_record_feedbacks` 一張表。
- [x] 不改既有 production 表結構。
- [x] 不需要 backfill，不使用 chunk / chunkById。
- [x] 無帶 DEFAULT 的既有表欄位新增。
- [x] Index 依 parent / teacher / director 查詢模式設計。
- [x] down() 回滾方案明確。
- [x] migration 執行時機遵守 PR merge 後合法部署流程。


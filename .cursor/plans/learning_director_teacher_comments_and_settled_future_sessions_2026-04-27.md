# [PLAN+B1] Learning Record Director Teacher Comments + Settled Course Future Sessions

## 0. Bug B1 偵查：歷史課程仍有未來堂次

| 欄位 | 內容 |
|---|---|
| 問題 | 大直周宏謙課程已結算並進入歷史課程，但仍看到未來堂次。 |
| 初判嚴重度 | P2：資料一致性與老師/主任待辦噪音；目前未回報全站中斷或扣堂錯帳。 |
| 已讀依據 | `docs/INDEX.md`、`docs/AI_REGRESSION_LESSONS.md`、`docs/AI_REGRESSION_LESSONS_ARCHIVE.md`、`docs/TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md`。 |
| 已確認現況 | `StudentClassController::togglePause` 的 pause/settled 路徑會把同課程今天起 `Status=scheduled` 的 `ClassSession` 更新為 `cancelled`。 |
| 根因候選 A | 該課程不是透過 `POST /api/v1/student-classes/{id}/pause` 結案，而是透過其他入口直接改 `Stop=1` / `closed_reason`，因此未觸發未來堂次取消。 |
| 根因候選 B | 未來堂次狀態不是 `scheduled`（例如 `completed`、`attended`、`leave_adjusted` 或資料異常），`togglePause` 的取消條件不會碰到。 |
| 根因候選 C | 後端已取消，但前端歷史課程詳情刻意用 `allSessionUnits()` 顯示含 `cancelled` 的全部堂次；使用者看到的是已取消堂次，但 UI 沒清楚標示或仍把它當未來堂次。 |
| 根因候選 D | 同日同時段存在 `cancelled + scheduled` 重複列；歷史上已發生過 SmartCalendar / LearningRecordsPage 誤取 cancelled 或重複顯示問題。 |
| 下一步 | 需進入 [BUG B1-Data Verify]：在安全環境查該學生/課程 `StudentClass`、`ClassSession` 狀態分布與最後更新入口，再確認根因。禁止直接連 production 改資料。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 學習評量表：主任可給老師評語；結案課程未來堂次一致性修復 |
| 日期 | 2026-04-27 |
| 狀態 | Draft / Phase 1 |
| 目標角色 | 主任、老師、super_admin |
| 工作流 | Workstream A = 新功能 PRD；Workstream B = Bug B1 後產正式 Bug Fix Plan |

## 2. 目標與業務背景

主任審核學習評量時，除了核准/退回/要求修改，也需要能留下給老師看的教學回饋，形成可追蹤的 coaching 記錄。另結案課程若仍出現未來堂次，會讓老師與主任誤以為還要上課或填評量，必須把「歷史課程」與「未來待處理堂次」語意切清楚。

KPI：
- 主任可在單筆評量留下 1-500 字老師評語。
- 老師可在同一筆評量看到主任評語與時間。
- 結案課程不再產生可操作的未來待上/待填項目。
- 所有新增/修復行為具備 PHPUnit regression tests。

## 3. 範圍

In Scope：
- 在 `LearningRecord` 周邊新增主任對老師評語的資料結構/API/UI。
- 老師端顯示主任評語；主任端可新增/更新評語。
- 檢查結案課程未來堂次來源，修復入口或顯示口徑。
- 補測角色權限、分校隔離、已結案課程未來堂次 regression。

Out of Scope：
- 不改家長回饋規則與家長入口。
- 不改繳費/續課提醒條件，除非 B1 證實與結案入口直接相關且另行確認。
- 不做 production 手動資料修補，除非 PR/CI/deploy 完成後依 runbook 產出可審計腳本。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（規劃） | `[PLAN]` | R |
| AI Agent（實作） | `[DEV]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（資安/審查） | `[SEC]` / `[REVIEW]` | R |
| AI Agent（文件/部署） | `[DOCS]` / `[OPS]` | R |
| 使用者 | CEO | I（批准進入下一 Phase） |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| DB migration | 主任對老師評語若不復用家長回饋表，需新增表或欄位 | 待 ARCH 決策 |
| 既有 API | `learning-records`、`learning-record-feedbacks`、`student-classes/{id}/pause` | 已存在 |
| 資料驗證 | 周宏謙實際 `StudentClass` / `ClassSession` 狀態 | B1 待查 |

## 5. User Stories + AC

### US-001 主任留下老師評語
As a 主任, I want 在學習評量審核畫面留下給老師的評語, so that 老師能知道如何調整教學。

AC：
- 主任可對可存取分校內的學習評量新增/更新評語。
- 空白或超過上限的評語回 422。
- teacher 不可替主任寫評語。

### US-002 老師閱讀主任評語
As a 老師, I want 在評量詳情看到主任評語, so that 我能依回饋修正教學或評量內容。

AC：
- 老師只能看到自己課程/代課堂次的主任評語。
- 評語顯示作者、時間、內容。

### US-003 結案課程不再有可操作未來堂次
AC：
- 結案後未來 `scheduled` 堂次應取消或不再列入待辦/可操作清單。
- 若只保留歷史詳情，UI 必須明確標示「已取消」且不算待上/待填。
- 同時段 `cancelled + scheduled` 要依既有優先序解析，不可讓 cancelled 蓋掉 scheduled。

## 5b. UI/UX 精緻化

| 頁面 | 規格 |
|---|---|
| `LearningRecordsPage.vue` | 評量詳情中新增「主任給老師評語」區塊；主任可編輯，老師唯讀；沿用現有卡片/textarea 風格。 |
| `LearningRecordsPage.vue` | 有主任評語的列表卡顯示小型 badge；不與家長回饋 badge 混淆。 |
| `CourseManagement.vue` | 若歷史課程詳情顯示 cancelled 未來堂次，chip 必須清楚灰階/刪除線/「已取消」，不可呈現為待上。 |
| Loading/Error | 評語儲存按鈕要有 loading；失敗用 inline 或 toast 顯示 API message。 |
| 響應式 | 手機版 textarea / badge 觸控目標 ≥ 44px。 |

## 6. 功能需求 FR

- FR-001：系統應提供 director/admin/super_admin 專用 API，對單筆 `LearningRecord` upsert 主任給老師評語。
- FR-002：系統應在 `GET /api/v1/learning-records` 回傳每筆評量的主任評語摘要。
- FR-003：系統應保留評語作者、最後更新時間與分校/老師/學生關聯，避免跨校讀取。
- FR-004：老師端應唯讀顯示主任評語，不可修改。
- FR-005：結案課程的未來 `scheduled` 堂次不得出現在老師待辦或主任可操作未來堂次。
- FR-006：若 B1 證實有結案入口未走 `togglePause`，該入口必須改為共用同一個服務/方法。

## 7. NFR

- API p95 < 500ms；評語載入不得引入 per-record N+1。
- 評語內容限制 1-500 字，避免過長 payload。
- 查詢必須套用 role + campus + teacher scope。

## 8. 技術方向（禁止 code）

- 優先評估新增 `learning_record_teacher_comments` 表，避免混用 `learning_record_feedbacks` 的家長語意與 unread 欄位。
- 也可評估擴充既有 feedback 表，但需避免 `parent_session_id`、家長 unread 與主任評語語意混淆。
- 後端集中在 `LearningRecordController` 或新 controller；前端集中在 `LearningRecordsPage.vue`。
- 結案修復應把「課程停止/結案 → 取消未來可操作堂次」抽為共用後端路徑，避免其他入口直接改 `Stop`。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-27 | Phase 1 先拆成 Feature + Bug B1 | 直接改 code | SOP 要先確認根因，且涉及評量/權限/課程堂次。 |
| 2026-04-27 | 主任評語不預設復用家長回饋表 | 擴充 `learning_record_feedbacks` | 家長回饋已有 parent session / unread 語意，混用風險高，ARCH 再定案。 |
| 2026-04-27 | 結案堂次優先取消而非刪除 | 物理刪除未來堂次 | 業界 SIS 對已發布課程偏好 cancel 保留紀錄，避免稽核斷裂。 |

## 9. 資安與存取控制

觸發 SEC：角色邊界、PII（學生/老師/評量內容）、多校區隔離。

- director/admin 只能寫可存取分校的評量。
- teacher 只能讀與自己正班/代課關聯的評語。
- super_admin 可跨校但仍需明確 scope。
- API 不接受前端傳入 `campus_id` 作為信任來源，應由 `LearningRecord -> StudentClass -> Student` 推導。

## 10. QA 驗收

- Happy：主任新增評語後，老師重新載入評量詳情可見。
- Happy：主任更新評語後，老師看到最新內容與更新時間。
- Edge：teacher 呼叫寫入 API 回 403。
- Edge：主任跨分校寫入回 403。
- Error：空白/超長評語回 422。
- Bug：結案課程未來 `scheduled` 轉為 `cancelled` 或不再列入待辦。
- Regression：`cancelled + scheduled` 同格仍選 scheduled，不回歸歷史錯誤。

## 11. 上線與維運

- 需 feature branch + PR + CI green，禁止直接 push main。
- 若有 migration，由 `deploy.yml` 在 PR merge 後自動處理。
- 前端變更需 CI green 後由 deploy workflow 自動部署；不可手動 `npm run deploy`。
- 回滾：`git revert <PR merge commit>`；若新增表且已上線，優先保留空表，必要時另開 rollback migration。
- Health check：deploy 後 `GET /api/v1/health` 需 200。

## 12. 里程碑與優先級

- P1 `[BUG]`：先查周宏謙資料與結案入口，避免老師/主任繼續看到錯誤未來堂次。
- P1 `[FEATURE]`：主任評語 API + UI。
- P1 `[TEST]`：權限、分校隔離、結案未來堂次 regression。
- P2 `[DOCS]`：CHANGELOG 與若發現新坑則補 AI_REGRESSION。

## 13. 風險 / 假設 / 開放問題

WebSearch 摘要：
- LMS/RBAC 業界做法強調 least privilege、角色權限稽核與 activity audit。
- Teacher evaluation 軟體強調集中保存 observation/feedback、可追蹤對話與可行動回饋。
- Registrar/SIS 課程取消做法偏向「發布後 cancel 而非 delete」，以保留學生/排課紀錄與避免混淆。

| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|---|---|---|---|
| 主任評語跨校外洩 | 高 | LMS RBAC + least privilege + audit trail | 由後端推導 campus 並驗 `require_campus` scope。 |
| 混用家長回饋導致 unread/語意混亂 | 中 | 評估/回饋資料集中但語意清楚 | ARCH 優先設獨立 teacher comment 表。 |
| 結案後直接刪除未來堂次造成稽核斷裂 | 中 | 已發布課程 cancellation 留痕 | 更新 status 為 `cancelled`，前端不列為待上。 |
| 周宏謙資料需要 production 查驗 | 高 | 只讀查詢與審計，避免直接改線上 | 不 SSH 改 production；若需資料修補，走 PR + script + CI + deploy。 |

開放問題：
- `[AI-RESOLVABLE]` 周宏謙課程 ID、分校 ID、未來堂次狀態分布需由安全查詢取得。
- `[AI-RESOLVABLE]` 主任評語是否需要未讀 badge/通知，先不做通知，除非使用者追加。

## 14. Definition of Done

- [ ] 主任評語 API：驗證方式 `backend` Feature test 覆蓋 director success / teacher 403 / cross-campus 403，CI 0 failures。
- [ ] 主任評語 UI：驗證方式前端 build 0 error，且 `LearningRecordsPage.vue` 有 director editable / teacher readonly 狀態。
- [ ] 結案未來堂次：驗證方式 Feature test 建立已結案課程與未來 scheduled，呼叫結案入口後未來可操作堂次為 0。
- [ ] Duplicate status regression：驗證方式既有/新增測試覆蓋 `cancelled + scheduled` 同格優先 scheduled。
- [ ] 文件：驗證方式 `docs/CHANGELOG.md` 有 2026-04-27 條目；若 B1 證實新坑，`docs/AI_REGRESSION_LESSONS.md` 有防再犯紀錄。
- [ ] OPS：驗證方式 PR merge 後 deploy success，`GET /api/v1/health` 回 `status=ok`。

## Exit Checklist（Phase 1）

- [x] 已讀 `docs/INDEX.md`。
- [x] 已讀 bug/PRD 規則與 AI regression 摘要。
- [x] 已完成 B1 根因候選，不直接改 production。
- [x] 已完成 Feature PRD 草案。
- [x] 使用者已批准進入下一 Phase：`[BUG B1-Data Verify]` + `[ARCH]`。

---

## Phase 2 — [BUG B1-Data Verify] 只讀驗證設計

> 本節只定義安全查詢與判讀標準。不得在 production 直接 UPDATE/DELETE；若需資料修補，必須走 WSL2 feature branch → PR → CI → merge → deploy/runbook。

### 2.1 只讀查詢目標

要確認「大直周宏謙已結算進到歷史課程可是還有未來堂次」到底是哪一類：

| 類型 | 判斷 |
|---|---|
| A：結案入口漏取消 | `StudentClass.Stop=1` / `closed_reason in ('settled','completed')`，但仍有未來 `ClassSession.Status='scheduled'`。 |
| B：狀態不是 scheduled | 結案後未來堂次存在，但狀態為 `completed/attended/leave_adjusted/absent` 等，標準 `togglePause` 不會取消。 |
| C：UI 顯示已取消堂次 | 未來堂次全是 `cancelled`，但 `CourseManagement` 歷史詳情用 `allSessionUnits()` 顯示全部，造成「還有未來堂次」觀感。 |
| D：同格重複列 | 同課程同日同時段存在 `cancelled + scheduled` 或多筆狀態，需用既有優先序解析。 |

### 2.2 安全只讀 SQL 草案

```sql
-- 1) 找出大直周宏謙的課程主檔與狀態
SELECT
  s.id AS student_id,
  s.name,
  s.CampusID,
  sc.ID AS student_class_id,
  sc.SubjectID,
  sc.TeacherID,
  sc.ScheduleMode,
  sc.SessionCount,
  sc.UsedSessions,
  sc.RemainingSessions,
  sc.Paid,
  sc.Stop,
  sc.closed_reason,
  sc.StartDate,
  sc.EndDate,
  sc.updated_at
FROM Student s
JOIN StudentClass sc ON sc.StudentID = s.id
WHERE s.name LIKE '%周宏謙%'
ORDER BY sc.ID DESC;

-- 2) 查每門課的未來堂次狀態分布
SELECT
  cs.StudentClassID,
  cs.Status,
  COUNT(*) AS count_rows,
  MIN(cs.SessionDate) AS first_date,
  MAX(cs.SessionDate) AS last_date
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID = cs.StudentClassID
JOIN Student s ON s.id = sc.StudentID
WHERE s.name LIKE '%周宏謙%'
  AND cs.SessionDate >= CURDATE()
GROUP BY cs.StudentClassID, cs.Status
ORDER BY cs.StudentClassID DESC, cs.Status;

-- 3) 查未來堂次明細與是否同格重複
SELECT
  cs.id,
  cs.StudentClassID,
  cs.SessionDate,
  cs.StartTime,
  cs.EndTime,
  cs.Status,
  cs.Note,
  lr.id AS learning_record_id,
  lr.Status AS learning_record_status
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID = cs.StudentClassID
JOIN Student s ON s.id = sc.StudentID
LEFT JOIN LearningRecord lr
  ON lr.ClassSessionID = cs.id
 AND lr.VoidedAt IS NULL
WHERE s.name LIKE '%周宏謙%'
  AND cs.SessionDate >= CURDATE()
ORDER BY cs.StudentClassID DESC, cs.SessionDate, cs.StartTime, cs.id;

-- 4) 找同課程同日同時段多筆狀態
SELECT
  cs.StudentClassID,
  cs.SessionDate,
  cs.StartTime,
  GROUP_CONCAT(CONCAT(cs.id, ':', cs.Status) ORDER BY cs.id) AS rows_at_slot,
  COUNT(*) AS row_count
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID = cs.StudentClassID
JOIN Student s ON s.id = sc.StudentID
WHERE s.name LIKE '%周宏謙%'
  AND cs.SessionDate >= CURDATE()
GROUP BY cs.StudentClassID, cs.SessionDate, cs.StartTime
HAVING COUNT(*) > 1
ORDER BY cs.SessionDate, cs.StartTime;
```

### 2.3 判讀後修復分支

| 查詢結果 | 修復方向 |
|---|---|
| A | 把所有結案入口收斂到 `togglePause` 等效服務；新增測試「Stop=1 settled 後無 future scheduled」。 |
| B | 產品確認是否應把未來非 attended-like 狀態也取消；若是資料異常，新增狀態分類與 warning。 |
| C | 前端歷史課程詳情改用清楚的 cancelled 視覺與「不算待上」說明；不需要資料修補。 |
| D | 套用既有 duplicate status 優先序，避免 cancelled 蓋掉 scheduled；必要時新增前端 regression。 |

## Phase 2 — [ARCH] 主任給老師評語

### 2.4 DB 異動清單

新增表（建議）：`learning_record_teacher_comments`

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | bigIncrements | 主鍵 |
| `learning_record_id` | unsignedBigInteger unique | 一筆評量目前一則主任評語 |
| `student_id` | unsignedBigInteger | 快照，供查詢與索引 |
| `student_class_id` | unsignedBigInteger | 快照 |
| `class_session_id` | unsignedBigInteger nullable | 對應堂次 |
| `teacher_id` | unsignedBigInteger | 評語目標老師 |
| `campus_id` | unsignedBigInteger | 分校隔離 |
| `author_user_id` | unsignedBigInteger | 建立/最後編輯主任 |
| `content` | text | 1-500 字 |
| `last_read_by_teacher_at` | dateTime nullable | 支援老師未讀 badge |
| `created_at` / `updated_at` | timestamps | 稽核時間 |

索引：
- unique `learning_record_id`
- index `teacher_id, last_read_by_teacher_at, updated_at`
- index `campus_id, updated_at`
- index `author_user_id, updated_at`

不復用 `learning_record_feedbacks` 的理由：
- 既有表語意是「家長 → 老師/主任」，包含 `parent_session_id`、`last_read_by_director_at`。
- 主任評語是「主任 → 老師」，未讀方向、權限與可見角色不同。
- 分表能避免未來家長回饋列表混入主任 coaching 訊息。

### 2.5 後端 API 合約

| Method | Path | Role | 說明 |
|---|---|---|---|
| `PUT` | `/api/v1/learning-records/{learningRecord}/teacher-comment` | director/admin/super_admin | 新增或更新主任給老師評語 |
| `DELETE` | `/api/v1/learning-records/{learningRecord}/teacher-comment` | director/admin/super_admin | 清除評語（可選，DEV 時若範圍需收斂可延後） |
| `POST` | `/api/v1/learning-record-teacher-comments/{comment}/read` | teacher | 老師標記已讀 |

`PUT` request：

```json
{
  "content": "請下次評量補充學生卡住的單元與下週追蹤方式"
}
```

`PUT` response：

```json
{
  "comment": {
    "id": 123,
    "learning_record_id": 456,
    "teacher_id": 78,
    "teacher_name": "王老師",
    "author_user_id": 9,
    "author_name": "大直主任",
    "content": "請下次評量補充學生卡住的單元與下週追蹤方式",
    "updated_at": "2026-04-27T06:54:00+00:00",
    "unread_for_teacher": true
  },
  "message": "已儲存給老師的評語"
}
```

`GET /api/v1/learning-records` 裝飾欄位新增：

```json
{
  "teacher_comment": {
    "id": 123,
    "content": "...",
    "author_name": "大直主任",
    "updated_at": "...",
    "unread_for_teacher": true
  }
}
```

### 2.6 權限與多校區隔離

- Director/admin：透過 `LearningRecord -> StudentClass -> Student.CampusID` 推導分校，必須在 `auth_campus_ids` 內；super_admin 可跨校。
- Teacher：只能讀自己 `TeacherID` 或代課關聯可見的評量；mark-read 必須確認 `comment.teacher_id === auth_teacher_id`。
- 不信任前端傳入的 `campus_id`、`teacher_id`、`student_id`；全部由評量上下文推導。
- 評語寫入不更動 `LearningRecord.Status`、不觸發扣堂、不改 `ReviewNote`。

### 2.7 前端 UI 規劃

`LearningRecordsPage.vue`：
- 評量 modal 的「上課狀況與評語」下方新增「主任給老師評語」區塊。
- Director/admin/super_admin：顯示 textarea + 儲存按鈕；空白時顯示 placeholder「給老師的內部評語，不會顯示給家長」。
- Teacher：顯示唯讀卡片；未讀時有「新主任評語」badge，開啟 modal 後呼叫 mark-read。
- 列表卡/表格列：有評語顯示獨立 badge「主任評語」，色彩與家長回饋區分（建議藍/紫系，不用橘色未讀家長回饋）。
- 不顯示給家長入口。

### 2.8 測試策略

新增 Feature tests：
- Director 可對同分校評量 upsert teacher comment。
- Director 跨分校 403。
- Teacher 寫入 403。
- Teacher 可讀自己評量的 teacher_comment。
- Teacher mark-read 後 `unread_for_teacher=false`。
- `GET learning-records` 裝飾 teacher_comment 不產生 N+1（可用 query count 或批次資料 smoke）。

## Phase 2 — [ARCH] 結案課程未來堂次一致性

### 2.9 後端修復設計

建議抽出私有/服務方法：

| 方法概念 | 職責 |
|---|---|
| `closeOrPauseStudentClass($studentClass, $reason)` | 設定 `Stop/closed_reason/EndDate` 並取消未來可操作堂次 |
| `cancelFutureScheduledSessions($studentClass, $reason)` | 統一取消 `SessionDate >= today AND Status='scheduled'` 的堂次並加 Note |

目前 `togglePause` 已有取消邏輯；修復重點不是重寫，而是防止其他入口繞過：
- 檢查 `TuitionCollectionPage` 結案、`CourseManagement` 結案、可能的 direct `PUT student-classes/{id}` 是否能直接帶 `Stop=1`。
- 若 `PUT student-classes/{id}` 允許 `Stop` 從 0 → 1，後端應呼叫同一取消邏輯，或拒絕直接修改並要求走 pause endpoint。
- 保留 `settled` 不改 `Paid` 的既有規則。

### 2.10 前端修復設計

若 B1 判讀為 C（只有 cancelled 仍顯示）：
- `CourseManagement` 歷史課程詳情保留 `allSessionUnits()` 供稽核，但 chip 必須明確 `cancelled` 樣式與 tooltip。
- 標題應寫「上課日期（已取消 X 堂，取消堂次不算待上）」。
- 若使用者看到未來 cancelled，不應顯示成可點擊可編輯的待上堂次。

若 B1 判讀為 A/D：
- 後端修復為主，前端只保留顯示防呆。

### 2.11 Regression tests

後端：
- `StudentClassPauseFutureSessionTest::test_settled_course_cancels_future_scheduled_sessions`
- `StudentClassUpdateStopTest::test_direct_stop_update_cannot_leave_future_scheduled_sessions`
- `ClassSessionDuplicateStatusTest` 不回歸 cancelled+scheduled 優先序。

前端/靜態：
- 檢查 `CourseManagement` 歷史 cancelled chip 樣式與文案。
- 檢查 `useCourseSessionsDisplay` 不讓 cancelled 參與剩餘/有效堂次。

## Phase 2 Exit Checklist

- [x] B1 只讀查詢與判讀標準已定義。
- [x] 主任評語 DB/API/UI/權限設計已完成。
- [x] 結案課程未來堂次修復設計已完成。
- [x] 多校區隔離與資安觸發點已標記。
- [x] 測試清單已列出。
- [ ] 等使用者批准進入 `[DEV]`：先新增 tests（RED）再改 production code。

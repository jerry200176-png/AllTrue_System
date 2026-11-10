# Bug Fix Plan：補課流程缺失——ClassSession 未建立 / 老師快速點名無法回溯日期

**檔案**：`bugfix_makeup_class_session_missing_2026-04-24.md`  
**狀態**：Draft（根因更新 2026-04-24 v2）  
**建立日期**：2026-04-24  

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤（多處）：缺少欄位（StudentID）/ 日期寫死 / ClassSession 未同步建立 |
| 根因摘要 | 三個獨立缺陷共同造成補課出勤無法自助回報：(A) `ScheduleController::store` 補建補課 schedule 時不建 ClassSession；(B) `submitQuickAttend` 缺少 `StudentID` → 422 靜默失敗；(C) `submitQuickAttend` 日期寫死 `today`，老師無法補登昨天的補課 |
| 錯誤行為 | 老師當天（4/23）使用「建課補點」，API 因缺少 `StudentID` 回傳 422 失敗但前端顯示不明確；管理員隔天（4/24）查看出勤頁，因出勤頁固定顯示「今天」（4/24），看不到 4/23 的任何記錄；課程管理與行事曆也看不到 4/23（schedules 表無 4/23 紀錄），誤判為什麼都沒建立；管理員手動補建 4/23 ClassSession + 出勤並完成點名後，老師才能填評量 |
| 預期行為 | 老師能在補課當天或次日選擇正確日期自行點名；系統自動建立對應 ClassSession；管理員能在出勤頁查看過去指定日期的記錄 |
| 影響範圍 | teacher 角色出勤頁（`submitQuickAttend`）、管理員手動補建路徑（`ScheduleController::store`）、出勤頁歷史查詢（`fetchRecords` 固定 today） |
| B1 偵查來源 | 本計畫整合 B1 內容（探索子 agent 3672a25d）+ 4/24 對話追蹤確認 |

**三個獨立缺陷：**

- **BUG-A（P1）**：`ScheduleController::store` 手動建補課時，僅插入 `schedules`，不同步建 `ClassSession`。出勤頁和評量頁依賴 `ClassSession`，因此補課在這兩處完全不可見。
- **BUG-B（P1）**：`AttendancePage.vue` `submitQuickAttend`（老師快速點名）送出的 JSON body 缺少 `StudentID`，後端 `AttendanceController::store` 驗證 `StudentID` 為必填（`'required|integer'`），導致 422 失敗；前端只在小字區域顯示錯誤，老師容易誤認為成功。
- **BUG-C（P2）**：`submitQuickAttend` 的 `SessionDate` 寫死為 `localTodayYmd()`（今天），老師無法補登昨天或更早的補課堂次。設計應支援「日期可選」或至少允許傳入日期參數。
- **設計限制（P2）**：`fetchRecords` 固定查 `date: today`，出勤頁無法查詢過去日期記錄，管理員無法確認老師的回報是否成功。

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 補課排程 ↔ ClassSession 一致性 |
| 版本 | v1.x（現有系統） |
| 狀態 | Draft |
| 嚴重度 | **P1** |
| 目標角色 | teacher、admin/director |
| 關聯 Bug | BUG-A（ClassSession 缺失）、BUG-B（快速點名 422 靜默失敗） |

---

## 2. 業務背景與影響

**痛點：**
- 木柵分校楊智超老師 4/22 請假、補課至 4/23，管理員手動補建後，老師無法在出勤頁和評量頁看到 4/23 課堂，整堂課的出缺勤與評量記錄無法建立。
- 老師已執行「建課補點」操作，卻無法確認是否成功，造成雙重不確定性。
- 若補課出勤未記錄，學生堂數扣除與家長通知均受影響。

**修復後預期行為：**
1. 管理員補建補課排程（schedules type=extra / rescheduled）後，系統自動建立對應 `ClassSession`（status: scheduled）。
2. 老師在出勤頁可選到補課當日該堂，完成點名並扣堂數。
3. 老師在評量頁可選到補課當日該堂，填寫學習評量。
4. 快速點名/補點操作在缺少必填欄位時，前端顯示明確錯誤訊息，不靜默失敗。

---

## 3. 範圍

### In Scope
- `ScheduleController::store`：新增 `type = extra` 或 `rescheduled` 的補課 schedule 時，同步 `ClassSession::create`（BUG-A）
- `AttendancePage.vue`：`submitQuickAttend` 補上 `StudentID`（從 teacherCourses 中對應取得）並顯示明確錯誤（BUG-B）
- `AttendancePage.vue`：`submitQuickAttend` 加入日期選擇器（預設今天，最多回溯 14 天）（BUG-C）
- `AttendancePage.vue`：`fetchRecords` 加入日期篩選器，允許查詢過去指定日期的出勤記錄（設計限制）
- ~~資料補丁~~：**已由管理員手動完成（4/23 記錄已建立，老師可填評量）**，無需再補丁

### Out of Scope
- 正規調課流程（`LearningRecordController::rescheduleSession`）：此路徑已會建立 ClassSession，不動
- `SmartCalendar.vue` 拖曳調課邏輯：已走正規路徑，不動
- `CourseLeaveCascadeService`：請假 cascade 邏輯不動
- 學生端（ParentPortal）：不涉及補課排程，不動
- 科目數統計、繳費提醒：不涉及，不動

---

## 4. RACI

| 工作項目 | R | A | C | I |
|---|---|---|---|---|
| 後端修復（BUG-A） | AI Agent | AI Agent | — | 使用者 |
| 前端修復（BUG-B） | AI Agent | AI Agent | — | 使用者 |
| 資料補丁 | AI Agent | AI Agent | — | 使用者 |
| Regression Tests | AI Agent | AI Agent | — | 使用者 |
| Code Review | AI Agent | AI Agent | — | 使用者 |
| 部署 | AI Agent | AI Agent | — | 使用者 |

---

## 4b. Dependencies

- 無前置 PR 或 migration 依賴。
- 資料補丁需先確認 4/23 該 schedule 記錄的 `id`、`student_course_id`、`start_time`、`end_time`（管理員補建的那筆）。
- 補丁執行前應先確認 4/23 是否已有 ClassSession（避免重複建立）。

---

## 5. Acceptance Criteria

### AC-001：補課 schedule 手動新增時同步建立 ClassSession
- AC-001-a：`POST /api/v1/schedules` 帶 `type=extra` 或 `status=rescheduled`，系統回傳 201，且 `ClassSession` 表新增一筆對應記錄（同 `student_course_id`、日期、時間、status=scheduled）。
- AC-001-b：`POST /api/v1/schedules` 帶 `type=normal`（非補課），ClassSession **不**被新增（不影響現有正常排課）。

### AC-002：出勤頁可選到補課堂次
- AC-002-a：補課 ClassSession 建立後，`GET /api/v1/class-sessions?start={補課日}&end={補課日}` 回傳含該堂次。
- AC-002-b：老師在出勤頁可完成點名（`POST /api/v1/attendance` 帶 `ClassSessionID`），回傳 201。

### AC-003：評量頁可選到補課堂次
- AC-003-a：補課 ClassSession 建立後，老師在評量頁可選到補課日的課堂下拉選項。
- AC-003-b：老師提交評量（`POST /api/v1/learning-records`），系統回傳 201。

### AC-004：快速點名缺少必填欄位時前端顯示錯誤
- AC-004-a：`submitQuickAttend` 送出前，若 `StudentID` 未定義或為空，前端顯示明確錯誤訊息，不呼叫 API。
- AC-004-b：若後端回傳 422，前端 toast 顯示錯誤細節，不顯示成功訊息。

### AC-005：老師快速點名支援回溯日期
- AC-005-a：老師在出勤頁選擇日期（如 4/23），點送出，系統以 4/23 建立 ClassSession + StudentSingIn，回傳 201。
- AC-005-b：選擇超過 14 天前的日期，前端顯示「超出可補登範圍，請聯絡管理員」，不呼叫 API。

### AC-006：出勤頁支援查詢過去日期記錄
- AC-006-a：管理員在出勤頁選擇日期 4/23，`fetchRecords` 查詢 4/23 的 StudentSingIn，正確顯示當日記錄。
- AC-006-b：預設不帶日期參數時，行為維持顯示今天（不破壞現有功能）。

---

## 6. 功能需求 FR

| 編號 | 描述 |
|---|---|
| FR-001 | 修復後，`ScheduleController::store` 在新增 `type=extra` 或 `status=rescheduled` 的補課 schedule 時，應在同一 DB transaction 內執行 `ClassSession::firstOrCreate`，欄位來自 schedule（`student_course_id`→`StudentClassID`、`schedule_date`→`SessionDate`、`start_time`、`end_time`、status=`scheduled`） |
| FR-002 | 修復後，若該日期 `StudentClassID` 已存在 `ClassSession`，`store` 應跳過建立（冪等）並回傳已存在的 session ID |
| FR-003 | 修復後，`submitQuickAttend` 的 JSON body 補上 `StudentID`（從已選課程物件的 `student_id` 取得）；後端回傳 4xx 時前端以明確錯誤訊息顯示，不顯示成功 toast |
| FR-004 | 修復後，`submitQuickAttend` 表單加入「上課日期」選擇器（預設今天，限制最多回溯 14 天）；`SessionDate` 讀取此日期，不再寫死 `today` |
| FR-005 | 修復後，出勤頁的歷史記錄區塊加入日期選擇器，`fetchRecords` 支援查詢指定日期（預設今天，不限於今天） |

---

## 7. 非功能需求 NFR

不適用。本 bug 為邏輯錯誤（缺失建立步驟），非效能問題。資料補丁為單次 INSERT，對 DB 無效能影響。

---

## 8. 技術方向

### BUG-A 修復位置
- **檔案**：`backend/app/Http/Controllers/ScheduleController.php`，方法 `store()`
- **修改**：在 `Schedule::create($data)` 後，判斷 `$data['type'] === 'extra'` 或 `$data['status'] === 'rescheduled'`，則在同一 `DB::transaction` 內執行 `ClassSession::firstOrCreate`（以 `StudentClassID` + `SessionDate` 為 unique key）
- **取捨**：選擇 `firstOrCreate` 而非 `create`，確保冪等性，避免重複 session；不在 `LearningRecordController::rescheduleSession` 動手，該路徑已正確運作

### BUG-B 修復位置（StudentID 缺失）
- **檔案**：`frontend/src/pages/AttendancePage.vue`，方法 `submitQuickAttend`
- **修改**：從 `teacherCourses` 找到目前選中課程的 `student_id`，補進 JSON body：`StudentID: Number(selectedCourse.student_id)`；後端回傳 4xx 時 `quickTimeError.value` 顯示 `err.message`，不顯示成功
- **取捨**：在前端補 StudentID，後端 validation 不動（已正確）

### BUG-C 修復位置（日期寫死 today）
- **檔案**：`frontend/src/pages/AttendancePage.vue`，方法 `submitQuickAttend` + `quickForm` ref
- **修改**：`quickForm` 新增 `date` 欄位（預設今天），表單加入 `<input type="date">` 限制 min=14 天前、max=今天；`SessionDate` 改讀 `quickForm.value.date`；超出範圍提示聯絡管理員
- **取捨**：14 天上限與業界 Schoolrunner 的補登期間慣例一致；超出由 director 透過「主任快速補點」操作（`submitDirQuick` 已支援指定日期）

### 設計限制修復（出勤頁歷史查詢）
- **檔案**：`frontend/src/pages/AttendancePage.vue`，方法 `fetchRecords` + template
- **修改**：加入 `historyDate` ref（預設今天），template 加日期選擇器，`fetchRecords` 改用 `historyDate.value` 作為 `date` 參數
- **取捨**：僅改查詢日期，不改資料結構，影響面小

### 架構取捨理由
- 選擇在 `ScheduleController::store` 補建 ClassSession，是唯一確保補課建立時同步的時機點
- 不動 `LearningRecordController::rescheduleSession`（已正確）
- 不動 `submitDirQuick`（已支援指定日期 + 補建 ClassSession via `AttendanceController::store`）

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-24 | `ScheduleController::store` 補建 ClassSession | 要求使用者改用 `reschedule-session` API | 使用者介面無法強制；根本解應在後端自動化 |
| 2026-04-24 | `firstOrCreate` 冪等建立 ClassSession | 先 check 再 create | `firstOrCreate` 原子性更好，避免 race condition |
| 2026-04-24 | 前端 guard 驗證 StudentID | 後端新增更嚴格 error response | 後端 validation 已正確；前端缺 guard 才導致靜默失敗 |

---

## 9. 資安與存取控制

本 bug 不涉及 auth / PII / 權限邊界異動。`ScheduleController::store` 已有 `auth:api` middleware 保護。資料補丁為管理員直接操作，不改動任何 token 或角色邏輯。

不適用深度資安審查。

---

## 10. QA 驗收

### Happy Path
- [ ] 新增 `type=extra` 補課 schedule → `ClassSession` 自動建立
- [ ] 出勤頁可選補課堂次並完成點名
- [ ] 評量頁可選補課堂次並填寫評量

### Edge Cases
- [ ] 同日同 StudentClassID 重複呼叫 `store`（例如管理員手誤再點一次）→ ClassSession 不重複建立
- [ ] `type=normal` 的 schedule → 不影響 ClassSession 建立
- [ ] 快速點名缺少 StudentID → 前端顯示錯誤，不呼叫 API

### Error Cases
- [ ] `submitQuickAttend` 後端回傳 422 → 前端顯示明確錯誤 toast（不顯示成功）
- [ ] 補丁執行時 ClassSession 已存在 → 不重複 INSERT，回傳已存在 ID

### Revert-proof 驗證
- [ ] `git stash` 後重跑新增的 feature test（補課 store 建 ClassSession），至少 1 case failure（確認測試真正覆蓋了 bug）

---

## 11. 上線與維運

### 部署步驟
1. 後端：`php artisan route:clear && php artisan config:clear`（無 migration）
2. 前端：`cd frontend && npm run deploy`
3. 資料補丁：執行補丁 SQL / Tinker 腳本，確認 4/23 ClassSession 已存在

### Migration
無（無新增欄位，現有 Schema 足夠）

### Observability
- 補丁後：`SELECT * FROM ClassSession WHERE SessionDate = '2026-04-23'` 確認有記錄
- 補丁後：驗證 `StudentSingIn` 是否存在（或提醒使用者手動補點名）

### 回滾方案
- 後端邏輯：git revert PR，重新部署（< 5 分鐘）
- 資料補丁：若 ClassSession 不需保留，`DELETE FROM ClassSession WHERE id = {新建的ID}`

---

## 12. 優先級

| Bug | 優先級 | 執行 Agent |
|---|---|---|
| BUG-A：ClassSession 缺失 | **P1** | `[DEV]` |
| BUG-B：快速點名靜默失敗 | **P2** | `[DEV]` |
| 資料補丁（4/23 楊智超） | **P1**（緊急執行） | `[DEV]` |
| Regression Tests | P1 | `[TEST]` |
| CHANGELOG + AI_REGRESSION_LESSONS | P2 | `[DOCS]` |

---

## 13. 風險 / 假設 / 開放問題

（已執行 WebSearch：「tutoring management system makeup class attendance teacher self-report flow UX best practices 2026」、「school management system rescheduled class session audit trail retroactive mark design pattern」）

### 業界作法參考

**1. 補課/調課的 session 是獨立資料主體（Single Source of Truth）**

Tutorbase、TutorFlow 等主流補教管理系統將 session（堂次）視為唯一的資料主體，schedule（排課規則）只負責生成 session，不直接驅動出勤或帳單。補課時應立即建立一筆新 session，而不是只建 schedule 等後續流程來補建。（對應 AllTrue 的 BUG-A）

**2. 補登出勤必須支援「回溯日期」（Retroactive Date Picker）**

Schoolrunner 等系統提供「Absence Date」選擇器，管理員和老師可指定任何過去日期補登。規則：「`Absence Date` 決定出勤記錄的日期，和今天無關。」補教場景中老師補課後當天點名是標準流程，系統不應只允許當天（today）操作。（對應 BUG-C）

**3. 操作者身份 + 時間戳記 Audit Trail**

PowerSchool 的 Class Attendance Audit Report 記錄：出勤日期、輸入日期、輸入者、操作來源。OneTap 強調「每次打卡都帶 timestamp、方式、裝置」，當家長或管理員質疑出勤記錄時有據可查。AllTrue 目前缺少「誰在何時操作」的 trail，管理員無法確認老師自報是否成功。（對應設計限制）

**4. 出勤成功後自動串接評量、帳單、薪資**

Tutorbase 的設計：老師點「Attended」→ 自動觸發 invoice 建立、扣堂數、老師薪資記錄。AllTrue 已有扣堂數邏輯，但補課路徑（手動建 schedule）繞過這個閉環，評量必須額外手動操作。理想做法是建立「補課完整閉環」：schedule → ClassSession → attendance → evaluation（自動 unlock）。

**5. 明確的操作狀態回饋**

系統應在老師送出快速點名後，立即以明確的成功/失敗訊息（含補建的 ClassSession ID、日期、學生姓名）確認。避免靜默 422 讓老師誤判已完成。

### 風險

| 風險 | 可能性 | 緩解 |
|---|---|---|
| `ScheduleController::store` 在非補課情境也被呼叫，誤觸發建 ClassSession | 低 | 條件嚴格限定 `type=extra` 或 `status=rescheduled` |
| 出勤頁加入日期選擇器後，`fetchRecords` 回傳大量歷史資料導致效能問題 | 低 | 限制查詢日期區間最多 7 天；預設仍顯示今天 |
| 老師看到補課相關的「回溯補登」功能，可能操作時間不正確的日期 | 中 | 限制可回溯天數（建議 14 天內），超出範圍需 director 操作 |

### 開放問題

- Q1（已解決）：4/23 楊智超 × 薛米亞的出勤與 ClassSession 已由管理員手動補建完成，老師可填評量。
- Q2：`submitQuickAttend` 的 `StudentID` 確認缺失（已驗證：code 裡未帶此欄位），後端 422 是確認的根因。
- Q3：是否要在出勤頁加入「過去 N 天」的日期篩選，或只修 `submitQuickAttend` 讓老師可以選日期補建？（建議兩者都做，但分開 PR）

---

## 14. Definition of Done

- [ ] **FR-001**（ScheduleController 補建 ClassSession）：驗證方式：`POST /api/v1/schedules` 帶 type=extra → `SELECT COUNT(*) FROM ClassSession WHERE SessionDate='{date}'` 回傳 1
- [ ] **FR-002**（冪等）：驗證方式：連續呼叫兩次同參數 `POST /api/v1/schedules` → `SELECT COUNT(*)` 仍回傳 1
- [ ] **FR-003**（前端 guard）：驗證方式：在 AttendancePage 不帶 StudentID 觸發快速點名 → 前端不發出 API 請求（Network tab 確認）
- [ ] **FR-004**（前端錯誤顯示）：驗證方式：後端回傳 422 → 頁面出現 toast 含錯誤文字
- [ ] **FR-005**（資料補丁）：驗證方式：`SELECT * FROM ClassSession WHERE SessionDate = '2026-04-23' AND StudentClassID = {id}` 回傳 1 筆
- [ ] **Revert-proof**：驗證方式：`git stash && php artisan test --filter=MakeupScheduleCreatesClassSession` 至少 1 case failure
- [ ] **CHANGELOG**：驗證方式：`git diff docs/CHANGELOG.md` 含 `2026-04-24` 新增條目

---

## Todos

| 類別 | 工作項目 | Agent |
|---|---|---|
| 後端修復 | `ScheduleController::store` 補建 ClassSession（BUG-A / FR-001 / FR-002） | `[DEV]` |
| 前端修復 | `submitQuickAttend` 補上 StudentID + 錯誤顯示（BUG-B / FR-003） | `[DEV]` |
| 前端修復 | `submitQuickAttend` 加日期選擇器支援回溯補登（BUG-C / FR-004） | `[DEV]` |
| 前端修復 | 出勤頁歷史記錄加日期篩選器（設計限制 / FR-005） | `[DEV]` |
| Regression Tests | 新增 feature test：type=extra schedule → ClassSession 建立（FR-001）；submitQuickAttend 帶正確 StudentID 和日期 | `[TEST]` |
| Revert-proof 驗證 | git stash → 測試至少 1 failure | `[TEST]` |
| Code Review | 逐條對照 FR-001 ~ FR-005 | `[REVIEW]` |
| CHANGELOG + AI_REGRESSION_LESSONS | 更新兩份文件（補記：出勤頁寫死 today 是常見陷阱） | `[DOCS]` |
| 部署 | 後端 config clear + 前端 npm run deploy | `[OPS]` |

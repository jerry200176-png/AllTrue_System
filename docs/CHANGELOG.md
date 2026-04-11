# AllTrue Changelog

此檔記錄「已上線或已合併」的重要變更，讓後續 AI / 工程師可以快速理解最近的系統行為。

## 2026-04-12 (G) — 老師：教學工作台（預設首頁）與跨分校本週課表

### Added
- **`frontend/src/pages/TeacherHomePage.vue`**（`App.vue` 內 `active === 'teacher-home'`）：老師登入後預設首頁「**教學工作台**」— **今日待辦**（待點名／待填與需修改評量之 CTA）、**本週課表**（依 `teacherBranches` 對各 `branch_id` 並行呼叫 `fetchClassSessions` 後合併、排序、去重；每筆附分校標籤；週切換；單校失敗時其餘分校仍顯示並提示）、科目數與班級行事曆捷徑；多校時若他校當日有課則顯示輕提示。
- **`App.vue`**：`mergeTeacherAttendanceBadge()` — 老師輪詢時以 `GET /api/v1/class-sessions`（當日）計算待點名數，寫入 `badgeByType.attendance`，側欄「出缺勤管理」可顯示紅點（不依主任 `notifications/unread-count`）。

### Changed
- **老師導覽**：預設 `active` 由 `learning` 改為 `teacher-home`（登入、`fetchProfile`、改密碼完成後）；側欄順序為 教學工作台 → 出缺勤 → 課表與評量 → 班級行事曆 → 科目數…；手機底欄五格為 工作台／出勤／評量／行事曆／更多（科目數等入「更多」）。
- **`LearningRecordsPage.vue`（老師路徑 RWD）**：`.ts-fill-btn`、`.ts-event`、`.ts-tabs button` 觸控區與高度；`@media (max-width: 768px)` 內表單 `input`/`select`/`textarea` 設 `font-size: 16px`，降低 641–768px 寬度區間 iOS 自動縮放問題。

### Notes
- 從工作台週課表點「填評量」若堂次屬他校，會寫入 `localStorage.app_branch` 再切至「課表與評量」；`learningTargetRecordId` 仍由 `App.vue` 既有機制傳入（有 `recordId` 時）。
- 前端上線務必 **`cd frontend && npm run deploy`**，保持 `backend/public/index.html` 與 `assets` hash 一致（見本檔 **2026-04-12 (F)** 與 `docs/AI_REGRESSION_LESSONS.md`）。

### Docs（協作／防再犯索引）
- **`docs/AI_REGRESSION_LESSONS.md`**：新增 **「2026-04-12 — 老師教學工作台（TeacherHome）」** 專節（禁止回歸、關聯檔、搜尋關鍵字）。
- **`CONTRIBUTING.md`**、**`AGENTS.md`**、**`CLAUDE.md`**、**`.github/copilot-instructions.md`**、**`AI_QUICKSTART.md`**、**`docs/GITHUB_SYNC_WORKFLOW.md`**：補齊 `git pull`／新 clone 後閱讀順序與 TeacherHome 對照，供 GitHub 上人類與 Copilot／Claude／Cursor 一致遵循。

---

## 2026-04-12 (F) — 前端 deploy：`index.html` 與 `assets` 強制一致

### Fixed
- **整站白屏（`index-*.js` MIME type `text/html`）**：多為 `backend/public/index.html` 仍引用舊 hash 的 `./assets/index-*.js`，實體檔已換新名；請求 miss 時 SPA fallback 回傳 HTML。已執行完整 **`npm run deploy`** 修復線上檔案組合。

### Changed
- **`frontend/scripts/copy-to-backend.cjs`**：`index.html` 改以 **`writeFileSync` 整份寫入** `backend/public/`；部署結束後 **`verifyIndexHtmlReferencesAssets()`** 檢查 index 內所有 `./assets/` 引用是否皆存在，否則 **exit 1**，避免靜默留下不同步組合。

### Notes
- 詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-11 — 前端上線 hash 不同步）及同檔 **2026-04-12 補強** 列。

---

## 2026-04-12 (E) — 課程編輯時段同步修正

### Fixed
- **編輯課程時段被舊堂次覆寫（兩處分支）**：`StudentClassController::update` → `maybeRebuildSessionsAfterUpdate` 有兩條路徑會略過未來堂次時間同步：(a) 無 `StartDate` 時若堂次全被鎖定，`reconcile` 會用舊時間覆寫新值；(b) 前端固定帶 `first_class_date`（開課日未變），後端直接回 `start_date_unchanged` 完全不跑 `syncFutureScheduledSessionTimes`，`reconcile` 再次覆寫。修正：路徑 (a) 當 `updated_future_sessions === 0` 時跳過 reconcile 並回傳 `reconcile_skipped` + `warning`；路徑 (b) 開課日未變但排程欄位有變時，仍呼叫 `syncFutureScheduledSessionTimes` 同步未來堂次時間。
- **智慧排課 410 主控台噪音**：`SmartCalendar.vue` 仍對已退役的 `POST /api/v1/student-classes/sync` 發請求（後端固定回 410），移除該呼叫。

### Added
- `scheduleFieldsPresentInMapped()` 輔助方法，判斷本次 PUT 是否含排程欄位變更。
- 前端 `CourseManagement.vue` 於 `session_sync.reconcile_skipped` 時顯示明確提示。
- **測試**：`StudentClassUpdateScheduleReconcileTest`（4 tests）涵蓋：有歷史 + 未來 scheduled 堂次正常同步、帶 `first_class_date` 但開課日未變 + 改時段仍同步、全鎖定時 reconcile 跳過、非排程變更仍 reconcile。

---

## 2026-04-12 (D) — 智慧排課：評量未填標示

### Added
- **課表格新增「評」小標**：在日檢視與週檢視的課程區塊上，若該堂次已結束且尚無學習評量紀錄，右下角顯示紅色「評」標籤，與既有的到班（✓）、漏點（!）、請假（假）小標並存。
- **圖例更新**：工具列圖例新增「評 未填評量」說明。
- **排除條件**：請假（leave / excused / leave_adjusted）、取消、未來堂次不顯示未填提示。

### Notes
- 資料來自現有 `GET /api/v1/class-sessions` 回傳的 `learning_record_id` / `learning_record_status`，無新增 API 請求。
- MVP 定義：無任何有效 `LearningRecord` 列（`learning_record_status === 'missing'`）。若需區分「有 pending 列但內容空白」，可在後續 Phase 2 擴充後端欄位。

---

## 2026-04-12 (C) — 事後補點名（Makeup Attendance）

### Added
- **出缺勤管理新增「待補點名（已結束節次）」區塊**：主任／櫃檯可依日期範圍查詢過去已結束但尚未點名的堂次，直接在頁面上補登出缺勤狀態（到班／遲到／缺席／請假），與現有「今日待點名」並存。
- **`GET /api/v1/attendance/ended-sessions` 強化**：新增 `start_date`／`end_date` 日期篩選（預設最近 7 天）、分頁（`per_page` 最大 200）、`VoidedAt` 過濾（已作廢的出缺勤不視為已點名，允許重新補登）。未帶 `branch_id` 且無校區時回傳 422。
- **測試**：`MakeupAttendanceEndedSessionsTest`（7 tests）涵蓋：列表回傳、active sign-in 排除、voided sign-in 可補登、super_admin 必帶 branch_id、老師僅自己課程、跨分校 403、補登後扣堂與狀態更新。

### Notes
- 補登走既有 `POST /api/v1/attendance`（`mark_mode` 省略，走預設 ended 模式），商業規則與扣堂邏輯完全一致。
- 請假（excused）補登同樣觸發順延。
- 「今日待點名」與「待補點名」的產品區隔：前者查 `class-sessions` 當日 scheduled、使用 `mark_mode=arrival`；後者查 `ended-sessions` 已過結束時間且無 active sign-in。

---

## 2026-04-12 (B) — 請假與學習評量一致性修復

### Fixed
- **請假後評量仍出現在待填／待審列表**：`LearningRecordController::index` 及所有批次操作（`batchApprove`、`batchReject`、`batchRequestChanges`）現一律排除已作廢（`VoidedAt` 有值）的評量列。修正前，`CourseLeaveCascadeService` 請假時僅設定 `VoidedAt` 但列表查詢未過濾，導致已請假的堂次仍出現待審評量。
- **`ensurePastRecords` 對請假堂次不再補建評量**：排除 `ClassSession.Status` 為 `leave`、`excused`、`leave_adjusted` 的堂次；若該堂次已有被作廢的評量列，也不會重複建立新的 pending 評量。
- **通知與科目數統計防呆**：`NotificationSyncService::buildLearningNotifications`、`FinanceController`（`subjectUnits`、`teacherPayroll`、`summary`）、`ParentPortalController` 核准評量列表均補上 `->active()` 排除作廢列。
- **唯一查找一致化**：`LearningRecordController::store`、backfill、`StudentClassController`、`ClassSessionController`、`EnrollmentService` 中以 `ClassSessionID` 查找既有評量改為 `active()->first()`，避免與作廢列衝突或在唯一約束下重複建立。
- **第二層（孤兒 pending）**：僅 `active()` 仍會漏掉「`VoidedAt` 為空、但堂次已是請假」的歷史錯誤列（多為舊版 `ensure-past` 在請假後仍補建）。已新增 **`LearningRecord::excludeLeaveSessionPendingReview()`**，套用在 `index`、`batchApprove`、`buildLearningNotifications`；並對已存在之孤兒筆執行一次性作廢（理由：堂次已請假，系統自動作廢孤兒評量）。

### Notes
- **驗收條件**：已請假且評量已作廢的列不出現在老師／主任的待填／待審列表，不進待審評量通知，不因 `ensure-past` 自動補建。
- **歷史資料**：上線後若原先列表中有因此消失的評量，屬預期行為（該堂實際已請假）。
- **防再犯**：`docs/AI_REGRESSION_LESSONS.md` 內 **2026-04-12 — 請假與學習評量** 一節；營運可偶跑該節所列稽核 SQL，確認孤兒筆數為 0。

---

## 2026-04-12 — 出缺勤科目顯示、待點名科目、Subject 中文化、曠改請假順延、舊 SubjectID 映射

### Fixed
- **`GET /api/v1/attendance`**（`AttendanceController::index`）：`subject_name` 改為 **`COALESCE(課程主檔 Subject, 簽到快照 Subject)`**（`sub_sc`／`sub_si` 兩次 left join）。修正歷史資料僅在 `StudentSingIn.SubjectID` 有值、但 `StudentClass.SubjectID` 為空或指到無效列時，「今日出缺勤紀錄」科目欄大量為「—」的問題。
- **`GET /api/v1/class-sessions`**（`ClassSessionController::index`）：新增 `leftJoin Subject on sc.SubjectID`，回傳 `subject_name`。修正「今日待點名堂次」表格科目欄全部顯示「—」的問題（前端已讀取該欄位，僅後端未回傳）。
- **老師手機／出缺勤**：將某堂次標為 **`excused`（曠改請假）** 且帶既有 `ClassSessionID` 時，與課程管理請假一致：建立對應 `schedules` 請假列，並呼叫 **`CourseLeaveCascadeService::applyLeaveCascade`** 順延後續堂次、必要時延伸 `EndDate`；再寫入一筆 `StudentSingIn(Status=excused)` 供列表顯示。

### Changed
- **`Subject` 表 `Subject_Name` 改為中文**：Chinese→國文、English→英文、Math→數學、Physics→物理、Chemistry→化學、Science→理化、Social→社會（生物不變）。所有透過 Subject JOIN 取得科目名的 API 均直接回傳中文，使用者無需額外對照。前端 `constants.js` 的 `SUBJECT_NAME_MAP` 已支援中英雙向映射。
- **`ScheduleController`**：請假／補請假／retro-leave 等路徑改以 **`CourseLeaveCascadeService`** 為單一實作，降低與出缺勤請假邏輯分歧。

### Added
- **`backend/app/Services/CourseLeaveCascadeService.php`**：請假後鎖定課程與堂次、標記請假堂、void 相關評量等、後續預排前移並補尾堂（與 `ScheduleController` 請假／補請假路徑共用）。
- **Migration `2026_04_12_200000_remap_orphaned_subject_ids`**：一次性將舊系統殘留的 `SubjectID`（1／14／15／21）映射到目前 `Subject` 表對應列，同步更新 `StudentClass` 與 `StudentSingIn`。**部署新後端後請執行 `php artisan migrate`**，否則 JOIN `Subject` 仍可能得不到名稱。
- **測試**：`AttendanceSubjectNameResolutionTest`、`AttendanceExcusedLeaveCascadeTest`。

### Notes
- **僅有 ClassSession 請假、尚無簽到列**的補充查詢（supplemental）仍只依 **`StudentClass.SubjectID`**；若仍顯示「—」請於學生課程補齊科目。
- 新增科目時 `Subject_Name` 請使用中文（與現有資料一致）。

### Docs
- `docs/FAQ.md`、`docs/AI_REGRESSION_LESSONS.md`、`docs/OPERATIONS_RUNBOOK.md` §K（含曠改請假 2a、回歸檢查 migration 項）已同步本批行為。

---

## 2026-04-11 (D) — 加購課程自動建立排課堂次（ClassSession）

### Fixed
- **`POST /api/v1/student-classes/{id}/purchase-batch`**：加購新批次後，系統現在會自動依來源課程的星期／時段設定建立 `ClassSession` 排課列（使用 `buildSessionsForCount`），並更新新課程 `EndDate` 與計數器。此修復前，加購只建立 `StudentClass` 而無堂次，導致智慧排課（SmartCalendar）看不到該課程。
- 一次性補齊兩筆既有孤兒課程（#262、#187）的 `ClassSession`。

### Changed
- `purchase-batch` API 回應新增 `created_sessions` 欄位與 `new_course.end_date`。

---

## 2026-04-11 (C) — 繳費／續課提醒：加購自動結案 + 結案 UI

### Changed
- **加購新批次（`purchase-batch`）**：若來源課程為堂數制、已繳（`Paid=1`）、剩餘 0 堂，加購成功後自動將來源設 **`Stop=1`**（`EndDate` 寫入當日），不再出現在主任總覽「繳費／續課提醒」中。
- **主任總覽**：標題改為「繳費／續課提醒」；`low_sessions` 徽章改為「已繳 · 堂數已用完」或「已繳 · 剩 N 堂」，與「未繳 · N 堂」明確區分；「複製通知」針對 `low_sessions` 改為續課／加購用語。

### Added
- **結案（不續報）UI**：課程管理（`CourseManagement.vue`）與學生課程（`StudentsList.vue`）對「堂數制 + 已繳 + 0 堂 + 進行中」提供「結案（不續報）」按鈕；confirm 後呼叫既有 `POST .../student-classes/{id}/pause`（`Stop=1`），該課程從繳費提醒消失。
- 測試：`PurchaseBatchClosesSourceTest`（3 tests）驗證自動結案與防誤關。

### Docs
- `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`：新增「結案與不再提醒」段落、回歸測試項。
- `docs/FAQ.md`：新增上完不補／為何還提醒 → 結案操作。

---

## 2026-04-11 (B) — 核准評量 = 點名核課（重大架構變更）

### ⚠️ Breaking Change — 核准評量現在會扣堂

> **改動前必讀**：`docs/OPERATIONS_RUNBOOK.md` §K、`docs/AI_REGRESSION_LESSONS.md`（2026-04-11 核准評量扣堂）

### Changed
- **核准評量（`LearningRecordController::approve / batchApprove`）現在等同點名**：
  - 核准時透過 `ApprovalSessionSyncService::syncOnApprove` 建立 `StudentSignIn(Memo=lr_approve, SessionDeducted=true)`
  - 同步更新 `ClassSession.Status → attended`
  - 呼叫 `SessionDeductionService::deductOnAttendance`（與手動點名同一管線）
  - 堂數制：`RemainingSessions -1`；月結制：`UsedSessions +1`（`RemainingSessions` 恆 0）
- **退回核准（`rollbackApproval`）對稱沖回**：void `lr_approve` 型 SignIn → reverse ledger → 若無其他點名則 `ClassSession.Status → scheduled`
- **冪等保護**：若已有獨立點名（`SessionDeducted=true` SignIn），核准不重複扣堂；rollback 不影響獨立點名
- 核准後再手動 POST attendance → 回傳 409（已有 SignIn）

### Added
- **`backend/app/Services/ApprovalSessionSyncService.php`**（新服務）：`syncOnApprove` / `syncOnRollback`，含守衛規則（leave/cancelled/未來堂次 skip、冪等 skip）
- 測試新增 3 個情境：月結制、orphan LR 綁定、409 衝突
- 測試改寫 3 個情境：核准扣堂、已點名不重複扣、rollback 對稱沖回

### Docs
- `docs/OPERATIONS_RUNBOOK.md` §K 全面更新（口徑、禁忌、回歸清單 5 → 7 項）
- `docs/AI_REGRESSION_LESSONS.md` 新增防再犯條目
- `docs/CHANGELOG.md` 本節

### 受影響關鍵檔案（修改前必讀本節與 §K）
- `backend/app/Services/ApprovalSessionSyncService.php`
- `backend/app/Services/SessionDeductionService.php`
- `backend/app/Http/Controllers/LearningRecordController.php`（approve / batchApprove / rollbackApproval）
- `backend/app/Http/Controllers/AttendanceController.php`
- `backend/tests/Feature/LearningRecordApprovalDeductionTest.php`

---

## 2026-04-11

### Added
- 新增 **`docs/FAQ.md`**：專案常見問題（角色、部署、登入、GitHub 同步、文件索引）；**`docs/DIRECTOR_SCALING_FAQ.md`**：大分校／主任向效能與資料說明
- 新增內部聊天系統（`/api/v1/chat/*`）：
  - 1 對 1 聊天、群組聊天室、訊息列表、已讀標記、未讀統計；訊息／成員帶**頭像 URL**（根相對 `/storage/...`）
  - 資料表：`chat_threads`、`chat_thread_members`、`chat_messages`
  - 前端頁面：`frontend/src/pages/ChatPage.vue`
- 新增 Bug 回報系統（`/api/v1/bugs*`）：
  - 全系統可提交；**主任／老師僅能看自己的回報**；**僅 `super_admin` 可更新狀態與內部備註**
  - **截圖附件**：`bug_report_attachments`，`POST /bugs` 支援 `attachments[]`
  - **側欄紅點**：`GET /bugs/unread-badge`、`POST /bugs/mark-inbox-seen`（super_admin）、`bug_report_user_reads` 與 `User` 收件匣欄位
  - 資料表：`bug_reports`、`bug_report_comments`、`bug_report_status_logs`、`bug_report_attachments`、`bug_report_user_reads`
  - 前端：`frontend/src/pages/BugReportsPage.vue`、`BugReportLauncher.vue`；`App.vue` 合併 `badgeTypes: ['bugs']` 與 `alltrue-refresh-badges`
- **文件**：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**（後續 AI／工程師改動前必讀，含禁止回歸項）

### Changed
- **登入（`POST /api/v1/auth/login`）**：查詢候選使用者時排除 `User.status` 為 **`inactive`**、**`suspended`** 的列，避免帳號合併／停用後仍因 `LoginName`／`Name` 比對（含不分大小寫）而登入舊帳。測試：`tests/Feature/AuthInactiveUserLoginTest.php`。
- **登入（老師待審核）**：`type = T` 且尚無任一「已放行」分校（`UserCampus` 無 `Approved = 1` 或 `Approved IS NULL` 之列）時，回 **403**，`message` 提示聯繫主任審核，`code`：`teacher_pending_approval`（與舊版僅依 `require_campus` 擋 API 不同，登入階段即拒絕發 token）。無 `UserCampus.Approved` 欄位時仍僅依 `User.status === pending` 判斷。測試：`tests/Feature/AuthTeacherPendingApprovalLoginTest.php`。
- Bug：**已移除指派**（無 assign API／UI；詳情不再回傳承辦人欄位）
- Bug 狀態：`POST /bugs/{id}/status` 僅 **`middleware super_admin`**（`RequireSuperAdmin`）
- Bug 留言：恢復 `is_internal_note` 為「回報者不可見」；`super_admin` 可在詳情頁切換每則留言「內部 / 給回報者看」
- 使用者頭像：`User.AvatarUrl` 上傳後只存 **disk 相對路徑**；API 經 **`App\Support\PublicAvatarUrl`** 輸出，避免 `APP_URL=localhost` 造成聊天／側欄破圖
- UI：Bug 浮動鈕可拖曳；聊天選人顯示名稱正規化

### Infra / Notes
- Laravel Broadcasting：`backend/config/broadcasting.php`、`routes/channels.php`、`ChatMessageCreated`
- 測試：`ChatApiTest.php`、`BugReportApiTest.php`；頭像相關可搭配 `ProfileCenterApiTest.php`

### Follow-up
- 建議把 WebSocket（soketi）納入正式常駐程序。
- 建議修復 `frontend/node_modules` 權限問題（或沿用 vendor-modules alias）。

**完整行為與檢查清單**：`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`。

# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

**不同工具如何接到本檔：** **Cursor** 透過 `AGENTS.md` 與 `.cursorrules`；**Claude Code** 讀根目錄 **`CLAUDE.md`**；**GitHub Copilot**／在 GitHub 上工作的 AI 讀 **`.github/copilot-instructions.md`**；人類協作者請看 **`CONTRIBUTING.md`**（皆連回本檔與繳費規則）。

相關專項規格：

- 主任儀表板「繳費提醒」完整規則：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`
- 內部聊天、Bug 回報、使用者頭像（**含禁止回歸項**）：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**
- **手動排課日期＝已上完（過去日）**：**`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**（勿擅自改語意）

---

## 2026-04-11 — ⚠️ 核准評量 = 點名核課（架構級變更，改動前必問使用者）

| 項目 | 說明 |
|------|------|
| **架構決策** | 2026-04-11 起，**核准評量（LR approved）等同點名**：`ApprovalSessionSyncService::syncOnApprove` 會建立 `StudentSignIn(Memo=lr_approve)`、更新 `ClassSession.Status=attended`、呼叫 `deductOnAttendance`。rollback 對稱沖回。**此為產品方明確要求的重大架構變更**。 |
| **禁止回退此行為** | 任何 AI 或工程師**不得**將核准評量改回「不扣堂」、不得移除 `syncOnApprove` 呼叫、不得在 `approve/batchApprove/rollbackApproval` 內繞過 `ApprovalSessionSyncService`。如有疑慮，**必須先詢問使用者**後才可改動。 |
| **關鍵守衛規則** | leave/cancelled 跳過、未來堂次不預扣、已有扣堂 SignIn 則冪等跳過（不重複扣）、rollback 只 void `Memo='lr_approve'` 型 SignIn（不影響獨立點名） |
| **月結制** | `RemainingSessions` 恆 0，`UsedSessions` 透過 `recomputeCounters` 累加 |
| **改動前必讀** | `docs/OPERATIONS_RUNBOOK.md` §K（強制口徑）、`docs/CHANGELOG.md`（2026-04-11 B）、本檔本節 |
| **關聯檔案（改動前必問使用者）** | `ApprovalSessionSyncService.php`、`SessionDeductionService.php`、`LearningRecordController.php`（approve / batchApprove / rollbackApproval）、`AttendanceController.php`、`LearningRecordApprovalDeductionTest.php` |
| **測試** | `./vendor/bin/phpunit --filter=LearningRecordApprovalDeductionTest`（17 tests, 95 assertions，必須全綠） |

---

## 2026-04-11 — 前端上線：`index.html` 與 Vite hashed chunk 不同步（整站無法載入，嚴重）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `backend/public/index.html` 仍引用**舊 build** 的 `./assets/index-*.js`（Vite 產生的 hash 檔名），但 `backend/public/assets/` 內已是**另一輪** build 的檔名（或曾**只覆寫部分** `assets`、未一併更新 `index.html`）。瀏覽器請求不存在的 `.js` 時，Laravel SPA fallback（`routes/web.php` 的 `/{path?}`，`^(?!api)`）改回傳**同一個** `index.html`，`Content-Type` 為 **`text/html`**。ES module 載入器預期 JavaScript → 主控台出現 **`Failed to load module script... MIME type of "text/html"`**，**整個後台白屏／無法使用**。 |
| **正確行為** | 每次要上線前端變更，一律在 repo 內執行 **`cd frontend && npm run deploy`**（`vite build` + `node scripts/copy-to-backend.cjs`），讓 **`index.html` 與整個 `assets/` 目錄同一輪、一併覆寫**（copy 腳本會清空後再拷貝 `assets`）。**禁止**只手動複製部分 chunk、或只更新 `assets` 忘記 `index.html`、或讓邊緣快取長期持有**舊** `index.html` 卻打到**新**檔名的路徑。部署後**抽查**：`index.html` 裡 `<script type="module" ... src="./assets/index-….js">` 的檔名，**必須**實際存在於 `backend/public/assets/`。 |
| **關聯檔案** | `frontend/scripts/copy-to-backend.cjs`、`frontend/vite.config.js`（`base: './'`）、`backend/public/index.html`、`backend/routes/web.php`；Cursor 規則 **`.cursor/rules/auto-frontend-deploy.mdc`**（改 `frontend/src` 等後須 deploy）。 |

---

## 2026-04-11 — 手動「過去日期」必須維持「已上完」語意

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | AI 為處理「隔天建課、首堂在昨天」誤扣堂，將 **過去手動日改為預排**、並放寬 `EnrollmentService` 對 `future_dates` 的驗證，**違反營運既定邏輯**。 |
| **正確行為** | 使用者在月曆**手動點選今天以前**的日期＝**已上完／補登**（進 `confirmed_dates`、後端 `completed`＋扣堂流程）。**不得**在未經產品同意的情況下改為「錨點預排」。目前產品**僅**透過 **`UniversalClassScheduler.vue`**（排課 modal）操作；**前端正向入口已無「新生入班精靈」**（舊元件已自 repo 移除）。 |
| **關聯檔案** | `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`、`UniversalClassScheduler.vue`、`EnrollmentService.php` |

---

## 2026-04-11 — 新建課程「學段／科目」提示：前後端與 Vue ref

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** `UniversalClassScheduler.vue` 的 `scopeWarning` 把 **`subjectOptions` ref 物件**傳給 `checkTeacherScope`，未傳 **`subjectOptions.value`**，在 `<script setup>` 內不會自動 unwrap，導致比對邏輯對不到陣列、**畫面學段黃條形同失效**（載入 API 科目後尤其嚴重）。**(2)**（歷史、精靈已移除）舊版前端正向「入班精靈」元件曾用**寫死科目、無 `Subject.id`**，導致 `checkTeacherScope` 與 `POST /api/v1/enrollments` 後端語意不一致。**(3)** 前端只比對「選到的那一筆科目的單一 `id`」；`Subject` 表內**同名科目多筆 id**（歷史／分校資料）與老師授課設定裡的 `subject_id` 不一致時，出現**假陽性**：「老師設定沒有數學」其實有。後端已用 `TeacherScopeService::resolveEquivalentSubjectIds` 處理等價 id。 |
| **正確行為** | 所有**目前產品內**「新建課程」入口（學生管理、課程管理、智慧排課之 **`UniversalClassScheduler`**，以及 **`CourseEditForm`** 等）的**事前**學段提示，應與後端同一套語意：**同名科目多 id 一併納入比對**；傳入 `checkTeacherScope` 的科目列表必須是**陣列**（`ref` 請 `.value`）；科目選項須含 **`id`**（例如 `fetchSubjectOptions()`）。成功建立後仍應保留 **`class-sessions/batch`** 回傳的 **`scope_warning`**（alert）。後端 **`POST /api/v1/enrollments`** 仍存在（測試／整合）；若日後重做精靈 UI，須符合上列並與 `EnrollmentService` 一致。 |
| **關聯檔案** | `frontend/src/lib/constants.js`（`checkTeacherScope`）、`frontend/src/components/UniversalClassScheduler.vue`、`frontend/src/components/CourseEditForm.vue`、`frontend/src/lib/subjectsApi.js`、`backend/app/Services/TeacherScopeService.php`、`backend/app/Services/EnrollmentService.php` |

---

## 2026-04-11 — 智慧排課：同一門課「不同週幾、不同時段」不得只複製 `start_time`

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 學生同一 `StudentClass` 登記週二 17:00～19:00 與週六 10:00～12:00；**點名／出缺勤**依 **該日 `ClassSession`** 顯示正確，但 **智慧排課課表圖**在週六仍把區塊畫在 17:00～19:00。根因：`GET /api/v1/student-classes` 將 `start_time`／`end_time` 設為 **`day_time_slots` 排序後第一筆**（常為週序較前的那一天）；`SmartCalendar.vue` 的 `filteredCourses` 在依 **堂次日期集合**（`ClassSession` 載入的 `sessionDatesByCourseId`）展開格子時，只複製課程主檔時段，**未依該日 session 或星期幾覆寫時段**。 |
| **正確行為** | 課表格上每一格顯示的 **開始／結束／時長** 須與 **該日實際堂次**一致：優先該日 `ClassSession`（與點名、課程管理一致）；若無則用後端 **`day_time_slots` 對應 `dow`**；最後才退回主檔 `start_time`。勿假設「一門課全週同一 `start_time`」。 |
| **關聯檔案** | `frontend/src/pages/SmartCalendar.vue`（`resolveCourseGridTimes`、`filteredCourses` 合併）、`frontend/src/lib/classSessionsApi.js`、`backend/app/Http/Controllers/StudentClassController.php`（`day_time_slots`、主檔 `start_time` 語意） |

---

## 使用方式

1. 實作或重構觸及下方「關聯檔案」時，逐項確認行為是否仍符合「正確行為」。
2. 若引入新的高風險 regression，於本檔**以日期新增一節**（簡短：缺口 → 正確行為 → 關聯檔案／測試）。

---

## 2026-04-11 — 聊天頭像、Bug 附件／權限／紅點

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 頭像存成含 `APP_URL` 的完整 URL，區網開網頁時聊天／側欄破圖；Bug 主任誤以為能看全校；指派與狀態權限混在 `director` 路由。 |
| **正確行為** | 詳見 **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**：`PublicAvatarUrl`、只存 disk 路徑、主任／老師僅自己的 bug、僅 super_admin 狀態／mark-inbox、無指派、未讀紅點規則與路由順序。 |
| **關聯測試** | `ChatApiTest.php`、`BugReportApiTest.php`、`ProfileCenterApiTest.php`（頭像相關） |

---

## 2026-04-10 — 暫停課程、評量待審、繳費提醒、課程列表 UI

### A. 暫停課程（`StudentClass.Stop = 1`）仍出現在「待審評量」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 課程已暫停，主任儀表板與學習評量頁仍出現該課的 `pending`／`changes_requested` 評量，誤以為還要填寫／審核。 |
| **正確行為** | 暫停課程的待審／需修改評量**不應列入**待審佇列與相關通知；**已核准、已退回等歷史**仍可查。 |
| **實作要點** | `LearningRecord` scope `excludePausedCoursePendingReview`；`LearningRecordController::index` 套用；`batchApprove` 僅限未暫停之 `StudentClass`；`NotificationSyncService::buildLearningNotifications` 排除暫停課程。 |
| **測試** | `tests/Feature/LearningRecordApprovalDeductionTest.php`（`test_paused_course_hides_pending_learning_record_from_index_but_keeps_approved_visible`）。 |

### B. 課程管理列表：暫停狀態「看不出來」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 僅小標「已暫停」，整列與操作區與正常課程幾乎相同，主任沒有「真的暫停」的感受。 |
| **正確行為** | 整列背景／左側色條、科目欄上方 **明確 callout**（暫停說明）、學生群組標題 **「含暫停課程」**、展開的上課日期區塊視覺一致；**恢復**按鈕仍清楚可點。 |
| **關聯檔案** | `frontend/src/pages/CourseManagement.vue` |

### C. 主任儀表板「繳費提醒」漏提醒（堂數 0 堂、整類月結消失）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /api/v1/alerts/tuition` 只查 `ScheduleMode = 'count'`，**整個月結制（`date`）被略過**；堂數制用 `RemainingSessions > 0 && <= 2`，**漏掉 0 堂**；畫面顯示「全數已繳」易誤導。 |
| **正確行為** | **必須**與 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 一致（堂數制 ≤2 含 0、月結 `settlement_day`、距繳費日 &lt; 5 天、逾期未繳等）。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`、`frontend/src/pages/DirectorDashboard.vue` |
| **測試** | `tests/Feature/TuitionAlertsApiTest.php`、`tests/Feature/NotificationApiTest.php`（`test_tuition_alert_endpoint_includes_low_sessions_even_when_paid`） |
| **營運手冊** | `docs/OPERATIONS_RUNBOOK.md`（繳費提醒／tuition API 說明需與上列規格文件同步） |

### D. 通知 API 測試與 `unread-count` 內建 sync

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /notifications/unread-count` 會先執行 `NotificationSyncService::sync`，手動建立的 `Type=tuition` 等**託管類型**可能被自動結案；測試預期的 `active_count` 與實際 sync 來源數不一致。 |
| **正確行為** | 測試用手動通知時使用**非** `managedTypes` 的 `Type`；或斷言與目前 `buildTuition`／`buildLowSessions` 等合併後筆數一致。 |
| **關聯檔案** | `backend/app/Http/Controllers/NotificationController.php`、`backend/tests/Feature/NotificationApiTest.php` |

---

## 檢查清單（快速）

- **前端 bundle 有變**（`frontend/src/**` 等）→ 上線前／Agent 任務結束前必跑 **`cd frontend && npm run deploy`**；**切勿**留下「舊 `index.html` 引用舊 hash + `assets` 已是新 hash」或相反組合。異常徵兆：主控台 **`MIME type of "text/html"`** on `index-*.js` → 先對照本檔 **「index.html 與 Vite hashed chunk 不同步」**。

修改以下路徑時，至少重跑相關 Feature tests：

- `ApprovalSessionSyncService.php` / `SessionDeductionService.php` / `LearningRecordController.php`（approve/batchApprove/rollbackApproval）→ **改動前必問使用者**；`LearningRecordApprovalDeductionTest`（17 tests 全綠）
- `LearningRecordController.php` / `LearningRecord.php` → LearningRecord 測試
- `AlertController.php`（`tuition`）→ `TuitionAlertsApiTest` + `NotificationApiTest`（tuition 相關）
- `NotificationSyncService.php` → `NotificationApiTest`
- `ChatService.php` / `ChatController.php` / `PublicAvatarUrl.php` / `AuthController.php`（`uploadAvatar`、`toAvatarUrl`）→ `ChatApiTest` + `ProfileCenterApiTest`
- `BugReportService.php` / `BugReportController.php` → `BugReportApiTest`
- `CourseManagement.vue` → 手動確認暫停列 UI；有腳本則 `npm run deploy`
- `EnrollmentService.php` / `UniversalClassScheduler.vue` → 必讀 **`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**，勿改「過去手動＝已上完」；學段提示見本檔 **2026-04-11 — 新建課程「學段／科目」提示**（`ref` 傳 `.value`、科目選項須含 `id`）。**前端正向已無入班精靈**；勿在文件或回覆中假設仍有 `EnrollmentWizard.vue`
- `checkTeacherScope` / `TeacherScopeService.php` → 科目多 id、前後端等價比對一致；勿只比對單一 `subject_id`
- `SmartCalendar.vue`（`filteredCourses`、堂數制與 `sessionDatesByCourseId`）→ 多日／多時段須對齊 **該日 `ClassSession` 或 `day_time_slots`**，勿全週套用主檔 `start_time`；見本檔 **2026-04-11 — 智慧排課：同一門課「不同週幾、不同時段」**；變更後 `npm run deploy`

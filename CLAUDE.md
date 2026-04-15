# Claude Code — AllTrue 專案指引

本檔放在儲存庫根目錄，供 **Claude Code**（以及未掛載 Cursor 專案規則的 AI 工具）自動載入。  
**與 `AGENTS.md` 互補**：技術細節仍以 `AGENTS.md`、`.cursorrules` 為主；本檔負責**必讀路徑**與**防再犯**提醒。

## 必讀順序（修改程式前）

1. **`AGENTS.md`** — 任務守則、First-read 清單、常見坑
2. **`AI_QUICKSTART.md`** — 專案與協作流程速覽
3. **`docs/AI_REGRESSION_LESSONS.md`** — **防再犯**：已發生過的缺口（暫停課程與評量待審、**請假後仍出待審評量（作廢列與孤兒 pending）**、**調課後評量表作廢未恢復**（`ensurePastRecords` un-void、`leave→attended` 須恢復 LR）、繳費提醒漏月結／0 堂、暫停 UI、**手動過去日＝已上完**、**固定排課契約與堂次（批次入班／列表時段／改星期同步未來堂）**、**老師教學工作台（TeacherHome／跨分校週課表／badge／預設頁）**、**智慧排課同格 `cancelled+scheduled` 誤標取消**、**老師註冊 vs `directors/pending`、Teacher 重複鍵**、**催繳名單／`tuition-slip`／已繳不產圖**、**增加購買堂數後第 N+1 堂起未自動產生**、**`index.html` 與 Vite hashed chunk 不同步導致整站 MIME 錯誤** 等）
4. **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** — 若動到主任儀表板「繳費／續課提醒」或 `GET /api/v1/alerts/tuition`（`AlertController::tuition`）；**邏輯變更前必問使用者**（見該檔「變更管制」）
5. **`docs/OPERATIONS_RUNBOOK.md`**、**`docs/GITHUB_SYNC_WORKFLOW.md`**
6. **`docs/CHANGELOG.md`**
7. **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**（聊天／Bug／頭像完整手冊）
8. **`docs/CHAT_BUG_SYSTEM.md`**（速覽）

## 高風險變更前請對照

| 主題 | 先讀 |
|------|------|
| **核准評量扣堂**（`ApprovalSessionSyncService` / `SessionDeductionService` / `LearningRecordController` approve/batchApprove/rollbackApproval） | **改動前必須先詢問使用者**。`docs/AI_REGRESSION_LESSONS.md`（2026-04-11 核准評量）、`docs/OPERATIONS_RUNBOOK.md` §K、`docs/CHANGELOG.md`（2026-04-11 B） |
| **請假與評量**（`VoidedAt`、`ensurePastRecords`、`excludeLeaveSessionPendingReview`、`NotificationSyncService`） | `docs/AI_REGRESSION_LESSONS.md`（2026-04-12 — 請假與學習評量；**2026-04-13 — 調課後評量作廢未恢復**：已上堂須 un-void／`leave→attended` 須恢復 LR）、`docs/CHANGELOG.md`（2026-04-12 B、**2026-04-13 (Q)**）、`AGENTS.md` Common pitfalls |
| 學習評量、待審列表、`LearningRecord`、`Stop`（暫停） | `docs/AI_REGRESSION_LESSONS.md` §2026-04-10 A |
| 課程列表暫停 UI | 同上 §B、`CourseManagement.vue` |
| 繳費提醒、堂數／月結 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` + 同上 §C |
| **催繳名單頁、出圖 API**（`tuition-collect`、`alerts/tuition-slip`、`PaymentSlipModal` 雙模式、已繳不產圖、稽核 log） | **`docs/AI_REGRESSION_LESSONS.md`（2026-04-13 — 催繳名單）**、**`docs/CHANGELOG.md`（2026-04-13 (K)）** |
| 通知 sync、`NotificationSyncService` | 同上 §D |
| 聊天、Bug、使用者頭像 URL | **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**、`docs/CHAT_BUG_SYSTEM.md`、`docs/CHANGELOG.md` |
| 批次排課／入班、`confirmed_dates`／`future_dates`、手動月曆日期語意 | **`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**、`docs/AI_REGRESSION_LESSONS.md`（2026-04-11 一節） |
| **課程時段顯示**（`reconcileWeekTimeFieldsFromSessions`、`index()` `$sessionSlotsByClassId`、`ensurePastRecords` StartTime/EndTime）、**出缺勤補請假 retro-leave**、**出缺勤 VoidedAt 過濾** | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-11 — 手動補登日期汙染課程時段顯示） |
| **固定排課契約與堂次**（`UniversalClassScheduler`、`EnrollmentService::store`、`StudentClassController::index` 契約過濾、`syncFutureScheduledSessionTimes`／`remapFutureScheduledSessionsToContract`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-12 — 固定排課契約與堂次一致）、`AGENTS.md` Common pitfalls |
| **老師教學工作台**（`teacher-home`、`TeacherHomePage.vue`、`mergeTeacherAttendanceBadge`、跨分校週課表、老師預設 `active`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-12 — 老師教學工作台）、**`docs/CHANGELOG.md`（2026-04-12 (G)）**、`CONTRIBUTING.md` |
| **科目名稱顯示**（`student_class_label`、`subject_name`、科目下拉）、**待補點名過濾**（`endedSessions whereNotIn attended`）、**開課日重建**（`hasImmutableSessionHistory VoidedAt`、`partial_rebuild`、`force_partial_rebuild`、`CourseManagement` modal click.self）、**手動排課日期限制**（`sessionCountForYmd`、`EnrollmentService` 星期驗證、`onDateClick` past bypass） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-12 — 科目顯示、排課彈性、待補點名、開課日重建）、**`docs/CHANGELOG.md`（2026-04-12 (H)）** |
| **專注模式與 modal 層級**、**契約時段不得被 ClassSession 覆寫**（`focus-fullscreen-mode`、`$sessionSlotsByClassId` → `schedule_drift`、`editCourse`、`formatDayTimeSlotLines`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-12 — 專注模式與 modal z-index / 契約時段不得被覆寫）、**`docs/CHANGELOG.md`（2026-04-12 (I)）** |
| **老師管理「授課學段」**（`subject_level_scopes`、`teacher_subject_levels`、`ProfileController`、`TeachersList.vue`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13）、`AGENTS.md` Common pitfalls |
| **課程 Stop 語意與 `closed_reason`**（`purchaseBatch`、`togglePause`、`CourseManagement.vue` / `StudentsList.vue` 暫停 vs 結算顯示） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — closed_reason）、**`docs/CHANGELOG.md`（2026-04-13 (M)）** |
| **出缺勤「補登」**（`GET /api/v1/attendance/ended-sessions`、`AttendanceController::endedSessions`、`StudentClass.Stop`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — 出缺勤補登與 `StudentClass.Stop`）、**`docs/CHANGELOG.md`（2026-04-13 (S)）** |
| **智慧排課同格誤標取消**（`SmartCalendar.vue`、`useCourseSessionsDisplay.js`、代課 `session_id` 解析） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-14 — 智慧排課角標誤判）、**`docs/CHANGELOG.md`（2026-04-14 (F)）**；禁止回退成 `.find()` 第一筆 |
| **老師自助註冊 vs 主任待審**（`AuthController::register`、`Teacher` unique、`GET directors/pending`、`DirectorAccountsPage.vue`、`TeachersList` 待審核） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-15）、**`docs/CHANGELOG.md`（2026-04-15 (B)）**；`pending` 須過濾 `User.type`，勿只靠 `UserCampus.Approved` |
| **側欄 `pending_teachers` vs「待審核」**（`NotificationController::unreadCount`、`UserCampus.Approved`、`TeachersList` `status=pending`、`ProfileController::update`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-15 第二節）、**`docs/CHANGELOG.md`（2026-04-15 (C)）**；兩者欄位不同，勿混用 |
| **當月學收（取代帳單列表）**（`TuitionReportPage.vue`、`FinanceController::branchMonthlyTuition`、側欄 `tuition-report`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — 當月學收月報）、**`docs/CHANGELOG.md`（2026-04-13 (N)）**；**勿把 `billing` / `BillingList` 加回側欄** |
| **增加購買堂數後第 N+1 堂起未自動產生**（`StudentClassController::update`、`extendSessionsIfNeeded`、`cancelExcessScheduledSessions`） | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — 增加購買堂數後第 N+1 堂起未自動產生）；`update` 必須在縮減之後緊接呼叫 `extendSessionsIfNeeded`；**勿整刪重建全部堂次**，應只補差額 |
| 前端上線、`backend/public`、瀏覽器 **`MIME type "text/html"`** on `index-*.js` | **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-11 — 前端上線／hash 不同步）；務必 **`npm run deploy`** 同輪寫入 `index.html` + `assets/` |

## 協作分支

- GitHub 協作主分支：**`jerry-sync-main`**（見 `AGENTS.md`）

## 前端變更上線

- 修改 `frontend/src` 等後執行：`cd frontend && npm run deploy`（見專案規則與 `AGENTS.md`）。**禁止**只覆寫部分 `assets` 或讓 `index.html` 與 chunk 檔名脫節，否則 SPA fallback 會把 HTML 當 JS 回傳、整站無法載入（見 **`docs/AI_REGRESSION_LESSONS.md`**）。

## Commit SOP（與 AGENTS 同步）

- 完成一個可驗收的子功能後，應建立一筆 commit（避免多功能混在一起）。
- commit 前必須確認：
  - 程式碼語法正確且可執行（至少完成相應 build / lint / 型別檢查）。
  - 若有既有測試腳本，至少通過該變更範圍的基本測試。
- 禁止將「單一檔案拼字修正」或「純格式微調」單獨 commit；除非該項目本身就是獨立任務。

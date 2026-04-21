# AGENTS.md

If you are an AI agent working in this repo, read this file first.

**Other AI surfaces (same rules):** **Claude Code** loads root **`CLAUDE.md`**; **GitHub Copilot** / AI on github.com should read **`.github/copilot-instructions.md`**; human contributors see **`CONTRIBUTING.md`**. All point back here and to `docs/AI_REGRESSION_LESSONS.md`.

## Mission

Keep the AllTrue system stable while delivering small, verifiable changes.

## First-read order

1. `README.md`
2. `AI_QUICKSTART.md`
3. `docs/OPERATIONS_RUNBOOK.md`
4. **`docs/AI_REGRESSION_LESSONS.md`**（防再犯：暫停課程／繳費提醒／**催繳名單與 `tuition-slip`（已繳不產圖）**／評量待審／**調課後評量表作廢未恢復**（`ensurePastRecords` un-void、`leave→attended` 須恢復 LR）／**固定排課契約與堂次**／**老師教學工作台（TeacherHome）**／**智慧排課同格 `cancelled+scheduled` 誤標取消**／**老師註冊 vs `directors/pending`、Teacher 重複鍵**／**增加購買堂數後第 N+1 堂起未自動產生**／**前端 `index.html` 與 hashed assets 必須同輪 deploy** 等已踩過的坑）
5. `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`（主任「繳費／續課提醒」業務規則；改 `AlertController::tuition` 或總覽對應邏輯**前必讀**，且**變更須先經使用者／產品明示同意**）
6. `docs/GITHUB_SYNC_WORKFLOW.md`
7. `docs/CHANGELOG.md`（近期功能異動與權限調整）
8. **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**（聊天／Bug／頭像：**已實作細節與禁止回歸**，改動前必讀）
9. `docs/CHAT_BUG_SYSTEM.md`（同上模組速覽與檔案索引）

## Non-negotiable rules

- Collaboration branch is `jerry-sync-main`
- Do not merge backup branches into main collaboration flow
- 禁止任何形式的版本回朔（rollback/revert-to-old-state）覆蓋現行已上線邏輯；除非使用者明確要求且指定範圍
- 禁止以舊檔覆蓋新檔（特別是 `routes/api.php`、`frontend/src/**`、`backend/app/**`、`docs/AI_REGRESSION_LESSONS.md`）
- 涉及高風險檔案修改後，必須做「存在性驗證」：不得讓既有關鍵路由／功能因編輯而消失
- Avoid destructive git operations on a dirty worktree
- If frontend code changed, run `cd frontend && npm run deploy`
- Validate API health after deploy/config edits

## Commit SOP（所有 AI 必須遵守）

- 完成一個**完整子功能**（可驗收、可回歸）就應建立一筆 commit，避免把多個需求混在同一筆。
- commit 前必做：
  - 程式碼語法正確、可執行（至少完成對應 build / lint / 型別或等價檢查）。
  - 若該模組已有測試腳本，至少跑基本測試並確認通過（最小可接受集合）。
- 禁止只因「單一檔案拼字修正」或「純格式微調」單獨 commit；除非該變更是明確獨立任務（例如文件專案、專門的 typo 任務）。
- commit 訊息需描述「為何」與子功能邊界；不要用含糊訊息（例如 `update` / `fix stuff`）。

## Project context (short)

- Frontend: Vue 3 in `frontend/`
- Backend: Laravel 8 in `backend/`
- Auth: token in `localStorage.alltrue_session`
- Domain: students, classes, schedules, attendance, billing, learning records
- **Teacher default home (2026-04-12)**: `active=teacher-home` → `frontend/src/pages/TeacherHomePage.vue`; cross-campus weekly schedule merges per-branch `class-sessions`; see `docs/CHANGELOG.md` (2026-04-12 (G)) + `docs/AI_REGRESSION_LESSONS.md` (TeacherHome section)
- **Director tuition list + slips (2026-04-13)**: `active=tuition-collect` → `TuitionCollectionPage.vue` (data from `GET /api/v1/alerts/tuition`); unpaid-only slip via `tuition-slip` or invoice `slip-data`; see `docs/CHANGELOG.md` (2026-04-13 (K))

## Common pitfalls

> ### 🚨 P0 事故防再犯（2026-04-21）— 測試清空生產資料庫
> **任何 AI 在執行測試前，必須先確認：**
> 1. `backend/.env.testing` 的 `DB_DATABASE=AllTrue_test`（**不是 AllTrue**）
> 2. `backend/phpunit.xml` 所有 DB 相關設定使用 `<env>` tag（**不是 `<server>`**，`<server>` 對 Laravel env() 無效）
> 3. `tests/TestCase.php` 的 production DB guard 存在且未被 bypass
> 4. 執行前驗證：`APP_ENV=testing php artisan tinker` → `config('database.connections.mysql.database')` 必須回傳 `AllTrue_test`
>
> **違反後果**：`RefreshDatabase` trait 對生產 DB 執行 `migrate:fresh` → 全部資料表清空 → 全系統 401 停機。詳見 `docs/AI_REGRESSION_LESSONS.md`（2026-04-21）與 `.cursor/rules/no-test-on-production-db.mdc`

- **核准評量 = 點名扣堂**（2026-04-11 架構級變更）：`ApprovalSessionSyncService` / `SessionDeductionService` / `LearningRecordController`（approve / batchApprove / rollbackApproval）為高風險檔案，**改動前必須先詢問使用者**。禁止將核准改回「不扣堂」。詳見 `docs/AI_REGRESSION_LESSONS.md`、`docs/OPERATIONS_RUNBOOK.md` §K
- **請假與評量**（2026-04-12）：待審列表不能只靠 `VoidedAt`；須保留 **`excludeLeaveSessionPendingReview`**（堂次 `leave`/`excused`/`leave_adjusted` 不顯示 pending）、**`ensurePastRecords`** 不對請假堂補建、讀取財務／家長端仍要 **`active()`**。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-12 — 請假與學習評量）
- **調課後評量「消失」**（2026-04-13）：請假 cascade 作廢 LR 後，同一 `ClassSession` 經 `reschedule-session` 改日並標已上時，**`ensurePastRecords` 須能 un-void**；**`leave→attended`** 須 **`restoreVoidedLearningRecord`**。勿改回「有作廢列就永遠跳過」。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — 調課／請假 cascade 後評量表作廢未恢復）、**`docs/CHANGELOG.md`（2026-04-13 (Q)）**
- **固定排課契約與堂次一致**（2026-04-12）：動 **`UniversalClassScheduler`／`EnrollmentService::store`／`StudentClassController::index`（列表時段）／`syncFutureScheduledSessionTimes`（編輯課程改星期）** 前必讀 **`docs/AI_REGRESSION_LESSONS.md`** 該節；禁止恢復「非固定星期手動日 fallback」、禁止列表用預排覆寫而不過濾契約、禁止只改時間卻不對契約外星期的未來堂重算日期。
- **老師教學工作台（TeacherHome）**（2026-04-12）：動 **`TeacherHomePage.vue`／`App.vue`（老師預設 `active`、`mergeTeacherAttendanceBadge`、側欄／底欄）** 前必讀 **`docs/AI_REGRESSION_LESSONS.md`** 該節與 **`docs/CHANGELOG.md`（2026-04-12 (G)）**；禁止週課表 silently 改回僅單校、禁止漏接 badge 合併、禁止他校填評量不切分校。
- **老師管理「授課學段」**（2026-04-13）：**`GET/PUT /api/v1/profiles`、老師列表、`TeachersList.vue`** 須與 **`teacher_subject_levels`／`TeacherScopeService`**、**`PUT /api/v1/me`** 一致帶出並可編輯 **`subject_level_scopes`**；勿只做科目不做學段。詳見 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-13）**。
- 未讀 `docs/AI_REGRESSION_LESSONS.md` 就改評量／**繳費／續課提醒（`AlertController::tuition`）**／**催繳名單（`TuitionCollectionPage`）／`tuitionSlipData`（`alerts/tuition-slip`）**／暫停課程相關邏輯，易重複已修過的 regression；**`tuition` 列入條件變更前必問使用者**（見 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 變更管制）；**已繳（`Paid=1`）不得產出催繳／繳費單圖**（前後端皆須擋）。
- **只更新部分** `backend/public/assets`、或 `index.html` 與 chunk **不同步**，會觸發整站 **`MIME type "text/html"`** on `index-*.js`（SPA fallback 誤當 JS）；務必 **`npm run deploy`** 一輪寫入。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-11 — 前端上線／hash 不同步）
- **勿把「帳單列表」（`active = 'billing'`、`BillingList.vue`）加回側欄**——已被「當月學收」（`TuitionReportPage.vue`、`active = 'tuition-report'`）取代（2026-04-13 (N)）。`BillingList.vue` 與 Invoice API 保留在程式中但不掛載。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — 當月學收月報）
- **增加購買堂數後須補建 ClassSession**（2026-04-13）：`StudentClassController::update` 更新 `SessionCount` 時，**僅縮減才呼叫 `cancelExcessScheduledSessions`** 是不夠的——增加時必須緊接呼叫 `extendSessionsIfNeeded` 補建差額堂次；勿整刪重建；`currentCount` 排除 `cancelled` 但含 `leave`/`attended`。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — 增加購買堂數後第 N+1 堂起未自動產生）
- **出缺勤「補登」`ended-sessions`**（2026-04-13）：`AttendanceController::endedSessions` 組 `classIds` 時須 **`where('Stop', 0)`**（主任／老師路徑皆然）；暫停後堂次多為 `cancelled`，僅靠 `whereNotIn(Status, attended/…)` **不會**排除，勿移除 `Stop` 篩選。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-13 — 出缺勤補登…）、**`docs/CHANGELOG.md`（2026-04-13 (S)）**
- **智慧排課同格誤標取消**（2026-04-14）：`SmartCalendar` 同日同時段多筆堂次（`cancelled + scheduled`）禁止用 `.find()` 拿第一筆；必須走共用優先序解析器（`scheduled` 高於 `cancelled`，同狀態 `id desc`）。代課 `session_id` 選取與 `useCourseSessionsDisplay` 也要同口徑。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-14 — 智慧排課角標誤判）、**`docs/CHANGELOG.md`（2026-04-14 (F)）**
- **老師自助註冊與主任待審**（2026-04-15）：**`GET /api/v1/directors/pending`** 不可只依 `UserCampus.Approved=false`；須排除 **`type=T`**。老師核准在 **`TeachersList` 待審核**。`Teacher` 表 `(CampusID, T_Name)` 衝突時 **`insertOrIgnore`**，勿裸 `insert` 導致整筆註冊 500。詳見 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-15）**、**`docs/CHANGELOG.md`（2026-04-15 (B)）**
- **老師管理側欄橘點 vs「待審核」**（2026-04-15）：側欄 **`pending_teachers`** 來自 **`notifications/unread-count`**（**`UserCampus.Approved=false`**）；**「待審核」tab** 只看 **`User.status=pending`**。核准 **`PUT profiles` → `active`** 須同步 **`UserCampus.Approved`**（見 **`ProfileController::update`**）。勿把兩種計數混為同一語意。詳見 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-15 第二節）**、**`docs/CHANGELOG.md`（2026-04-15 (C)）**
- **編輯課程費率後 Charge 未同步**（2026-04-15）：`StudentClassController::update()` 更新 `Rate` 或 `SessionCount` 後**必須重算 `Charge`**（`session` 模式：`Rate × SessionCount`；`hour` 模式：`Rate × TotalHours`）。勿移除 `update()` 中的 Charge 同步區塊。催繳單（`AlertController::tuitionSlipData`）直接讀 `Charge`，若未同步則金額永遠停在建課時的舊值。詳見 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-15 — 編輯課程費率後 Charge 未同步）**
- **課程管理 chip 重複（LEFT JOIN 行乘積）**（2026-04-15）：`ClassSessionController::index` 的 `sub_sched`/`LearningRecord`/`StudentSingIn` LEFT JOIN 若對應多筆，會使同一 `ClassSession` 出現 N 次。**必須**用 Derived Table（`MAX(id)` per group）限定為 1:1。前端 `normalizeClassSessionsPayload` 須有 id 去重防禦。詳見 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-15 — LEFT JOIN 行乘積）**、**`docs/CHANGELOG.md`（2026-04-15 (I)）**
- Wrong PR target branch
- Stale Laravel cache files under `backend/bootstrap/cache/`
- Broken `.env` DB credentials
- Missing routing files (`backend/server.php`, `backend/public/.htaccess`)
- Permission mismatch in frontend build folders
- 變更聊天 / Bug 回報時未先確認 `docs/CHAT_BUG_SYSTEM.md` 的角色權限矩陣（目前僅 `super_admin` 可處理 bug）

## Working style

- Make minimal scoped edits
- Explain why changes are needed
- Keep docs updated when process changes
- Prefer reversible operations


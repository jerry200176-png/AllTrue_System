# AGENTS.md

If you are an AI agent working in this repo, read this file first.

**Other AI surfaces (same rules):** **Claude Code** loads root **`CLAUDE.md`**; **GitHub Copilot** / AI on github.com should read **`.github/copilot-instructions.md`**; human contributors see **`CONTRIBUTING.md`**. All point back here and to `docs/AI_REGRESSION_LESSONS.md`.

## Mission

Keep the AllTrue system stable while delivering small, verifiable changes.

## First-read order

1. `README.md`
2. `AI_QUICKSTART.md`
3. `docs/OPERATIONS_RUNBOOK.md`
4. **`docs/AI_REGRESSION_LESSONS.md`**（防再犯：暫停課程／繳費提醒／評量待審／**固定排課契約與堂次**／**老師教學工作台（TeacherHome）**／**前端 `index.html` 與 hashed assets 必須同輪 deploy** 等已踩過的坑）
5. `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`（主任「繳費／續課提醒」業務規則；改 `AlertController::tuition` 或總覽對應邏輯**前必讀**，且**變更須先經使用者／產品明示同意**）
6. `docs/GITHUB_SYNC_WORKFLOW.md`
7. `docs/CHANGELOG.md`（近期功能異動與權限調整）
8. **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**（聊天／Bug／頭像：**已實作細節與禁止回歸**，改動前必讀）
9. `docs/CHAT_BUG_SYSTEM.md`（同上模組速覽與檔案索引）

## Non-negotiable rules

- Collaboration branch is `jerry-sync-main`
- Do not merge backup branches into main collaboration flow
- Avoid destructive git operations on a dirty worktree
- If frontend code changed, run `cd frontend && npm run deploy`
- Validate API health after deploy/config edits

## Project context (short)

- Frontend: Vue 3 in `frontend/`
- Backend: Laravel 8 in `backend/`
- Auth: token in `localStorage.alltrue_session`
- Domain: students, classes, schedules, attendance, billing, learning records
- **Teacher default home (2026-04-12)**: `active=teacher-home` → `frontend/src/pages/TeacherHomePage.vue`; cross-campus weekly schedule merges per-branch `class-sessions`; see `docs/CHANGELOG.md` (2026-04-12 (G)) + `docs/AI_REGRESSION_LESSONS.md` (TeacherHome section)

## Common pitfalls

- **核准評量 = 點名扣堂**（2026-04-11 架構級變更）：`ApprovalSessionSyncService` / `SessionDeductionService` / `LearningRecordController`（approve / batchApprove / rollbackApproval）為高風險檔案，**改動前必須先詢問使用者**。禁止將核准改回「不扣堂」。詳見 `docs/AI_REGRESSION_LESSONS.md`、`docs/OPERATIONS_RUNBOOK.md` §K
- **請假與評量**（2026-04-12）：待審列表不能只靠 `VoidedAt`；須保留 **`excludeLeaveSessionPendingReview`**（堂次 `leave`/`excused`/`leave_adjusted` 不顯示 pending）、**`ensurePastRecords`** 不對請假堂補建、讀取財務／家長端仍要 **`active()`**。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-12 — 請假與學習評量）
- **固定排課契約與堂次一致**（2026-04-12）：動 **`UniversalClassScheduler`／`EnrollmentService::store`／`StudentClassController::index`（列表時段）／`syncFutureScheduledSessionTimes`（編輯課程改星期）** 前必讀 **`docs/AI_REGRESSION_LESSONS.md`** 該節；禁止恢復「非固定星期手動日 fallback」、禁止列表用預排覆寫而不過濾契約、禁止只改時間卻不對契約外星期的未來堂重算日期。
- **老師教學工作台（TeacherHome）**（2026-04-12）：動 **`TeacherHomePage.vue`／`App.vue`（老師預設 `active`、`mergeTeacherAttendanceBadge`、側欄／底欄）** 前必讀 **`docs/AI_REGRESSION_LESSONS.md`** 該節與 **`docs/CHANGELOG.md`（2026-04-12 (G)）**；禁止週課表 silently 改回僅單校、禁止漏接 badge 合併、禁止他校填評量不切分校。
- 未讀 `docs/AI_REGRESSION_LESSONS.md` 就改評量／**繳費／續課提醒（`AlertController::tuition`）**／暫停課程相關邏輯，易重複已修過的 regression；**後者邏輯變更前必問使用者**（見 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 變更管制）
- **只更新部分** `backend/public/assets`、或 `index.html` 與 chunk **不同步**，會觸發整站 **`MIME type "text/html"`** on `index-*.js`（SPA fallback 誤當 JS）；務必 **`npm run deploy`** 一輪寫入。詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-11 — 前端上線／hash 不同步）
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


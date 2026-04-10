# AGENTS.md

If you are an AI agent working in this repo, read this file first.

**Other AI surfaces (same rules):** **Claude Code** loads root **`CLAUDE.md`**; **GitHub Copilot** / AI on github.com should read **`.github/copilot-instructions.md`**; human contributors see **`CONTRIBUTING.md`**. All point back here and to `docs/AI_REGRESSION_LESSONS.md`.

## Mission

Keep the AllTrue system stable while delivering small, verifiable changes.

## First-read order

1. `README.md`
2. `AI_QUICKSTART.md`
3. `docs/OPERATIONS_RUNBOOK.md`
4. **`docs/AI_REGRESSION_LESSONS.md`**（防再犯：暫停課程／繳費提醒／評量待審等已踩過的坑）
5. `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`（主任儀表板「繳費提醒」業務規則，改 `AlertController::tuition` 前必讀）
6. `docs/GITHUB_SYNC_WORKFLOW.md`
7. `docs/CHANGELOG.md`（近期功能異動與權限調整）
8. `docs/CHAT_BUG_SYSTEM.md`（聊天＋Bug 回報模組交接）

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

## Common pitfalls

- 未讀 `docs/AI_REGRESSION_LESSONS.md` 就改評量／繳費提醒／暫停課程相關邏輯，易重複已修過的 regression
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


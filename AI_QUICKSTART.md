# AI Quickstart (for `/home/admin`)

This file is the fast onboarding guide for any AI/engineer entering this workspace.

## 1) What this project is

AllTrue is a cram-school operations platform:
- student/course management
- scheduling + leave/reschedule/makeup
- attendance + RFID
- billing and payment tracking
- learning records approval flow

## 2) Stack and folders

- Frontend: `frontend/` (Vue 3 + Vite)
- Backend: `backend/` (Laravel 8)
- Docs/SOP: `docs/`
- Utility scripts: `scripts/`

## 3) Collaboration branch model

- GitHub collaboration branch: `jerry-sync-main`
- Local daily branch: `main` (tracks `origin/jerry-sync-main`)

Avoid operating against unrelated `origin/main` history.

## 4) Safe daily workflow

```bash
git checkout main
git pull
# edit code
./scripts/git-sync.sh "feat: your change"
```

For larger work:
```bash
git checkout -b feature/your-topic
# work...
./scripts/git-sync.sh "feat: your-topic"
```
Then open PR into `jerry-sync-main`.

## 5) Critical do/don't (important)

Do:
- keep working tree clean before branch surgery
- verify app/API health after infra or config changes
- keep `.env` DB credentials consistent with actual DB users

Do NOT:
- force push shared branch unless explicitly approved
- run risky reset/rebase on dirty tree
- use `sudo` in project folders unless absolutely required

## 6) Production safety checks

Use these minimal checks:
```bash
curl -I https://daan.lifenet.com.tw
curl -i https://daan.lifenet.com.tw/api/v1/branches
```

If API fails:
- check `backend/.env` DB credentials
- remove stale cache files under `backend/bootstrap/cache/`
- confirm `backend/public/.htaccess` exists

## 7) Read next

- **`CONTRIBUTING.md`**（人類 + 各 AI 工具入口）
- `README.md`
- `docs/OPERATIONS_RUNBOOK.md`
- **`docs/AI_REGRESSION_LESSONS.md`**（AI／工程必讀，避免已修問題再犯）
- `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`（繳費提醒 API 規則）
- `docs/CHANGELOG.md`（近期上線內容與權限調整）
- `docs/CHAT_BUG_SYSTEM.md`（聊天＋Bug 回報交接）
- `docs/GITHUB_SYNC_WORKFLOW.md`
- **Claude Code**：`CLAUDE.md` · **GitHub Copilot**：`.github/copilot-instructions.md`
- `docs/INCIDENT_2026-04-10_GITHUB_AND_SITE_ROLLBACK.md`


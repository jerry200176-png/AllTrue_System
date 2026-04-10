# Claude Code — AllTrue 專案指引

本檔放在儲存庫根目錄，供 **Claude Code**（以及未掛載 Cursor 專案規則的 AI 工具）自動載入。  
**與 `AGENTS.md` 互補**：技術細節仍以 `AGENTS.md`、`.cursorrules` 為主；本檔負責**必讀路徑**與**防再犯**提醒。

## 必讀順序（修改程式前）

1. **`AGENTS.md`** — 任務守則、First-read 清單、常見坑
2. **`AI_QUICKSTART.md`** — 專案與協作流程速覽
3. **`docs/AI_REGRESSION_LESSONS.md`** — **防再犯**：已發生過的缺口（暫停課程與評量待審、繳費提醒漏月結／0 堂、暫停 UI 等）
4. **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** — 若動到主任儀表板「繳費提醒」或 `GET /api/v1/alerts/tuition`（`AlertController::tuition`）
5. **`docs/OPERATIONS_RUNBOOK.md`**、**`docs/GITHUB_SYNC_WORKFLOW.md`**
6. **`docs/CHANGELOG.md`**、**`docs/CHAT_BUG_SYSTEM.md`**（聊天＋Bug 回報）

## 高風險變更前請對照

| 主題 | 先讀 |
|------|------|
| 學習評量、待審列表、`LearningRecord`、`Stop`（暫停） | `docs/AI_REGRESSION_LESSONS.md` §2026-04-10 A |
| 課程列表暫停 UI | 同上 §B、`CourseManagement.vue` |
| 繳費提醒、堂數／月結 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` + 同上 §C |
| 通知 sync、`NotificationSyncService` | 同上 §D |
| 聊天功能、Bug 回報權限 | `docs/CHAT_BUG_SYSTEM.md` + `docs/CHANGELOG.md` |

## 協作分支

- GitHub 協作主分支：**`jerry-sync-main`**（見 `AGENTS.md`）

## 前端變更上線

- 修改 `frontend/src` 等後執行：`cd frontend && npm run deploy`（見專案規則與 `AGENTS.md`）

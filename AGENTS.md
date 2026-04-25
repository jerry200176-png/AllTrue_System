# AGENTS.md — AllTrue AI Agent 入口

> **Cursor 使用者**：`.cursorrules` 已自動載入，這裡補充必讀順序與 Commit SOP。

## 開工前 First-read 順序

1. `.cursorrules`（P0 事故 + 安全快評 + 工作流程概覽）— **自動載入，已讀**
2. **`docs/INDEX.md`（導航地圖，決定接下來只讀哪些章節）— 必讀，省 token 關鍵**
3. `docs/AI_REGRESSION_LESSONS.md`（已發生過的缺口，改高風險模組前必讀）
4. `.cursor/.local/test-credentials.md`（做任何瀏覽器測試前讀）
5. 若涉及繳費/提醒邏輯：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`

## Commit SOP

每個獨立可驗收的子任務完成後立即 commit：

```bash
git add <相關檔案>
git commit -m "<type>(<scope>): <one-line summary>

<optional body: 說明 why，不是 what>"
```

- **type**：`feat` / `fix` / `refactor` / `test` / `docs` / `chore`
- **scope**：模組名（`billing` / `attendance` / `rfid` / `auth` 等）
- **禁止**：`git push --force`、跳過 CI、一次 commit 混入多個不相關的改動

## 其他工具入口

- **Claude Code**：讀根目錄 `CLAUDE.md`（若存在）
- **GitHub Copilot**：讀 `.github/copilot-instructions.md`（若存在）
- **人類協作者**：讀 `CONTRIBUTING.md`（若存在）

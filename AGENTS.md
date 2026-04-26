# AGENTS.md — AllTrue AI Agent 入口

> **Cursor 使用者**：`.cursorrules` 已自動載入，這裡補充必讀順序與 Commit SOP。
>
> **AllTrue AI 公司 slogan：前人種樹，後人乘涼。**
> 所有 Agent 做事前先查 `docs/INDEX.md` 與 MemPalace；做完把決策寫回文件，讓下一個 AI 不靠猜測。

## 開工前 First-read 順序

1. `.cursorrules`（P0 事故 + 安全快評 + 工作流程概覽）— **自動載入，已讀**
2. **`docs/INDEX.md`（導航地圖，決定接下來只讀哪些章節）— 必讀，省 token 關鍵**
3. 需要回顧決策或 bug 時：`~/.local/bin/mempalace search "<關鍵字>"`
4. `docs/AI_REGRESSION_LESSONS.md`（已發生過的缺口，改高風險模組前必讀）
5. `.cursor/.local/test-credentials.md`（做任何瀏覽器測試前讀）
6. 若涉及繳費/提醒邏輯：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`

## 公司治理記錄原則

- 新功能 / bug fix 上線：更新 `docs/CHANGELOG.md`。
- AI 犯錯或發現防再犯規則：更新 `docs/AI_REGRESSION_LESSONS.md`。
- 本次不修但會影響未來維護：更新 `docs/TECH_DEBT.md`。
- 複雜架構、資料流或 SOP：更新 `docs/SYSTEM_TECH_GUIDE.md` 或 `docs/OPERATIONS_RUNBOOK.md`。
- 文件不要複製長 SOP；入口文件只導航，單一主題只保留一個權威出處。

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

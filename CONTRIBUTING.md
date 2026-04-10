# 貢獻與協作說明（AllTrue）

歡迎貢獻。本專案同時有**人類**與**AI 助理**（Cursor、Claude Code、GitHub Copilot 等）參與，下列文件請在開工前讀過，避免已修復的問題再次發生。

## 必讀文件（所有人）

| 順序 | 檔案 | 用途 |
|------|------|------|
| 1 | [`AGENTS.md`](AGENTS.md) | AI／工程共通守則、First-read 清單、協作分支 |
| 2 | [`AI_QUICKSTART.md`](AI_QUICKSTART.md) | 專案結構與日常 Git 流程 |
| 3 | [`docs/AI_REGRESSION_LESSONS.md`](docs/AI_REGRESSION_LESSONS.md) | **防再犯**：已知缺口與正確行為 |
| 4 | [`docs/GITHUB_SYNC_WORKFLOW.md`](docs/GITHUB_SYNC_WORKFLOW.md) | GitHub 與 `jerry-sync-main` 流程 |
| 5 | [`docs/OPERATIONS_RUNBOOK.md`](docs/OPERATIONS_RUNBOOK.md) | 部署、避坑、事故處理 |
| 6 | [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | 近期變更摘要（先看再改） |
| 7 | [`docs/CHAT_BUG_SYSTEM.md`](docs/CHAT_BUG_SYSTEM.md) | 聊天＋Bug 回報模組交接與權限矩陣 |

若變更**主任儀表板繳費提醒**或 **`AlertController::tuition`**，另讀：  
[`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](docs/DIRECTOR_PAYMENT_ALERT_RULES.md)。

## 使用不同 AI 工具時

| 工具 | 專用入口 |
|------|-----------|
| **Cursor** | 專案內 `.cursorrules`、`.cursor/rules/*.mdc`（會與 `AGENTS.md` 一併套用） |
| **Claude Code** | 根目錄 [`CLAUDE.md`](CLAUDE.md) |
| **GitHub Copilot**（網頁／IDE） | [`.github/copilot-instructions.md`](.github/copilot-instructions.md) |

以上入口**都指向同一套** `AGENTS.md` 與 `docs/AI_REGRESSION_LESSONS.md`，避免各工具各說各話。

## Git 與 PR

- 協作主分支：**`jerry-sync-main`**（非任意 `main`／備份分支）。
- 提交前請確認前端若有改動已執行 `cd frontend && npm run deploy`（見 `AGENTS.md`）。
- 觸及評量、繳費提醒、通知 sync 時，請執行相關 PHPUnit 測試（見 `docs/AI_REGRESSION_LESSONS.md` 文末檢查清單）。

## 新增「防再犯」項目

若你修了一個**容易再犯**的產品或邏輯錯誤，請在 **`docs/AI_REGRESSION_LESSONS.md`** 以日期新增一節簡短說明，並在必要時更新 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** 等專項文件。

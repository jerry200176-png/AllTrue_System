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
| 7 | [`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`](docs/AI_HANDOFF_CHAT_BUG_AVATAR.md) | 聊天／Bug／頭像：**完整手冊與禁止回歸**（改動前必讀） |
| 8 | [`docs/CHAT_BUG_SYSTEM.md`](docs/CHAT_BUG_SYSTEM.md) | 同上模組速覽與檔案索引 |

**老師端／教學工作台（2026-04-12 起）**：動預設首頁、側欄、手機底欄、`TeacherHomePage`、跨分校週課表合併、或 **`mergeTeacherAttendanceBadge`** 前，請讀 **`docs/CHANGELOG.md`（2026-04-12 (G)）** 與 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-12 — 老師教學工作台）**，避免與「跨校一覽／點名導覽」規格衝突。

若變更**主任儀表板繳費提醒**或 **`AlertController::tuition`**，另讀：  
[`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](docs/DIRECTOR_PAYMENT_ALERT_RULES.md)。

**催繳名單／繳費單圖（2026-04-13 起）**：動 **`TuitionCollectionPage.vue`**、**`PaymentSlipModal.vue`**、**`GET /api/v1/alerts/tuition-slip/*`** 或 **`BillingController::slipData`** 前，請讀 **`docs/CHANGELOG.md`（2026-04-13 (K)）** 與 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-13 — 催繳名單）**；名單須與 **`alerts/tuition`** 一致，**已繳不產圖**。

**評量表與調課／請假 cascade（2026-04-13 起）**：動 **`LearningRecordController::ensurePastRecords`**、**`reschedule-session`**、**`CourseLeaveCascadeService`**、或 **`ClassSessionController::update`（`leave`↔已上）** 前，請讀 **`docs/CHANGELOG.md`（2026-04-13 (Q)）** 與 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-13 — 調課／請假 cascade 後評量表作廢未恢復）**；勿改回「有作廢 LR 就永遠跳過不處理」。

## 使用不同 AI 工具時

| 工具 | 專用入口 |
|------|-----------|
| **Cursor** | 專案內 `.cursorrules`、`.cursor/rules/*.mdc`（會與 `AGENTS.md` 一併套用） |
| **Claude Code** | 根目錄 [`CLAUDE.md`](CLAUDE.md) |
| **GitHub Copilot**（網頁／IDE） | [`.github/copilot-instructions.md`](.github/copilot-instructions.md) |

以上入口**都指向同一套** `AGENTS.md` 與 `docs/AI_REGRESSION_LESSONS.md`，避免各工具各說各話。

## Git 與 PR

- 協作主分支：**`jerry-sync-main`**（非任意 `main`／備份分支）。
- 未經需求方明示同意，禁止版本回朔（rollback/revert-to-old-state）與「用舊覆新」。
- 涉及高風險檔案（如 `routes/api.php`）時，提交前需驗證關鍵路由／功能未被靜默移除。
- 提交前請確認前端若有改動已執行 `cd frontend && npm run deploy`（見 `AGENTS.md`）。
- 觸及評量、繳費提醒、通知 sync 時，請執行相關 PHPUnit 測試（見 `docs/AI_REGRESSION_LESSONS.md` 文末檢查清單）。
- **`git pull` 或新 clone 後**：請再掃一眼 **`docs/CHANGELOG.md` 最上方數則**，GitHub／其他 AI 協作時以變更紀錄為單一真相來源（與 `AGENTS.md` First-read 一致）。

## 新增「防再犯」項目

若你修了一個**容易再犯**的產品或邏輯錯誤，請在 **`docs/AI_REGRESSION_LESSONS.md`** 以日期新增一節簡短說明，並在必要時更新 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** 等專項文件。

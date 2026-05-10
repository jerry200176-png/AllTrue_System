# AGENTS.md — AllTrue AI Agent 入口

> **Cursor 使用者**：`.cursorrules` 已自動載入，這裡補充必讀順序與 Commit SOP。
>
> **AllTrue AI 公司 slogan：前人種樹，後人乘涼。**
> 所有 Agent 做事前先查 `docs/INDEX.md` 與 MemPalace；做完把決策寫回文件，讓下一個 AI 不靠猜測。

## 開工前 First-read 順序

**SOP（防重踩同坑）**：收到任務後**先讀文檔再打程式**，禁止只靠對話上下文硬改。高風險模組（代課／評量／智慧行事曆合併／扣堂／繳費提醒等）必須對照下面第 2、4 步與 `AI_REGRESSION_LESSONS` 文末**模組索引表**對應 §，再動 `backend/`、`frontend/src/`。

1. `.cursorrules`（P0 事故 + 安全快評 + 工作流程概覽）— **自動載入，已讀**
2. **`docs/INDEX.md`（導航地圖，決定接下來只讀哪些章節）— 必讀，省 token 關鍵**
2b. 任務牽涉 **長文件 / 多份 docs** 時：`docs/AI_DOC_LITERACY.md`（速讀卡、CHANGELOG→公告、MemPalace 何時 mine）
3. 需要回顧決策或 bug 時：`~/.local/bin/mempalace search "<關鍵字>"`
4. `docs/AI_REGRESSION_LESSONS.md`（已發生過的缺口，改高風險模組前必讀；並查文末**模組對照索引**挑 §）
5. `.cursor/.local/test-credentials.md`（做任何瀏覽器測試前讀）
6. 若涉及繳費/提醒邏輯：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`

## 公司治理記錄原則

- 新功能 / bug fix 上線：更新 `docs/CHANGELOG.md`。
- AI 犯錯或發現防再犯規則：更新 `docs/AI_REGRESSION_LESSONS.md`。
- 本次不修但會影響未來維護：更新 `docs/TECH_DEBT.md`。
- 複雜架構、資料流或 SOP：更新 `docs/SYSTEM_TECH_GUIDE.md` 或 `docs/OPERATIONS_RUNBOOK.md`。
- 文件不要複製長 SOP；入口文件只導航，單一主題只保留一個權威出處。

## Agent Orchestration SOP

開工前先判斷任務類型，避免把 AI 協作變成額外認知負擔：

| 類型 | 定義 | 處理方式 |
|---|---|---|
| Fire-and-forget | 錯字、footer 日期、單一連結、小型 lint/docs 修正 | 累積到 docs batch；不要單獨開 PR 浪費 Actions |
| Context-dependent | API 串接、前後端同改、README/Runbook 同步 | 先產 artifact（API contract、diff、測試結果），下游只讀 artifact |
| Decision-requiring | DB schema、auth、堂數/繳費、CI/CD、備份/還原 | 必須進 PLAN/ARCH 或 BUG B1，等使用者批准後才 DEV |

強制原則：
- 以 bounded context 切任務，不以 migration/model/controller/frontend/test 這種技術層硬切碎。
- 規劃/ARCH/BUG B1 不可只靠模型記憶：研究順序為 `本專案 Docs/MemPalace` → `大公司/業界做法` → `相關開源專案實作`，最後才收斂為 AllTrue 的取捨。
- Agent handoff 只交 output artifact，不要求下游讀完整推理過程。
- PRD/ARCH 至少寫到 architecture boundary：API 合約、資料邊界、權限、錯誤處理、多校區隔離。
- 沒有 architecture boundary 的需求，不進 DEV；讓 agent 猜架構會把 decision load 丟給錯的人。
- 多 agent 或多 PR 任務需指定 `[INT] Integration Owner` 檢查 artifact 能否接起來；完成後由 `[DOCS/MEM] Memory Curator` 決定寫回哪份長期記憶文件。
- 完成後把有效策略寫回 `AI_REGRESSION_LESSONS.md`、`TECH_DEBT.md` 或 `SYSTEM_TECH_GUIDE.md`，讓下一個 session 不重學。

### 外部 Agent Playbook 引用

可參考 [`msitarzewski/agency-agents`](https://github.com/msitarzewski/agency-agents) 的角色設計、deliverable 格式與多工具整合概念，但它不是 AllTrue 產品功能，也不可整包安裝覆蓋本 repo 規則。

引用時遵守：
- 只挑選角色/交付物模板，改寫成 AllTrue bounded context 的 artifact handoff。
- 所有外部 agent 規則都必須服從 `.cursorrules`、P0 gate、分校隔離、CI/deploy SOP。
- 禁止一鍵匯入大量 `.cursor/rules/*.mdc`；新增或改 rule 必須走 T0 docs-only PR。
- 若外部流程與 AllTrue P0 安全規則衝突，永遠以 AllTrue 規則為準。

### Workflow Risk Tiers

大廠 workflow 的重點不是所有任務都變重，而是讓風險決定流程重量：

| Tier | 範圍 | 必要流程 |
|---|---|---|
| T0 Docs-only | README、FAQ、INDEX、Runbook、規則文件，且不碰 `.github/**` / `scripts/**` | docs batch → `git diff --check` → PR；避免 deployable diff |
| T1 Low-risk code | 單一 UI 顯示、純 helper、無資料寫入、無權限邊界 | 小 PR → 對應測試/build → REVIEW |
| T2 Product workflow | 前後端契約、排課、出缺勤、評量、跨分校查詢 | PLAN/ARCH → DEV → TEST → INT → REVIEW |
| T3 Safety-critical | auth、PII、RFID、LINE webhook、堂數扣除、繳費、migration、備份/還原、CI/CD | PLAN/ARCH + SEC + OPS；使用者批准後才實作，CI 綠才可 merge |

**Definition of Ready（進 DEV 前）**
- 已定義 product intent、architecture boundary、API/DB/data ownership、錯誤處理、多校區隔離。
- 已判斷 Tier、是否需要 SEC/OPS/DBA、是否能平行。
- 已列出不可碰的檔案/邏輯與必讀文件。

**Definition of Done（回報完成前）**
- PR CI 狀態明確；docs-only 要確認未混入 deployable diff。
- 有使用者可驗收的測試或 smoke test 清單。
- 新規則、事故、技術債、架構決策已寫回正確文件。

**Stop-the-line 條件**
- 發現可能寫 production DB、繞過 auth、暴露 token/PII、直接 push `main`、force push、或在 Pi production 跑測試。
- CI/deploy 狀態不明但要回報「完成」。
- 備份/restore 目標不確定，或無法確認 restore drill 不會碰 production `AllTrue`。

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
- **GitHub 協作（分支／PR／Issue／通報）**：`CONTRIBUTING.md`、`SECURITY.md`
- **人類協作者**：先讀 `README.md`、`docs/INDEX.md`、本檔 `AGENTS.md`

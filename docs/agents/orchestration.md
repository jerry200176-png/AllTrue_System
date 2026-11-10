# Agent orchestration（多 agent／規劃任務才讀）

> 2026-11-11 從 `AGENTS.md` 原文搬來；規則沒有改。

## Agent Orchestration SOP

開工前先判斷任務類型，避免把 AI 協作變成額外認知負擔：

| 類型 | 定義 | 處理方式 |
|---|---|---|
| Fire-and-forget | 錯字、footer 日期、單一連結、小型 lint/docs 修正 | 累積到 docs batch；不要單獨開 PR 浪費 Actions |
| Context-dependent | API 串接、前後端同改、README/Runbook 同步 | 先產 artifact（API contract、diff、測試結果），下游只讀 artifact |
| Decision-requiring | DB schema、auth、堂數/繳費、CI/CD、備份/還原 | 先進 PLAN/ARCH 或 BUG B1，釐清產品意圖與受保護邊界；可先完成實作、測試與證據包，在受保護的合併、正式啟用或資料操作前取得 Founder 對具體範圍的決定。重大產品方向無明確最佳選項時，先取得決定再實作 |

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

工程技能工作流可參考 [`addyosmani/agent-skills`](https://github.com/addyosmani/agent-skills) — **完整評估與安裝 SOP 見 [`docs/GUIDE_AGENT_SKILLS.md`](../GUIDE_AGENT_SKILLS.md)**（繁中）；UI 品味另見同文件 §taste-skill（[`Leonxlnx/taste-skill`](https://github.com/Leonxlnx/taste-skill)，已裝 `design-taste-frontend`／`redesign-existing-projects`）。AllTrue 本地化技能見 [`docs/GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md`](../GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md) 與 `.claude/skills/alltrue-*`（只挑技能本地化，禁止整包覆蓋 P0 / PRD 流程；UI 仍以 `docs/RULE_DESIGN_SYSTEM.md` 為準）。

引用時遵守：
- 只挑選角色/交付物模板，改寫成 AllTrue bounded context 的 artifact handoff。
- 所有外部 agent 規則都必須服從 `CLAUDE.md` 紅線、P0 gate、分校隔離、CI/deploy SOP。
- 禁止一鍵匯入大量外部 rule 檔；新增或改 rule 必須走 T0 docs-only PR。
- 若外部流程與 AllTrue P0 安全規則衝突，永遠以 AllTrue 規則為準。

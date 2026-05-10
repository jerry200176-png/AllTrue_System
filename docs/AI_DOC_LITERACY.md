# AI 讀檔協議 — 長文不漏讀、決策可追溯

> **目的**：讓 AI（與新人）在 **不通讀** 數百行文件的前提下，仍能命中正確規則；並與 **CHANGELOG / MemPalace** 銜接，避免「對話結束就失憶」。  
> **原則**：導航在 `INDEX`，細節在權威檔；本檔只提供 **怎麼讀**，不重複貼 SOP。

---

## 參考文獻（權威順序）

| 層級 | 檔案 | 用途 |
|------|------|------|
| 流程 | [`AGENTS.md`](../AGENTS.md) | First-read、Risk tier、DoD |
| 導航 | [`docs/INDEX.md`](INDEX.md) | 任務 → 該讀哪個檔、哪一節 |
| 寫作規則 | [`.cursor/rules/doc-writing.mdc`](../.cursor/rules/doc-writing.mdc) | 禁止雙份長文、腳本優先 |
| 治理節奏 | [`docs/DOCS_GOVERNANCE_SOP.md`](DOCS_GOVERNANCE_SOP.md) | CHANGELOG、integrity check、MemPalace 保鮮 |
| 大廠對齊 | [`docs/ENTERPRISE_WORKFLOW_ALIGNMENT.md`](ENTERPRISE_WORKFLOW_ALIGNMENT.md) | CI/deploy 邊界、分眾資訊（例 §R45） |

---

## 五步讀檔法（預設每次任務）

1. **讀** [`docs/INDEX.md`](INDEX.md) 對應列 → 決定「唯一權威檔」。
2. **只讀** 下表「必讀錨點」；其餘章節 **除非 INDEX 明確點名** 否則不讀。
3. **長文**用 repo 內搜尋：`rg -n "關鍵字" docs/某檔.md`（或 MemPalace `search`），不要 eyeball 掃全文。
4. **歷史 / archive**（`*ARCHIVE*`、`.cursor/plans/**`）→ **不通讀**，只搜尋。
5. **做完**寫回：`CHANGELOG`（使用者可感知）、`AI_REGRESSION`（新紅線）、`TECH_DEBT`（欠債）；必要時 `mempalace mine`（見下節）。

---

## 版本更新（CHANGELOG → 產品內公告）

| 產物 | 權威位置 | AI 注意 |
|------|----------|---------|
| 人類可讀異動 | [`docs/CHANGELOG.md`](CHANGELOG.md) | 一行一條；**先查日期/關鍵字** 再讀 |
| 教職員版公告卡 | `frontend/src/lib/releaseNotes.generated.js` | **勿手改**；來源為腳本 |
| 產生腳本 | `scripts/changelog-to-release-notes.mjs` | 改規則後 `cd frontend && npm run sync-release-notes` |
| 家長分眾 | [`AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) §R45、`CLAUDE.md` G-008 | 家長僅 `audience` 含 `parent` |

---

## MemPalace（對話與文件入庫）

**目的**：補足「模型 context 以外的長期記憶」；**不能**取代 INDEX / 權威 docs。

| 時機 | 動作 | 詳見 |
|------|------|------|
| Session 開始 | `mempalace wake-up`（若已索引） | [`docs/INDEX.md`](INDEX.md) §MemPalace 導航 |
| 查舊決策 / 事故 | `mempalace search "關鍵字" [--wing …]` | 同上 |
| PR merge 後 / docs 大改 | `mempalace mine …` | [`docs/DOCS_GOVERNANCE_SOP.md`](DOCS_GOVERNANCE_SOP.md) §4 |

> 指令全文與 wings 說明：**只維護一份** → 見 [`INDEX.md`](INDEX.md)「MemPalace 導航」區塊，避免與本檔重複分叉。

---

## 速讀卡：核心與規則

| 檔案 | 讀這份的目的 | 太長時怎麼讀 |
|------|----------------|----------------|
| [`INDEX.md`](INDEX.md) | 任務 → 檔案/章節路由 | 全檔可讀；以表格為單位跳讀 |
| [`AGENTS.md`](../AGENTS.md) | Agent 流程、Commit、Risk tier | 讀 §開工前 + §Orchestration + §DoD |
| [`CLAUDE.md`](../CLAUDE.md) | 與 `.cursorrules` 對照的總覽 | **勿與 `.cursorrules` 同任務重複通讀**；只讀 Gotchas / API 表 |
| `.cursorrules` | P0、工作流程 | 已自動載入；改規則時再讀對應段 |
| [`AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) | 防再犯紅黃線、模組→條目索引 | **先讀** 開頭摘要 + **模組索引表** + 相關 `Rxx` 全文 |
| [`AI_REGRESSION_LESSONS_ARCHIVE.md`](AI_REGRESSION_LESSONS_ARCHIVE.md) | 舊事故長敘 | **禁止通讀**；`rg` / MemPalace |
| [`QA_GOLDEN_SCENARIOS.md`](QA_GOLDEN_SCENARIOS.md) | Golden ↔ CI | 讀標題與與你改動相關的 § |

---

## 速讀卡：業務規則

| 檔案 | 讀這份的目的 | 太長時怎麼讀 |
|------|----------------|----------------|
| [`DIRECTOR_PAYMENT_ALERT_RULES.md`](DIRECTOR_PAYMENT_ALERT_RULES.md) | 繳費/續課提醒邏輯 | **擅改前必問使用者**；用 `rg` 找狀態/條件 |
| [`PRICING_CONTRACT.md`](PRICING_CONTRACT.md) | 每堂費用語意 | 只讀與計價相關段落 |
| [`ROLE_PLAYBOOK.md`](ROLE_PLAYBOOK.md) | 角色 UI / SOP | 只讀對應角色 § |
| [`FAQ.md`](FAQ.md)、[`DIRECTOR_SCALING_FAQ.md`](DIRECTOR_SCALING_FAQ.md) | 常見操作問題 | 當字典搜尋 |

---

## 速讀卡：技術與安全

| 檔案 | 讀這份的目的 | 太長時怎麼讀 |
|------|----------------|----------------|
| [`SYSTEM_TECH_GUIDE.md`](SYSTEM_TECH_GUIDE.md) | 架構深度 | **只讀目錄對應章節**；預設不全讀 |
| [`CHANGELOG.md`](CHANGELOG.md) | 近期上線事實 | 從最新日期往回；配合 `rg` |
| [`TECH_DEBT.md`](TECH_DEBT.md) | TD-NNN 欠債 | 讀 Open 列與相關 ID |
| [`SECURITY.md`](SECURITY.md)、根目錄 [`SECURITY.md`](../SECURITY.md) | 通報入口與決策摘要 | 讀 policy + 與你改動相關段落 |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | 部署架構 | 讀與 Pi / build 路徑相關段 |
| [`ENTERPRISE_WORKFLOW_ALIGNMENT.md`](ENTERPRISE_WORKFLOW_ALIGNMENT.md) | CI/deploy/分眾對齊 | 讀「已對齊」表 + deployable 路徑 |

---

## 速讀卡：維運與高風險

| 檔案 | 讀這份的目的 | 太長時怎麼讀 |
|------|----------------|----------------|
| [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) | 日常/事故 SOP | **先讀開頭「章節導航」表**，再只打開對應 §（勿全文） |
| [`DANGEROUS_OPERATIONS.md`](DANGEROUS_OPERATIONS.md) | P0 操作、禁止事項 | 執行任何 git/db/deploy 前先 `rg` 你的動作 |
| [`DAILY_CHECKLIST.md`](DAILY_CHECKLIST.md) | 例行巡檢 | 當 checklist 用 |
| [`DB_PERF.md`](DB_PERF.md) | 慢查詢/索引紀錄 | `rg` 表名 |

---

## 速讀卡：模組專題（易長篇）

| 檔案 | 讀這份的目的 | 太長時怎麼讀 |
|------|----------------|----------------|
| [`SCHEDULE_DISCREPANCY_REVIEW.md`](SCHEDULE_DISCREPANCY_REVIEW.md) | 課表回報審核 | 讀流程段 + 名詞定義 |
| [`SUBSTITUTE_UX.md`](SUBSTITUTE_UX.md) | 代課 UX | 讀與畫面/狀態有關段 |
| [`MANUAL_SCHEDULE_DATE_SEMANTICS.md`](MANUAL_SCHEDULE_DATE_SEMANTICS.md) | 排課日期語義 | 當 reference 搜尋 |
| [`CHAT_BUG_SYSTEM.md`](CHAT_BUG_SYSTEM.md) | 問題回報設計 | 讀資料流段 |
| [`LINE_LIFF_CHECKLIST.md`](LINE_LIFF_CHECKLIST.md) | LINE 上線檢查 | 當 checklist |
| [`PORSCHE_VISUAL_SYSTEM.md`](PORSCHE_VISUAL_SYSTEM.md) | 視覺規格 | 僅 UI 大改時讀相關 token |

---

## 歷史、PRD、地端操作（易誤導）

| 檔案 | 讀法 |
|------|------|
| [`CHANGELOG_ARCHIVE_2026-04.md`](CHANGELOG_ARCHIVE_2026-04.md) | 只搜尋；現況以 [`CHANGELOG.md`](CHANGELOG.md) |
| [`更新網站前端.md`](更新網站前端.md) | 本機除錯用；**正式 deploy 依 CI** |
| [`TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md`](TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md) | 調查紀錄；與現碼衝突時以程式碼為準 |
| `PRD_*.md` / `CTO_SPEC_*.md` | 規格草稿或歷史；**勿單檔改商業邏輯** |

完整列表見 [`INDEX.md`](INDEX.md)「歷史／參考／易誤導」表。

---

## 驗收（本檔變更後）

- `node scripts/docs-integrity-check.mjs --strict`
- [`docs/INDEX.md`](INDEX.md) 已連回本檔
- [`README.md`](../README.md)「重要文件索引」已連回本檔

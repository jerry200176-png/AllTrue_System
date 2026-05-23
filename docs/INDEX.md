# AllTrue Docs Index — AI 導航地圖

> **前人種樹，後人乘涼。**
> 做事前讀文件，快速知道怎麼做；做完事留記錄，避免錯誤再犯，快速知道怎麼修或加功能。
>
> **知識流轉三層：**
> ```
> Docs（長期文件）←──────────────────────────────┐
>   ↓ AI 開工讀 INDEX → 定位 → 只讀對應章節       │ 做完寫記錄
> MemPalace（對話記憶）                            │ (CHANGELOG /
>   ↓ post-merge hook 自動 mine 每次 PR           │  AI_REGRESSION /
>   ↓ session 開始 wake-up 喚醒上下文             │  TECH_DEBT)
> AI Session（執行）──────────────────────────────┘
> ```
> 設計原則：**最小讀取，最大效果。** 先看這頁決定去哪，再只讀那個章節。  
> **長文不漏讀**：各檔「怎麼讀、讀哪段」→ [`docs/AI_DOC_LITERACY.md`](AI_DOC_LITERACY.md)（速讀卡 + 版本更新鏈 + MemPalace 參照）。

---

## 🏢 AllTrue AI 公司治理

AllTrue 現在以 **AllTrue AI 公司** 方式治理。使用者是 CEO；AI Agents 是產品、工程、QA、資安、維運、文件等職能團隊。

**公司 slogan：前人種樹，後人乘涼。**

治理原則：
1. 做事前先查：先讀本 INDEX，再按任務查 Docs / MemPalace / 對應 rules。
2. 做完要記錄：功能進 `CHANGELOG`，事故進 `AI_REGRESSION_LESSONS`，技術債進 `TECH_DEBT`，複雜架構進 `SYSTEM_TECH_GUIDE`。
3. 規則單一出處：頂層文件只導航，不複製長 SOP；避免文件互相打架。
4. 任何 AI 不靠記憶硬猜；先查資料，再動手。
5. `.cursor/plans/**`、`*_ARCHIVE*` 與長篇歷史文件只供 `rg` / MemPalace 搜尋，不通讀；若與本 INDEX、`.cursorrules`、`OPERATIONS_RUNBOOK.md` 衝突，以現行入口與 runbook 為準。

---

## 🚨 開工前必讀（每次都要）

| 檔案 | 內容 | Token 成本 |
|------|------|-----------|
| `.cursorrules` §P0 | 5 條紅線 + 3 條黃線 + 6 次事故摘要 | 已自動載入 |
| `docs/AI_REGRESSION_LESSONS.md` | 最新防再犯規則摘要與模組索引 | 按需讀 |

---

## 📋 任務導航（按任務類型跳）

### 新功能 / Bug 修復
1. `.cursor/rules/plan-as-prd-cross-functional.mdc` — PRD 14 節格式
2. `.cursor/rules/bug-fix-plan.mdc` — Bug 調查 SOP
3. `docs/AI_REGRESSION_LESSONS.md` — 對應模組的已知坑
4. **In-app Bug 回報**（分診／上線後回寫）：`docs/CHAT_BUG_SYSTEM.md` §3.6–§3.7、`AI_REGRESSION_LESSONS.md` §R51／§R53

### 後端開發
| 需要什麼 | 去哪裡找 |
|----------|---------|
| API 路由清單 | `CLAUDE.md` §API 路由 |
| DB schema / 資料表結構 | `CLAUDE.md` §核心資料表 |
| 高風險邏輯（繳費/堂數） | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` |
| Migration 規則 | `.cursor/rules/module-migration.mdc` |
| 測試規則 / Factory | `.cursor/rules/module-test.mdc` |

### 前端開發
| 需要什麼 | 去哪裡找 |
|----------|---------|
| 頁面清單 + active key | `CLAUDE.md` §前端頁面 |
| Deploy SOP | `.cursor/rules/auto-frontend-deploy.mdc` |
| UI 設計規則 | `.cursor/rules/module-frontend.mdc` |
| 行事曆週檢視資料合併規則 | `CLAUDE.md §G-007`（⛔ 禁止分散 if，必走 `calendarOccurrenceMerge.js`）|
| 行事曆回歸測試 | `npm run test:calendar`（修改任何 calendar merge 邏輯前必跑）|
| 家長入口 UX、分眾版本公告 | `docs/ROLE_PLAYBOOK.md` §4、`docs/AI_REGRESSION_LESSONS.md` §R45；`npm run test:release-notes`（改 `releaseNotes.js` / changelog 產生器時） |

### 部署 / 維運
| 需要什麼 | 去哪裡找 |
|----------|---------|
| 部署 SOP（Phase 7） | `docs/OPERATIONS_RUNBOOK.md §A-B` |
| 緊急事故 / 回滾 | `docs/DANGEROUS_OPERATIONS.md` |
| Dependabot merge SOP / SLA | `docs/OPERATIONS_RUNBOOK.md §B0 / §T` |
| Secret 輪換 | `docs/OPERATIONS_RUNBOOK.md §O` |
| 工程成熟度現況 | `docs/OPERATIONS_RUNBOOK.md §P` |
| Branch protection 啟用步驟 | `docs/OPERATIONS_RUNBOOK.md §R` |
| SSH key 季度輪替 SOP | `docs/OPERATIONS_RUNBOOK.md §S` |
| Staging 設計 / Feature flag / Visual regression | `docs/OPERATIONS_RUNBOOK.md §U / §V / §W` |
| AI / 大廠式 workflow gate | `AGENTS.md §Agent Orchestration SOP`、`docs/OPERATIONS_RUNBOOK.md §B3` |

### SRE / Product Ops
| 需要什麼 | 去哪裡找 |
|----------|---------|
| SLI / SLO / Error Budget / Release Freeze | `docs/SRE_POLICY.md` |
| Post-release T+7/T+14/T+30 metrics review | `docs/PRODUCT_OPS.md` |
| Perception pulse survey 設計 | `docs/PROFESSIONAL_PERCEPTION_SURVEY.md` |

### 資安審查
| 需要什麼 | 去哪裡找 |
|----------|---------|
| STRIDE 速查 | `.cursor/rules/module-security.mdc` |
| 已知安全漏洞 | `docs/SECURITY.md` |
| 家長入口安全規則 | `docs/AI_REGRESSION_LESSONS.md §R18` |
| OWASP ASVS L1 自查（年度） | `docs/security/ASVS_L1_2026.md` |
| Audit log 政策（敏感 admin 行為） | `docs/security/AUDIT_LOG_POLICY.md` |

### 技術債
| 需要什麼 | 去哪裡找 |
|----------|---------|
| Open 技術債清單 | `docs/TECH_DEBT.md` |
| 清償流程 | `.cursor/rules/tech-debt.mdc` |

### 測試帳號 / 登入
- `.cursor/.local/test-credentials.md` — 各角色帳密 + Browser MCP 踩坑 SOP

---

## 🗄️ 文件目錄（完整）

### 核心規則（每次任務按需查）
| 檔案 | 一行說明 |
|------|---------|
| `.cursorrules` | P0 事故紀錄 + 工作流程總覽（自動載入）|
| `CLAUDE.md` | Claude Code 版總覽（同 `.cursorrules`，不重複讀）|
| `AGENTS.md` | Agent 開工順序 + Commit SOP |
| `.cursor/rules/p0-gate.mdc` | 5 紅線 3 黃線速查卡 |

### 防再犯
| 檔案 | 一行說明 |
|------|---------|
| `docs/AI_REGRESSION_LESSONS.md` | 最新防再犯規則摘要與模組索引，改前必查 |
| `docs/AI_REGRESSION_LESSONS_ARCHIVE.md` | 33 條詳細事故記錄（archive，只搜尋不通讀）|
| `docs/AI_DOC_LITERACY.md` | **AI 讀檔協議**：長文速讀卡、CHANGELOG→公告鏈、MemPalace 何時用（防漏讀） |
| `docs/QA_GOLDEN_SCENARIOS.md` | Golden § ↔ CI（Presubmit CHECK 6 + `.github/scripts/golden-ci-report.sh`）|

### 業務規則
| 檔案 | 一行說明 |
|------|---------|
| `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | 繳費/續課提醒規則，**禁止擅改** |
| `docs/PRICING_CONTRACT.md` | 費率合約（每堂費用計算）|
| `docs/ROLE_PLAYBOOK.md` | 各角色權限與 UI 行為 |
| `docs/DIRECTOR_SCALING_FAQ.md` | 主任常見問題 |
| `docs/FAQ.md` | 系統常見問題 |

### 技術文件
| 檔案 | 一行說明 |
|------|---------|
| `CONTRIBUTING.md` | GitHub 協作入口：分支、PR／Issue、CI、**SECURITY 通報** |
| `docs/ENTERPRISE_WORKFLOW_ALIGNMENT.md` | 與大廠式流程對齊摘要（merge queue、deploy 邊界、Golden 自動化）|
| `docs/SYSTEM_TECH_GUIDE.md` | 架構深度文件（延伸閱讀，非必讀）|
| `docs/CHANGELOG.md` | 最近上線功能記錄 |
| `docs/CHANGELOG_ARCHIVE_2026-04.md` | 舊 CHANGELOG（archive，只搜尋不通讀） |
| `docs/TECH_DEBT.md` | TD-NNN 技術債清單 |
| `docs/DANGEROUS_OPERATIONS.md` | 高風險操作清單與 SOP |
| `docs/DEPLOYMENT.md` | 部署架構說明 |
| `docs/DB_PERF.md` | DB 效能優化記錄 |
| `docs/SECURITY.md` | 安全設計決策 |
| `docs/PORSCHE_VISUAL_SYSTEM.md` | Porsche-inspired light-first 視覺系統規格 |
| `docs/WSL2_DEV_SETUP.md` | WSL2 本地開發環境設定 |

### 維運 SOP
| 檔案 | 一行說明 |
|------|---------|
| `docs/OPERATIONS_RUNBOOK.md` | 完整 SOP 手冊（§A-P，按節查）|
| `docs/DAILY_CHECKLIST.md` | 每日例行檢查清單 |
| `docs/DOCS_GOVERNANCE_SOP.md` | 文件治理節奏（每日/每週/每月）與 MemPalace 保鮮 SOP |

### 模組文件
| 檔案 | 一行說明 |
|------|---------|
| `docs/SCHEDULE_DISCREPANCY_REVIEW.md` | 課表出入差異審核流程 |
| `docs/SUBSTITUTE_UX.md` | 代課 UX 設計 |
| `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md` | 排課日期語義 |
| `docs/CHAT_BUG_SYSTEM.md` | 聊天／Bug 回報；**§3.6–§3.7**＝分診 + 修完回 in-app 完整 SOP |
| `docs/LINE_LIFF_CHECKLIST.md` | LINE LIFF 上線檢查清單 |

### 歷史／參考／易誤導（**勿當唯一真相；不通讀**）

| 檔案 | AI 怎麼用 |
|------|-----------|
| [`CHANGELOG_ARCHIVE_2026-04.md`](CHANGELOG_ARCHIVE_2026-04.md) | 舊 CHANGELOG 彙整；只 `rg`；現況看 [`CHANGELOG.md`](CHANGELOG.md) |
| [`AI_REGRESSION_LESSONS_ARCHIVE.md`](AI_REGRESSION_LESSONS_ARCHIVE.md) | 事故長文；只搜尋 §；摘要看 [`AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) |
| [`ENGINEERING_MATURITY_GAPS.md`](ENGINEERING_MATURITY_GAPS.md) | 流程／CI 缺口決策短記 |
| [`TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md`](TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md) | 2026-04-12 技術調查；對照現程式碼 |
| [`更新網站前端.md`](更新網站前端.md) | 本機手動覆蓋 `public`；**上線**勿當 SOP（見 deploy 規則） |
| [`使用說明_主任與超級管理員.md`](使用說明_主任與超級管理員.md) | Developer Bypass 陷阱 FAQ；角色全貌見 [`ROLE_PLAYBOOK.md`](ROLE_PLAYBOOK.md) |
| `PRD_*.md`、`CTO_SPEC_*.md` | 歷史或 Draft 規格；**實作與上線事實**以程式碼 + [`CHANGELOG.md`](CHANGELOG.md) 為準 |

---

## 🤖 GitHub（協作介面）

| 項目 | 說明 |
|------|------|
| `CONTRIBUTING.md` | 貢獻流程與與 branch protection / Dependabot 的對應 |
| `.github/pull_request_template.md` | 開 PR 預填：`Refs` / `Closes`、merge 前檢查 |
| `.github/ISSUE_TEMPLATE/` | 建立 Issue 時選擇：Bug／工程變更／Ops（`config.yml` 含導航連結）|
| `SECURITY.md`（根目錄） | GitHub **Security policy** 與漏洞通報入口；細節見 `docs/SECURITY.md` |
| `.github/CODEOWNERS` | 敏感路徑自動請求 review |
| `dependency-review.yml` | PR **供應鏈（選用）**：官方 dependency-review；需 GHAS + 變數 `ENABLE_DEPENDENCY_REVIEW=true`；未開時僅 notice |

## 🤖 GitHub Workflows（自動化）

| Workflow | 觸發時機 | 功能 |
|----------|---------|------|
| `ci.yml` | PR / main push | 所有 PR 都觸發 required checks context；再依 changed areas 決定是否跑 PHPUnit、Vite、coverage gate、composer/npm audit |
| `deploy.yml` | main CI success | 有 deployable diff 才自動部署 Pi + smoke test + rollback；docs-only merge 跳過 |
| `presubmit.yml` | 每次 PR | Branch 命名規範檢查 |
| `codeql.yml` | PR / main push / weekly | 後端或 workflow 相關改動才跑 PHPStan level 5 |
| `dependency-review.yml` | 每次 PR | 選用 GHAS 依賴審查（見變數 `ENABLE_DEPENDENCY_REVIEW`）；見 `CONTRIBUTING.md` |
| `pi-health.yml` | 每 6 小時 | 磁碟/溫度/備份年齡/UptimeRobot |
| `slow-query-report.yml` | 每週一 | MySQL 慢查詢報告 |
| `backup-restore-test.yml` | 每月 1 日 | 備份還原完整性驗證 |
| `dora-metrics.yml` | 每週一 | DORA 指標計算（部署頻率/lead time/CFR）|
| `branch-hygiene.yml` | 週一至五 | 已合併分支 dry-run 報告 |
| `docs-integrity.yml` | PR / 每週一 | 文件連結完整性、INDEX 導航與核心文件存在性檢查 |

> `ci.yml` / `presubmit.yml` / `codeql.yml` 使用 WSL2 self-hosted runner `wsl2-jerry-alltrue`（labels: `self-hosted`, `Linux`, `X64`, `wsl-ci`, `alltrue-ci`）節省 GitHub-hosted minutes；`deploy.yml` 必須保留 GitHub-hosted runner，不可在個人電腦 runner 上部署 production。
> `main` branch protection 已啟用：required checks + admin enforcement + 禁止 force push/delete。備份同步會產生 Google Drive manifest（檔名 / 大小 / sha256），詳見 `OPERATIONS_RUNBOOK.md §P`。

---

## 📐 MemPalace 導航（AI 記憶層）

```bash
# 搜尋任何主題（調查 bug 或回顧決策前先跑）
~/.local/bin/mempalace search "<關鍵字>"
~/.local/bin/mempalace search "<關鍵字>" --wing alltrue-sessions  # 只搜對話
~/.local/bin/mempalace search "<關鍵字>" --wing alltrue-docs      # 只搜文件

# 看全局記憶摘要（session 開始時替代全讀文件）
~/.local/bin/mempalace wake-up

# 每次 PR merge 後更新記憶（post-merge hook 自動執行）
# 若 hook 未自動跑，手動執行：
~/.local/bin/mempalace mine ~/.cursor/projects/home-jerry-alltrue/agent-transcripts \
  --mode convos --wing alltrue-sessions
~/.local/bin/mempalace mine ~/alltrue/docs --wing alltrue-docs
```

Wings：`alltrue-sessions`（對話記憶）、`alltrue-docs`（文件知識）、`alltrue-code`（程式碼知識）

> MemPalace 目前 `wake-up` 回傳「No memories yet」= palace 尚未有索引，需手動 mine 後才有內容。

---

## ⚡ 省 Token 原則

1. **先讀 INDEX.md（本檔）** → 確定要讀哪個文件 → 只讀那個
2. **不要全讀 SYSTEM_TECH_GUIDE.md**（延伸閱讀，按需查對應節）
3. **`.cursorrules` 已自動載入**，不需再 Read
4. **`CLAUDE.md` = `.cursorrules` 的 Claude 版**，兩者不需同時讀
5. **MemPalace `wake-up`** 在 session 開始時替代全讀文件
6. **怕漏讀長文時** → 先打開 [`AI_DOC_LITERACY.md`](AI_DOC_LITERACY.md) 對照「速讀卡」再下鑽

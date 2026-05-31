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
| **設計系統 / 視覺規格（色票/按鈕/金額）** | `docs/RULE_DESIGN_SYSTEM.md`（唯一真相來源，改 UI 前必讀）|
| Deploy SOP | `.cursor/rules/auto-frontend-deploy.mdc` |
| UI 設計規則 | `.cursor/rules/module-frontend.mdc` |
| 行事曆週檢視資料合併規則 | `CLAUDE.md §G-007`（⛔ 禁止分散 if，必走 `calendarOccurrenceMerge.js`）|
| 行事曆回歸測試 | `npm run test:calendar`（修改任何 calendar merge 邏輯前必跑）|
| 家長入口 UX、分眾版本公告 | `docs/ROLE_PLAYBOOK.md` §4、`docs/AI_REGRESSION_LESSONS.md` §R45；`npm run test:release-notes`（改 `releaseNotes.js` / changelog 產生器時） |
| `assume-unchanged` 藏檔導致 PR 漏 diff | `AI_REGRESSION_LESSONS.md` §R58 |

### 部署 / 維運
| 需要什麼 | 去哪裡找 |
|----------|---------|
| 部署 SOP（Phase 7） | `docs/OPERATIONS_RUNBOOK.md §A-B` |
| 緊急事故 / 回滾 | `docs/DANGEROUS_OPERATIONS.md` |
| Dependabot merge SOP / SLA | `docs/OPERATIONS_RUNBOOK.md §B0 / §T` |
| Secret 輪換 | `docs/OPERATIONS_RUNBOOK.md §O` |
| 工程成熟度現況 | `docs/OPERATIONS_RUNBOOK.md §P` |
| Branch protection 啟用步驟 | `docs/OPERATIONS_RUNBOOK.md §R` |
| **Solo + AI GitHub 週期 SOP** | `docs/OPERATIONS_RUNBOOK.md §B5` |
| SSH key 季度輪替 SOP | `docs/OPERATIONS_RUNBOOK.md §S` |
| Staging 設計 / Feature flag / Visual regression | `docs/OPERATIONS_RUNBOOK.md §U / §V / §W` |
| AI / 大廠式 workflow gate | `AGENTS.md §Agent Orchestration SOP`、`docs/OPERATIONS_RUNBOOK.md §B3` |

### SRE / Product Ops
| 需要什麼 | 去哪裡找 |
|----------|---------|
| SLI / SLO / Error Budget / Release Freeze | `docs/SRE_POLICY.md` |
| Post-release T+7/T+14/T+30 metrics review | `docs/PRODUCT_OPS.md` |
| Perception pulse survey 設計 | `docs/archive/PROFESSIONAL_PERCEPTION_SURVEY.md` |

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

## 📝 新建 docs 命名規範（Phase C 起生效）

新建 `docs/` 檔案請加 prefix，讓 AI 從名稱判斷文件類型：

| 前綴 | 用途 | 範例 |
|------|------|------|
| `RULE_` | 規範性，read-before-doing | `RULE_PAYMENT_ALERTS.md` |
| `RUNBOOK_` | 操作手冊 | `RUNBOOK_DEPLOY.md` |
| `REF_` | 純參考查表 | `REF_API_ROUTES.md` |
| `MODULE_` | 模組設計 | `MODULE_CHAT_BUG.md` |
| `GUIDE_` | 教學 how-to | `GUIDE_WSL2_SETUP.md` |
| `POLICY_` | 政策決策 | `POLICY_SRE.md` |

舊檔按現有名稱延用（不強制改名，但下次大改時順手 rename）。CI 會對不符合 prefix 且非既有清單的新檔發出 `warning`。

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
| `docs/archive/AI_REGRESSION_LESSONS_ARCHIVE.md` | 33 條詳細事故記錄（archive，只搜尋不通讀）|
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
| `docs/SYSTEM_TECH_GUIDE.md` | 架構深度文件（延伸閱讀，非必讀）|
| `docs/CHANGELOG.md` | 最近上線功能記錄 |
| `docs/archive/CHANGELOG_ARCHIVE_2026-04.md` | 舊 CHANGELOG（archive，只搜尋不通讀） |
| `docs/TECH_DEBT.md` | TD-NNN 技術債清單 |
| `docs/DANGEROUS_OPERATIONS.md` | 高風險操作清單與 SOP |
| `docs/DEPLOYMENT.md` | 部署架構說明 |
| `docs/DB_PERF.md` | DB 效能優化記錄 |
| `docs/SECURITY.md` | 安全設計決策 |
| `docs/RULE_DESIGN_SYSTEM.md` | **設計系統唯一真相來源**（Stripe-inspired：淺色+navy+indigo、金額 tabular）；所有前端 UI 照此生成 |
| `docs/PORSCHE_VISUAL_SYSTEM.md` | ⛔ Superseded，改用 `RULE_DESIGN_SYSTEM.md`（保留歷史參考）|
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
| `docs/archive/SCHEDULE_DISCREPANCY_REVIEW.md` | 課表出入差異審核流程（已移入 archive）|
| `docs/SUBSTITUTE_UX.md` | 代課 UX 設計 |
| `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md` | 排課日期語義 |
| `docs/CHAT_BUG_SYSTEM.md` | 聊天／Bug 回報；**§3.6–§3.7**＝分診 + 修完回 in-app 完整 SOP |
| `docs/LINE_LIFF_CHECKLIST.md` | LINE LIFF 上線檢查清單 |

### 歷史／參考／易誤導（**勿當唯一真相；不通讀**）

| 檔案 | AI 怎麼用 |
|------|-----------|
| [`docs/archive/CHANGELOG_ARCHIVE_2026-04.md`](archive/CHANGELOG_ARCHIVE_2026-04.md) | 舊 CHANGELOG 彙整；只 `rg`；現況看 [`CHANGELOG.md`](CHANGELOG.md) |
| [`docs/archive/AI_REGRESSION_LESSONS_ARCHIVE.md`](archive/AI_REGRESSION_LESSONS_ARCHIVE.md) | 事故長文；只搜尋 §；摘要看 [`AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) |
| [`docs/archive/ENGINEERING_MATURITY_GAPS.md`](archive/ENGINEERING_MATURITY_GAPS.md) | 流程／CI 缺口決策短記 |
| [`docs/archive/TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md`](archive/TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md) | 2026-04-12 技術調查；對照現程式碼 |
| `docs/archive/更新網站前端.md` | 本機手動覆蓋 `public`；**正式 deploy 依 CI，勿用本檔** |
| `docs/archive/使用說明_主任與超級管理員.md` | Developer Bypass FAQ；角色全貌見 [`ROLE_PLAYBOOK.md`](ROLE_PLAYBOOK.md) |
| `docs/archive/PRD_*.md`、`docs/archive/CTO_SPEC_*.md` | 歷史或 Draft 規格；**實作與上線事實**以程式碼 + [`CHANGELOG.md`](CHANGELOG.md) 為準 |

---

## 🤖 GitHub（協作介面）

| 項目 | 說明 |
|------|------|
| `CONTRIBUTING.md` | 貢獻流程與與 branch protection / Dependabot 的對應 |
| `.github/pull_request_template.md` | 開 PR 預填：`Refs` / `Closes`、merge 前檢查 |
| `.github/ISSUE_TEMPLATE/` | 建立 Issue 時選擇：Bug／工程變更／Ops（`config.yml` 含導航連結）|
| `SECURITY.md`（根目錄） | GitHub **Security policy** 與漏洞通報入口；細節見 `docs/SECURITY.md` |
| `.github/CODEOWNERS` | 敏感路徑自動請求 review |
| 供應鏈安全 | PR gate＝`composer audit` + `npm audit`（ci.yml，required，不需 GHAS）；每週深掃＝`osv-scanner.yml`；GHAS 升級路徑＝`dependency-review.yml`（`ENABLE_DEPENDENCY_REVIEW=true`）。矩陣見 `OPERATIONS_RUNBOOK.md §R1c` |

## 🤖 GitHub Workflows（自動化）

| Workflow | 觸發時機 | 功能 |
|----------|---------|------|
| `ci.yml` | PR / main push | **required**：所有 PR 觸發 context；依 changed areas 跑 PHPUnit、Vite、coverage gate、composer/npm audit、Golden scenarios |
| `presubmit.yml` | 每次 PR | **required**：Branch 命名規範檢查 |
| `secret-scan.yml` | 每次 PR | **required**：`gitleaks scan` 機密外洩偵測 |
| `codeql.yml` | PR / main push / weekly | **required**：後端或 workflow 改動才跑 `PHPStan Advisory (php)` level 5（baseline-gated，只擋新增）|
| `docs-integrity.yml` | PR / 每週一 | **required**：文件連結完整性、INDEX 導航與核心文件存在性檢查 |
| `deploy.yml` | main CI success | 有 deployable diff 才自動部署 Pi + smoke test + rollback；docs-only merge 跳過 |
| `release.yml` | main push（CHANGELOG 變更）/ 手動 | CalVer 自動打 tag + GitHub Release（見 `OPERATIONS_RUNBOOK.md §X`）|
| `ui-smoke.yml` | 每週 / 手動 | Playwright UI 煙霧測試（需 `SMOKE_*` secrets，否則 skip）|
| `dependency-review.yml` | 每次 PR | 供應鏈（選用 GHAS 升級路徑）；需 `ENABLE_DEPENDENCY_REVIEW=true`，未開僅 notice |
| `osv-scanner.yml` | 每週一 / 手動 | **OSS 供應鏈深掃**：OSV-Scanner 掃 lockfiles（不需 GHAS）；控制矩陣見 `OPERATIONS_RUNBOOK.md §R1c` |
| `pi-health.yml` | 每日 09:00 台灣 | 磁碟/溫度/備份年齡/UptimeRobot（門檻見 §Z）|
| `slow-query-report.yml` | 每週一 | MySQL 慢查詢報告 |
| `migration-dryrun.yml` | 每次 PR | migration 變更時 `migrate --pretend` 乾跑 |
| `missing-tests-warn.yml` | 每次 PR | 改 controller/service 未附測試時警告（advisory）|
| `htaccess-guard.yml` | 每次 PR | `public/.htaccess` 變更守門（事故 D 防再犯）|
| `backup-restore-test.yml` | 每月 1 日 | 備份還原完整性驗證 |
| `dora-metrics.yml` | 每週一 | DORA 指標計算（部署頻率/lead time/CFR；review SOP 見 §Y）|
| `mempalace-monthly.yml` | 每月 | MemPalace 記憶索引重建 |
| `branch-hygiene.yml` | 週一至五 | 已合併分支 dry-run 報告 |
| `teacher-signin-diagnose.yml` / `teacher-signin-recovery.yml` | 手動 / 排程 | 老師刷卡資料診斷與回補 |

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

## ⚡ 省 Token 原則 + 五步讀檔法

1. **先讀 INDEX.md（本檔）** → 確定要讀哪個文件 → 只讀那個。
2. **只讀必讀錨點**；其餘章節除非 INDEX 點名否則不讀。
3. **長文用 `rg`**：`rg -n "關鍵字" docs/某檔.md`（或 MemPalace `search`），不 eyeball 掃全文。
4. **歷史 / archive**（`*ARCHIVE*`、`.cursor/plans/**`）→ 不通讀，只搜尋。
5. **做完寫回**：`CHANGELOG`（使用者可感知）、`AI_REGRESSION`（新紅線）、`TECH_DEBT`（欠債）。

**`.cursorrules` 已自動載入**，不需再 Read；`CLAUDE.md` 是 Claude Code 版總覽，兩者不需同時讀。

---

## 📚 速讀卡（如何讀各類長文）

| 檔案 | 讀這份的目的 | 太長時怎麼讀 |
|------|------------|------------|
| `AGENTS.md` | Agent 流程、Commit、Risk tier | 讀 §開工前 + §Orchestration + §DoD |
| `AI_REGRESSION_LESSONS.md` | 防再犯紅黃線 | **先讀開頭摘要 + 模組索引表** + 相關 Rxx 全文 |
| `OPERATIONS_RUNBOOK.md` | 日常/事故 SOP | **先讀章節導航表**，再只打開對應 § |
| `DIRECTOR_PAYMENT_ALERT_RULES.md` | 繳費提醒邏輯 | **擅改前必問使用者**；用 `rg` 找條件 |
| `SYSTEM_TECH_GUIDE.md` | 架構深度 | **只讀目錄對應章節**；預設不全讀 |
| `CHANGELOG.md` | 近期上線事實 | 從最新日期往回；配合 `rg` |
| `CHANGELOG → 公告卡` | 版本公告 | `npm run sync-release-notes`（改 CHANGELOG 後）|
| `docs/archive/*.md` | 歷史草稿（已移入 archive）| **禁止通讀**；`rg` / MemPalace |

---

## 🗓️ 治理節奏（文件保鮮）

**每日（PR/任務完成時）**
- 更新 `docs/CHANGELOG.md`（一行原則）。
- 若發現 AI 新踩坑 → 更新 `docs/AI_REGRESSION_LESSONS.md`。

**每週（文件巡檢）**
- `node scripts/docs-integrity-check.mjs --strict`
- 修正斷鏈、遺漏導航、入口與章節不一致。

**每月（記憶保鮮）**
- `mempalace mine` 重新索引近期對話與 docs。
- 抽查高風險關鍵字是否可被 `mempalace search` 命中。

**變更守則**：先改權威文件，再補 INDEX 導航；不在多份文件複製完整 SOP（避免版本漂移）。

---

## 📁 docs/archive/ — 歷史文件區

下列 11 份文件已移入 `docs/archive/`，不再主動維護。只搜尋用，禁止通讀。

| 檔案 | 說明 |
|------|------|
| `AI_REGRESSION_LESSONS_ARCHIVE.md` | 事故長文 archive；摘要在 `AI_REGRESSION_LESSONS.md` |
| `CHANGELOG_ARCHIVE_2026-04.md` | 2026-04 以前的 changelog |
| `PRD_PARTTIME_PAYROLL_PER_TEACHER_OVERRIDES.md` | 已完成的分攤薪資 PRD |
| `PRD_PARTTIME_TEACHER_PAYROLL.md` | 已完成的兼職薪資 PRD |
| `PRD_SINGLE_SESSION_UX_CLARITY.md` | 已完成的單堂 UX PRD |
| `CTO_SPEC_BRANCH_MONTHLY_TUITION_REPORT.md` | 歷史 CTO spec |
| `ENGINEERING_MATURITY_GAPS.md` | 歷史工程成熟度評估 |
| `ENTERPRISE_WORKFLOW_ALIGNMENT.md` | 歷史流程對齊文件 |
| `PROFESSIONAL_PERCEPTION_SURVEY.md` | 歷史使用者調研 |
| `SCHEDULE_DISCREPANCY_REVIEW.md` | 歷史排課差異審查 |
| `TECH_REPORT_COURSE_SCHEDULE_SYNC_ISSUES.md` | 歷史技術報告 |

---

## 🏷️ 新文件命名 prefix 規範（Phase C）

新建 `docs/` 文件時，依用途選擇 prefix：

| Prefix | 用途 | 範例 |
|--------|------|------|
| `RULE_` | 規則、限制、條件（不可擅改） | `RULE_PAYMENT_ALERTS.md` |
| `RUNBOOK_` | 操作 SOP（step-by-step） | `RUNBOOK_DEPLOY.md` |
| `REF_` | 參照資料（API、schema、對照表） | `REF_DB_SCHEMA.md` |
| `MODULE_` | 模組深度說明（架構 + 流程） | `MODULE_BILLING.md` |
| `ADR_` | Architecture Decision Record | `ADR_001_calendar_merge.md` |
| `(無 prefix)` | 既有文件維持現狀，不強制改名 | — |

> 只對**新建文件**套用；既有文件不因命名規範而強制 rename（會破壞參照）。

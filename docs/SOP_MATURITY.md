# SOP 成熟度與大廠對標（AI 接手地圖）

> 目的：讓任何 AI（Claude Code / Cursor）能快速了解我們的工程 SOP 對標大廠到哪、還缺什麼，並在 usage 上限或換手時無縫接續。
> 本文件被 `CLAUDE.md` 的「On-demand 快查」引用，請在交接前先讀此檔頂部的「進行中狀態」。

---

## 🔴 進行中狀態（交接區 — 完成後清空此節）

更新時間：2026-06-14 15:30（Actions-down 高價值工作：support macro library + bug triage + project hygiene）

**本輪 Claude Code 交接成果（Actions minutes 用完期間，無 CI/deploy）**
- 已驗證 production `version.json` = `6c68d8a0`（2026-06-14 10:35），**確認 #853 / #854 / #856 仍未部署**，deploy-dependent in-app bug 不可標 `resolved`。
- **新增 `docs/GUIDE_SUPPORT_REPLY_MACROS.md`**（#907 交付草案）：10 個 in-app bug 公開回覆 macro，含公開留言＋內部備註＋禁用詞檢查＋對應狀態機；已補 `docs/INDEX.md` 兩處入口，並在 #907 留交付 comment。
- **In-app bug triage**（read-only，未改 in-app 狀態/留言）：#851 / #855 / #909 各補「使用者白話問題 + 驗收條件 + blocked by deploy?」triage comment。#909（in-app #167）尚無修復、屬 audience mismatch（M8 macro 情境），未轉 triaged。
- **Metadata hygiene**：#851 +`priority:p3`+`area:ui`+`status:blocked`；#855 +`priority:p3`+`status:blocked`；#867 / #870 +`status:blocked`（受 GitHub 帳單/額度卡住）。
- ⚠️ gh token 缺 `read:project` scope，無法經 CLI 讀寫 Project v2 欄位；Project board 欄位同步需使用者 `gh auth refresh -s read:project,project` 後再做（milestone + labels 仍是規劃真相，已對齊）。

**已部署並完成 in-app Phase C**
- #852 任務中心去誤導 + SystemTrust 401 修復已部署到 production `version.json` hash `6c68d8a0`。
- in-app #163 / #164 已依狀態機 `triaged → in_progress → resolved`，並已發公開驗收留言。

**已合併但尚未部署，禁止誤標 resolved**
- #853 軍階系統重校（in-app #165）已 merge，但 production `version.json` 仍停在 `6c68d8a0`，尚未包含此修復。
- #854 老師端「同事軍階」互看 strip 已 merge，但同樣尚未部署。
- #856 bug 詳情手機自動捲入視野（in-app #166）已 merge，但尚未部署。
- 已 rerun 最新 deploy workflow（head `5952e95`），仍在 GitHub-hosted runner 層的 `Detect deployable changes` failure，沒有 step log。部署卡點由 #867 / #870 追蹤。

**分支保護**
- main required checks 已還原為 7 項：`Presubmit Checks`、`PHPUnit Feature & Unit Tests`、`Vite Frontend Build`、`gitleaks scan`、`Golden scenarios report`、`Docs Integrity Check`、`PHPStan Advisory (php)`。
- 長期解：#867 把受帳單影響的 GitHub-hosted job 搬到 self-hosted 或替換為自架可跑的等價掃描。

**GitHub roadmap 現況**
- 舊 Phase 1/2/3 milestones（#1–#3）與 M1/M2/M3（#4–#6）皆已 0 open issue 並關閉；active roadmap 只看 M4–M9。
- M4（#7）現包含 #867–873：staging / feature flags / CI 高可用 / merge queue / DORA / postmortem。
- M5（#8）已建立：UI/UX 質感與可讀性；包含 epic #866、子 #857–865、in-app UX bugs #851 / #855、角色體驗 #909–912。
- M6（#9）已建立：GitHub 治理與協作成熟度；包含 #875–880。
- M7（#10）已建立：系統維護與 SRE 營運成熟度；包含 #881–886。
- M8（#11）已建立：資安、隱私與合規成熟度；包含 #887–892。
- M9（#12）已建立：工作流程與組織營運 SOP；包含 #893–898。

**GitHub Projects**
- `AllTrue Engineering Roadmap` 已建立：https://github.com/users/jerry200176-png/projects/1
- 目前收錄 M4（#867–873）、M5（#866 + #857–865 + #909–912）、M6（#875–880）、M7（#881–886）、M8（#887–892）、M9（#893–898）與仍開著的 in-app bug 追蹤（#851 / #855）。
- Project 欄位已補：`Status`、`Risk Tier`、`Area`、`Priority`、`Milestone`；milestone 仍是 roadmap 分組真相，Project 作為跨 milestone 執行視圖。

**每個 milestone Top 3（避免 roadmap 膨脹，依 priority label + 當前可動性）**
- M4：**#867** CI→self-hosted（解部署卡點）、**#870** CI 高可用 + 用量告警、**#868** staging 環境。← 三者都直接解今日帳單/部署 deadlock。
- M5：**#909** 老師端 trust 面板白話化（status:ready，有真實 in-app 回饋）、**#858** 可讀性/對比 WCAG AA（p1）、**#866** UI/UX 去 AI 化 epic（總綱）。
- M6：**#875** GitHub Environments（p1）、**#878** Release/Deploy/In-app 三者可追溯（p1）、**#877** Project automation（減少本輪這種手動 hygiene 工）。
- M7：**#881** MySQL PITR（p1，降資料損失）、**#882** Full server DR tabletop（p1）、**#901** Data quality checks（p1，可先寫 spec）。
- M8：**#888** IAM/access review、**#889** PII inventory/retention、**#887** host hardening（前兩者為 paper audit，Actions-down 期間即可推進）。
- M9：**#893** Service catalog/ownership/RACI（p1）、**#905** Role-based QA matrix（p1，可先寫矩陣）、**#898** AI/human onboarding & handoff package（直接降低換手成本）。

**狀態分類（M4–M9 open issues）**
- `In Review`／待部署回寫：in-app #851（#165 部分）、#855——已合併、卡部署。
- `Blocked`（外部依賴 / Actions capacity）：#867、#870、以及所有需 CI merge 才能落地的 production code PR。
- `Ready`（無 CI 即可大幅推進的 paper/spec/docs 工）：#888、#889、#893、#894、#896、#898、#900、#901、#902、#903、#904、#905、#906、#907、#908。
- `Backlog`（需設計或排期）：其餘 M5 UI 子項、M6/M7 需動到 infra 的項目。

---

## 我們的 SOP 已對標到哪（M1–M3，皆已規劃/完成）

我們已有成熟度框架（標籤 R=Reliability、T=Testing、V=reView）：

| Milestone | 已涵蓋（對標大廠） |
|---|---|
| **M1 交付安全網** | AI/Bot review gate（#736）、Rollback 演練 + MTTR 量測（#733）、Playwright E2E merge gate（#730）、Vitest 元件測試（#729）、CI raw-hex guard（#689） |
| **M2 品質縱深** | PR Design Review Gate + checklist（#737）、Migration 向後相容 + rollback 安全（#734）、覆蓋率 advisory→blocking（#731） |
| **M3 工程韌性** | 第二 maintainer approval（#738）、漸進發布 + SLO 驅動自動 rollback（#735）、測試金字塔 + 視覺回歸 + flaky 治理（#732）、稽核日誌（#766） |

**既有自動化**（`.github/workflows/deploy.yml`）：health-check（`/api/v1/health`）→ post-merge smoke → 失敗**自動 rollback**（前端重 build + `migrate:rollback` + rollback health check）。Sentry DSN 已接、dependabot 已設、CI raw-hex guard 已上。

## 仍缺的下一層（M4，對標大廠，已開 issue）

| # | 缺口 | 大廠對標 |
|---|---|---|
| #868 | **staging/pre-prod 環境**（目前合併後直上 prod Pi） | GitHub Flow、Heroku pipelines、dev/prod parity |
| #869 | **Feature flags**（解耦 deploy 與 release、kill-switch） | Unleash、LaunchDarkly、dark launch |
| #870 | **CI 高可用**（消除單一 self-hosted runner SPOF + 用量告警；今日帳單事件暴露） | runner scale sets、ARC |
| #871 | **Merge Queue**（消除 up-to-date 競態、免手動 update-branch） | GitHub Merge Queue、Bors/Mergify |
| #872 | **DORA 四指標儀表板** | Google DORA / Four Keys |
| #873 | **Blameless postmortem 範本 + 行動項追蹤**（正式化事故 A–F） | Google SRE Postmortem Culture |

## GitHub 治理缺口（M6，除 Actions minutes 之外）

GitHub 基礎治理已具備：branch protection、required checks、PR template、issue templates、CODEOWNERS、release tags、Security/Dependency workflows、Project board。下一層不是再堆更多 CI，而是讓 GitHub 本身更像大公司工程系統：部署環境、審核責任、看板自動化、版本追溯與安全治理。

| # | 缺口 | 大廠對標 |
|---|---|---|
| #875 | **GitHub Environments**：production/staging secrets 與 deployment protection | GitHub Environments、GitLab Environments、Heroku Pipelines |
| #876 | **CODEOWNERS / review ownership**：從提醒升級為可執行審核責任 | Google/Meta ownership、two-person review |
| #877 | **Project automation**：issue/PR 自動進 Roadmap，Status/Milestone 同步 | Linear/Jira/GitHub Projects automation |
| #878 | **Release / Deploy / In-app traceability**：版本、部署、回寫三者可追溯 | Change management、release train |
| #879 | **Security Advisory / private vulnerability reporting / secret rotation drill** | GitHub Security Advisory、SLSA、quarterly secret rotation |
| #880 | **Repository ruleset / merge policy hygiene**：branch protection 演進到可版本化治理 | Policy-as-code、protected tags、repository rulesets |

## 系統維護 / SRE 營運缺口（M7）

維運基礎已具備：UptimeRobot、health endpoint、Pi health、slow-query report、nightly/sixhour/monthly + Google Drive 備份、manifest、monthly restore drill、rollback runbook、SLO policy。下一層缺口不是再多寫功能，而是把「事故前預防、事故中指揮、事故後恢復」制度化。

| # | 缺口 | 大廠對標 |
|---|---|---|
| #881 | **MySQL PITR / binlog point-in-time recovery**：降低兩次備份間資料損失 | RDS PITR、MySQL binlog restore、data recovery drill |
| #882 | **Full server DR tabletop**：全新 Pi 從零重建到可服務 | Disaster Recovery tabletop、AWS Well-Architected Operational Excellence |
| #883 | **On-call / incident response**：分級、升級、通報與演練節奏 | PagerDuty、Google SRE incident command |
| #884 | **Observability 補強**：集中 log、指標、trace 與告警降噪 | Sentry release health、Datadog/New Relic、alert fatigue control |
| #885 | **Capacity management**：Pi 磁碟/CPU/RAM/DB 成長預測與預警 | Capacity planning、SRE resource budget、FinOps |
| #886 | **Maintenance window / status page**：例行維護、公告與使用者可見狀態 | Statuspage.io、GitHub Status、maintenance window policy |

## 資安 / 隱私 / 合規缺口（M8）

資安基礎已具備：`SECURITY.md` 事故紀錄、gitleaks/OSV/Dependency workflows、PIN gate、PII dump 防再犯、PR Threat Note。下一層要從「修單點漏洞」升級為「持續合規與風險治理」。

| # | 缺口 | 大廠對標 |
|---|---|---|
| #887 | **Production host hardening verification**：UFW/SSH/fail2ban/服務暴露定期稽核 | CIS Benchmark、NIST CSF Protect |
| #888 | **IAM / access review**：App/GitHub/Pi/第三方高權限盤點 | SOC2 access review、least privilege、JML 流程 |
| #889 | **PII data inventory / retention**：學生個資資料地圖與保存刪除政策 | GDPR/PDPA data inventory、data minimization |
| #890 | **Sensitive action audit log coverage**：高風險操作稽核覆蓋率盤點 | SOX/SOC2 audit trail、admin activity logs |
| #891 | **Threat modeling / ASVS review cadence**：T3 功能上線前資安設計審查 | Microsoft SDL、OWASP ASVS、STRIDE |
| #892 | **Third-party / vendor risk register**：LINE/Sentry/GitHub/UptimeRobot/Drive 依賴盤點 | Vendor risk management、SOC2 vendor inventory |

## 工作流程 / 組織營運 SOP 缺口（M9）

AllTrue 已有 PR/issue template、Phase gate、Agent Orchestration、Project board；下一層要降低「只有 AI 當下記得」的風險，把服務 ownership、SOP 保鮮、支援 SLA 與決策紀錄制度化。

| # | 缺口 | 大廠對標 |
|---|---|---|
| #893 | **Service catalog / ownership / RACI**：模組、owner、SLO、runbook 對照表 | Backstage service catalog、ownership model |
| #894 | **SOP / runbook review cadence**：文件保鮮與演練證據制度化 | Runbook lifecycle management、audit evidence |
| #895 | **In-app bug / support SLA metrics**：回報處理時效與積壓管理 | Zendesk/Jira Service Management、support SLA |
| #896 | **ADR / RFC process**：重大架構與流程決策留下可追溯紀錄 | ADR、Google design docs、RFC culture |
| #897 | **Release train / planning cadence**：週期規劃、freeze、scope control | Kanban WIP limit、release train、SRE freeze |
| #898 | **AI / human onboarding & handoff package**：新人或新 agent 30 分鐘接手 | Onboarding runbook、incident handoff、team playbook |

## 軟體公司跨部門 Operating Model

AllTrue 目前已用 M4–M9 把工程成熟度拆成多條 track。從「軟體公司各部門」角度看，下一步不是每個部門各開一套流程，而是把部門職責映射到既有 roadmap，確保每個缺口都有 owner、milestone 與驗收。

| 部門 | 關注點 | 已有 roadmap | 新增補強 |
|---|---|---|---|
| IT / Infrastructure | 設備、主機、網路、帳號、備份可盤點/可恢復/可稽核 | M7 #882 / #886、M8 #887 / #888 / #892 | #899 RFID / device inventory |
| SRE / Operations | 事故前預防、事故中指揮、事故後復原 | M7 #881–886 | #900 weekly ops review |
| Security | 從漏洞修補升級為持續風險治理 | M8 #887–892 | #902 security exception register |
| Engineering | 降低變更風險與長期維護成本 | M9 #893 / #896 / #897 | #904 technical health scorecard |
| QA / Test | 角色導向、風險導向測試矩陣 | M1/M5 golden/E2E/visual regression | #905 role-based QA test matrix |
| Product / UX | 角色採用、痛點、復發問題與體驗一致性 | M5 #857–865、Product Ops docs | #906 role-based product health review |
| Support / Customer Success | in-app bug 回覆、SLA、驗收與白話溝通 | M9 #895、CHAT_BUG_SYSTEM | #907 public reply macro library |
| Data / Analytics | 核心營運資料可信度 | M4 #872、adoption metrics docs | #901 data quality checks |
| Legal / Privacy | 個資最小化、保存、刪除、外部服務合規 | M8 #889 / #892 | #903 privacy request SOP |
| Docs / PMO | SOP 保鮮、交接、roadmap 不膨脹 | M9 #894 / #898 | #908 quarterly roadmap review ritual |

## 角色體驗對標（老師 / 主任 / 家長）

2026-06-14 以正式帳號提供的三種角色視角做唯讀審查（未新增/修改學生、課程、評量、帳務；未改 in-app bug 狀態）。結論：功能覆蓋已接近或超過同級補教系統，主要差距在 **角色分眾文案、下一步引導、狀態可理解性與主動通知**。

| 角色 | 觀察 | 大公司對標缺口 | GitHub |
|---|---|---|---|
| 老師 | `SYSTEM TRUST` 在手機上顯示「高優先缺陷」「待審評量」與工程化 release note；in-app #167 問「那是什麼意思」 | 老師端應只顯示白話、可行動、與教學工作相關的系統狀態；內部 defect / deploy notes 應分眾隱藏或改寫 | #909 / #910 |
| 主任 | `DirectorDashboard` 已是營運 cockpit，但指標多、密度高；部分數字需要主任自行理解定義與優先順序 | 成熟 cockpit 會提供 metric definition、原因摘要、Top risks、drill-down 到責任項目 | #911 |
| 家長 | `ParentPortal` 已有進度中心、評量留言、請假與帳務；但家長仍可能不知道留言/請假/繳費提醒「處理到哪一步」 | 家長/客服類大公司產品會提供狀態時間線、處理中/已完成語意與主動通知 | #912 |

## Actions minutes 用完時的工作分流

大公司不會在 CI/deploy capacity 不足時硬繞過 production gate；正確做法是 **freeze CI-dependent work，轉做不耗 Actions 的高價值工作**：

- 暫停：merge/deploy、需要 required checks 的 production code PR、in-app `resolved` 回寫。
- 繼續：issue/milestone/project 整理、Bug triage、PRD/ARCH/spec、docs、code review、風險盤點、設計/UX 規劃。
- 追蹤：把 CI capacity 事件寫回 #867 / #870，待 billing/spending limit 恢復後 rerun deploy。
- 禁止：為了趕上線改用 self-hosted runner 跑 production deploy、手動 SSH 覆蓋 production、或未 deploy 就把 in-app bug 標 `resolved`。

### 大廠工程師在「CI/deploy 凍結」時實際會做的事（playbook）

關鍵心法：**CI 凍結只擋「會改 production 的路徑」，不擋思考、規格、治理與唯讀盤點。** 成熟工程師此時把時間投到「等 CI 恢復後能一次跑得更順、更安全」的準備工作，而不是空等或硬繞 gate。

| 類別 | 不耗 Actions 即可做 | 對應 issue / 產出 |
|---|---|---|
| **Support / 客服閉環** | 整理公開回覆 macro、把 in-app bug 的白話問題＋驗收條件寫進 GitHub、起草（不送出）公開回覆 | #907（已交付 `GUIDE_SUPPORT_REPLY_MACROS.md`）、#895 SLA 指標 |
| **Triage / Backlog 衛生** | 補 priority/area/status 標籤、標 `status:blocked`、合併重複、每 milestone Top 3 | 本輪 #851/#855/#867/#870 已補；#908 季度 review |
| **規格先行（spec-ahead）** | 把 data quality checks、QA 矩陣、安全盤點寫成可直接執行的 spec/SQL 草案（不在 prod 跑） | #901 data checks、#905 QA matrix、#888 IAM、#889 PII |
| **設計 / ADR** | 寫架構決策紀錄、feature flag/staging 設計、UX 分眾文案規格 | #896 ADR、#869 flags、#868 staging、#909–912 角色體驗 |
| **唯讀稽核** | 讀 production health/version.json、唯讀 SQL sanity check、依賴/權限盤點（不寫入） | #888、#889、#890、#892、#900 weekly ops |
| **code review / 讀碼** | review 既有 open PR、讀熱點模組、整理 known-issues registry（不 merge） | #876 review ownership、AI_REGRESSION_LESSONS |
| **把卡點變成可預防** | 把本次 capacity 事件寫成 postmortem 行動項、設用量告警與 self-hosted 遷移計畫 | #867、#870、#872 DORA、#873 postmortem |
| **降低換手成本** | 更新本檔「進行中狀態」、handoff package、onboarding runbook | #898 onboarding/handoff |

判斷一件事「現在能不能做」的單一準則：**它需不需要走 required checks 改動 production？** 需要 → 凍結等 CI；不需要（docs/issue/spec/audit/review）→ 現在就做，並把成果掛回對應 issue，讓 CI 一恢復就能無縫接上 PR。

## GitHub Projects
`AllTrue Engineering Roadmap` Project board 已啟用，作為 **跨 milestone 執行視圖**；milestone + labels + issue body 仍是規劃真相來源。使用方式：
- M4：生產安全與流程自動化（#867–873）。
- M5：UI/UX 質感與可讀性（#866 + #857–865）與角色體驗對標（#909–912）。
- M6：GitHub 治理與協作成熟度（#875–880）。
- M7：系統維護與 SRE 營運成熟度（#881–886）。
- M8：資安、隱私與合規成熟度（#887–892）。
- M9：工作流程與組織營運 SOP（#893–898）。
- 跨部門補強：IT/SRE/Security/Engineering/QA/Product/Support/Data/Legal/Docs 的新增缺口（#899–908）。
- In-app bug：只放仍需跟 production deploy / 回寫狀態同步的追蹤 issue。

## AI 接手原則
1. 先讀本檔「進行中狀態」+ `CLAUDE.md` 5 紅線 + `MEMORY.md`。
2. 任何 CI 卡住先分清：**self-hosted（Vite/Presubmit/PHPUnit，不吃 GitHub 額度）** vs **GitHub-hosted（gitleaks/Golden/Dependency Review，受帳單影響）**。
3. `.vue` 註解禁寫 `#NNN`（會被 hex guard 當色碼）；hex guard 用 WSL/Linux 跑（Windows grep 會漏報）。

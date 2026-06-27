# SOP 成熟度與大廠對標（AI 接手地圖）

> 目的：讓任何 AI（Claude Code / Cursor）能快速了解我們的工程 SOP 對標大廠到哪、還缺什麼，並在 usage 上限或換手時無縫接續。
> 本文件被 `CLAUDE.md` 的「On-demand 快查」引用，請在交接前先讀此檔頂部的「進行中狀態」。

---

## 🔴 進行中狀態（交接區 — 完成後清空此節）

更新時間：2026-06-27（Engineering Governance Audit 完成 + PR #954 prod 收斂）

**2026-06-27 Engineering Governance Audit**
- ✅ 全庫 10-phase 稽核完成（無 CI；靜態分析 + code review）
- 📄 報告：`docs/reviews/ENGINEERING_AUDIT_2026-06-27.md`
- 🎫 新建 **39** GitHub issues **#957–#995**（security / regression / architecture / testing / perf / docs）
- ⛔ **Stop-the-line：#970** `X-User-Id` header auth bypass — 優先於其他資安 hardening
- 📋 Top priority epic：**#957** ClassSession materialization 統一
- ✅ Production 已手動收斂至 `main` @ `5efaf61`（#954 calendar fix）；`deploy.yml` 仍 DISABLED

**urgent handoff（`.cursor/plans/urgent_login_attendance_leave_handoff_2026-06-20.md`）— 部分 superseded**
- ✅ Bug 1 家長登入 regex：`#922` merged；prod API `/auth/login` + `/learning-records` 200。
- ✅ in-app `#169` / `#170`：程式 `#928` / `#927` merged；in-app 狀態皆 `resolved`；prod 待審清單已無鄭筠霏 06-20（唯讀 API 驗證）。
- ✅ `#919` self-hosted CI/deploy 已恢復；2026-06-21 deploy 至 `fd04b07` 成功。
- ⏳ Bug 2 count 課稀疏堂次 materialize：**PR #937**（`fix/count-session-same-day-materialize`）；**刻意跳過 `PackageID>0` 共用池**（周宏謙 / #162 需商業規則後另 PR）。
- ⏳ in-app `#174` 重疊課程 force-create modal：**PR #938**（`fix/course-overlap-force-create` → main）；prod 曾手動部署 `acf1251`，`version.json` 可能落後 git HEAD，merge 後應走正常 deploy。
- 🗑️ 聚合 hotfix **PR #925** 已留言 superseded，可 close。

**production 快照（2026-06-27）**
- `GET /api/v1/health` → ok；`version.json` hash=`acf1251`（前端-only 手動部署痕跡；backend 實際含 `#922–#929` 多數修復，以 deploy log / Pi `git HEAD` 為準）。
- **#926** Sentry `Unknown column 'not'`：2026-06-20 中間版 SQL 事故；**現行 prod `/learning-records` 200**，issue 可標 resolved-after-deploy 並關閉。
- 新 in-app `#171–#176` / GitHub `#931–#936`：**不在本 handoff 範圍**，勿重複 triage。

**待 merge / 待 CEO 決策**
| 項目 | 動作 |
|---|---|
| **#937** Bug 2 materialize | CI 綠 → merge → deploy → 請陸逸老師驗證非共用池 count 課點名 |
| **#938** #174 overlap modal | merge → deploy（讓 main 與 prod 收斂）|
| **#874** + **#850** docs handoff | 本分支已更新 INDEX / SOP_MATURITY / ROADMAP / integrity-check；CI 綠後 merge（docs-only）|
| **#920** in-app #168 | `status:needs-decision`：主任確認 3/19 是否計入本期後才開資料修復 PR |
| **周宏謙共用池 materialize** | 需定義「12 堂池分配到各科」規則後再實作；不可 per-course SessionCount 盲目補堂 |

**下一個 Agent 第一步**
1. 讀 `docs/reviews/ENGINEERING_AUDIT_2026-06-27.md` Top 20 + 30-day roadmap
2. **#970** header auth bypass — stop-the-line fix branch（手動 security test；CI minutes 耗盡時勿等 Actions）
3. 開 in-app #931–#936 triage 與 #957 materialization epic 規劃（不重複 audit 已建 issues）
4. `gh pr checks 937` / `938` 若仍 open → merge 順序不變

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

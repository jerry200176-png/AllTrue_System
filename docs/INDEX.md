# AllTrue Docs Index — Service Catalog

> **INDEX = registry only. NOT a decision or execution system.**
>
> **Single runtime spec:** [`docs/CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5) — supreme on conflict  
> **Decision (I3):** INCIDENT stack via [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)  
> **Execution (I1):** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only  
> **Audit / conflicts:** [`CONTROL_PLANE_AUDIT.md`](CONTROL_PLANE_AUDIT.md) · [`CONTRADICTION_REGISTRY.md`](CONTRADICTION_REGISTRY.md)

> **Source of truth:** committed files on `origin/main` only.

> **前人種樹，後人乘涼。** 本檔只做指標定位；runtime 不讀 INDEX。
>
> **知識流轉三層：**
> ```
> Docs（長期文件）←──────────────────────────────┐
>   ↓ AI 開工讀 INDEX → 定位 → 只讀對應章節       │ 做完寫記錄
> MemPalace（召回索引，非權威）                      │
>   ↓ scripts/mempalace-ingest.sh（唯一更新入口）   │
>   ↓ post-merge 自動呼叫 ingest                    │
> AI Session（執行）──────────────── write-back ──►│ (CHANGELOG /
>                                                 │  AI_REGRESSION /
>                                                 │  TECH_DEBT)
> ```
> 設計原則：**最小讀取，最大效果。** 先看這頁決定去哪，再只讀那個章節。  
> **長文不漏讀**：速讀卡與版本更新鏈已整合在本 INDEX；[`docs/AI_DOC_LITERACY.md`](AI_DOC_LITERACY.md) 僅保留作索引 stub。

---

## 🗂️ Structured service catalog (registry schema)

**Schema (required fields per row):** `service name` · `role` · `execution owner` · `incident linkage` · `SLO`

**Production incident?** → [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) → [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md)

| Service name | Role | Execution owner | Incident linkage | SLO |
|--------------|------|-----------------|------------------|-----|
| Control plane contract | prod | [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) | supreme runtime spec | yes |
| POP (Production Operations Platform) | prod | [`docs/pop/adr/README.md`](pop/adr/README.md) · [`operations/catalog.yaml`](../operations/catalog.yaml) | Architecture Freeze 2026-07-16; Phase 1 foundation | yes |
| Contradiction registry | tool | [`CONTRADICTION_REGISTRY.md`](CONTRADICTION_REGISTRY.md) | conflict resolution | no |
| Control plane enforcer | tool | [`CONTROL_PLANE_ENFORCER.md`](CONTROL_PLANE_ENFORCER.md) · `scripts/control-plane-lint.mjs` | CI gate | no |
| AllTrue production app | prod | Pi runtime + [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) | [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) | yes |
| Incident policy (I3/I4 sub-layer) | incident | [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) | FINAL_ACTION per contract I4 | yes |
| Incident inference engine | prod | [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) | [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) step 2 | yes |
| Incident runtime loop | prod | [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) | self | yes |
| Incident state machine | prod | [`INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md) | classification layer only | yes |
| Incident decision entry | prod | [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) | observe + runbook paths | yes |
| Production deploy | infra | [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) | [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) § CONTAIN | yes |
| CI merge gate | infra | [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) | [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) · [`SEVERITY_MATRIX.md`](SEVERITY_MATRIX.md) CI-* | yes |
| Rollback procedures | ref | [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) → `deploy.yml` | execution helper only | yes |
| Severity lookup | ref | [`SEVERITY_MATRIX.md`](SEVERITY_MATRIX.md) | mapping only — no decision authority | yes |
| Dangerous ops guard | infra | [`DANGEROUS_OPERATIONS.md`](DANGEROUS_OPERATIONS.md) | [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) | yes |
| Backup / restore | infra | [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) §P · [`.github/workflows/backup-restore-test.yml`](../.github/workflows/backup-restore-test.yml) | [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) DB path | yes |
| Operational constraints | ref | [`OPERATIONAL_CONSTRAINTS.md`](OPERATIONAL_CONSTRAINTS.md) | checklist — does not override contract | no |
| SOP drift audit | tool | [`OPERATIONAL_CONSISTENCY_CHECK.md`](OPERATIONAL_CONSISTENCY_CHECK.md) | — | no |
| MemPalace ingest | local | [`MEMPALACE_OPERATIONS_HANDBOOK.md`](MEMPALACE_OPERATIONS_HANDBOOK.md) · `scripts/mempalace-ingest.sh` | none (MP-* best-effort) | **no** |
| Shadow control plane (archived) | archive | [`archive/control-plane-shadow-v1/README.md`](archive/control-plane-shadow-v1/README.md) | non-runtime — do not execute | **no** |

**MemPalace is a non-production, best-effort local system. It has no incident authority, no SLO, and no execution impact on production.**

**Authority:** [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) · INDEX = registry only

---

## INCIDENT stack pointer (not a second authority — contract I3)

Part of the INCIDENT decision system defined in [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md):

| File | Role in stack |
|------|----------------|
| [`INCIDENT_RUNTIME_LOOP.md`](INCIDENT_RUNTIME_LOOP.md) | Loop orchestration |
| [`INCIDENT_INFERENCE_ENGINE.md`](INCIDENT_INFERENCE_ENGINE.md) | SIGNAL → STATE |
| [`INCIDENT_POLICY_ENGINE.md`](INCIDENT_POLICY_ENGINE.md) | STATE → FINAL_ACTION (I4) |
| [`INCIDENT_STATE_MACHINE.md`](INCIDENT_STATE_MACHINE.md) | State transitions |
| [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) | Observe + runbook command paths |

Precedence within stack: POLICY > STATE > SIGNAL (contract I4).

---

## 🏢 AllTrue AI 公司治理

AllTrue 現在以 **AllTrue AI 公司** 方式治理。使用者是 CEO；AI Agents 是產品、工程、QA、資安、維運、文件等職能團隊。

**公司 slogan：前人種樹，後人乘涼。**

治理原則：
1. 做事前先查：先讀本 INDEX，再按任務查 Docs / MemPalace / 對應 rules。
2. 做完要記錄：功能進 `CHANGELOG`，事故進 `AI_REGRESSION_LESSONS`，技術債進 `TECH_DEBT`，複雜架構進 `SYSTEM_TECH_GUIDE`。
3. 規則單一出處：頂層文件只導航，不複製長 SOP；避免文件互相打架。
4. 任何 AI 不靠記憶硬猜；先查資料，再動手。
5. `.cursor/plans/**`、`*_ARCHIVE*` 與長篇歷史文件只供 `rg` / MemPalace 搜尋，不通讀；**runtime 衝突時**以 [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) 為準。

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
2. `.cursor/rules/bug-fix-plan.mdc` — Bug 調查 SOP（**§B0：修 bug 前必翻歷史 + 認領復發家族，降低復發率**）
3. `docs/AI_REGRESSION_LESSONS.md` — 對應模組的已知坑（**§復發家族 F1–F6＝known-issues registry，改前先認領**）
4. **In-app Bug 回報**（分診／上線後回寫）：`docs/CHAT_BUG_SYSTEM.md` §3.6–§3.7、`AI_REGRESSION_LESSONS.md` §R51／§R53；**關閉閘門** → `docs/GUIDE_BUG_CLOSURE_GATE.md`
5. **In-app Bug 公開回覆白話範本**：`docs/GUIDE_SUPPORT_REPLY_MACROS.md`（10 個 macro，對應狀態機；送出前跑禁用詞檢查）
6. **外部工程技能包（選用）**：[`docs/GUIDE_AGENT_SKILLS.md`](GUIDE_AGENT_SKILLS.md) — addyosmani/agent-skills 整合評估；[`docs/GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md`](GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md) — AllTrue 本地化 `.cursor/skills/alltrue-*`
7. **資料修復／事故 execution package（需核准）**：[`docs/incidents/189-191-data-repair-plan.md`](incidents/189-191-data-repair-plan.md)、[`docs/incidents/189-191-execution-package.md`](incidents/189-191-execution-package.md)、[`docs/incidents/190-historical-billing-repair-plan.md`](incidents/190-historical-billing-repair-plan.md)、[`docs/incidents/190-reconciliation-report.md`](incidents/190-reconciliation-report.md)、[`docs/incidents/190-billing-technical-options.md`](incidents/190-billing-technical-options.md)、[`docs/incidents/189-191-dryrun-report.md`](incidents/189-191-dryrun-report.md)、[#1127 scheduler output evidence](incidents/1127-scheduler-evidence-execution-package.md)
8. **Bug 關閉閘門**：[`docs/GUIDE_BUG_CLOSURE_GATE.md`](GUIDE_BUG_CLOSURE_GATE.md) — 根因/測試/驗證/回覆/文件/回滾六項必填
9. **Release Execution Package**：[`docs/GUIDE_RELEASE_EXECUTION_PACKAGE.md`](GUIDE_RELEASE_EXECUTION_PACKAGE.md) — production 變更標準模板
10. **#957 D1 Sprint**：[`docs/refactor/957-d1-sprint-design.md`](refactor/957-d1-sprint-design.md)、[`docs/runbooks/957-d1-deploy-runbook.md`](runbooks/957-d1-deploy-runbook.md)、[`docs/runbooks/957-d1-production-readiness-report.md`](runbooks/957-d1-production-readiness-report.md)、[`docs/runbooks/957-d1-pcr.md`](runbooks/957-d1-pcr.md)

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
| **UI 文案 / 空狀態 / Loading 規範** | `docs/GUIDE_UI_COPY.md` |
| **前端 PR 設計驗收清單** | `docs/GUIDE_DESIGN_QA_SMOKE.md` |
| Deploy SOP | `.cursor/rules/auto-frontend-deploy.mdc` |
| UI 設計規則 | `.cursor/rules/module-frontend.mdc` |
| 行事曆週檢視資料合併規則 | `CLAUDE.md §G-007`（⛔ 禁止分散 if，必走 `calendarOccurrenceMerge.js`）|
| 行事曆 ClassSession 投影完整性 | `docs/GUIDE_PROJECTION_INTEGRITY.md`（list vs projection API；CI `ClassSessionProjectionTest`）|
| **SmartCalendar 受控拆分（#740）** | `docs/GUIDE_SMARTCALENDAR_REFACTOR.md`（元件清單、API、CSS 解耦決策）|
| 行事曆回歸測試 | `npm run test:calendar`（修改任何 calendar merge 邏輯前必跑）|
| 家長入口 UX、分眾版本公告 | `docs/ROLE_PLAYBOOK.md` §4、`docs/AI_REGRESSION_LESSONS.md` §R45；`npm run test:release-notes`（改 `releaseNotes.js` / changelog 產生器時） |
| `assume-unchanged` 藏檔導致 PR 漏 diff | `AI_REGRESSION_LESSONS.md` §R58 |

### 部署 / 維運
| 需要什麼 | Registry 入口 |
|----------|---------------|
| **Production 事故** | [`docs/INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md) |
| **Deploy 執行** | [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) |
| **Rollback** | [`docs/RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) |
| **CI** | [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) · `docs/OPERATIONS_RUNBOOK.md` §B |
| **CI minutes 耗盡時 offline merge** | [`docs/OFFLINE_MERGE_SOP.md`](OFFLINE_MERGE_SOP.md) |
| **Branch protection 升級** | [`docs/BRANCH_PROTECTION_UPGRADE.md`](BRANCH_PROTECTION_UPGRADE.md) |
| **Backup** | `docs/OPERATIONS_RUNBOOK.md` §P |
| **完整 SOP** | `docs/OPERATIONS_RUNBOOK.md` |
| **危險操作** | `docs/DANGEROUS_OPERATIONS.md` |
| **SOP 漂移檢查** | `docs/OPERATIONAL_CONSISTENCY_CHECK.md` |

### SRE / Product Ops
| 需要什麼 | 去哪裡找 |
|----------|---------|
| SLI / SLO / Error Budget / Release Freeze | `docs/SRE_POLICY.md` |
| Post-release T+7/T+14/T+30 metrics review | `docs/PRODUCT_OPS.md` |
| 採用率 / 品質指標定義 | `docs/ADOPTION_QUALITY_METRICS.md` |
| **Product → Engineering maturity roadmap** | `docs/MODULE_PRODUCT_ENGINEERING_MATURITY_ROADMAP.md`（7/1 後 AI 接手總圖） |
| **AI-native 演進路線圖（BI/異常/留存/AI 行政）** | `docs/POLICY_AI_NATIVE_ROADMAP.md`；metric 底座＝`ops:business-digest` / `BusinessDigestService` |
| 產品缺口審查（月度快照） | `docs/reviews/PRODUCT_GAP_REVIEW_2026-06.md` |
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
| **分層規則 + controller `DB::` ratchet** | `docs/ADR_003_layering_and_controller_db_ban.md`（新 DB:: 不得超基線；`node scripts/controller-db-ratchet.mjs`）|

### 測試帳號 / 登入
- `.cursor/.local/test-credentials.md` — 各角色帳密 + Browser MCP 踩坑 SOP

---

## 📝 新建 docs 命名規範（Phase C 起生效）

新建 `docs/` 檔案請加 prefix，讓 AI 從名稱判斷文件類型（對齊 Diátaxis 文件分類）：

| 前綴 | 用途 | 範例 |
|------|------|------|
| `RULE_` | 規範性，read-before-doing（不可擅改） | `RULE_PAYMENT_ALERTS.md` |
| `RUNBOOK_` | 操作 SOP（step-by-step） | `RUNBOOK_DEPLOY.md` |
| `REF_` | 純參考查表（API、schema、對照表） | `REF_API_ROUTES.md` |
| `MODULE_` | 模組深度說明（架構 + 流程） | `MODULE_CHAT_BUG.md` |
| `GUIDE_` | 教學 how-to | `GUIDE_WSL2_SETUP.md` |
| `POLICY_` | 政策決策 | `POLICY_SRE.md` |
| `ADR_` | 架構決策記錄（**historical / draft until on `main`**） | `ADR_001_calendar_merge.md` |

舊檔按現有名稱延用（不強制改名，會破壞參照；下次大改時順手 rename）。CI（`scripts/docs-integrity-check.mjs`）會對不符合 prefix 且非既有清單的新檔發出 `warning`。

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
| `docs/AI_DOC_LITERACY.md` | AI 讀檔協議 stub；速讀卡已整合進本 INDEX |
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
| `docs/SOP_MATURITY.md` | SOP 成熟度、M4–M9 roadmap、Actions freeze 接手地圖 |
| `docs/MODULE_PRODUCT_ENGINEERING_MATURITY_ROADMAP.md` | Product maturity → engineering maturity 的 7/1 後 AI 接手總圖 |
| `docs/CHANGELOG.md` | 最近上線功能記錄 |
| `docs/archive/CHANGELOG_ARCHIVE_2026-05.md` | 2026-05 CHANGELOG（滾動歸檔，只搜尋不通讀） |
| `docs/archive/CHANGELOG_ARCHIVE_2026-04.md` | 2026-04（含更早）CHANGELOG（archive，只搜尋不通讀） |
| `docs/TECH_DEBT.md` | TD-NNN 技術債清單 |
| `docs/DANGEROUS_OPERATIONS.md` | 高風險操作清單與 SOP |
| `docs/DEPLOYMENT.md` | 部署架構說明 |
| `docs/DB_PERF.md` | DB 效能優化記錄 |
| `docs/SECURITY.md` | 安全設計決策 |
| `docs/RULE_DESIGN_SYSTEM.md` | **設計系統唯一真相來源**（淺色底 + navy 墨字 + 品牌橘黃主色、金額 tabular、不用 gradient mesh）；所有前端 UI 照此生成 |
| `docs/GUIDE_UI_COPY.md` | UI 文案、空狀態、loading 規範 |
| `docs/GUIDE_DESIGN_QA_SMOKE.md` | 前端設計 QA / smoke 驗收清單 |
| `docs/GUIDE_SMARTCALENDAR_REFACTOR.md` | SmartCalendar 受控拆分與元件/ composable 對照 |
| `docs/WSL2_DEV_SETUP.md` | WSL2 本地開發環境設定 |
| `docs/api-swipe-rfid.md` | RFID 刷卡端點 API 參考（請求/回應、Apache DocumentRoot 排錯）|
| `docs/SUPER_ADMIN_AND_MIGRATIONS.md` | super_admin 與 migration 操作速記 |
| `docs/RULE_MIGRATION_COMPAT.md` | **Migration 向後相容守則**（Expand/Contract、down() 可逆性、PR 必填欄位）|
| `docs/AMBIENT_AUDIO_LICENSES.md` | 環境音效彩蛋的音檔授權清單 |

### 維運 SOP
| 檔案 | 一行說明 |
|------|---------|
| `docs/OPERATIONS_RUNBOOK.md` | 完整 SOP 手冊（§A-P，按節查）|
| `docs/SRE_POLICY.md` | SLI / SLO / error budget / release freeze 政策 |
| `docs/PRODUCT_OPS.md` | Post-release metrics review 與產品營運節奏 |
| `docs/ADOPTION_QUALITY_METRICS.md` | 採用率與品質指標定義 |
| `docs/RUNBOOK_ROLLBACK.md` | 回滾 SOP 與 rollback readiness 檢查 |
| `docs/DAILY_CHECKLIST.md` | 每日例行檢查清單 |
| `docs/SMOKE_TEST_RUNBOOK.md` | 部署後 smoke test SOP（`scripts/post-merge-smoke.sh`）|
| `docs/DOCS_GOVERNANCE_SOP.md` | 文件治理節奏（已整合進本 INDEX §治理節奏；stub 供索引）|
| `docs/AI_DOC_LITERACY.md` | AI 讀檔協議（已整合進本 INDEX §速讀卡；stub 供索引）|

### 模組文件
| 檔案 | 一行說明 |
|------|---------|
| `docs/archive/SCHEDULE_DISCREPANCY_REVIEW.md` | 課表出入差異審核流程（已移入 archive）|
| `docs/SUBSTITUTE_UX.md` | 代課 UX 設計 |
| `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md` | 排課日期語義 |
| `docs/CHAT_BUG_SYSTEM.md` | 聊天／Bug 回報；**§3.6–§3.7**＝分診 + 修完回 in-app 完整 SOP |
| `docs/GUIDE_SUPPORT_REPLY_MACROS.md` | in-app bug 公開回覆白話 macro library（#907）；對應 §3.8 禁用詞規則 |
| `docs/LINE_LIFF_CHECKLIST.md` | LINE LIFF 上線檢查清單 |
| `docs/reviews/PRODUCT_GAP_REVIEW_2026-06.md` | 2026-06 產品缺口審查 snapshot；新月份建立新 reviews 檔後再歸檔舊版 |

### 資安文件
| 檔案 | 一行說明 |
|------|---------|
| `docs/security/ASVS_L1_2026.md` | OWASP ASVS L1 年度自查 |
| `docs/security/AUDIT_LOG_POLICY.md` | 敏感 admin 行為 audit log 政策 |

### Backend 局部參考（非主入口）
| 檔案 | 用途 |
|------|------|
| `backend/docs/import_templates.md` | 匯入 CSV/XLSX 欄位速查；主流程仍看匯入頁與測試 |
| `backend/docs/rfid_swipe_test_steps.md` | 舊 `POST /api/v1/attendance/swipe` api_key 手測參考；分校讀卡機以 `docs/api-swipe-rfid.md` 為準 |
| `backend/docs/line_setup.md` | LINE Developers console 歷史設定筆記；現行上線檢查以 `docs/LINE_LIFF_CHECKLIST.md` 為準 |

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
| **`Deploy to Pi` (`deploy.yml`)** | merge `main`（deployable diff） | → [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) |
| `rollback-readiness.yml` | 排程 / 手動 | 非破壞性 rollback 就緒度（#733） |
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
| `mempalace-monthly.yml` | 每月 | **Reminder only** — comment on issue #519；ingest 須 WSL2 手動 `mempalace-maintain.sh` |
| `branch-hygiene.yml` | 週一至五 | 已合併分支 dry-run 報告 |
| `teacher-signin-diagnose.yml` / `teacher-signin-recovery.yml` | 手動 / 排程 | 老師刷卡資料診斷與回補 |

> `ci.yml` / `presubmit.yml` / `codeql.yml` / `deploy.yml`（#867 起）皆使用 WSL2 self-hosted runner `wsl2-jerry-alltrue`（labels: `self-hosted`, `Linux`, `X64`, `wsl-ci`, `alltrue-ci`）。deploy 移到 self-hosted 是避免 GitHub-hosted 額度凍結卡死部署鏈；單一 runner 序列化，deploy 排在長 CI 之後有分鐘級延遲屬正常。部署以 `workflow_run.head_sha` 為準並校驗 HEAD（§R62，杜絕靜默舊版）。
> `main` branch protection 已啟用：required checks + admin enforcement + 禁止 force push/delete。備份同步會產生 Google Drive manifest（檔名 / 大小 / sha256），詳見 `OPERATIONS_RUNBOOK.md §P`。

---

## 📐 MemPalace 導航（dev tooling — NOT production）

> **MemPalace is a non-production, best-effort local system. It has no incident authority, no SLO, and no execution impact on production.**

**索引更新（唯一入口 — event-sourced DAG）：**
```bash
bash scripts/mempalace-ingest.sh              # 完整 ingest（state = events.jsonl）
bash scripts/mempalace-ingest.sh --replay     # 從 event log 重建 run 狀態
bash scripts/mempalace-ingest.sh --resume     # 跳過已有 stage_completed 的節點
bash scripts/mempalace-ingest.sh --dry-run --no-lock
bash scripts/mempalace/run-stage.sh preflight --no-lock
```

Manifest: `scripts/mempalace/engine/pipeline.manifest.json`  
Events: `~/.mempalace/palace/.ingest-run/runs/<run_id>/events.jsonl`

**讀取（不寫索引）：**
```bash
~/.local/bin/mempalace search "<關鍵字>" --wing alltrue-sessions
~/.local/bin/mempalace search "<關鍵字>" --wing alltrue-docs
~/.local/bin/mempalace wake-up --wing alltrue-sessions
```

**設定 SSOT：** `scripts/mempalace-config.sh`（wing 名稱、路徑）  
**架構：** `docs/MEMPALACE_ARCHITECTURE_HEALTH.md`  
**維運手冊（Runbook / Failure / On-call）：** `docs/MEMPALACE_OPERATIONS_HANDBOOK.md`

Wings：`alltrue-sessions`（對話）、`alltrue-docs`（文件）、`alltrue-code`（程式碼，手動 `mempalace mine . --wing alltrue-code`）

**Source of truth：** MemPalace 是召回索引，不是權威。與 markdown 衝突時以 git 內文件為準。

**觸發時機：** PR merge 後 post-merge hook 背景呼叫 `mempalace-ingest.sh`；每月手動 `mempalace-maintain.sh`。

**L0 identity（可選）：** 複製 `docs/mempalace-identity.example.txt` → `~/.mempalace/identity.txt`

**已移除：** Cursor / Claude MemPalace hooks（避免多路徑 ingest）。本機若仍設定 `~/.cursor/hooks.json` 或 `.claude/settings.local.json` MemPalace hooks，請刪除。

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
- [`docs/OPERATIONAL_CONSISTENCY_CHECK.md`](OPERATIONAL_CONSISTENCY_CHECK.md)（catalog / deploy authority 漂移）
- 修正斷鏈、遺漏導航、入口與章節不一致。

**每月（記憶保鮮 + CHANGELOG 滾動歸檔）**
- `bash scripts/mempalace-maintain.sh`（唯一 ingest 路徑：`scripts/mempalace-ingest.sh`）
- **CHANGELOG 滾動歸檔**（對齊 Keep a Changelog）：月初把上月條目從 `docs/CHANGELOG.md` 移入 `docs/archive/CHANGELOG_ARCHIVE_YYYY-MM.md`，主檔只留當月 + 頂部 archive 導航。size gate 已對 `chore/docs-*` 分支排除 CHANGELOG/archive 搬移（presubmit CHECK 2）。

**變更守則**：先改權威文件，再補 INDEX 導航；不在多份文件複製完整 SOP（避免版本漂移）。

---

## 📁 docs/archive/ — 歷史文件區

下列文件已移入 `docs/archive/`，不再主動維護。只搜尋用，禁止通讀。

| 檔案 | 說明 |
|------|------|
| `PORSCHE_VISUAL_SYSTEM.md` | ⛔ Superseded 設計系統；現行看 `RULE_DESIGN_SYSTEM.md` |
| `使用說明_主任與超級管理員.md` | Developer Bypass FAQ（歷史）；角色全貌見 `ROLE_PLAYBOOK.md` |
| `更新網站前端.md` | 本機手動覆蓋 `public`（歷史）；正式 deploy 依 CI |
| `AI_REGRESSION_LESSONS_ARCHIVE.md` | 事故長文 archive；摘要在 `AI_REGRESSION_LESSONS.md` |
| `CHANGELOG_ARCHIVE_2026-05.md` | 2026-05 changelog（滾動歸檔）|
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

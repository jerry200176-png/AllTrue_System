---
owner: jerry (CEO)
status: Planned — wave 1 in progress (Refs #3115)
review_cycle: biweekly
last_reviewed: 2026-10-13
---

# 九大工程面向：現況量測與執行計畫（2026-10-13 修訂）

**上層 roadmap：** [#3115](https://github.com/jerry200176-png/AllTrue_System/issues/3115)（M0–M3、工作包代號 P/A/I/D/R/O/S/G/E 沿用）。本文件**不另開 backlog**，只補「量測過的現況、排序、可開工的 PR 切法」，並把 Founder 指定的 10 個 agent skill 對應到實際工作方式。  
**量測基準：** main @ `7e2597f5`，2026-10-13，四份唯讀 audit（產品／架構、平台／交付／SRE／可觀測、資安／治理／Agent、UI/UX）。數字是當天 grep／檔案統計，不是 KPI。  
**本文件：** R0 docs only。各工作包的 PR 依自己的 Risk-Class／Autonomy-Tier 走現行政策；本計畫**不**授權任何 T3 動作。

---

## 0. 一句話結論

系統的「防線」（exact-SHA 部署、risk tier、ratchet、F 家族）已經是大公司等級；落後的是三件事：**同一個業務事實仍有多個計算點**（錢、剩餘堂數、排課鏈）、**量測是假的或沒有**（DORA 以 merge 當部署、SLO 從未填數字、Sentry 沒有 release）、**畫面沒有共用的視覺語言**（149 種字級、205 種陰影、886 個原生按鈕、323 個瀏覽器 alert）。接下來 90 天只打這三件事，不開新平台、不重寫。

## 1. 10 個 skill 怎麼用在 AllTrue（取方法，不批量安裝）

`alltrue-inapp-product-loop` §7 禁止批量安裝外部 skill。做法：每個 skill 只取「工作方式」，落到既有流程；確定重複 ≥3 次且有效益的，才以單一 PR、固定 commit、授權檢查後 vendor 進 `.claude/skills/`（W1-E2）。

| Skill | 解決什麼 | 在 AllTrue 落在哪 |
|---|---|---|
| find-skills | 找到合適工具 | 開工前查 `.cursor/skills`／`.agents/skills`／`docs/GUIDE_AGENT_SKILLS.md`，不重造 |
| grill-me | 追問盲點 | 每個 `PLAN_REQUIRED` 的 Decision Packet 先列「反方問題」再推薦 |
| grill-with-docs | 依原文核對 | 業務規則只引用 GLOSSARY／ADR／SourceRef；找不到原文 = 列為 Founder 決策，不自創規則 |
| improve-codebase-architecture | 找架構卡點 | §3 架構工作包：把淺模組加深成單一權威（ContractPrice、SessionBalance、OccurrenceReader） |
| agent-browser | 代辦網頁操作 | UI PR 一律附 390／1440 Playwright 截圖（ui-foundation harness，mock API） |
| tdd | 先寫測試 | 既有 R1 紅線＋每個 seam 先寫 characterization test，再搬呼叫者 |
| frontend-design | 去模板感 | §10 的 Anti-AI-slop 規則＋ratchet guard |
| setup-matt-pocock-skills | 工單標籤與規則 | `docs/agents/triage-labels.md` 已寫，但 GitHub 上 4/5 個 label 不存在 → W1 補齊 |
| handoff | 換手不重講 | GitHub issue 留言模板（task／risk／evidence／blocker／next）；雲端 session 無本機 MemPalace 時的唯一換手來源 |
| triage | 分類＋下一步 | in-app auto-intake issue 加 `needs-triage`→`ready-for-agent`/`ready-for-human`，與 F 家族 label 並用 |

---

## 2. 產品與執行工程（P）

**現況：** in-app 回報 → GitHub 已自動化（hourly auto-intake、Phase-C autodispatch、週報）。約 230 筆回報的根因集中在 F7 金額 ~45、F8 佔位 ~35、F4 剩餘堂數 ~35、F1 生命週期 ~30。兩條核心旅程（繳費、排課）**沒有**可重跑的驗收套件（P1 未開工）。10/07 後 6 天新增 174 份 docs（112 份 `docs/changes/`）。

**工作包（依序）：**
1. **P1 旅程驗收套件**：繳費、排課各 ~10 個場景，每個場景連到既有測試或 SourceRef；兩個 CI target `test:journey-billing`／`test:journey-schedule`。先只彙整既有測試，不新增業務規則。（R0–R1）
2. **P2 每 PR 標 F 家族**：PR 模板已有欄位 → presubmit 檢查非空；週報加「各家族復發數」。（T1，scripts/ci）
3. **P3 WIP 上限**：同一 F 家族同時最多 2 個進行中 PR（C-PR／B-PR／ARCH2 目前同檔並行）。由 `pr-overlap.mjs` 報警，不硬擋。

## 3. 架構工程（A）— improve-codebase-architecture

**現況：** 控制器 47k 行；`StudentClassController` 6,554 行（27 actions、69 個 `DB::`）。最長方法：`ParentPortalController::dashboard` 648 行、`StudentClassController::sessionDates` 598、`::index` 579、`::update` 528。`ContractMoneyVerdict` 已建好但**只有 1 個呼叫端**；count-mode 金額公式約 30 處；`RemainingSessions` 11 個直接寫入點；`schedules` 被 11 個控制器寫入。Unit test 39 個（只有 1 個碰 DB）。

**已完成、不要重做：** ContractMoneyVerdict、ContractRenewal、BillingCorrection、payment-actions、GLOSSARY truth rule、occurrence CT-1..7、ADR-003 ratchet、ArchitectureInvariantsTest、lazy-load ban、query budget。

**工作包（每個 = 一個 seam，先 characterization test 再搬呼叫者）：**
1. **A2a `ContractPrice::expectedCharge()`**：純函式（rate_unit、package、hour vs session），Unit test 覆蓋；先搬 `AlertController::countModeCharge/packageCountModeCharge` 與兩條 correction 路徑。語意不變的重構 → 依 2026-10-12 常設決定①可由 Agent 合併；若測試顯示現有呼叫端結果不一致 → 停下，列 Founder 決策（屬新帳務語意）。（R3 實作既定規則）
2. **A2b `StudentClassController::index` 改走 `ContractMoneyVerdict::forCourses()`**：刪 inline match（:571-626），characterization test 延伸到 list endpoint。依賴 #3862 hold 的結論。（R3）
3. **A2c `SessionBalance` 唯一寫入者**：所有 `RemainingSessions` 寫入改經 ledger；ArchitectureInvariantsTest 新規則禁止直接指派（ratchet，現有 11 處只減不增）。（R3）
4. **A2d `ParentDashboardQuery` read model**：從 648 行 `dashboard()` 抽出，先補 Feature test。（R2）
5. **A2e 前端 `courseMoneyState.js` 狀態→動作表**：吸收 `TuitionCollectionPage` 的 31 個 `payment_status` 分支。（R1–R2）
6. **A1 readers/writers/authority 表**：錢、排課各一張，寫進既有 RFC（不新開文件）。（R0）

排課 `OccurrenceReader`（TD-076 Phase 3）依 RFC 既定 GO 條件，不在本輪提前。

## 4. 平台與基礎設施（I）

**現況：** 單台 Pi；deploy 為 `git reset` 到 exact SHA。Laravel `^8.75` 鎖在 **8.x-dev**（EOL、不可由 tag 重現）；PHP 8.2 安全支援到 2026-12。staging stack（`infra/daan-staging/`）存在但不在發布路徑上。

1. **I1a 釘 Laravel 8 到 tag `v8.83.x`** + `docs/architecture/RUNTIME_VERSIONS.md`（查詢日期）。composer.lock 屬 deployable → 走 release train。（T2）
2. **I1b PHP 8.3 相容性 job**（先 optional）。（T2，CI）
3. **I2 staging 唯讀登入 smoke** 接 release train 前置。（control plane → T3，需 Founder）
4. Laravel 9/10 升級留在 #977／#2833（Founder：重做衝突或暫停）。

## 5. 交付與發布（D）

**現況：** 部署控制面成熟（exact SHA、classify-activation、DB dump before migrate、health＋smoke＋SHA 比對、hourly release train、land-queue batching）。10/06 後 60 次 Deploy run 中真正部署 9 次（8 成功、1 fail-closed）。

1. **D1 migration 失敗即中止**：`deploy.yml:1679-1685` 目前 migration 失敗新碼仍上線、run 最後才紅。改為 `abort_deploy`（expand-only label 例外）＋contract-test。**風險最高的一項**。（deploy.yml → T3，Founder GO）
2. **D2 release receipt**：deploy job 輸出 JSON（SHA、含哪些 PR、tier、批准者、health、smoke）並在成功時打 tag。（T3）
3. **D3 部署後觀察窗**：15 分鐘後再查 health＋Sentry 新 issue 數。（T3）

## 6. 生產可靠性（R）

**現況：** `docs/SRE_POLICY.md` 定義 SLI-01..05，但月報只有一列「待收集」（2026-05）。備份 6 小時／每日／每月＋Google Drive；restore drill 每月綠，但只還原本機最新 dump、門檻極低（Student ≥10），從未還原雲端副本、不量 RTO。

1. **R1 每週 SLI 寫回**：UptimeRobot 可用率（SLI-01）＋部署 smoke 通過率（SLI-05）自動補表。（workflow → T2/T3 依 autonomy_gate）
2. **R3a 異地還原演練**：rclone 拉 Drive 副本 → 還原 → 與正式列數比對 → 記錄耗時（RTO）。讀取正式備份 → Founder GO。
3. **R3b 事故模板**：偵測／開始／恢復／驗證四個時間戳。（R0）

## 7. 可觀測性（O）

**現況：** Sentry 前後端都有，但**沒有 release**（`SENTRY_RELEASE` 讀了但 deploy 沒注入；前端 `Sentry.init` 無 release）；後端 traces 預設 null。`X-Request-Id` 已有。DORA workflow 仍以 merged PR 當部署（約 130 merge vs 9 次真部署 → 數字失真 14 倍）。

1. **O1 DORA 改用真部署**：部署頻率＝Deploy to Production 成功次數；lead time＝merge→deploy job 完成；變更失敗＝失敗或回滾的部署。仍是代理值者改名標示。（workflow，T2）
2. **O2 Sentry release＝TARGET_SHA**：deploy 注入 `SENTRY_RELEASE`／`VITE_SENTRY_RELEASE`，`main.js` 帶 release，`AssignRequestId` 加 Sentry tag。（deploy.yml 部分 T3；app 部分 R1）
3. **O3 告警目錄**：`OPERATIONS_RUNBOOK.md` 加一張表（來源、接收者、門檻、runbook、恢復條件）。（R0）

## 8. 資安（S）

**現況：** 沒有 Laravel Policy／Gate；`RequireCampus` 只檢查「有沒有分校」不檢查「這筆資源是不是你的分校」；63/85 控制器手寫分校檢查（23 個各自的 helper）；499 條路由約 48 條不在任何群組。raw SQL 538 處（18 處字串插值，抽查非使用者輸入）。composer audit 有 `|| true`；#1387 secret-echo guard 沒有任何 workflow 在跑（#3890 處理中）。`.claude.json`（含帳號 email／UUID）被追蹤在公開 repo（#3907 已 untrack；歷史清除需 Founder）。

1. **S1a 路由清單測試**：所有非公開路由必須在 `require_campus` 群組或明列 allowlist（ratchet）。（T2）
2. **S1b 每個控制器家族一個跨分校 403 測試**，先從帳務、學生、出勤。（tests only, T1）
3. **S2 安全閘門 fail-closed**：composer audit 移除 `|| true`、#1387 guard 接上 Control Plane Lint（#3890）。（control plane → T3）
4. **S3** Git 歷史中的 `.claude.json` 是否 purge：Founder 決策（不可逆 force-push）。

## 9. 治理工程（G）

**現況：** 指令檔約 6,200 行／480 KB；ExoProtocol 區塊（120–144 行）貼在 4 個根檔，內容與實際不符（max files 12、approved checks pytest），最後 ticket 2026-09-12。實際衝突：Pi SSH（CLAUDE.md R6 允許唯讀；AGENTS.md／.cursorrules／Constitution／settings／guard 仍全禁）、「5 條紅線」實列 R1–R6、PR 上限文件寫 700 實際 1,300／3,000。4 支治理腳本沒有任何 workflow 呼叫。

1. **G1 `docs/governance/POLICY_CHECK_MAP.md`**：規則 → 腳本 → workflow → 是否 required。（T3，protected path）
2. **G2 把 `check-instruction-invariants.sh` 接進 Control Plane Lint**，含 SSH／tier 用語一致性檢查。（T3）
3. **G3 指令收斂**：移除失效的 Exo 區塊、修正紅線數與 SSH 衝突。（T3，Founder 決定 Exo 去留）

## 10. 開發者／Agent 生產力（E）＋ UI/UX

### Agent
**現況：** 必讀約 1,730 行（35–45k tokens），風險模組再加 1,815 行 AI_REGRESSION_LESSONS。3 套記憶（MemPalace 僅本機、`.exo` 停用、GitHub issue）。沒有 handoff skill。triage label 4/5 不存在。

1. **E1a 建立 4 個 triage label**（`needs-triage`、`needs-info`、`ready-for-agent`、`ready-for-human`），對齊 `docs/agents/triage-labels.md`。（設定，T0）
2. **E1b handoff 模板**：`docs/agents/handoff.md`＋issue comment 格式。（R0）
3. **E2 選擇性 vendor skill**：tdd、grill-with-docs、improve-codebase-architecture、handoff 四個，固定 commit、授權檢查、一個 PR。（T1，依 §1 原則）
4. **E3 `docs/agents/FIRST_READ.md`（~80 行）**，根檔改為指向它的 adapter。（動到 AGENTS/CLAUDE → T3）

### UI/UX — Anti-AI-slop 規則（frontend-design）
**量測：** 141 個 SFC、93.6k 行；886 原生 `<button>` vs 300 `<AtButton>`；149 種字級（只有 57/2,769 用 token）、60 種圓角、205 種陰影、593 種顏色字面值；124 個漸層；94 個 emoji 當圖示、無 icon 系統；323 個 `alert()/confirm()`。五大畫面評分：主任儀表板 B、帳務中心 B−、出缺勤 C+、行事曆 C、課程管理 D（390px 標題按鈕一字一行、表格被截斷）。

**規則（2–6 做成只降不升的 ratchet，比照 hex guard）：**
1. 每個區域只有一個實心主要按鈕；不用 `--ds-brand-gradient`，品牌色用平色 `--ds-accent`。
2. 字級只用 7 個 `--ds-font-size-*`。
3. 圓角只用 `--ds-radius-sm/md/lg`；pill 只給狀態 badge。
4. 陰影只用 `--ds-shadow-1/2`；區塊用分隔線，不做卡片套卡片。
5. 圖示只用 `AtIcon`（單一 SVG 套件）；模板不放 emoji；純圖示按鈕必有 `aria-label`。
6. 禁止原生 `alert`／`confirm`，改 AtDialog／AtInlineAlert／toast。
7. 載入／空／錯誤只用 AtSkeleton／AtEmpty／AtInlineAlert：一句話＋一個動作。
8. 資料清單用真表格；<768px 改成「標籤：值」堆疊列，不擠欄。
9. 文案只寫事實與動作，提示 ≤20 字；不要眉標、不要描述介面本身、不要「點此」、不要安撫性長句；長說明收進 `AtHelpDisclosure`。
10. 每頁同一骨架：AtPageHeader → AtToolbar/AtFilterBar → 內容，固定 page gutter；區段標題用中性墨色，不用橘色。

**PR 順序（每個 <700 行、不改邏輯、附 390／1440 截圖）：**
U1 style ratchet guard（字級／圓角／陰影／emoji／alert 基線，增加即 fail）＋`AtIcon`（~15 個 SVG）→ U2 全域按鈕去漸層 → U3 課程管理標題列／工具列 mobile → U4 課程管理去漸層與 hex（可拆 2 個）→ U5 課程管理 alert/confirm → U6 出缺勤表格 responsive → U7 帳務中心表格化（等 #3896 落地後）→ U8 行事曆外框（不碰 `calendarOccurrenceMerge`，`npm run test:calendar` 必綠）→ U9 主任儀表板 → U10 五頁文案（依 `GUIDE_UI_COPY.md`）。

---

## 11. 排程與 WIP

同時最多 3 條產品工作線（#3115 原則）。進行中 PR 收尾與 in-app 回報**優先於**本計畫。

| 波次 | 期間 | 可由 Agent 自行推進（T0–T2／常設決定①） | 需 Founder GO |
|---|---|---|---|
| W1 | 10/13–10/20 | E1a label、E1b handoff、U1 ratchet＋AtIcon、S1b 跨分校測試、P1 旅程套件（彙整）、O3 告警目錄、R3b 事故模板 | D1 migration 中止、S3 歷史清除、G3 Exo 去留 |
| W2 | 10/21–11/06 | U2–U5、A2a ContractPrice、A2d ParentDashboardQuery、I1a 釘 Laravel tag、O1 DORA、S1a 路由測試 | D2 receipt、O2 deploy 注入、R3a 異地還原 |
| W3 | 11/07–12/06 | U6–U10、A2b／A2c、A2e、E2 skill vendor、R1 SLI 寫回 | G1／G2、I2 staging smoke、E3 FIRST_READ |
| W4 | 12/07–01/05 | 成效比對（前後數字）、無效措施撤回 | 其他專案移植清單 |

## 12. 成效量測（只比較同口徑）

| 指標 | 2026-10-13 基準 | 90 天目標 |
|---|---|---|
| 真部署頻率／lead time | DORA 失真（待 O1） | O1 後取 14 天基準，再定 25% 改善 |
| F7＋F4＋F8 新回報／週 | 以 AI_REGRESSION_LESSONS 週數為準 | 減半 |
| `ContractMoneyVerdict` 呼叫端 | 1 | 帳務列表／催繳／家長端全部 |
| `RemainingSessions` 直接寫入點 | 11 | 1（SessionBalance） |
| 原生 `<button>`／`alert()` | 886／323 | 五大畫面 0 |
| 字級／陰影種類 | 149／205 | ratchet 只降；五大畫面只用 token |
| SLI 已量測 | 0/5 | 2/5（SLI-01、SLI-05） |
| Sentry 事件可對到 SHA | 否 | 是 |
| 必讀 tokens | ~40k | ≤15k（E3 後） |

每兩週在 #3115 更新一次；無效的措施撤回，不累積。

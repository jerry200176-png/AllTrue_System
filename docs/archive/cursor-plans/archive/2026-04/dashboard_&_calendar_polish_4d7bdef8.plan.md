---
name: Dashboard & Calendar Polish
overview: PRD：精緻化主任儀表板「近 7 天代課記錄」卡片，並修復智慧排課平板視圖在「只顯示今日有課老師」模式下教師欄位溢出格子的版型問題。
todos:
  - id: backend-api
    content: 後端 API / 資料：本次不適用，原因：兩項改動均為純前端 UI 調整，不異動任何 API 或資料表。
    status: completed
  - id: frontend-feature-rsc
    content: "[FEATURE] 前端 UI（功能）— RecentSubstitutesCard：日期相對格式化、展開 reason 點擊切換、摘要計數列"
    status: completed
  - id: uiux-rsc
    content: "[FEATURE] UI/UX 精緻化 — RecentSubstitutesCard：依第 5b 節規格精緻化（日期層次、箭頭 icon、老師名色彩、含換時 amber chip、空狀態 icon、3 行 skeleton）"
    status: completed
  - id: frontend-feature-calendar
    content: "[FEATURE] 前端 UI（功能）— SmartCalendar：修復 ≤1100px / ≤900px 斷點 .teacher-grid min-width 導致欄位溢出的 CSS"
    status: completed
  - id: uiux-calendar
    content: "[FEATURE] UI/UX 精緻化 — SmartCalendar：≤900px 斷點 teacher-col-header overflow: hidden，確保固定高度內無視覺溢出"
    status: completed
  - id: test
    content: "[TEST] 測試設計：設計 QA 手動測試案例（含 UI/UX 驗收清單），覆蓋 FR-001～FR-006 的 Happy Path / Edge / Error"
    status: completed
  - id: qa
    content: QA 驗收：執行 PRD 第 10 節所有 FR 驗收測試，含 UI/UX 驗收清單
    status: completed
  - id: security
    content: 資安確認：確認存取控制與 STRIDE 無阻擋風險（預期：無新增存取控制需求）
    status: completed
  - id: review
    content: "[REVIEW] Code Review：對前端變動（RecentSubstitutesCard.vue、SmartCalendar.vue）執行程式碼審查"
    status: completed
  - id: docs
    content: "[DOCS] 文件更新：更新 docs/CHANGELOG.md，記錄本次 UI 精緻化與 tablet 修復"
    status: completed
  - id: deploy
    content: 部署與上線：cd frontend && npm run deploy，確認 index.html + assets chunk 同步、API health 正常
    status: completed
  - id: uiux-signoff
    content: UI/UX sign-off：UI/UX Designer 確認第 5b 節所有精緻化項目已實作並符合規格
    status: completed
  - id: pm-signoff
    content: PM sign-off：PM 確認 DoD 全部打勾
    status: completed
isProject: false
---

# PRD — 主任儀表板代課紀錄精緻化 & 平板行事曆版型修復

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 近 7 天代課記錄卡片精緻化 + 智慧排課平板欄位溢出修復 |
| 版本 / 日期 | v1.0 / 2026-04-18 |
| 狀態 | Draft |
| 目標角色 | 主任（使用儀表板代課卡片）、任課老師（使用智慧排課平板視圖） |

---

## 2. 目標與業務背景

**痛點：**
- 近 7 天代課記錄卡片的日期顯示為原始 `YYYY-MM-DD HH:mm~HH:mm` 格式，資訊層次不清，主任難以快速掃視「今天/昨天有哪些代課」。代課流程（原老師 → 代課老師）以純文字箭頭呈現，缺乏色彩區分，易讀性差。「含換時」badge 與一般 chip 外觀相同，主任無法快速辨識複合操作。代課原因只能透過滑鼠 hover tooltip 查看，在觸控裝置（平板、手機）完全失效。
- 智慧排課在平板裝置（iPad 橫式 / 直式，搭配側欄後內容寬度約 520–650 px）開啟「只顯示今日有課老師」後，老師欄位標頭文字溢出格子邊界，造成排版破壞，影響使用排課的主任與老師。

**業務價值：**
- 代課卡片精緻化後，主任可在 3 秒內完成一次代課記錄掃視，減少認知負擔。
- 平板版型修復後，老師與主任在教室使用平板管理排課時不再遭遇排版破壞，減少因操作困惑產生的諮詢量。

**成功指標（KPI）：**
- 代課卡片：使用者研究中「視覺滿意度」題項達 4 分（5 分制）以上 `[TODO: 需確認是否有使用者研究計畫]`
- 平板排版：在 iPad 9.7" 直式（含側欄）上，5 位以上老師時無橫向 overflow、無欄位內文字溢出格子

---

## 3. 範圍

**In Scope：**
- `RecentSubstitutesCard.vue`：日期相對格式化、時間段格式、代課流向色彩差異化、"含換時" chip 換色、代課原因可展開、空狀態圖示化、3 行 skeleton、筆數摘要列
- `SmartCalendar.vue`：修復 `≤1100px` / `≤900px` 斷點下 `.teacher-grid` 缺少 `min-width: max-content` 導致欄位壓縮溢出，並補 `.teacher-col-header overflow: hidden`

**Out of Scope：**
- 不異動後端 API 或資料表
- 不新增或修改代課相關業務邏輯（代課送出、通知等）
- 不調整 SmartCalendar 的月曆、週覽以外模式的排版
- 不修改除 `RecentSubstitutesCard.vue` / `SmartCalendar.vue` 以外的元件

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | 需求方 | A |
| CTO / 工程 | [FEATURE] Agent | R |
| UI/UX Designer | [FEATURE] Agent（本次兼任） | R |
| QA | [TEST] + QA Agent | R |
| 資安 | [REVIEW] Agent | C |
| IT / Ops | 執行 npm run deploy | I |

> UI/UX Designer 職責：把關色彩 token 一致性、日期層次排版、空狀態視覺設計、skeleton 結構對齊、平板斷點驗收。PRD 第 5b 節與第 10 節 UI/UX 驗收清單皆需 sign-off。

---

## 5. User Stories

**US-01（代課卡片 — 主任）**
> **As a** 主任，**I want** 近 7 天代課記錄以「今天 / 昨天 / M/D（週X）」格式顯示日期，**so that** 我可以不需心算就快速辨識代課發生的時間。
>
> Acceptance Criteria：
> - [ ] 當天的代課記錄日期顯示「今天」
> - [ ] 昨天的代課記錄日期顯示「昨天」
> - [ ] 2 天以前顯示「M/D（週X）」（e.g., 4/16（週四））
> - [ ] 時間段格式為 `HH:mm ～ HH:mm`（全形波浪號，兩側有空白）

**US-02（代課卡片 — 主任）**
> **As a** 主任，**I want** 代課流向（原老師 → 代課老師）用色彩區分，**so that** 我能一眼看出「誰取代了誰」。
>
> Acceptance Criteria：
> - [ ] 原老師名稱以灰色（`#6b7280`）顯示
> - [ ] 代課老師名稱以主色（`var(--primary, #2563eb)`）且粗體顯示
> - [ ] 中間箭頭使用 Material Symbols `arrow_forward` 圖示，顏色為 `#6b7280`

**US-03（代課卡片 — 主任）**
> **As a** 主任，**I want** 「含換時」badge 以琥珀色顯示以區別一般代課，**so that** 我能快速識別同時調整時間的代課事件。
>
> Acceptance Criteria：
> - [ ] `operation_type === 'substitute_with_reschedule'` 時，chip 背景為 `#fef3c7`、文字色為 `#92400e`
> - [ ] 一般代課（無換時）不顯示此 chip

**US-04（代課卡片 — 主任）**
> **As a** 主任，**I want** 代課原因可在觸控裝置上展開查看完整內容，**so that** 我使用平板時不需依賴 hover tooltip。
>
> Acceptance Criteria：
> - [ ] 代課原因預設顯示單行截斷（`text-overflow: ellipsis`）
> - [ ] 點擊原因區塊後展開顯示完整文字
> - [ ] 再次點擊後收合

**US-05（代課卡片 — 主任）**
> **As a** 主任，**I want** 空狀態時看到一個有意義的圖示與說明，**so that** 我確知系統正常，而非誤認為資料載入失敗。
>
> Acceptance Criteria：
> - [ ] 空狀態顯示 Material Symbols 圖示（`event_available`）+ 標題「近 7 天無代課記錄」+ 副文字
> - [ ] 不使用 emoji

**US-06（SmartCalendar — 主任 / 老師）**
> **As a** 主任，**I want** 在平板裝置開啟「只顯示今日有課老師」時版型不破版，**so that** 我可以正常使用排課功能。
>
> Acceptance Criteria：
> - [ ] 在 iPad 9.7" 直式（視窗寬度約 768px，含側欄後內容寬度約 580px）顯示 5 位以上老師時，老師欄標頭文字不溢出格子邊界
> - [ ] 水平捲動正常運作（`.teacher-grid-wrapper overflow-x: auto`）
> - [ ] 欄位標頭內的名稱以 `text-overflow: ellipsis` 截斷，不造成視覺外溢

---

## 5b. UI/UX 精緻化需求

### RecentSubstitutesCard（`frontend/src/components/substitute/RecentSubstitutesCard.vue`）

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 每筆代課記錄分三層：(1) 日期時間（次要色，小字）、(2) 代課流向（主視覺，最大字、有色彩）、(3) 代課原因（最小字、可折疊）。卡片間距維持 `gap: 6px`，行內 gap 調整為 `8px` |
| **色彩一致性** | 原老師灰色 `#6b7280`；代課老師主色 `var(--primary)`；含換時 chip 沿用既有「跨分校」badge 的琥珀色系（`#fef3c7` / `#92400e`）；箭頭 icon 灰色 `#6b7280` |
| **互動回饋** | 代課原因區塊 hover 背景微亮（`#f9fafb`）、cursor pointer；展開 / 收合無需動畫（簡單 toggle，避免增加感知負擔） |
| **空狀態設計** | Material Symbols `event_available` 圖示（font-size 36px，色 `#9ca3af`）+ 標題「近 7 天無代課記錄」（font-weight 600，色 `#111827`）+ 副文字「老師出勤穩定，辛苦您！」（font-size 12px，色 `#6b7280`）。禁止使用 emoji |
| **載入狀態** | Skeleton 結構調整為 3 行（對應：meta 行 60% 寬、flow 行 80% 寬、reason 行 40% 寬），避免展開後 layout shift |
| **防呆設計** | 無表單；原因文字展開時若超過 4 行，僅截斷顯示不彈出 modal，降低操作門檻 |
| **響應式** | 本元件置於 DirectorDashboard 工作欄，寬度由父容器決定；需確認在 320px 最小寬度下代課流向不換行導致 layout 破版（箭頭兩側各為一個 span，`flex-wrap: nowrap` 並於最窄時讓老師名稱自行截斷） |

### SmartCalendar — 平板日視圖（`frontend/src/pages/SmartCalendar.vue`）

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 平板（≤900px）下每個老師欄的標頭高度固定 56px，文字層次維持「頭像 + 姓名 + 教室」不變 |
| **色彩一致性** | 不新增色彩；沿用現有 design token |
| **互動回饋** | 修復後捲動行為不變，仍為水平捲動（`overflow-x: auto` on wrapper）|
| **空狀態設計** | 不適用（此修復不涉及空狀態） |
| **載入狀態** | 不適用 |
| **防呆設計** | 不適用 |
| **響應式 / 行動裝置** | 在 iPad 9.7" 直式（768px viewport，側欄展開後內容寬 ≈ 580px）、5 位以上老師時：`teacher-grid-wrapper` 水平捲動正常，欄位不壓縮至 minWidth 以下，`teacher-col-header` 以 `overflow: hidden` 確保固定高度內無溢出 |

---

## 6. 功能需求（FR）

- **FR-001**：系統應將代課記錄日期以「今天 / 昨天 / M/D（週X）」格式顯示（對應 US-01）
- **FR-002**：系統應以灰色顯示原老師姓名、以主色粗體顯示代課老師姓名，並以 `arrow_forward` icon 分隔（對應 US-02）
- **FR-003**：`operation_type === 'substitute_with_reschedule'` 時，「含換時」chip 背景應為琥珀色系（對應 US-03）
- **FR-004**：代課原因文字可點擊展開 / 收合，展開後顯示完整文字（對應 US-04）
- **FR-005**：空狀態時應顯示 Material Symbols 圖示 + 標題 + 副文字，禁止使用 emoji（對應 US-05）
- **FR-006**：在 ≤900px 視窗寬度下，`SmartCalendar` 日視圖的老師欄格線不得壓縮至 minColWidth 以下，超出部分改為水平捲動（對應 US-06）

---

## 7. 非功能需求（NFR）

- **效能**：兩項均為純 CSS / script 層修改，不新增 API 請求，不影響頁面首次載入時間
- **降級策略**：若日期格式化邏輯拋出例外，fallback 顯示原始 `session_date` 字串（不白畫面）
- **瀏覽器支援**：需在 Safari 15+（iPad）、Chrome 108+（PC / Android）正常顯示；`min-width: max-content` 與 CSS Grid 均已在目標瀏覽器廣泛支援

---

## 8. 技術方向（給 CTO）

**受影響頁面 / 元件：**
- `frontend/src/components/substitute/RecentSubstitutesCard.vue`（代課記錄卡片）
- `frontend/src/pages/SmartCalendar.vue`（智慧排課）

**受影響 API：** 無

**受影響資料表：** 無

**架構選擇取捨：**
- 日期相對格式化在元件內以 computed 輔助計算（不引入額外日期函式庫），理由：僅需「今天 / 昨天 / M/D 週X」三種格式，複雜度不足以引入 dayjs/date-fns 額外依賴
- SmartCalendar 修復選擇 CSS-only，不動 JS 計算邏輯，理由：根因是 `min-width: max-content` 未在 ≤1100px / ≤900px 斷點宣告，純 CSS 兩行可解決，風險最低
- 不需要 migration

**子任務 Agent 派發：**
- `[FEATURE]` → 前端 Vue 實作（RecentSubstitutesCard + SmartCalendar CSS）
- `[TEST]` → 設計 QA 手動測試案例
- `[REVIEW]` → 程式碼審查（CSS 正確性、跨瀏覽器相容）
- `[DOCS]` → 更新 `docs/CHANGELOG.md`

---

## 9. 資安與存取控制

- **存取控制**：兩項修改均為純 UI 顯示改進，不新增 API 路由，不異動 `middleware role:*` / `require_campus` 設定；現有主任角色限制不受影響
- **PII / 敏感資料**：代課記錄已在現有 API 中依 `branch_id` 過濾，本次修改不改變資料查詢邏輯，不擴大 PII 暴露範圍
- **稽核 log**：無新增需稽核的操作（代課原因展開為純前端 DOM toggle）
- **STRIDE 快評**：
  - Spoofing：無新增認證入口，無風險
  - Tampering：純前端顯示修改，無資料寫入，無風險
  - Information Disclosure：不新增資料欄位或 API，無風險
  - 其他：無

---

## 10. QA 驗收標準與測試計畫

### FR-001 日期格式化

| 路徑 | 測試條件 | 預期結果 |
|---|---|---|
| Happy Path | `session_date` = 今天 | 顯示「今天」 |
| Happy Path | `session_date` = 昨天 | 顯示「昨天」 |
| Happy Path | `session_date` = 2 天前 | 顯示「M/D（週X）」，星期正確 |
| Edge Case | `session_date` = 空字串或 null | fallback 顯示原始值，不白畫面 |

### FR-002 代課流向色彩

| 路徑 | 測試條件 | 預期結果 |
|---|---|---|
| Happy Path | 有原老師與代課老師 | 原老師灰色、代課老師主色粗體、箭頭 icon 正常顯示 |
| Edge Case | 原老師名稱為 `—`（空） | 灰色「—」顯示，不報錯 |

### FR-003 含換時 chip

| 路徑 | 測試條件 | 預期結果 |
|---|---|---|
| Happy Path | `operation_type = substitute_with_reschedule` | 顯示琥珀色 chip |
| Edge Case | `operation_type` 為其他值 | 不顯示 chip |

### FR-004 原因展開

| 路徑 | 測試條件 | 預期結果 |
|---|---|---|
| Happy Path | 有 reason 文字、點擊 | 文字展開顯示完整內容 |
| Happy Path | 展開後再次點擊 | 收合回截斷狀態 |
| Edge Case | reason 為空 | 不顯示原因區塊 |

### FR-005 空狀態

| 路徑 | 測試條件 | 預期結果 |
|---|---|---|
| Happy Path | items 為空陣列 | 顯示 `event_available` icon + 標題 + 副文字，無 emoji |

### FR-006 SmartCalendar 平板排版

| 路徑 | 測試條件 | 預期結果 |
|---|---|---|
| Happy Path | iPad 9.7" 直式，5 位老師，開啟「只顯示今日有課老師」 | 欄位標頭不溢出，水平捲動正常 |
| Happy Path | iPad 9.7" 直式，10 位老師 | 同上，extra-compact 模式正常 |
| Edge Case | 桌機（≥1200px），10 位老師 | 不受影響，行為與修復前相同 |
| Edge Case | 手機（≤480px） | ≤768px 規則仍優先，行為不變 |
| 回歸 | 本次修改不得影響週覽（week overview）排版 | `.day-col` / `.week-overview-grid` 正常 |

**UI/UX 驗收清單：**
- [ ] 空狀態有 Material Symbols 圖示 + 說明文字，非空白或純文字
- [ ] Skeleton 結構為 3 行，與真實卡片結構對應，無 layout shift
- [ ] 代課流向色彩符合第 5b 節規格（灰 / 主色 / 箭頭 icon）
- [ ] 含換時 chip 使用琥珀色，與「跨分校」badge 同調色系
- [ ] 代課原因展開 / 收合觸控目標區域 ≥ 44px 高度
- [ ] 平板（≤900px）SmartCalendar 日視圖無水平 overflow 在標頭區
- [ ] 色彩 / 間距 / 字型層次符合既有 design token，無視覺突兀點

---

## 11. 上線與維運

**部署步驟：**
1. 修改 `frontend/src/components/substitute/RecentSubstitutesCard.vue`
2. 修改 `frontend/src/pages/SmartCalendar.vue`
3. 執行 `cd frontend && npm run deploy`（同步 `index.html` + `assets/` chunk，避免 MIME 錯誤）
4. 確認 `backend/public/index.html` 時間戳更新

**監控：** 無新增監控項目（純 UI 修改）

**回滾方案：** 若上線後發現視覺問題，`git revert` 相關 commit，重新執行 `npm run deploy` 即可回滾

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| P0（Must Have） | SmartCalendar 平板欄位溢出修復（FR-006） | 0.5 hr | [FEATURE] |
| P0（Must Have） | RecentSubstitutesCard 日期格式化（FR-001） | 0.5 hr | [FEATURE] |
| P1（Should Have） | 代課流向色彩差異化（FR-002）+ 含換時 chip amber（FR-003） | 0.5 hr | [FEATURE] |
| P1（Should Have） | 代課原因可展開（FR-004）+ 空狀態去 emoji（FR-005） | 0.5 hr | [FEATURE] |
| P2（Nice to Have） | 3 行 skeleton + 摘要計數列 | 0.5 hr | [FEATURE] |

---

## 13. 風險、假設、開放問題

**風險：**
- 低：`min-width: max-content` 在 Safari 15 以下可能不被支援 → 緩解：Safari 15 已是 2021 年版本，補習班現有 iPad 應已更新至 iOS 15+；若發現問題可改用 `width: max-content` 作為 fallback
- 低：代課記錄的 `session_date` 格式若非 `YYYY-MM-DD` 會導致日期計算錯誤 → 緩解：加 fallback 顯示原始字串

**假設：**
- 假設代課 API 回傳的 `session_date` 欄位格式固定為 `YYYY-MM-DD`
- 假設 `items` 陣列中每筆都有 `id` 欄位可作為展開狀態的 key `[TODO: 需確認 substituteApi.js 回傳結構]`
- 假設此計畫不需要新的後端欄位

**開放問題：**
- `[TODO: 需確認]` 補習班是否有使用者研究計畫，以量化代課卡片滿意度 KPI

---

## 14. Definition of Done

- [ ] FR-001 ～ FR-006 全部通過 QA 驗收（第 10 節）
- [ ] **UI/UX 驗收清單（第 10 節）全部打勾，UI/UX Designer sign-off**
- [ ] 資安審查無阻擋項
- [ ] `npm run deploy` 執行成功，`index.html` + `assets/` chunk 同步（防 MIME 錯誤）
- [ ] `docs/CHANGELOG.md` 更新，記載本次改動
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off

---
name: 統一排課介面步驟化重設計
overview: 將 UniversalClassScheduler.vue 的頂端 Tab 切換改為步驟式精靈（Step 1 選課程類型 → Step 2 填寫資料），合併一般課程與多科共用方案的進入流程，降低選錯模式的認知負擔，同時保留兩個模式原有的所有功能差異。
todos:
  - id: fe-step-state
    content: "[FEATURE] UniversalClassScheduler.vue — script: 新增 step ref（初値 1）、selectType(type) 函式、goBack() 函式、confirmBackOpen ref；watch modelValue 時加入 step = 1 重置"
    status: completed
  - id: fe-step1-template
    content: "[FEATURE] UniversalClassScheduler.vue — template: 移除 .mode-tabs；step=1 時顯示 .usw-step1-cards（两張類型選擇卡）"
    status: completed
  - id: fe-step2-wrapper
    content: "[FEATURE] UniversalClassScheduler.vue — template: 在現有 packageMode 兩個區塊外包 v-if=\"step===2\"；modal header 下方加 .usw-stepper + 返回連結"
    status: completed
  - id: fe-back-dialog
    content: "[FEATURE] UniversalClassScheduler.vue — 返回確認 dialog（confirm 或小型 modal），確認後呼叫 resetForm / resetPkgForm 並回 step 1"
    status: completed
  - id: fe-css
    content: "[FEATURE] UniversalClassScheduler.vue — 新增 .usw-step1-cards、.usw-stepper 樣式；Step 1 選擇卡 hover/click 動畫；Step 1→Step 2 過場 transition"
    status: completed
  - id: qa-regression
    content: QA 手動驗收：四種送出路徑回歸（一般課程堆數制、月結制，多科方案堆數制、月結制）+ UI/UX 驗收清單
    status: completed
  - id: deploy
    content: 部署：npm run deploy + smoke test 確認四種建窳成功 + CHANGELOG.md 更新
    status: completed
isProject: false
---

# PRD：統一排課介面 — 步驟式精靈重設計

## 1. 文件資訊

- 功能名稱：UniversalClassScheduler 步驟式精靈重設計（Unified Scheduler Wizard）
- 版本 / 日期：v1.0 / 2026-04-18
- 目標角色：主任（主要操作者）
- 相關計畫背景：整合自 `多科共用方案月結制_251591de`、`多科方案月結制_template_補齊_434719a2`

---

## 2. 目標與業務背景

### 現有痛點

[`frontend/src/components/UniversalClassScheduler.vue`](frontend/src/components/UniversalClassScheduler.vue) 頂端有兩個 Tab：**一般課程** / **多科共用方案**。

兩個 Tab 的操作語意、欄位結構與計費邏輯差異很大：

| 面向 | 一般課程 | 多科共用方案 |
|---|---|---|
| 計費切換 | `payment_type: session/monthly`（下拉） | `payment_type: session/monthly`（pill 切換） |
| 科目 | 單一科目 | 多科目清單（各有老師/時長/開始日） |
| 排課方式 | 固定星期自動補齊 + 手動選日 | 月曆補登已上課日期 |
| 摘要 | — | 方案摘要卡 |

主任開啟 modal 後必須先判斷自己要用哪個 Tab，再切過去，容易誤操作；且 Tab UI 暗示這是同一流程的兩個平行選項，但實際操作邏輯差異很大，沒有明確引導。

### 業務價值

- 減少主任選錯模式的概率（目前估計約 10–15% 的操作需要切換 Tab）
- Step 1 的類型說明卡讓主任在開始前就知道「多科方案適合什麼情境」，降低諮詢成本
- 步驟式精靈讓長流程（多科方案有 4~5 個填寫區塊）更有節奏感，降低遺漏必填欄位的機率

### KPI

- 主任選錯模式需切換 Tab 的比率降至 0%（Tab 完全移除）
- 新流程送出成功率與現行持平（≥ 98%）
- modal 首頁（Step 1）至送出完成，主任操作時間 ≤ 現行（不因多一步驟而增加）

---

## 3. 範圍

### In Scope

- 移除 `mode-tabs` 切換 UI，改為 Step 1 類型選擇卡
- Step 1：兩張大卡片（一般課程 / 多科共用方案），各有描述文字與適用情境說明
- Step 2 起：現有兩個模式的表單與邏輯完整保留，僅調整佈局加上步驟麵包屑（Stepper）與「返回」按鈕
- 步驟狀態：`step: 1 | 2`；Step 2 依選擇的類型決定顯示哪套表單
- 返回 Step 1 時重置整個表單狀態（已填內容清空，需二次確認 dialog）
- 一般課程類型卡說明文字：「單一科目、固定排課時間，或一次買斷 N 堂」
- 多科方案類型卡說明文字：「多個科目共用同一份堂數或月結計費，學生可跨科目自由補課」

### Out of Scope

- 一般課程與多科方案的欄位邏輯本身不改動（已由前兩份計畫完成）
- 不新增 API endpoint
- 不改動呼叫端（CourseManagement.vue、SmartCalendar.vue、StudentsList.vue）的 props 介面

---

## 4. User Stories

> **As a** 主任，**I want** 開啟排課 modal 時先看到清楚的類型選擇畫面，**so that** 我不需要先判斷 Tab 再切換，可以直接選「這次我要建什麼」。
>
> Acceptance Criteria：
> - 開啟 modal 預設顯示 Step 1（類型選擇），不自動跳入任何表單
> - Step 1 顯示兩張選擇卡，各含標題、副標題（適用情境說明）、圖示
> - 點擊任一選擇卡後立即進入 Step 2，不需額外確認

> **As a** 主任，**I want** 填到一半發現選錯類型時，可以返回選擇重選，**so that** 不用關閉 modal 重開。
>
> Acceptance Criteria：
> - Step 2 頂部有步驟指示器（Step 1 → Step 2）與「← 返回」連結
> - 點擊返回時彈出確認 dialog：「返回將清空已填入的資料，確定繼續？」
> - 確認後回到 Step 1，表單狀態完全重置

> **As a** 主任，**I want** Step 2 的表單體驗與現行一致，**so that** 我已熟悉的操作方式不需要重新學習。
>
> Acceptance Criteria：
> - 兩種課程類型的 Step 2 表單欄位、邏輯與現行完全相同
> - 僅在頂部多出步驟指示器和返回連結，其餘 UI 不異動

---

## 5b. UI/UX 精緻化需求

### Step 1 — 類型選擇卡

| 面向 | 要求描述 |
|---|---|
| 版面層次 | 兩張選擇卡水平並排（桌面）；卡片寬度各 50%，height: auto，padding 24px；modal header 保留標題「新增課程」，移除 mode-tabs |
| 色彩一致性 | 一般課程卡圖示用現有 `--primary` blue；多科方案卡用現有 `--accent` green；hover 狀態卡片外框加 2px solid 對應色；選中（點擊後進入 Step 2）無需停留選中態，直接跳頁 |
| 卡片內容 | 上方圖示（material-symbols-outlined：`menu_book` for 一般課程；`layers` for 多科方案）；標題 16px 600；副標題（適用情境說明）13px muted 色；底部小字標籤（例：堂數制 / 月結制 均支援） |
| 互動回饋 | hover 有 `shadow-hover` token 升起效果（與老師卡片一致）；點擊後卡片短暫 scale(0.97) → 跳入 Step 2 |
| 響應式 | ≤ 768px 時兩卡改為垂直堆疊（各佔全寬）；觸控目標整張卡片 |

### Step 2 — 步驟指示器與返回

| 面向 | 要求描述 |
|---|---|
| 步驟指示器 | modal header 下方加一條 stepper bar（Step 1 ✓ → Step 2 ●）；Step 1 完成後顯示勾選綠色，Step 2 為目前步驟藍色 active |
| 返回連結 | 指示器左側放「← 返回」文字按鈕（ghost 樣式），僅在 Step 2 顯示 |
| 返回確認 dialog | 標準 confirm dialog，標題「確認返回」，說明「返回將清空已填入的資料，確定返回類型選擇？」，按鈕「取消」（維持 Step 2）/ 「確認返回」（清空並回 Step 1） |

---

## 6. 功能需求（FR）

- **FR-001**：移除 `mode-tabs` 區塊（template 中 class 為 `.mode-tabs` 的兩個按鈕），以 `step` state 控制顯示。
- **FR-002**：`step = 1` 時顯示類型選擇卡；`step = 2` 時顯示現有表單（依 `packageMode` 決定顯示哪套）。
- **FR-003**：點擊類型選擇卡後，設定對應的 `packageMode` 並立即切換到 `step = 2`。
- **FR-004**：`step = 2` 時，modal header 下方顯示步驟指示器與「返回」連結。
- **FR-005**：點擊「返回」觸發確認 dialog；確認後呼叫現有 `resetForm()` / `resetPkgForm()` 並將 `step` 設回 `1`、`packageMode` 重置。
- **FR-006**：開啟 modal（`modelValue` 為 `true`）時永遠從 `step = 1` 開始，不記憶上次選擇。
- **FR-007**：現有兩個模式的所有欄位、驗證、送出邏輯完全不動（零回歸風險）。

---

## 7. 非功能需求（NFR）

- 不新增任何 API 呼叫；Step 1 為純前端 state 切換，無網路延遲
- Step 1 → Step 2 的視覺切換動畫 ≤ 200ms（slide-right 或 fade，使用 Vue `<transition>`）
- CSS 只新增 Step 1 所需樣式（`.usw-step1-cards`、`.usw-stepper`），不修改現有 CSS 類名

---

## 8. 技術方向

### 受影響範圍

只改 [`frontend/src/components/UniversalClassScheduler.vue`](frontend/src/components/UniversalClassScheduler.vue)，共三處：

1. **Template — header 區塊**：移除 `.mode-tabs`，加入 `.usw-stepper`（step 2 時才顯示）
2. **Template — modal body**：在現有 `v-if="packageMode"` 和 `v-else` 的外層包一層 `v-if="step === 2"`；step 1 時顯示 `.usw-step1-cards`
3. **Script setup**：新增 `step` ref（初值 1）、`selectType(type)` 函式、`goBack()` 函式、`confirmBackDialog` ref；watch `modelValue` 時重置 `step = 1`

### 架構選擇理由

- 選「在現有表單外層加 step wrapper」而非「重寫表單」：零功能回歸風險，兩個模式的 200+ 行表單邏輯完全不動
- 選「step = 1/2 兩步驟」而非「step = 1/2/3 三步驟」：多科方案本身已有方案資料 + 科目清單 + 月曆三個區塊，再切步驟反而讓流程更長，保持現有 Step 2 一頁式佈局最合理
- 返回確認 dialog 使用瀏覽器原生 `confirm()` 或既有的 dialog 元件（沿用專案現有做法）

### 子任務 Agent 派發

- `[FEATURE]` → 前端 UniversalClassScheduler.vue 三處改動
- `[TEST]` → QA 手動驗收（無新 API，無需 Pest 新測試）
- `[DOCS]` → CHANGELOG.md 新增條目

---

## 9. 資安與存取控制

無新 API、無新角色、無資料存取變更。現有 `role:director + require_campus` middleware 保護不受影響。

---

## 10. QA 驗收標準

### FR-001/002/003（Step 1 類型選擇）

| 案例 | 預期 |
|---|---|
| Happy Path：開啟 modal → 看到 Step 1 類型選擇卡 | Step 1 顯示，無表單欄位可見 |
| Happy Path：點「一般課程」→ 進入 Step 2 | `packageMode = false`，顯示一般課程表單，步驟指示器 Step 2 active |
| Happy Path：點「多科共用方案」→ 進入 Step 2 | `packageMode = true`，顯示多科方案表單，步驟指示器 Step 2 active |
| Edge：關閉 modal 重新開啟 | 回到 Step 1，不保留上次選擇 |

### FR-005（返回與確認）

| 案例 | 預期 |
|---|---|
| Happy Path：Step 2 點「返回」→ 確認 → 回 Step 1 | 表單清空，Step 1 顯示 |
| Edge：Step 2 點「返回」→ 取消 | 維持 Step 2，已填資料不遺失 |
| Edge：已填部分欄位，點返回 → 確認 | 所有欄位重置為初始值 |

### FR-007（回歸測試）

| 案例 | 預期 |
|---|---|
| 一般課程 — 堂數制完整送出 | 與現行行為完全相同 |
| 一般課程 — 月結制完整送出 | 與現行行為完全相同 |
| 多科方案 — 堂數制建立（2 科以上）| 與現行行為完全相同 |
| 多科方案 — 月結制建立 | 與現行行為完全相同 |
| 多科方案 — 月結制科目 < 2 時送出按鈕 disabled | 與現行行為完全相同 |

### UI/UX 驗收清單

- Step 1 類型選擇卡在桌面水平並排，≤ 768px 垂直堆疊，無水平 overflow
- 選擇卡 hover 有升起效果，點擊有短暫縮放動畫
- Step 1 → Step 2 有平滑過場動畫（≤ 200ms）
- 步驟指示器 Step 1 完成後顯示勾選；Step 2 為 active
- 返回確認 dialog 措辭明確，「確認返回」為 destructive 樣式（紅色或次要色）
- 整體 modal 高度與現行一致（Step 1 的卡片高度合理，不撐大 modal）

---

## 11. 部署步驟

1. 前端：修改 `UniversalClassScheduler.vue`
2. `cd frontend && npm run deploy`
3. 確認 `index.html` + assets hash 更新
4. Smoke test：主任帳號分別建立一般課程（堂數制）、一般課程（月結制）、多科方案（堂數制）、多科方案（月結制）各一筆，確認送出成功

---

## 12. 里程碑與優先級

- P0（Must Have）：FR-001 ~ FR-007 前端改動 — 半天
- P0（Must Have）：QA 回歸驗收（4 種送出路徑）— 半天
- P1（Should Have）：CHANGELOG.md 更新 — 0.1 天

---

## 13. 風險、假設、開放問題

### 風險

- 低風險：Step 1 → Step 2 的 `watch(modelValue)` 與現有 watch 邏輯是否衝突（例如 `initialStudentId` 自動帶入）。緩解：`selectType()` 呼叫後立即觸發現有的 studentId watch，與開啟 modal 時的初始化行為一致。

### 假設

- 主任每次開啟排課 modal 都是從 Step 1 選類型，不需要記憶上次選擇（若後續需要，可用 localStorage 記憶，列入 P2）

### 開放問題

- 若主任從學生資料頁「為此學生新增課程」觸發 modal（已知 `initialStudentId`），Step 1 是否可以跳過，直接進入 Step 2？建議保留 Step 1（幾秒鐘選擇不影響體驗），但可在 Step 1 的卡片頂部顯示「為 {學生姓名} 新增課程」的脈絡提示。Owner：PM 確認。

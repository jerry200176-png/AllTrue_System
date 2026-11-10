---
name: ""
overview: ""
todos:
  - id: dev-template
    content: 重構 TeacherHomePage.vue template（17-43行）：加 icon-wrap、雙欄 time chip、左邊框 class binding
    status: pending
  - id: dev-style
    content: 更新 TeacherHomePage.vue style：卡片二段佈局、icon-wrap 動態顏色、time chip 樣式、@media 斷點
    status: pending
  - id: dev-computed
    content: 新增 clockinCardClass computed（左邊框+icon顏色），不動其他 JS 邏輯
    status: pending
  - id: test-visual
    content: 四種狀態視覺驗收：無打卡、簽到未簽退、完整簽到退、遲到；桌機 + 手機 390px 各一輪
    status: completed
  - id: ops-deploy
    content: CI 全綠 → merge → npm run deploy → curl health check → 回報
    status: completed
isProject: false
---

# PRD：教學工作台「今日打卡狀態」卡片 UI 改版

## 1. 文件資訊

| 欄位 | 內容 |
|------|------|
| 功能名稱 | 教學工作台打卡狀態卡片行動裝置排版改版 |
| 版本 | v1.0 |
| 狀態 | PLAN（待批准） |
| 目標角色 | teacher（老師帳號） |
| 優先級 | P2 — 體驗改善，不影響核心功能 |
| 關聯檔案 | `frontend/src/pages/TeacherHomePage.vue` 第 17–43 行（template）、第 1025–1057 行（style）|

---

## 2. 目標與業務背景

### 痛點

老師使用手機（主要裝置）登入教學工作台時，「今日打卡狀態」卡片在手機寬度下呈現：
- **文字直行折行**：icon、標題、badge、時間全擠在同一個 `flex` 橫排，小螢幕折行後排版混亂
- **狀態不一眼可辨**：指紋 icon 為灰色，無法立即傳達「正常/遲到/未打卡」
- **觸控面積不足風險**：卡片高度過低，老師一手持機時容易誤觸
- **不符合老師使用情境**：老師通常在課前 30 秒快速確認打卡狀態，需要秒讀

### 業務價值

改善老師首頁最顯眼卡片的使用體驗，降低老師誤以為自己沒打卡而回報問題的頻率。

### KPI

| 指標 | 目標 |
|------|------|
| 卡片 min-height | ≥ 72px（確保觸控面積） |
| 所有互動元素觸控目標 | ≥ 44px（Apple HIG / WCAG 2.1 AAA 標準）|
| 手機 390px 寬無水平 overflow | 100% 無溢出 |
| 四種狀態色彩可立即辨識 | 視覺驗收 4/4 通過 |

---

## 3. 範圍

### In Scope

- `TeacherHomePage.vue` 打卡狀態卡片（第 17–43 行 template、第 1025–1057 行 style）
- 新增一個 `computed` 屬性 `clockinCardClass`（純 CSS class 計算，無後端請求）
- 四種狀態的視覺設計：無打卡、簽到未簽退、完整、遲到

### Out of Scope

- 後端 API（不改）
- 其他卡片（today actions、week schedule 等）
- 打卡邏輯、badge label/class（已有，只重用）
- 其他頁面

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|------|------|---------|
| AI Agent（實作） | `[FEATURE]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（審查） | `[REVIEW]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 人類（閱讀） | 使用者 | I |

---

## 4b. Dependencies

本次無外部依賴。

| 類型 | 說明 | 狀態 |
|------|------|------|
| 後端 API | `/api/v1/teacher-attendance/today` — 不改動 | 已存在 |
| CSS 設計 token | `--success`、`--warning`、`--primary` 等 — 直接沿用 | 已存在 |

---

## 5. User Stories + AC

### US-001：老師手機快速確認打卡狀態

**As a** 老師，**I want** 打開工作台首頁時一眼看出今天有沒有打卡、狀態是什麼，**so that** 不用看文字才能判斷。

#### AC-001-a：狀態色彩立即可辨
- 無打卡 → 卡片左邊框為灰色、icon 圈為灰底灰色 fingerprint
- 簽到未簽退 → 左邊框為 `--primary`（橘紅）、icon 圈為 `--primary-bg` + `--primary` icon
- 完整（正常）→ 左邊框為 `--success`（綠）、icon 圈為 `--success-bg` + `--success` icon
- 遲到 → 左邊框為 `#c62828`（紅）、icon 圈為 `#fce8e6` + `#c62828` icon

#### AC-001-b：手機 390px 寬無折行亂排
- 卡片在 390px 寬度下完整顯示，無水平 overflow，無文字被截斷
- 簽到/簽退時間各佔一個獨立區塊，並排顯示

#### AC-001-c：觸控目標合規
- 整張卡片為可點擊區域（已有），min-height ≥ 72px
- 卡片內部不含小於 44px 的次要互動元素

---

## 5b. UI/UX 精緻化需求

**元件**：`TeacherHomePage.vue` — `.th-clockin-card`

| 面向 | 規格 |
|------|------|
| **版面層次** | 兩段式：第一段（header row）= icon圈 + 標題 + badge + 箭頭；第二段（body）= 並排的「簽到」「簽退」兩個 chip；第三段（hint）= 第一堂課時間 |
| **色彩一致性** | 沿用既有 design token：`--success`/`--success-bg`、`--primary`/`--primary-bg`、`--warning`/`--warning-bg`；遲到用 `#c62828`/`#fce8e6`（已存在於 `.th-badge-late`） |
| **互動回饋** | 點擊整張卡片 → `scale(0.98)` + `background: var(--bg-hover)` 100ms transition（與現有 hover 一致）|
| **空狀態設計** | 無打卡時：icon 灰色 + 「今日尚未打卡，請記得刷卡」文字，配合整體灰色邊框 |
| **載入狀態** | 載入中時顯示 skeleton 效果（兩個 chip 位置用灰色方塊佔位），避免 layout shift |
| **響應式** | 斷點：無需 `@media`，使用 `flex-wrap: nowrap` + `min-width: 0` 確保兩個 chip 等寬並排；整張卡片 `width: 100%` |
| **無障礙** | 整張卡片已有 `role="button"` + `tabindex="0"` + `aria-label`；icon 圈加 `aria-hidden="true"`；顏色對比度：深色文字在淺色背景上均 ≥ 4.5:1 |
| **觸控目標** | 卡片 `min-height: 72px`；簽到/簽退 chip `padding: 10px 12px`，實際高度 ≥ 44px |

### 四種狀態視覺對照

```
── 無打卡 ───────────────────────────────────────
│ ⬜[灰指紋圈]  今日打卡狀態  [尚未打卡]      ›  │
│  ─────────────────────────────────────────── │
│  ┌── 簽到 ──────┐  ┌── 簽退 ──────┐          │
│  │  — （未打卡）│  │  —           │          │
│  └──────────────┘  └──────────────┘          │

── 簽到未簽退（上班中）──────────────────────────
│🟠[橘指紋圈]  今日打卡狀態  [上班中]       ›  │
│  ─────────────────────────────────────────── │
│  ┌── 簽到 ──────┐  ┌── 簽退 ──────┐          │
│  │   09:30      │  │  未簽退  ⚠   │  (橘字)  │
│  └──────────────┘  └──────────────┘          │
│  第一堂課：10:00                              │

── 完整打卡（已完成）────────────────────────────
│🟢[綠指紋圈]  今日打卡狀態  [已完成]       ›  │
│  ─────────────────────────────────────────── │
│  ┌── 簽到 ──────┐  ┌── 簽退 ──────┐          │
│  │   09:30      │  │   17:00      │          │
│  └──────────────┘  └──────────────┘          │

── 遲到 ─────────────────────────────────────────
│🔴[紅指紋圈]  今日打卡狀態  [遲到]         ›  │
│  ─────────────────────────────────────────── │
│  ┌── 簽到 ──────┐  ┌── 簽退 ──────┐          │
│  │   10:15      │  │   未簽退  ⚠  │          │
│  └──────────────┘  └──────────────┘          │
│  第一堂課：10:00                              │
```

---

## 6. 功能需求 FR

| # | 需求 | 可測試條件 |
|---|------|---------|
| FR-001 | 卡片分為 header 列（icon圈+標題+badge+箭頭）與 body 列（雙chip）兩段垂直排列 | DOM 中存在獨立的 `.th-clockin-header` 和 `.th-clockin-body` |
| FR-002 | icon 圈顏色（背景+icon色）依照 status 動態切換 | `clockinCardClass` computed 回傳對應 class |
| FR-003 | 卡片左邊框顏色依 status 動態切換 | CSS class 對應 `border-left: 4px solid <color>` |
| FR-004 | 簽到與簽退分別顯示在獨立 chip 內，chip 並排不折行 | 390px 寬度下兩個 chip 並排，各 chip min-width: 0、flex: 1 |
| FR-005 | 未簽退時簽退 chip 顯示「未簽退」並帶警示色（`--primary` 橘紅） | 無 sign_out_dt 時 chip 內顯示警示文字 |
| FR-006 | 無打卡時兩個 chip 均顯示「—」placeholder | 無 sign_in_dt 時兩個 chip 顯示破折號 |
| FR-007 | 載入中時 chip 位置顯示 skeleton（灰色動畫方塊）無 layout shift | `v-if="clockinLoading"` 時渲染佔位 skeleton |
| FR-008 | 卡片 min-height ≥ 72px，整體可點擊區域觸控合規 | CSS `min-height: 72px` |

---

## 7. 非功能需求 NFR

| 面向 | 規格 |
|------|------|
| 效能 | CSS-only 改動，無額外 JS 執行，渲染時間影響 < 1ms |
| 觸控合規 | 觸控目標 ≥ 44×44px（Apple HIG）；NN/G 建議 ≥ 1cm×1cm 實體尺寸 |
| 降級策略 | 純 CSS class 改動；若顏色 token 不存在 fallback 到 `--border` 灰色 |

---

## 8. 技術方向

**唯一改動檔案**：`frontend/src/pages/TeacherHomePage.vue`

### Template 重構（第 17–43 行）
- 移除現有單橫排 flex 結構
- 新增 `.th-clockin-header`（一行：icon圈 + 標題 + badge + 箭頭）
- 新增 `.th-clockin-chips`（並排：`.th-clockin-chip` × 2，各含 label + value）
- Skeleton：`v-if="clockinLoading"` 時渲染兩個 `.th-clockin-chip.skeleton` 佔位塊

### Style 重構（第 1025–1057 行）
- 移除舊 `.th-clockin-body` 橫排 flex
- 新增 `.th-clockin-card` 二段垂直 flex（`flex-direction: column`）
- 新增 `.th-clockin-chips`（`display: flex; gap: 8px`）
- 新增 `.th-clockin-chip`（`flex: 1; min-width: 0; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border); min-height: 44px`）
- 新增 4 組動態 class：`.th-ckin-normal`、`.th-ckin-working`、`.th-ckin-late`、`.th-ckin-empty`，各控制 `border-left` 顏色與 icon 圈顏色

### Script 新增（不改動現有邏輯）
- 新增 `clockinCardClass` computed：根據 `clockinRecord.value.status` 和 `sign_out_dt` 回傳對應 class string

---

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|------|------|----------------|---------|
| 2026-04-23 | chip 並排而非上下排 | 上下排（更多空間）、時間線設計 | 手機橫向空間夠（390px÷2=195px/chip），並排讓簽到/簽退一眼對比，符合 Material 卡片模式 |
| 2026-04-23 | 左邊框色條（4px）傳達狀態 | 整個卡片底色染色 | 底色染色在深色模式下難以調校；左邊框輕量、與 `.th-overdue` 設計語言一致 |
| 2026-04-23 | 不加 `@media` 斷點 | 加斷點在桌機顯示不同佈局 | 此卡片已在 `max-width: 720px` 容器內，手機與桌機用相同佈局即可，省去維護複雜度 |

---

## 9. 資安與存取控制

本次純 CSS/HTML 改版，無 API 新增、無資料異動、無角色邊界變更。**不觸發 SEC 審查。**

---

## 10. QA 驗收

### Happy Path

| 情境 | 操作 | 預期結果 |
|------|------|---------|
| 完整打卡 | 老師今日已簽到 + 簽退 | 綠色左邊框、綠 icon 圈、[已完成] badge、兩個 chip 各顯示時間 |
| 上班中 | 老師已簽到、未簽退 | 橘紅左邊框、橘 icon 圈、[上班中] badge、簽退 chip 顯示「未簽退」橘色文字 |

### Edge Case

| 情境 | 操作 | 預期結果 |
|------|------|---------|
| 無打卡 | 老師今日尚未打卡 | 灰色邊框、灰 icon 圈、[尚未打卡] badge、兩 chip 均顯示「—」 |
| 遲到 | status = late | 紅色左邊框、紅 icon 圈、[遲到] badge |
| 載入中 | API 未回傳 | 兩個 chip 顯示 skeleton 動畫佔位，無 layout shift |

### UI/UX 驗收清單

- [ ] 四種狀態均無水平 overflow（390px 寬度手機）
- [ ] 兩個 chip 並排，390px 下各約 185px 寬
- [ ] 卡片整體 min-height ≥ 72px
- [ ] chip min-height ≥ 44px（觸控合規）
- [ ] 載入中 skeleton 無 layout shift
- [ ] 深色模式下顏色對比度 ≥ 4.5:1（目視驗收）
- [ ] 鍵盤 Tab + Enter 可觸發點擊（現有邏輯，確認未破壞）

---

## 11. 上線與維運

**部署步驟**：
1. `git checkout -b feat/teacher-clockin-card-ui`
2. 改 `TeacherHomePage.vue`
3. Push → CI 全綠（自己等、自己驗）
4. PR merge → `git pull`
5. `cd /home/admin/frontend && npm run deploy`
6. `curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool`

**Feature Flag**：無（純視覺改版，無功能風險）

**Observability**：無需新增監控（純 CSS 改動）

**回滾**：`git revert HEAD` → `npm run deploy`；預估 3 分鐘內完成

---

## 12. 里程碑與優先級

| Phase | Agent | 任務 |
|-------|-------|------|
| `[FEATURE]` | DEV | Template + Style + computed 重構 |
| `[TEST]` | TEST | 四種狀態視覺驗收、桌機 + 手機 390px |
| `[REVIEW]` | REVIEW | 逐條對照 FR-001–FR-008 |
| `[DOCS]` | DOCS | 更新 `docs/CHANGELOG.md` |
| `[OPS]` | OPS | deploy + health check |

---

## 13. 風險 / 假設 / 開放問題

（已 WebSearch 業界解法）

| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|------|------|------------|--------------|
| 手機各廠牌螢幕寬度差異（320px～430px）導致 chip 過窄 | 中 | Apple/Google 建議 `flex: 1` + `min-width: 0` 讓 flex child 自動均分（Material Design 3 Card spec） | chip 各設 `flex: 1; min-width: 0`，320px 下每 chip 約 150px，仍可顯示 5 位數時間 |
| 深色模式下 chip 邊框/背景對比不足 | 低 | 沿用已測試的 design token（`--border`、`--bg`），dark mode 已由 `:root[data-theme="dark"]` 定義 | 使用現有 token，不自訂 hex 顏色，讓 dark mode 自動套用 |
| 觸控目標 44px 在低解析度裝置的物理尺寸 | 低 | NN/G 研究建議最小實體 1cm×1cm（約 38px @96dpi） | chip padding 使實際高度 ≥ 44px CSS px，主流手機（96–401 DPI）均 ≥ 1cm |

**假設**：老師手機以 iOS 16+/Android 12+ 為主，支援 CSS `gap`、`flex`、CSS 變數。若不成立，AI 可在 `npm run build` 時檢查 Vite 的 browserslist 輸出確認。

---

## 14. Definition of Done

- [ ] FR-001–FR-008：驗證方式：`[REVIEW]` Agent 逐條對照 template/style diff，全部 ✅
- [ ] 手機 390px 無 overflow：驗證方式：browser 工具 390px 模式截圖，無水平捲動條
- [ ] 觸控目標：驗證方式：Chrome DevTools 量測 `.th-clockin-chip` 元素高度 ≥ 44px
- [ ] Skeleton 無 layout shift：驗證方式：throttle 網路到 Slow 3G，觀察卡片載入過程無跳動
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 `2026-04-23` 條目
- [ ] Health check：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok"}`

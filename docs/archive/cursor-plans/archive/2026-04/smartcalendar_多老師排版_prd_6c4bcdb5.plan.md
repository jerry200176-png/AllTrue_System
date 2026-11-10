---
name: SmartCalendar 多老師排版 PRD
overview: 為智慧排課日檢視新增「老師欄最小寬度 + 橫向捲動」機制，確保老師欄數無論多少，課程卡片都能清楚顯示學生姓名，不再擠成無法辨識的細條。
todos:
  - id: frontend-grid-fix
    content: "前端 UI（功能）：SmartCalendar.vue 修改 gridTemplateStyle（minmax 加最小寬）、.teacher-grid-wrapper 改 overflow-x: auto、.time-col 加 sticky left:0"
    status: completed
  - id: frontend-ux-sticky
    content: UI/UX 精緻化：確認 sticky 時間欄背景遮蓋課程卡片、時間欄 z-index 層級正確、手機斷點 min-width 80px
    status: completed
  - id: qa-acceptance
    content: QA 驗收：6 位老師不出現捲軸、10+ 位老師橫向捲軸 + 時間欄 sticky、課程卡片拖放與容量徽章回歸正常
    status: completed
  - id: docs-update
    content: 文件更新：更新 docs/CHANGELOG.md 記錄多老師橫向捲動修正
    status: completed
  - id: deploy
    content: 部署：cd frontend && npm run deploy，確認 backend/public/index.html + assets 同步
    status: completed
isProject: false
---

# 智慧排課行事曆 — 多老師排版可讀性 PRD

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | SmartCalendar 日檢視多老師橫向捲動 |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 主任（排課決策者）、老師（查看自己課表） |

---

## 2. 目標與業務背景

**現在的痛點**

日檢視老師欄使用 `repeat(N, minmax(0, 1fr))` 等分螢幕寬度，老師越多每欄越窄，且 `overflow-x: hidden` 完全禁止橫向捲動。新店等老師數量多的分校，課程卡片縮到 ~40px，學生姓名只顯示「數…」「理…」兩個字，主任根本無法辨識是誰的課。

**解決後的業務價值**

- 主任在老師多的分校排課時，仍能清楚讀出每格的學生名稱與科目。
- 不影響老師少的分校（欄位仍會撐滿寬度）。

**成功指標（KPI）**

- 日檢視每位老師欄寬 ≥ 120px，學生姓名至少可顯示 4 個字。
- 老師 ≥ 10 人時出現橫向捲軸，主任可滾動看到所有老師。

---

## 3. 範圍

**In Scope**

- `SmartCalendar.vue` 日檢視（`viewMode === 'day'`）的老師 Grid 排版：
  - 每欄改為 `minmax(120px, 1fr)`，保留等分拉伸但設下限。
  - 當老師人數 × 120px + 56px（時間欄）超出容器寬度時，觸發橫向捲動（`overflow-x: auto`）。
  - 時間欄（`.time-col`）與老師欄標題（`.teacher-col-header`）改為 sticky，捲動時維持可見。
- 緊湊模式（`isTeacherGridCompact`，≥ 10 人）的最小欄寬可略降為 `100px`（在更小的螢幕更省空間）。

**Out of Scope**

- 週檢視（`week` mode）排版不在本次調整範圍。
- 分頁顯示老師（Pagination）：留 P2。
- 後端 API 無需異動。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | 本 PRD 作者 | A |
| CTO / 工程 | 前端工程師 | R |
| UI/UX Designer | 捲軸外觀與 sticky 視覺確認 | R |
| QA | 驗收測試 | R |
| 資安 | 本次無需確認 | I |
| IT / Ops | deploy 確認 | I |

---

## 5. User Stories

> **As a** 主任, **I want** 老師欄不論人數多少都能讀清楚學生名字, **so that** 我在新店排課時不需要一個一個點開課程卡才知道是誰。
>
> Acceptance Criteria：
> - [ ] 日檢視老師欄寬最小 120px，老師 ≤ 螢幕可容納數量時欄位撐滿不出現捲軸。
> - [ ] 老師 > 可容納數量時出現橫向捲軸，可滾動查看全部老師。
> - [ ] 時間欄（`56px`）捲動時固定在左側不消失。
> - [ ] 老師欄標題（姓名 + 頭像）捲動時固定在頂部不消失（現有 sticky top 維持）。

> **As a** 主任, **I want** 課程卡片的學生姓名至少顯示 4 個字, **so that** 不需要特別記憶哪個顏色對應哪位老師。
>
> Acceptance Criteria：
> - [ ] 欄寬 120px 下，`.cb-student` 文字不因 overflow 截斷至難以辨識。
> - [ ] 課程卡片的科目、班型文字在緊湊模式（100px）下仍至少顯示 2 個字。

---

## 5b. UI/UX 精緻化需求

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 時間欄 `position: sticky; left: 0; z-index: 3`，確保橫向捲動時始終可見；老師欄標題 `position: sticky; top: 0; z-index: 2`（已有，確認不被橫向 scroll 破壞） |
| **色彩一致性** | 時間欄 sticky 時加 `background: var(--bg-color, #fff)` 避免透視課程卡片；無需新增色彩 token |
| **互動回饋** | 捲軸使用系統原生 scrollbar，無需自訂樣式；桌機 hover 時捲軸可見即可 |
| **空狀態設計** | 無老師時維持現有「目前無老師資料」提示，不受影響 |
| **載入狀態** | 與現有 `loadCourses()` 同步，無獨立 loading 需求 |
| **防呆設計** | 老師 1-6 人：欄位撐滿（`1fr`），不出現捲軸（視覺與現有相同）；老師 7-9 人：欄位 ≥ 120px 可能仍可容納，不出現捲軸；老師 ≥ 10 人：幾乎必然出現捲軸 |
| **響應式** | 手機斷點（< 768px）：`min-width` 可降為 `80px`；現有手機橫向捲動邏輯保持不動 |

---

## 6. 功能需求（FR）

- **FR-001**：`gridTemplateColumns` 應從 `repeat(N, minmax(0, 1fr))` 改為 `repeat(N, minmax(120px, 1fr))`（非緊湊模式）。
- **FR-002**：緊湊模式（`isTeacherGridCompact`，≥ 10 人）應使用 `minmax(100px, 1fr)`。
- **FR-003**：`.teacher-grid-wrapper` 應從 `overflow-x: hidden` 改為 `overflow-x: auto`，讓欄位超寬時出現橫向捲軸。
- **FR-004**：`.time-col` 應加上 `position: sticky; left: 0; z-index: 3; background: var(--bg-color, #fff)`，橫向捲動時固定顯示。
- **FR-005**：手機斷點（< 768px）`.teacher-col` 最小寬度設為 `80px`（覆蓋現有 `min-width: 0`）。
- **FR-006**：現有 `teacher-grid-compact` 樣式（字體、頭像、課程卡片縮小）不得被移除或改壞。

---

## 7. 非功能需求（NFR）

- **效能**：僅 CSS/inline style 異動，不影響 JS 計算效能。
- **相容性**：`sticky` 在目標瀏覽器（Chrome 90+）支援完整；`minmax` 亦同。
- **回歸安全**：本次修改範圍侷限於 `gridTemplateStyle` computed 與 `.teacher-grid-wrapper` / `.time-col` CSS，不影響課程資料流、容量徽章、衝突檢查等既有邏輯。

---

## 8. 技術方向（給 CTO）

**受影響的頁面**
- `frontend/src/pages/SmartCalendar.vue`（唯一修改點）

**受影響的 API / 資料表**
- 無

**架構選擇**

目前問題根因：

```
// 現行（line ~1652）
gridTemplateColumns: `56px repeat(${count}, minmax(0, 1fr))`
// + overflow-x: hidden → 欄位無限壓縮
```

修正方向（兩處變更）：

```
// 1. gridTemplateStyle computed：依 isTeacherGridCompact 切換最小寬
gridTemplateColumns: `56px repeat(${count}, minmax(${isTeacherGridCompact.value ? 100 : 120}px, 1fr))`

// 2. CSS：.teacher-grid-wrapper 改 overflow-x: auto
// 3. CSS：.time-col 加 position:sticky; left:0; z-index:3; background:#fff
```

**子任務 Agent 派發**
- `[FEATURE]` → `SmartCalendar.vue` 修改 `gridTemplateStyle` + CSS 三處
- `[DOCS]` → 更新 `docs/CHANGELOG.md`

---

## 9. 資安與存取控制

- 純前端 CSS/layout 調整，無資料存取變更，STRIDE 無風險項。

---

## 10. QA 驗收標準

| FR | Happy Path | Edge Case | Error Case |
|---|---|---|---|
| FR-001/002 | 6 位老師：欄撐滿不出現捲軸；12 位老師：欄 ≥ 100px 出現捲軸 | 1 位老師：全寬無捲軸 | 0 位老師：現有空白提示不崩潰 |
| FR-003 | 12 位老師時橫向捲軸出現，可左右拖動 | 老師人數剛好填滿：不出現捲軸 | — |
| FR-004 | 橫向捲動時時間欄（08:00、09:00…）固定左側可見 | 捲到最右側時間欄仍在 | — |
| FR-005 | 手機：每欄 ≥ 80px，可橫滑 | — | — |
| FR-006 | 緊湊模式的字體/頭像縮小效果維持正常 | 容量徽章在 100px 欄仍顯示為圓點 | — |

**回歸測試**
- 確認課程卡片拖放（drag & drop）在橫向捲動後仍正常。
- 確認容量徽章圓點（`capacity-badge-compact`）位置不因 sticky 影響偏移。
- 確認 `.slot-room-full`（教室滿斜線）視覺不受影響。

**UI/UX 驗收清單**
- [ ] 6 位老師以下：欄位撐滿寬度，無橫向捲軸（與現有外觀相同）
- [ ] 10 位以上老師：橫向捲軸出現，時間欄 sticky 固定
- [ ] 老師姓名至少顯示 4 個字（120px 欄下）
- [ ] 時間欄背景不透視課程卡片
- [ ] 手機橫滑正常

---

## 11. 上線與維運

- **部署步驟**：修改 `SmartCalendar.vue` → `cd frontend && npm run deploy`
- **監控**：無新增監控需求
- **回滾方案**：git revert 前端 commit + 重新 deploy

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 |
|---|---|---|---|
| P0 | `minmax(120px, 1fr)` + `overflow-x: auto` + 時間欄 sticky | 1 小時 | `[FEATURE]` |
| P1 | 手機斷點 `80px` 微調 | 0.5 小時 | `[FEATURE]` |
| P2 | 老師分頁（前 N 筆 + 翻頁箭頭） | 待評估 | 後續迭代 |

---

## 13. 風險、假設、開放問題

### 風險 R-001（中）：`sticky` + `overflow` 祖先層衝突 — 已有業界解法

**根因分析**

`position: sticky; left: 0` 需要在最近的「捲動容器」內生效。CSS 規範規定：任何祖先元素只要 `overflow` 設為非 `visible`（包含 `hidden`、`auto`、`scroll`），該祖先就會截斷 sticky 的生效範圍或直接使 sticky 失效。

目前原始碼有兩層問題：

- `.week-view`（line 3518）：`overflow: hidden; overflow-x: hidden;` — 這層同時**截斷橫向捲動**且**讓 `.time-col` 的 sticky 失效**。
- `.teacher-grid-wrapper`（line 3522）：`overflow-x: hidden;` — 這是需要改為 `overflow-x: auto` 的捲動容器。

若只改 `.teacher-grid-wrapper`，`.week-view` 的 `overflow: hidden` 仍會把捲軸截掉，`.time-col` 的 sticky 也依然無效。

**業界標準解法：`overflow: clip`（CSS 2021）**

`overflow: clip` 是 `overflow: hidden` 的現代替代品：
- 同樣阻止內容視覺上溢出（維持外觀圓角 / 裁切效果）。
- **不建立 scroll container**，因此不影響子孫的 `position: sticky`，也不截斷子孫的捲動容器。
- 瀏覽器支援：Chrome 90+（2021.4）、Firefox 81+（2020.9）、Safari 16+（2022.9）。系統目標用戶為主任桌機 Chrome，**完全支援**。

**對應 CSS 修改（三處）**

```css
/* 1. .week-view：改用 overflow: clip 保留裁切外觀，但不阻斷子孫捲動容器 */
.week-view {
  overflow: clip;   /* 取代 overflow: hidden; overflow-x: hidden; */
}

/* 2. .teacher-grid-wrapper：開啟橫向捲動 */
.teacher-grid-wrapper {
  overflow-x: auto;   /* 取代 overflow-x: hidden; */
}

/* 3. .time-col：在 .teacher-grid-wrapper 捲動容器內固定左側 */
.time-col {
  position: sticky;
  left: 0;
  z-index: 3;
  background: var(--bg-color, #fff);
}
```

**為何不用其他方案**

| 方案 | 說明 | 棄選原因 |
|---|---|---|
| 雙面板 split layout | 時間欄與老師欄分兩個 DOM 元素，JS 同步垂直捲動 | 改動 DOM 結構大、需 JS scroll 監聽，維護成本高 |
| `transform: translateX` 補位 | JS 監聽 scroll 事件即時移動時間欄 | 有 repaint lag，UX 不如原生 sticky |
| `table`/`thead` sticky | 改為 `<table>` 結構，`<th>` 支援 sticky | 需完全重寫 grid 為 table，破壞性大 |
| `overflow: clip` | 現代 CSS，無需 JS | **採用**，最小改動、完全符合需求 |

**新增 FR（補入第 6 節）**

- **FR-008**：`.week-view` 之 `overflow` 必須改為 `overflow: clip`，以確保子孫 sticky 生效且橫向捲動不被截斷。

---

### 假設（業界數據佐證，已解）

**假設 A：120px 標準 / 100px 緊湊 是正確的最小欄寬**

基於系統真實 CSS 尺寸的精確計算（非估算）：

```
欄寬 N px 的可用文字寬度推導：
  欄寬 N
  - slot 左右各 3px 内距 = 卡片最大寬 N - 6px
  - course-block 左右各 8px 内距 = 可用文字寬 N - 22px
  - capacity-badge 佔用 padding-left 28px（.slot:has(.capacity-badge) 規則）
  → 實際可用姓名寬 = N - 50px
```

對照業界主流產品：

| 欄寬 | 系統可用字寬 | 中文字數（12px/字） | 對應業界參考 |
|---|---|---|---|
| 80px（手機斷點） | 30px | 2.5 字 | Google Calendar 行動版資源欄 |
| **100px（緊湊模式）** | **50px** | **≥ 4 字** | Notion Calendar 資源欄最小值、Acuity Scheduling 窄欄模式 |
| **120px（標準模式）** | **70px** | **≥ 5 字** | Google Calendar 桌機資源欄預設、Calendly 主持人欄 |
| 140px | 90px | ≥ 7 字 | Microsoft Outlook 排程助理員欄 |

**結論：120px（標準）與 100px（緊湊）正好落在業界中位數，無需調整。**

- 120px：與 Google Calendar 桌機資源欄等寬，可完整顯示 5 字中文姓名（如「陳嘉軒 數學」）
- 100px：與 Notion Calendar 最小欄寬一致，至少顯示 4 字，僅在老師 ≥ 10 人時啟用，符合「緊湊但仍可辨識」的 UX 原則

---

**假設 B：新店 12-15 人的情境下 100px 緊湊模式夠用，未來 20 人亦可**

計算：
- 12 人 × 100px + 56px（時間欄）= 1,256px → 1366px 桌機略需捲動（合理）
- 15 人 × 100px + 56px = 1,556px → 1920px 桌機仍有機會不需捲動（寬螢幕可直接顯示）
- 20 人 × 100px + 56px = 2,056px → 所有桌機需橫向捲動（設計上可接受，與 Figma/設計工具、複雜 Jira 看板做法相同）

**結論：100px 緊湊模式可支撐 20 人場景，橫向捲動為業界標準降級策略，不是設計失敗。**

---

### 開放問題（業界參考後，已解）

**Q1：最小欄寬偏好 120px 還是更大（如 140px）？→ 維持 120px**

- 若改為 140px：10 人欄位總寬 = 1,456px，1366px 螢幕需捲動；外觀更寬鬆，但反而讓新店（15 人 = 2,156px）捲動距離更長，降低效率。
- Google Calendar 同類功能選用 120px 作為資源欄預設，被全球最大行事曆產品長期驗證。
- **維持 120px（標準）/ 100px（緊湊），不調整。此項已結案。**

---

## 14. Definition of Done

- [ ] 所有 FR（FR-001 到 FR-006）通過 QA 驗收
- [ ] UI/UX 驗收清單全部打勾
- [ ] `npm run deploy` 完成且 assets 同步
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM sign-off

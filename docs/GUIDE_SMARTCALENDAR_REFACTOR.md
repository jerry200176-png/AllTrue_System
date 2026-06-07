# SmartCalendar 受控拆分 — 技術文件（#740 Phase 4c）

> **狀態**：2026-06-07 暫時收尾（CEO 視覺驗收通過）。Modals 與 P4-a/b 延後。  
> **Issue**：[#740](https://github.com/jerry200176-png/AllTrue_System/issues/740)  
> **紅線**：`CLAUDE.md §G-007` — 週檢視 occurrence 合併**唯一合法路徑**仍為 `calendarOccurrenceMerge.js`；本重構**未動**合併邏輯。

---

## 1. 成果摘要

| 指標 | Before | After | Δ |
|------|--------|-------|---|
| `SmartCalendar.vue` 行數 | 5260 | 4845 | **−415** |
| 剝離純函式模組 | 0 | 3 | `calendarDateUtils` / `calendarFormat` / `teacherColor` |
| 剝離 presentational 元件 | 0 | 5 | `components/calendar/*` |
| 元件單元測試（vitest） | 0 | 22 cases | 5 元件 × 3–7 cases |
| 回歸測試 | `test:calendar` 全綠 | 全綠 | G-007 occurrence merge 零回歸 |

**重構鐵律（全程遵守）**

1. **Leaf-First**：先剝純函式與視覺邊界明確的葉子，最後才動有狀態父層。
2. **Pure Move**：函式體一字不改，僅加 `export` 或搬 markup/CSS。
3. **Container / Presentational**：子元件只接 props + emit，業務狀態留在 `SmartCalendar.vue`。
4. **測試驅動**：每步 `npm run test:calendar` +（Step 4 起）`npm run test:unit`。

---

## 2. 檔案地圖

```
frontend/src/
├── lib/
│   ├── calendarDateUtils.js      ← Step 1 純日期（6 函式）
│   ├── calendarDateUtils.test.js
│   ├── calendarFormat.js         ← Step 2 格式化（8 函式/常數）
│   ├── calendarFormat.test.js
│   ├── teacherColor.js           ← Step 3 教師配色 memo
│   └── teacherColor.test.js
├── components/calendar/
│   ├── TeacherColumnHeader.vue   ← Step 4a
│   ├── DayTabsBar.vue            ← Step 4b
│   ├── WeekTeacherChips.vue      ← Step 4c
│   ├── WeekNavBar.vue            ← Step 4d
│   ├── CourseBlockContent.vue    ← Step 5（CSS 解耦）
│   └── __tests__/*.test.js       ← 各元件 vitest
└── pages/
    └── SmartCalendar.vue         ← 容器：狀態、API、modals、.course-block 外殼
```

**既有、未動的 calendar lib**（G-007 核心，重構前已存在）：

- `calendarOccurrenceMerge.js` — 週檢視合併 resolver（⛔ 禁止繞過）
- `calendarExceptionMerge.js` — 例外合併
- `calendarLoadPerformance.js` — 視窗快取 `isRangeWithinFetchedBounds`

---

## 3. Lib 模組 API

### 3.1 `calendarDateUtils.js`（純、無狀態）

| 函式 | 簽名 | 用途 |
|------|------|------|
| `formatLocalDate` | `(date: Date) → YYYY-MM-DD` | 本地時區日期，避免 `toISOString` UTC 偏移 |
| `getNextWeekdayYmd` | `(dow: 1–7) → YYYY-MM-DD` | 下一個指定星期幾 |
| `getMondayOfMonthWeek` | `(year, month, weekNum) → YYYY-MM-DD` | 該月第 N 週週一 |
| `toYmd` | `(val) → YYYY-MM-DD` | API/DB 日期正規化 |
| `addDays` | `(ymd, days) → YYYY-MM-DD` | 日期加減 |
| `getWeekNumberOfDate` | `(ymd) → 1–5` | 與週檢視 `displayWeek` 一致 |

測試：20 cases（跨年、跨月、2/29、週日等邊界）。

### 3.2 `calendarFormat.js`（純、無狀態）

| 匯出 | 用途 |
|------|------|
| `classTypeLabel(type)` | 班型中文標籤 |
| `dayLabel(d)` | 週一～週日 |
| `dayOfWeekFromDate(ymd)` | 1=週一 … 7=週日 |
| `getWeekLabel(weeks)` | 「第1,3週」字串 |
| `parseHour(t)` | `"HH:MM"` → 小時數 |
| `TIME_STEP_MINUTES` | 常數 30 |
| `normalizeTimeTo30(timeStr)` | 半小時格正規化 |
| `computeEndTime(start, durationHours)` | 結束時間 |

測試：21 cases（單碼時數、24:00 clamp、空值等）。

### 3.3 `teacherColor.js`（有狀態 memo）

| 匯出 | 用途 |
|------|------|
| `getTeacherColor(teacherId)` | 穩定配色；module-level `teacherColorMap` 快取 |
| `__resetTeacherColorCache()` | **僅測試用**，重置快取 |
| `__paletteSize` | **僅測試用**，palette 長度 |

測試：6 cases（cache hit/miss、palette 輪替、falsy id）。

---

## 4. Presentational 元件清單

設計原則：props ≤ 5、無副作用、父層持有狀態。

### 4.1 `TeacherColumnHeader`（Step 4a）

日檢視老師欄表頭（頭像 + 姓名 + 教室）。

| Props | 型別 | 預設 | 說明 |
|-------|------|------|------|
| `name` | `String` | `''` | 老師姓名（取首字作頭像） |
| `room` | `String` | `''` | 教室；空則不渲染 |
| `color` | `String` | `'#90A4AE'` | 頭像底色 + 頂部邊框色 |
| `compact` | `Boolean` | `false` | 取代 `.teacher-grid-compact .teacher-col-header` |

Events：無。

CSS 解耦：`.teacher-grid-compact .teacher-col-header` → `compact` prop → `.tch-compact`。

### 4.2 `DayTabsBar`（Step 4b）

日檢視「週一～週日」分頁列。

| Props | 型別 | 說明 |
|-------|------|------|
| `tabs` | `Array` | `[{ name, dateLabel, count }]` — 父層 `dayTabs` computed |
| `activeIdx` | `Number` | 目前選中索引；`-1` = 無 |

| Events | Payload |
|--------|---------|
| `select` | `idx: number` |

### 4.3 `WeekTeacherChips`（Step 4c）

週檢視老師多選篩選 chips。

| Props | 型別 | 說明 |
|-------|------|------|
| `teachers` | `Array` | `[{ id, username, color }]` — `color` 由父層 `getTeacherColor` 預算 |
| `selectedIds` | `Array` | 已選老師 id（字串化） |

| Events | Payload |
|--------|---------|
| `toggle` | `id` |
| `clear` | — |

### 4.4 `WeekNavBar`（Step 4d）

週檢視上週/下週 + 週次下拉。

| Props | 型別 | 說明 |
|-------|------|------|
| `modelValue` | `Number` | 週次（`0` = 全部） |
| `weekOptions` | `Array` | `[{ value, label }]` |

| Events | Payload |
|--------|---------|
| `update:modelValue` | `number` |
| `prev` / `next` | — |

### 4.5 `CourseBlockContent`（Step 5 — CSS 解耦重點）

日/週檢視課程卡**內容**（multi-root fragment）。外層 `.course-block`（定位、拖曳、`inline style` 底色）**仍由父層持有**。

**API 嚴格 3 props**（不再擴充）：

#### `course`（required）

```ts
{
  student_name: string
  subject: string          // 由子元件內部 getSubjectLabel 轉中文
  class_type: string       // 由子元件內部 classTypeLabel 轉中文
  teacher_id?: number      // 週檢視 teacherTag 用（父層算 color）
  teacher_name?: string
}
```

#### `badges`

```ts
{
  rollCall?: { kind: 'done'|'missed'|'leave'|'cancelled', label: string } | null
  evalMissing?: { label: string } | null
  teacherTag?: { name: string, color: string } | null   // 週檢視多老師時
}
```

父層組裝範例（日檢視）：

```vue
<CourseBlockContent
  :course="course"
  :badges="{
    rollCall: rollCallBadge(course, selectedDateStr),
    evalMissing: evalBadge(course, selectedDateStr),
    teacherTag: null
  }"
  :layout="{
    compact: isTeacherGridCompact,
    firstBadge: (cIdx === 0 && getSlotOccupancy(...).count > 0)
      ? (isTeacherGridCompact ? 'compact' : 'full')
      : null
  }"
/>
```

#### `layout`

```ts
{
  compact?: boolean                              // 日檢視多老師分裂卡
  firstBadge?: 'full' | 'compact' | null         // 容量徽章首卡左縮排
}
```

Events：無。

---

## 5. CSS 解耦決策（CourseBlockContent）

**問題**：原樣式依賴祖先/sibling 選擇器（`:has()`、`:first-of-type`、`.teacher-grid-compact`），Vue scoped 子元件無法繼承，盲目搬移會**靜默跑版**。

**解法順序**（Linear/Airbnb 實戰，逐步驗證）：

| 順序 | 原選擇器 | 解耦方式 | 子元件 class |
|------|----------|----------|--------------|
| 1 | `.teacher-grid-compact .cb-*` | `layout.compact` prop | `.cbc-compact` |
| 1 | `.teacher-grid-compact .rc-tag` | 同上 | `.rc-tag.cbc-compact` |
| 2 | `.course-block:has(.rc-tag) .cb-student` | 由 `badges` 推導 `hasRc` | `.cbc-has-rc`（18px/compact 10px 右留白） |
| 2 | `.slot:has(.capacity-badge) .course-block:first-of-type .cb-student` | `layout.firstBadge` | `.cbc-badge-full`(20px) / `.cbc-badge-compact-pad`(12px) |
| 3 | RWD `@media` 900/768/640 的 `cb-*` | 原樣搬入子元件 scoped | — |

**rc-tag 雙份 scope（刻意設計）**

| 位置 | 用途 | 選擇器差異 |
|------|------|------------|
| `CourseBlockContent.vue` | 課程卡角標（absolute 定位） | `.rc-tag { position: absolute; … }` |
| `SmartCalendar.vue` | toolbar **legend** 靜態展示 | `.rc-legend .rc-tag { position: static; }` |

兩份互不污染；改 legend 樣式動父層，改卡片角標動子元件。

**父層仍保留的 course-block CSS**

- `.course-block` 外殼（absolute 定位、hover、拖曳 cursor）
- RWD 下 `.course-block` 字級/padding（外殼尺寸，非內容 `cb-*`）

---

## 6. SmartCalendar.vue 仍持有（未拆分）

| 類別 | 內容 |
|------|------|
| 狀態 / 業務 | `loadCourses`、篩選、拖曳調課、點名、請假、所有 modal 開關 |
| 資料合併 | 呼叫 `mergeWeekCalendarOccurrences`（G-007） |
| 外殼 DOM | `.course-block` div + `@click` / `@dragstart` / `getTeacherCourseBlockStyle` |
| Modals | 建課、調課、代課、請假等（**延後 Step 6**） |
| Toolbar | 篩選、legend、視圖切換 |

---

## 7. 延後項目（#740 未結）

| 項目 | 原因 | 建議下一步 |
|------|------|------------|
| **Modals 群拆分** | 有狀態父層、表單驗證與 API 耦合深 | 獨立 PRD；每個 modal 一 PR |
| **P4-a 請求平行化** | 原 issue 範圍；與拆分正交 | 實測冷載 before/after 再決策 |
| **P4-b student-classes 抓取量** | 需後端 API 契約 | 與 ARCH 評估日期視窗 |
| **行數目標 < 3000** | 目前 4845；Modals 佔大宗 | Modals 拆完再評估 |

---

## 8. 測試護欄

```bash
# 必跑（含 G-007 occurrence merge + 新 lib 測試）
npm run test:calendar

# 元件單元測試（含 5 個 calendar 元件）
npm run test:unit
```

| 測試檔 | Cases | 覆蓋重點 |
|--------|-------|----------|
| `calendarDateUtils.test.js` | 20 | 跨年/跨月/2-29/週日 |
| `calendarFormat.test.js` | 21 | normalizeTimeTo30、computeEndTime 邊界 |
| `teacherColor.test.js` | 6 | cache hit/miss、palette 輪替 |
| `CourseBlockContent.test.js` | 7 | 徽章、compact、firstBadge、空值 |
| `TeacherColumnHeader.test.js` | 4 | compact、空名、預設色 |
| `DayTabsBar.test.js` | 3 | active、emit、空 tabs |
| `WeekTeacherChips.test.js` | 4 | toggle/clear、active 色 |
| `WeekNavBar.test.js` | 4 | v-model、prev/next |

CI：`test:unit` blocking（#729）；`frontend/src/**` 變更觸發 UI Smoke E2E（#730）。

**視覺驗收**（CI 無法像素 diff）：部署後肉眼確認課程卡角標讓位、compact、容量徽章首卡縮排、週檢視老師標籤。2026-06-07 CEO 已確認 OK。

---

## 9. PR 對照表

| Step | PR | 合併時間 | 內容 |
|------|-----|----------|------|
| 1 | [#751](https://github.com/jerry200176-png/AllTrue_System/pull/751) | 2026-06-06 | `calendarDateUtils.js` |
| 2+3 | [#752](https://github.com/jerry200176-png/AllTrue_System/pull/752) | 2026-06-06 | `calendarFormat.js` + `teacherColor.js` |
| 4a | [#754](https://github.com/jerry200176-png/AllTrue_System/pull/754) | 2026-06-06 | `TeacherColumnHeader` |
| 4b | [#755](https://github.com/jerry200176-png/AllTrue_System/pull/755) | 2026-06-06 | `DayTabsBar` |
| 4c/4d | [#756](https://github.com/jerry200176-png/AllTrue_System/pull/756) | 2026-06-06 | `WeekTeacherChips` + `WeekNavBar` |
| 5 | [#757](https://github.com/jerry200176-png/AllTrue_System/pull/757) | 2026-06-07 | `CourseBlockContent` + CSS 解耦 |

---

## 10. 後續修改規則（防再犯）

1. **改週檢視合併** → 只動 `calendarOccurrenceMerge.js` + `test:calendar`，禁止在 `SmartCalendar.vue` 加分散 if（G-007）。
2. **改課程卡內容樣式** → 優先改 `CourseBlockContent.vue`；若需新祖先耦合，**禁止**加 `:has()` 回父層，改加 `layout`/`badges` 旗標。
3. **改課程卡外殼**（拖曳、定位、底色）→ 改 `SmartCalendar.vue` 的 `.course-block`。
4. **改 toolbar legend 色票** → 改父層 `.rc-tag` / `.rc-legend`（與子元件 scope 分離）。
5. **新葉子元件** → props ≤ 5、附 `__tests__/*.test.js` ≥ 3 cases、`npm run test:unit` 綠燈。

---

## 11. 相關文件

| 主題 | 出處 |
|------|------|
| G-007 合併規則 | `CLAUDE.md §G-007` |
| 效能史（TD-062） | `docs/TECH_DEBT.md` TD-062、`.cursor/plans/calendar-performance-epic_2026-06-01.md` |
| 前端元件測試基建 | #729 / `vitest.config.js` |
| 設計系統（Wave 1 行事曆） | `docs/RULE_DESIGN_SYSTEM.md` |

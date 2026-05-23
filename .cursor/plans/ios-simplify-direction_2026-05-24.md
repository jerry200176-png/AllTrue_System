# 設計方向提案：iOS 風格簡潔化（v1，待 CEO 批准範圍）

> **狀態**：Plan / proposal — 等 CEO 確認哪些頁面要做、確認設計取向後才進 DEV
> **建立日期**：2026-05-24
> **要求來源**：CEO「有些頁面可以簡潔一點 要像 ios 那樣的風格簡潔」
> **既有相關文件**：`docs/PORSCHE_VISUAL_SYSTEM.md`（與本檔相容，不取代）

---

## 0. 為什麼要寫提案、不直接開幹

整套系統視覺改動 = 高風險（影響每個使用者、跨多 PR、易出回歸）。沒有取得 CEO 對「哪些頁面、改多深、保留多少品牌」的明確意見前，直接動 CSS 會：
- 改完 CEO 覺得太淡、要求回退
- 改完家長／老師覺得陌生
- 一個 PR 動太多檔，CI / review / rollback 都困難

因此先寫提案、列具體候選頁、提案 v0 demo 頁，等 CEO 批准後才分 PR 實作。

---

## 1. 「iOS 風格」具體拆解（避免抽象）

iOS HIG（Human Interface Guidelines）與蘋果生態的「簡潔感」具體由這幾件事構成：

| 元素 | iOS 做法 | AllTrue 目前 | 提案改法 |
|------|---------|-------------|---------|
| 字體 | SF Pro / `system-ui` 為主，字重收斂在 400/500/600 | 自訂混合 | 統一改 `-apple-system, BlinkMacSystemFont, "PingFang TC", system-ui`，字重僅用 400/500/600/700 |
| 留白 | 大量空白；卡片之間 16-24px gap；卡片內 padding 20-24px | 不一致 | 統一 `--spacing-card-gap: 16px`、`--spacing-card-pad: 20px` |
| 圓角 | 12-16px on cards、22-28px on sheets、999px on pills | 多種值並用 | 收斂到 12/16/22/999 四檔 |
| 顏色 | 黑灰白主導 + 單一 accent（藍色為預設） | 橘 #F57C00 + 多色 | **保留 AllTrue 品牌橘**（不改），只統一灰階 + 收斂 accent 使用場景 |
| 邊框 | 幾乎不用實線邊框；用陰影或背景色分層 | 1-2px 灰邊框很多 | 卡片邊框淡化為 `rgba(0,0,0,0.06)` 或改成 soft shadow |
| 陰影 | 柔和、低不透明度（`0 1px 3px rgba(0,0,0,0.06)`） | 多處硬陰影 | 統一陰影 token |
| 按鈕 | 大圓角實心、純文字、無 emoji | emoji 與多種樣式並用 | Primary 改 12-14px 圓角實心；emoji 僅限非操作元素 |
| 表單 | inline label 或上方 label，大欄位 high touch target（44pt） | OK 但 padding 偏小 | 輸入欄位高度 ≥ 44px |
| 列表 | iOS Settings 風格：白底卡片 + 細分隔線 + chevron | 部分頁有、部分用 table | Profile/Settings/家長入口列表頁改 iOS Settings 風 |
| 動效 | `cubic-bezier(0.16, 1, 0.3, 1)` 0.25-0.4s | 已有部分 | 收斂為 token |

---

## 2. 候選頁面分級（CEO 圈選）

### Tier A — 強烈建議首批做（高 ROI、低風險）

| 頁面 | 為何 | 風險 |
|------|------|------|
| `Login.vue` | 每個使用者第一眼，615 行單檔，無業務邏輯 | 低 |
| `ProfileCenterPage.vue` | iOS Settings.app 範本對齊度最高，所有角色都會用 | 中（1100 行，但純設定無核心 flow） |
| `BugReportsPage.vue` | 反饋頁，iOS-style 列表完美對齊 | 低 |

### Tier B — 建議第二批（高使用率，但較複雜）

| 頁面 | 為何 | 風險 |
|------|------|------|
| `ParentPortal.vue` | 家長手機看，iOS-style 對家長最有意義 | 中高（2364 行、影響家長日常） |
| `TeacherHomePage.vue` | 老師預設首頁，每天看 | 中 |
| `NotificationsCenter.vue` | 通知中心，iOS Notification Center 對齊 | 中 |

### Tier C — 不建議首批做（複雜表格 / 流程，動視覺易出 bug）

- `DirectorDashboard.vue`（提醒卡複雜）
- `CourseManagement.vue`（表格 + 排課 + 補課邏輯）
- `SmartCalendar.vue`（拖曳 + 多視圖）
- `StudentsList.vue`、`ClassesList.vue`（資料密集表格）
- `AttendancePage.vue`（點名核心，動了影響每天工作）
- `LearningRecordsPage.vue`（評量主畫面）

> 這些頁面要做也可以，但建議先在 Tier A 累積一套 iOS 設計 token 與元件，再批次套到 Tier B/C，避免每頁各自演化。

---

## 3. v0 Demo 建議（CEO 不用回答前可先看）

我會在這個 PR 內**只實作 Login.vue 一頁**作為 v0 demo：

- 移除目前的橘色 logo 大圓+陰影 → 改為精簡 SF-style 標題
- 移除身分選擇按鈕的 emoji → 改 SF Symbols 風（用簡單字母／圖形）
- 卡片陰影改柔和、邊框淡化
- 按鈕統一 14px 圓角
- 字體換 `-apple-system` 系統棧
- 保留 AllTrue 品牌橘為 accent，但只用在 active state 與 primary CTA
- **不改任何邏輯，只改 template + style**

CEO 看完 v0 後：
- 喜歡 → 我繼續推 Tier A 其他兩頁
- 太淡 / 要調整 → 我們在 v0 上微調，不擴散
- 不喜歡方向 → 一個 git revert 就回退，影響範圍只有登入頁

---

## 4. 設計 Token（新增到 `frontend/src/styles.css`，不取代 Porsche tokens）

```css
:root {
  /* iOS-inspired tokens — 與 --porsche-* 共存，由各頁選用 */
  --ios-font-family: -apple-system, BlinkMacSystemFont, "PingFang TC", "Microsoft JhengHei", system-ui, sans-serif;
  --ios-radius-card: 16px;
  --ios-radius-control: 12px;
  --ios-radius-pill: 999px;
  --ios-spacing-1: 8px;
  --ios-spacing-2: 12px;
  --ios-spacing-3: 16px;
  --ios-spacing-4: 24px;
  --ios-spacing-5: 32px;
  --ios-shadow-card: 0 1px 3px rgba(0, 0, 0, 0.06), 0 8px 24px rgba(0, 0, 0, 0.04);
  --ios-shadow-hover: 0 4px 12px rgba(0, 0, 0, 0.08), 0 16px 40px rgba(0, 0, 0, 0.06);
  --ios-border-faint: rgba(0, 0, 0, 0.06);
  --ios-border-default: rgba(0, 0, 0, 0.1);
  --ios-bg-page: #f2f2f7;     /* iOS systemGroupedBackground */
  --ios-bg-card: #ffffff;
  --ios-text-primary: #1c1c1e;
  --ios-text-secondary: #6e6e73;
  --ios-text-tertiary: #c7c7cc;
  --ios-accent-brand: #F57C00; /* 保留 AllTrue 品牌橘 */
  --ios-accent-blue: #007AFF;  /* iOS 預設藍，輔助 */
  --ios-ease: cubic-bezier(0.16, 1, 0.3, 1);
  --ios-touch-target: 44px;
}
```

---

## 5. 範圍與不在範圍

**在範圍（此 PR）**
- 設計提案文件（本檔）
- 新增 `--ios-*` token 到 `styles.css`
- `Login.vue` v0 改版（template + style only，邏輯不變）

**不在範圍（後續 PR）**
- ProfileCenterPage、BugReportsPage 改版
- Tier B/C 頁面
- 設計系統元件抽出（VButton / VCard 等）
- 元件庫導入

---

## 6. 驗收（v0 Login）

- [ ] Login 主要功能不變：老師/主任切換、登入、註冊、忘記密碼
- [ ] 視覺：卡片陰影柔和、字體 system stack、按鈕高度 ≥ 44px
- [ ] 響應式：手機與桌面皆可用
- [ ] CI 全綠（PHPUnit 不會影響、Vite build 必須過）
- [ ] CEO 觀察 5 分鐘後留言「繼續 / 微調 / 回退」

---

## 7. 風險與緩解

| 風險 | 緩解 |
|------|------|
| 改完 CEO 不喜歡 | v0 範圍只有 Login.vue，一個 git revert 即可 |
| 視覺與其他頁面風格不一致（過渡期） | 接受過渡期，文件聲明 v0 進行中；Tier B/C 在批准後跟進 |
| 老師/主任反映「為什麼登入頁變了」 | CHANGELOG 寫清楚是視覺更新；保留所有原本互動 |
| 行動裝置斷裂 | 在 PR description 附桌面 + 手機截圖 |

---

## 8. 後續決策點（CEO 回覆即可）

1. **v0 Login 方向 OK 嗎？** （看 PR 後回覆）
2. **Tier A 其餘兩頁（ProfileCenter, BugReports）要不要繼續？**
3. **Tier B 是否進入規劃？**（若 Yes，要不要先做 ParentPortal 因為家長量最大）
4. **是否要強制系統字體（system-ui stack）取代目前的字體設定？**

回答這四題後，我會排下一波 PR。

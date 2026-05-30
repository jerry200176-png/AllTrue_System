# AllTrue Design System — Stripe-Inspired

> **單一真相來源（Single Source of Truth）。** 所有前端頁面、元件、新功能 UI 一律照本文件生成，確保介面統一協調。
> 取代 `docs/PORSCHE_VISUAL_SYSTEM.md`（已 superseded）。
> 風格來源：`VoltAgent/awesome-design-md` 的 Stripe `DESIGN.md`，改寫為 AllTrue 補習班管理系統可落地的版本。

## 1. Intent（設計意圖）

AllTrue 的視覺方向是 **淺色優先、專業可信、為「資料與金流」而生**。

補習班管理系統的本質是後台操作工具：主任看儀表板、老師點名/填評量、家長查繳費。所以視覺語言要：

- 淺色為底（白／冷調近白），長時間在明亮辦公室看不刺眼；
- 海軍藍墨（navy ink）取代純黑，沉穩專業；
- 單一靛藍（indigo）作為唯一主行動色，克制使用；
- **金額／堂數／日期一律等寬數字（tabular）**，這是金流產品的隱性信任訊號；
- 圓角藥丸按鈕、細邊框卡片、輕陰影；
- 行銷頁那種彩色 gradient mesh **不採用**（那是 Stripe 官網行銷用，後台不需要）。

## 2. Design Principles（設計原則）

1. **淺色優先**：預設底色白／冷調近白；深色只用於側欄與夜間模式。
2. **靛藍要稀有**：`--ds-primary` 只給主 CTA、連結強調、焦點環。一個區塊只放一顆實心主按鈕。
3. **navy 取代黑**：所有內文用 `--ds-ink`（#0d253d），不用純黑 #000。
4. **色彩克制**：黑/白/灰/navy 撐起整頁；indigo 是行動，semantic 顏色只表達狀態。
5. **金額必 tabular**：任何金額、堂數、人數、百分比用 `font-variant-numeric: tabular-nums`，避免跳動。
6. **品牌一致勝於單頁花俏**：新頁面重用既有 hero / metric / panel / row / badge / button 語言，不自創。

## 3. Color Tokens（色票，定義於 `frontend/src/styles.css`）

落地時對應到既有 CSS 變數，**改 token 值即可全站換膚**（不需逐檔改色）。

| DS Token | 值（light） | 用途 | 對應既有變數 |
|---|---|---|---|
| `--ds-primary` | `#533afd` | 主 CTA、連結、焦點 | `--primary` `--accent` |
| `--ds-primary-deep` | `#4434d4` | gradient 中段、hover | `--accent-hover` |
| `--ds-primary-press` | `#2e2b8c` | 按下狀態 | — |
| `--ds-primary-soft` | `#665efd` | 圖表/UI 點綴 | `--primary-light` |
| `--ds-primary-wash` | `#eef0ff` | 淡靛藍底（tag/選中底）| `--primary-bg` |
| `--ds-ink` | `#0d253d` | 內文主色（navy，非純黑）| `--text` `--porsche-ink` |
| `--ds-ink-secondary` | `#273951` | 次要文字 | — |
| `--ds-ink-mute` | `#64748d` | 輔助文字、表頭、說明 | `--text-light` `--porsche-ink-soft` |
| `--ds-canvas` | `#ffffff` | 主白面 | `--card-bg` `--modal-bg` |
| `--ds-canvas-soft` | `#f6f9fc` | 冷調頁底、輸入底 | `--bg` `--input-bg` |
| `--ds-hairline` | `#e3e8ee` | 卡片/表格 1px 邊框 | `--border` `--porsche-border` |
| `--ds-hairline-input` | `#a8c3de` | 表單邊框 | — |
| `--ds-success` | `#1a8245` | 完成/健康/已繳 | `--success` `--porsche-green` |
| `--ds-warning` | `#b54708` | 繳費/期限/注意 | `--warning` `--porsche-amber` |
| `--ds-danger` | `#e11d48` | 破壞性/緊急（Stripe ruby 系）| `--danger` `--porsche-red` |
| `--ds-info` | `#533afd` | 資訊/導航（同 primary）| `--porsche-blue` |

> Semantic 顏色**只**用於狀態（出缺勤、繳費、審核），不可拿來當裝飾或第二主色。

## 4. Typography（字級）

- 字體沿用既有 `Inter` + `Noto Sans TC`（Stripe 的 Söhne 是商用字，Inter 是官方推薦的開源替代）。
- 中文為主的後台，標題不用 Stripe 行銷頁的 thin 300；採 **600/700** 確保中文清晰。
- 英數標題可加負字距 `-0.02em ~ -0.04em` 取得 Stripe 編輯感。
- **金額/數字**：`font-variant-numeric: tabular-nums; letter-spacing: -0.01em;`

| 角色 | size | weight | 用途 |
|---|---|---|---|
| display | clamp(28px,4vw,44px) | 700 | 頁面 hero 標題 |
| h2 / 區塊標題 | 20–22px | 700 | 卡片/區塊標題 |
| h3 | 16–18px | 600 | 小區塊 |
| body | 14–15px | 400 | 內文 |
| caption | 12–13px | 500 | 表頭、說明、meta |
| money | 依情境 | 600 | 金額（必 tabular）|

## 5. Shape & Spacing（形狀與間距）

- 圓角：input/卡片內元件 `8px`、卡片 `12px`、大面板 `16px`、按鈕/標籤 `pill 9999px`。
- 間距基準 4px：`4 / 8 / 12 / 16 / 24 / 32 / 64`。
- 卡片內距 24–32px；表格 cell 10–12px。
- 陰影輕：`0 1px 3px rgba(0,55,112,.08)`（卡片）、`0 8px 24px rgba(0,55,112,.08)`（浮層）。

## 6. Component Language（元件語言）

| 元件 | 規格 |
|---|---|
| **Button / Primary** | 藥丸；底 `--ds-primary`，字白；padding 8–16；hover→`--ds-primary-deep`，press→`--ds-primary-press`。一區塊一顆。 |
| **Button / Secondary** | 藥丸；白底、`--ds-primary` 字與 1px 邊。 |
| **Button / Ghost** | 透明底、`--ds-ink` 字、`--ds-hairline` 邊。 |
| **Button / Danger** | 藥丸；底 `--ds-danger`，字白。 |
| **Input** | 白底、`--ds-hairline-input` 1px 邊、圓角 6–8；focus 邊框換 `--ds-primary` + 3px wash 外環。 |
| **Card** | 白底、`--ds-hairline` 1px 邊、圓角 12、Level-1 陰影；hover 邊框加深。 |
| **Metric tile** | 白底；大號 tabular 數字 + 小寫 uppercase 標籤；底部可 2–3px 類別線。 |
| **Badge / Pill** | 藥丸；semantic 色只用於狀態（amber 繳費/期限、green 完成、red 緊急、indigo 資訊）。 |
| **Table** | 表頭 `--ds-ink-mute` uppercase；金額欄靠右 + tabular；row hover `--ds-canvas-soft`。 |
| **Dashboard mockup/panel** | 白底細邊框面板，內含表格/圖；陰影 Level-2。 |

## 7. Forbidden Patterns（禁止）

- 純黑 `#000` 內文（用 navy `--ds-ink`）。
- 橘色舊主色當 CTA（已全面換 indigo）。
- 行銷頁彩色 gradient mesh 進後台。
- 一個元件超過兩個 accent 色。
- 金額/數字不套 tabular。
- 硬寫新色票（已有 `--ds-*` token 就用 token）。
- 頁面自創、無法對回本文件的視覺實驗。

## 8. Token-level Rebrand 策略（為何能全站統一）

全站既有頁面都消費 `--primary` / `--accent` / `--text` / `--porsche-*` 等 CSS 變數。本系統把這些變數**重新指向 `--ds-*` Stripe 值**，因此：

1. **地基 PR** 改 token 值 → 全站立即換成 Stripe 觀感（最高槓桿、低風險、純 CSS）。
2. **逐頁 PR** 再精修：按鈕改藥丸、金額套 tabular、hero/metric 對齊規格。

這是 Stripe / Shopify Polaris / GitHub Primer 做品牌改版的標準路徑：先換 token，再逐面精修，絕不一次改 62 檔。

## 9. Rollout Order（逐頁順序，每頁一個小 PR）

1. `DirectorDashboard`、`CourseManagement`（reference pages）
2. `StudentsList`
3. `TeachersList`
4. `TuitionCollectionPage`、`TuitionReportPage`、`PayReportPage`（金流，重點 tabular）
5. `AttendancePage`
6. `LearningRecordsPage`
7. `SmartCalendar`、其餘頁面與 modal

每頁 PR 必含：本地 `npm run build` → PR CI 綠 → merge → deploy success → `/api/v1/health` OK → 前端有改時確認 `/version.json` 更新。

進度追蹤見 GitHub Epic issue（逐頁子 issue + Project 看板）。

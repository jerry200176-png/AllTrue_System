# 學生課程頁面 IA 重設計方案 — 2026-08-29

> Issue: [#2007](https://github.com/jerry200176-png/AllTrue_System/issues/2007)  
> 狀態：提案，待產品／主任確認後實作  
> 範圍：`frontend/src/pages/StudentsList.vue` 展開後的學生課程視圖

## 目的

目前的學生課程頁面可以找到資料，但主任需要在同一個展開區塊裡同時辨識「有幾門課、哪一門需要處理、堂數還剩多少、付款狀態、排課與歷史紀錄」。這讓頁面像資料堆疊，而不是一個能快速做決策的工作介面。

本方案把頁面第一眼收斂成四個答案：

1. 這位學生目前有幾門進行中的課程？
2. 哪一門最需要我現在處理？
3. 下一步是續報、編輯、確認付款，還是暫時不用處理？
4. 其他資料要去哪裡找，而不是全部同時攤開？

這是管理者／主任工作頁，不把 XP、連續學習天數、排行榜等學習產品機制帶進來。可愛與鼓勵感保留在老師與學生的學習導向頁面；帳務、出勤、個資與高風險操作維持專業、克制、可追溯的企業介面。

## 目前基線與問題

2026-08-28 已上線第一階段：active course 已從九欄內嵌表格改為 task-first cards，保留既有資料與 mutation handlers，並提供 honest session progress、月結 cadence、primary action 與 `更多操作`。這個基線是可保留的，不重新打開已收斂的付款／課程動作。

下一階段只處理資訊架構與視覺層級：

- 多門課仍是單一長欄堆疊，缺少「目前課程總覽」與選擇成本低的導覽。
- 課程狀態、下一步與細節仍混在同一張卡片，主任要讀完多個欄位才能判斷是否需要處理。
- 歷史課程雖可折疊，但和 active course 的關係不夠清楚；資料多時會拉長展開區塊。
- 目前沒有針對「正常、需要關注、資料未完整、月結」的共同視覺語法與排序規則。

## 研究與取捨

### 官方／產品行為

- Duolingo 的新版首頁以單一路徑、明確的下一步與進度回饋降低選擇成本；本方案只借用「下一步清楚、進度可讀」的原則，不把學習遊戲化套到主任工作台。
  - [Duolingo: New Duolingo Home Screen Design](https://blog.duolingo.com/new-duolingo-home-screen-design/)
  - [Duolingo: Improving the Streak](https://blog.duolingo.com/improving-the-streak/)

### 維護中的設計系統

- GitLab Pajamas 的 progress bar 以文字標籤與數值共同表達進度，避免只靠顏色。
- Primer 以一致的 content、action 與 disclosure 元件建立由摘要到細節的層次。
  - [GitLab Pajamas Progress Bar](https://design.gitlab.com/components/progress-bar/)
  - [Primer Components](https://primer.github.io/design/components/)

### 維護中的開源產品

- GibbonEdu 的學生 context 將 timetable、attendance 與學生主檔分層，讓使用者先定位學生，再進入特定工作檢視。研究版本：`v31.0.00`，commit `76b5286f81e17dcf793ab7357e410dc2a4dcd00ca4`，GPL-3.0。
- Frappe 將 list 的主要 action／filter 與 record detail 分開，降低同一層級同時出現太多操作。研究版本：commit `013f68771ac342c70dc5886c9fe94b50e74fcacb`，MIT。
- 本提案只採用資訊架構原則，不複製程式碼或品牌視覺。

### AllTrue 適配

- 沿用 `docs/RULE_DESIGN_SYSTEM.md` 的 `--ds-*` token、現有 `At*` 元件、44px 操作高度與 light/dark theme。
- 不改 API、資料庫、權限、分校隔離、付款規則、出勤／堂數計算與既有 mutation handler。
- 高風險動作（付款狀態、帳單、刪除、結案）仍留在明確的次要操作區，不能因視覺簡化而隱藏確認與 audit 邊界。

## 提案 IA

```text
學生主檔
├─ 學生姓名／在學狀態／家長／身份綁定
├─ 課程總覽（只顯示摘要）
│  ├─ 進行中 2 門 · 1 門需要處理 · 歷史 3 筆
│  └─ [所有進行中課程] [需要處理]
├─ 目前課程工作區
│  ├─ 課程 A（預設選中：需要處理）
│  │  ├─ 狀態 + 下一步 + 進度／月結節奏
│  │  ├─ 老師／時段／地點
│  │  └─ [續報加購] [更多操作]
│  └─ 課程 B（摘要列，可展開）
│     ├─ 狀態 + 剩餘堂數／月結
│     └─ [編輯課程]
└─ 歷史課程（獨立 disclosure，不和 active course 混排）
```

### 桌面 wireframe

```text
┌─ 林小明  在學中 ───────────────────────────────┐
│ 家長：王小姐    本分校    RFID 已綁定             │
├─ 課程總覽 ─────────────────────────────────────┤
│ 進行中 2 門   需要處理 1 門   歷史 3 筆            │
│ [所有課程] [需要處理]                              │
├─ 今天先處理 ─────────────────────── [續報加購] ──┤
│ 英文・一對一       即將用完                       │
│ 剩餘 2 / 8 堂   ██████░░░░   已使用 6 堂           │
│ 老師 林老師 · 週二 18:00–20:00 · 大安             │
│ 付款 未繳 · $1,100／堂                            │
│ [更多操作]                                        │
├─ 其他進行中課程 ────────────────────────────────┤
│ 數學・一對二       剩餘 6 / 12 堂        [編輯課程] │
├─ 歷史課程 3 筆 ▾ ───────────────────────────────┤
└──────────────────────────────────────────────────┘
```

### 手機 wireframe

```text
學生主檔                         ⋮
在學中 · 進行中 2 門

課程總覽
進行中 2    需處理 1    歷史 3
[需要處理]

今天先處理
英文・一對一
即將用完
剩餘 2 / 8 堂
██████░░░░
林老師 · 週二 18:00–20:00
[續報加購]
[查看課程詳情]

其他進行中課程 1 門 ▾
歷史課程 3 筆 ▾
```

## 狀態與排序規則

排序只使用現有資料，不新增推測分數：

1. 需要主任立即處理：session-based 剩餘堂數低於既有 warning threshold，或付款狀態為 overdue／unpaid 且目前已由現有 API 提供。
2. 資料待確認：堂數未設定、未指派老師、未排定時段或未指定地點；顯示白話原因與 `編輯課程`。
3. 正常進行中：有完整課程資料，按既有 active course 順序呈現。
4. 月結課程：顯示月結節奏，不製造假的堂數百分比。
5. 歷史課程：永遠在獨立 disclosure，除非使用者主動展開，不佔用 active work area。

每一門課的摘要都必須回答「狀態、進度／節奏、下一步」；不能只靠 badge 顏色，也不能把 warning 變成沒有依據的 AI 建議。

## 視覺語言

- 外框與頁面：延續 bank-like 的清楚底色、navy 文字、單一橘色 primary CTA、細緻 border 與 restrained shadow。
- 進度：使用 token 化的 primary／warning semantic color，加上數值和文字說明；不使用彩虹色或裝飾性百分比。
- 層級：摘要列 → selected work card → disclosure details。每層只放該層需要做決策的資訊。
- 溫度：在課程進度與成功完成等學習導向語句可使用輕微暖色與柔和插圖語氣；不在付款、個資、刪除、結案或出勤衝突中放 mascot、遊戲徽章或慶祝動畫。
- 動畫：只允許低幅度 expand/focus transition；`prefers-reduced-motion` 必須停用非必要 motion。

## 驗收條件

實作前需先確認本提案；核准後另開 implementation PR，並至少驗收：

- 390、412、768、1280、1440px：主要 CTA 不需水平滾動即可找到，`scrollWidth <= clientWidth`。
- 正常、多門課、需要處理、月結、堂數未設定、無 active course、歷史展開、loading、API error、長姓名／長備註。
- 所有摘要狀態有文字，不只靠色彩；progressbar 有正確 `aria-valuenow`／`aria-valuemax`。
- 課程切換、details、主要 CTA、更多操作皆可鍵盤操作，focus visible；手機操作高度維持至少 44px。
- 既有 edit、renew/add sessions、payment status、invoice、payment info、settle、delete handler 與 payload 不變。
- `npm run lint:no-undef`、`npm run build`、UI foundation Playwright；production 只做 read-only desktop/mobile smoke 與 version/health readback。

## 分階段交付

1. 本文件：研究、IA、wireframe、狀態規則，待主任／產品確認。
2. Phase 2A：只做課程總覽與 active-course selection，不改資料與 action contract。
3. Phase 2B：整理 course detail disclosure、歷史區與 mobile layout。
4. Phase 2C：補齊 state matrix、Playwright、production evidence；比較首次找到下一步的時間與 `更多操作` 使用情況，若沒有既有 telemetry 則只記錄 read-only evidence，不臆造 KPI。

本方案通過前，不把 #2007 視為完成，也不以單一 screenshot 宣稱整頁已經達到大公司產品品質。

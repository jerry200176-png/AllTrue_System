# UX：3A 級高質感但可操作的營運後台

## 1. 設計原則

「像 3A 遊戲大作」不等於堆滿特效，而是：

- 進畫面立刻知道任務目標。
- 重要資訊像 HUD 一樣固定、清楚、可掃描。
- 每次操作都有即時回饋。
- 有深度、光影、動效，但不犧牲可讀性。
- 使用者永遠知道「我現在在哪、下一步做什麼、剛才改了什麼」。

## 2. 視覺語言

### Theme：AllTrue Command Center

| 元素 | 規格 |
|---|---|
| 背景 | 淺色主題保留，加入極淡藍紫漸層與 mesh glow；不可影響文字 |
| 卡片 | glass-lite：白底 92–96% opacity + 柔和陰影 + 1px 邊框 |
| 重點卡 | 使用細光邊，不用大面積霓虹 |
| 狀態色 | 綠=完成/安全；琥珀=待處理；紅=危險/逾期；藍=資訊/下一步 |
| 字體層級 | 任務標題 22–28px；HUD 數字 28–36px；正文 14–16px |
| 圖示 | Material Symbols 為主，避免混用 emoji 作為核心狀態 |

## 3. Motion System

| 情境 | 動效 | 限制 |
|---|---|---|
| Modal 開啟 | 120–180ms scale + fade | reduced-motion 時只 fade |
| Preview 產生 | skeleton → content reveal | 不用旋轉大 loading |
| 成功 receipt | subtle glow sweep + check icon | 不用 confetti，避免幼稚 |
| 危險警告 | card border pulse 1 次 | 不循環閃爍 |
| 表格 hover | row lift 1px + background tint | 保持高效能 |

必須支援 `prefers-reduced-motion`。

## 4. 續報 Wizard UX

### Step 1：選擇操作

頁面：CourseManagement / StudentsList 課程列。

CTA 文案：

- 堂數制剩 ≤2 堂：`續報新批次`
- 堂數制一般：`新增購買批次`
- 月結制：`月結續約`
- 補課/補登：保留獨立文案，不與續報混用。

### Step 2：輸入參數

Modal 左側：輸入堂數 / 開始日 / 延長月數。  
Modal 右側：即時摘要。

防呆：

- 堂數不可為 0。
- 開始日若早於最後一堂，顯示 warning。
- 月結到期日若不比現有 EndDate 晚，blocked。

### Step 3：Preview

顯示三張卡：

1. 舊課程會怎樣  
   例如：剩 0 堂、已繳費，續報成功後會自動結案。

2. 新課程/新月份會怎樣  
   例如：建立 8 堂，首堂 2026-05-04，末堂 2026-06-23。

3. 收款會怎樣  
   例如：應收 NT$4,800，狀態為未繳費；或免費課程 NT$0 已結算。

### Step 4：Confirm

按鈕文字不可只寫「確認」：

- `確認建立新批次`
- `確認月結續約`
- `返回修改`

如果有 warning，確認按鈕改琥珀色，並要求勾選「我已確認不是重複續報」。

### Step 5：Receipt

成功後顯示：

- 操作結果一句話。
- 新課程 / 帳單 ID。
- 建立堂次數。
- 下一步 CTA：`查看新批次`、`前往核帳`、`回到課程列表`。

## 5. 複雜流程 UX Audit 規格

第一輪盤點頁面：

| 頁面 | 常見複雜點 | 預期改善方向 |
|---|---|---|
| CourseManagement | 續報、補課、請假、調課、結案混在一起 | 動作分區 + preview/receipt |
| StudentsList | 學生資料、課程、核帳、CSV 匯入混在一起 | 課程列 action 分組 + 狀態 badge |
| SmartCalendar | 基底課、補課、調課、代課一起顯示 | 圖例、filter、衝突卡、hover detail |
| NotificationsCenter | 通知可讀、可解除、可核帳 | 通知類型分組 + inline next action |
| LearningRecordsPage | 老師填寫與主任審核語意不同 | 分角色模式 + 審核 receipt |
| AttendancePage | 點名、刷卡、自修、異常狀態多 | 狀態說明 drawer + 一鍵篩異常 |

## 6. Page Blueprint

### CourseManagement Command Header

上方固定 4 張 HUD 卡：

- 待續報
- 未繳費
- 今日異常
- 已暫停/已結案

每張卡：

- 大數字
- 1 行說明
- CTA 或 filter chip
- 狀態色光邊

### Course Row

每筆課程 row 的 action 分成：

| 區塊 | 動作 |
|---|---|
| 日常 | 詳情、編輯、補課/補登 |
| 續報/收款 | 續報新批次、月結續約、核帳 |
| 狀態 | 暫停、恢復、結案 |
| 危險 | 刪除 |

## 7. Design Tokens

建議新增 CSS token，不引入大型 UI 套件：

| Token | 用途 |
|---|---|
| `--at-bg-command` | Command Center 背景 |
| `--at-card-glass` | glass-lite 卡片 |
| `--at-glow-info` | 資訊光邊 |
| `--at-glow-warning` | 警告光邊 |
| `--at-glow-danger` | 危險光邊 |
| `--at-motion-fast` | 120ms |
| `--at-motion-base` | 180ms |
| `--at-radius-xl` | 高質感卡片圓角 |

## 8. Accessibility Guardrails

- 文字對比 ≥ 4.5:1。
- 玻璃/發光只當背景，不承載唯一資訊。
- 色彩搭配文字/icon，不只靠顏色判斷。
- Modal focus trap。
- ESC 可關閉非破壞性 modal。
- Confirm button loading 時不可重複送出。

## 9. 第一批可交付 UI

P0：

- `RenewalPreviewModal`
- `ActionReceiptModal`
- `CourseActionMenu` 分區

P1：

- CourseManagement Command Header
- StudentsList 課程列狀態 badge 統一

P2：

- 全站 premium shell / background
- 通知中心任務式卡片
- SmartCalendar HUD 圖例

## 10. Exit Checklist

- [x] 版面層次已定義。
- [x] 色彩/動效/空狀態/loading/防呆已定義。
- [x] 3A 風格已轉成可維護規格。
- [x] 無障礙與 reduced motion 已納入。
- [x] 已明確不做會影響可讀性的重度特效。


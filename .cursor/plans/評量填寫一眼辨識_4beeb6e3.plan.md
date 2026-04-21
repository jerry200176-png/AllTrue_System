---
name: 評量填寫一眼辨識
overview: 在學習評量表（[`LearningRecordsPage.vue`](frontend/src/pages/LearningRecordsPage.vue)）的既有分組表格上，用「內容是否為空」區分老師是否已填寫，並在學生群組標題加彙總，讓主任一打開列表就能掃到未填筆數；以純前端判斷即可對齊目前 `ensure-past` 產生的空殼待審列。
todos:
  - id: helper-filled
    content: 在 LearningRecordsPage.vue 新增 hasLearningRecordBody(record) 與（可選）列／群組用的顯示標籤邏輯
    status: completed
  - id: table-column
    content: 表格新增「填寫」欄 badge +（可選）未填列 tr 強調樣式
    status: completed
  - id: group-summary
    content: filteredGroupedRecords / grouped 資料結構加上 unfilled_body_count，summary 顯示「N 未填」pill
    status: completed
  - id: optional-sort-filter
    content: （可選）同組未填列排序、主任「只看未填」toggle
    status: completed
  - id: deploy-verify
    content: npm run deploy + 手動驗證主任／老師、待審／已核准列顯示
    status: completed
isProject: false
---

# 學習評量表：一眼看出誰有填／誰沒填

## 背景與根因

- 列表資料來自 [`GET /api/v1/learning-records`](backend/app/Http/Controllers/LearningRecordController.php)（[`index`](backend/app/Http/Controllers/LearningRecordController.php)），**只會列出已存在的 `LearningRecord` 列**。
- 系統會用 [`ensurePastRecords`](backend/app/Http/Controllers/LearningRecordController.php) 對過去堂次補建列：`Content => ''`、`Status => 'pending'`（約 1203–1213 行）。這類列與老師已寫完、同樣是「待審核」的列，在 UI 上無法區分。
- 老師在課表區塊已有「未填」語意（[`scheduleStatusLabel`](frontend/src/pages/LearningRecordsPage.vue) 的 `missing` → `未填`、週檢視 [`missingCount`](frontend/src/pages/LearningRecordsPage.vue)），但**下方依學生分組的表格**沒有對應資訊。

本計畫假設「沒寫」= **該筆評量主檔存在，但老師尚未填寫有意義的評量文字**（與審核狀態分開顯示）。若未來要涵蓋「已上課但 DB 完全沒有 LearningRecord」需另做排課／堂次與 API 的 join，建議列為可選第二階段，本次不強制。

## 實作方向（以前端為主）

**檔案**：[`frontend/src/pages/LearningRecordsPage.vue`](frontend/src/pages/LearningRecordsPage.vue)

### 1. 共用判斷函式 `hasLearningRecordBody(record)`

- 將下列欄位 `trim` 後合併判斷是否「有內容」：`Content`、`Progress`、`NextHomework`、`Comment`、`QuizScore`（若專案實務會把重點寫在 `Content` 也要涵蓋）。
- **僅在** `Status` 為 `pending` 或 `changes_requested` 時，用此結果驅動「未填／已填」標籤；`approved` / `rejected` 顯示「—」或省略強調（避免已核准列仍佔視覺）。
- 註解簡短說明：與 `ensure-past` 空殼列對齊，若日後調整欄位規則可改此處。

### 2. 表格：新增「填寫」欄（或等效標籤）

- 在表頭增加一欄（建議名稱：**填寫**）。
- 每列顯示小型 badge：
  - **未填**：`pending`/`changes_requested` 且 `!hasLearningRecordBody`（建議 class：高對比、例如沿用現有 warn 色系，與 [`status-tag`](frontend/src/pages/LearningRecordsPage.vue) 風格一致）。
  - **已填**：同上狀態且 `hasLearningRecordBody`（較低調的 success / neutral）。
  - **—**：`approved` / `rejected`。
- 可選加強：`tr` 加 class（例如左側色條）僅針對「待審／需修改且未填」，讓掃視更快（與 badge 二選一或並用，避免過花）。

### 3. 學生群組 summary：彙總「N 未填」

- 在 [`filteredGroupedRecords`](frontend/src/pages/LearningRecordsPage.vue) 建 group 時，除既有 `pending_count` 外，增加 **`unfilled_body_count`**（在當前 `reviewTab`／`teacherFilterTab` 篩選後的列上計算）：符合「待審／需修改且 `!hasLearningRecordBody`」的筆數。
- 在 `<summary class="lr-group-summary">` 內，若 `unfilled_body_count > 0`，顯示與老師週檢視類似的 pill，例如 **`{{ unfilled_body_count }} 未填`**，讓主任未展開也能先看到哪位學生有缺口。

### 4. 可選 UX（視實作時間取捨）

- **同組排序**：同一學生底下將「未填」列排在前，方便展開後先處理（僅影響顯示順序，不影響資料）。
- **主任「待審佇列」快速篩選**：在主任 tabs 旁加 toggle「只看未填」，以 `filteredRecords` 再 filter 一層（純前端）。

### 5. 上線與測試

- 修改 `frontend/src/**` 後依專案規則執行：`cd frontend && npm run deploy`。
- 手動驗證：`ensure-past` 產生的空 `Content` 待審列顯示「未填」；老師填過 Progress/Comment 後顯示「已填」；群組 summary 數字與表格一致；老師／主任兩種角色表格皆正常。

## 風險與邊界

- **誤判**：若老師只打算上傳附件、文字全空，會被判為未填——可接受或日後再加 `AttachmentUrl` 納入判斷。
- **分頁**：目前 [`fetchRecords`](frontend/src/pages/LearningRecordsPage.vue) 使用 `per_page=200`；超過一頁時 summary 僅反映已載入列（與現況一致）。若要全校區完整統計需後端 aggregate，屬擴充範圍。

## 第二階段（可選，本次不實作除非你需要）

- 新 API：依 `ClassSession`（已結束、`attended` 等）與 `LearningRecord` 左連接，回傳「應填未建檔」堂次，主任頁另開「缺評量堂次」區塊。牽涉 [`ClassSession`](backend/app/Models/ClassSession.php)、權限與效能，與本需求分開規劃較妥。

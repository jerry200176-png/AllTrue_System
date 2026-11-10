---
name: learning-record-session-number
overview: 為學習評量加入可對外顯示的堂次序號，讓老師匯出評量圖與家長端查看時都能明確知道該評量對應課程的第幾堂，並以單一後端口徑避免不同頁面顯示不一致。
todos:
  - id: backend-api-session-number
    content: "[FEATURE] 後端統一提供 learning record 堂次序號欄位，覆蓋老師端 learning records 與家長端 parent dashboard，並對齊既有堂次商業口徑"
    status: completed
  - id: frontend-teacher-export
    content: "[FEATURE] 老師端學習評量匯出圖改為顯示真正的「第 X 堂」，不可再以匯出順序編號冒充堂次"
    status: completed
  - id: frontend-parent-ui
    content: "[FEATURE] 家長端學習評量列表卡片與展開詳情顯示「第 X 堂」，並維持分頁、篩選與手機版可讀性"
    status: completed
  - id: ui-ux-polish
    content: "[FEATURE/UI-UX] 依 PRD 第 5b 節精緻化老師匯出圖與家長端堂次標示的版面層次、色彩、空狀態、loading、一致回饋與響應式表現"
    status: completed
  - id: test-design
    content: "[TEST] 設計並補齊 API、匯出圖、家長端顯示的測試案例，覆蓋多課程、請假、補課、分頁與降級策略"
    status: completed
  - id: qa-validation
    content: "[QA] 依 PRD 第 10 節執行 Happy Path / Edge / Error / UI-UX 驗收清單，確認老師端與家長端堂次一致"
    status: completed
  - id: security-review
    content: "[REVIEW/資安] 確認 teacher/parent 權限邊界、校區隔離與資料來源一致，避免跨學生或跨課程堂次洩漏"
    status: completed
  - id: code-review
    content: "[REVIEW] 對後端堂次口徑與前端顯示調整執行 code review，特別檢查請假/補課/approved-only 回歸風險"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 docs/CHANGELOG.md 與必要操作說明，記錄學習評量新增堂次顯示規格"
    status: completed
  - id: deploy-release
    content: "[Ops] 完成部署順序驗證，前端執行 npm run deploy，確認 backend/public 的 index.html 與 assets 同步"
    status: completed
  - id: ui-signoff
    content: "[UI/UX Designer] 依 PRD 第 5b 與第 10 節完成 UI/UX sign-off"
    status: completed
  - id: pm-signoff
    content: "[PM] 確認需求口徑、開放問題與 DoD 全數完成後進行 PM sign-off"
    status: completed
isProject: false
---

# 學習評量匯出圖顯示堂次 PRD

## 1. 文件資訊
- 功能名稱：學習評量顯示「第幾堂」
- 版本 / 日期：v1.0 / 2026-04-16
- 狀態：Draft
- 目標角色：家長、老師、主任

## 2. 目標與業務背景
- 痛點：目前老師匯出的評量圖片只有日期、時段、科目與評量內容，家長看到圖片時無法直接判斷這是該課程的第幾堂；家長端頁面也沒有明確標示堂次，造成溝通成本增加。
- 業務價值：讓家長在收到評量圖或於家長端查看時，能快速理解課程進度；老師與櫃檯在對話時也能直接引用「第 X 堂」降低誤解。
- 成功指標（KPI）：
  - 匯出評量圖 100% 顯示堂次序號。
  - 家長端學習評量卡片與展開詳情 100% 顯示相同堂次序號。
  - 老師端與家長端對同一筆評量顯示的堂次序號一致率 100%。

## 3. 範圍
- In Scope：
  - 在老師端學習評量單筆匯出圖與批次匯出圖顯示「第 X 堂」。
  - 在家長端學習評量列表卡片與展開詳情顯示「第 X 堂」。
  - 由後端統一提供學習評量的堂次序號欄位，供老師端與家長端共用。
  - 堂次口徑以現有「詳情/課程端對第幾堂的理解」為基準，預設採該學生該課程合約內的非請假堂次序號。
- Out of Scope：
  - 不調整核准評量扣堂機制。
  - 不新增家長端匯出圖片功能。
  - 不改動主任提醒、帳務、出缺勤扣堂規則。
  - 不重做學習評量整體 UI 架構。

## 4. RACI
- PM：A
- CTO / 工程：R
- UI/UX Designer：R
- QA：R
- 資安：C
- IT / Ops：I
- UI/UX Designer 職責說明：負責老師端匯出圖與家長端評量卡片的資訊層級、標籤視覺、空狀態、載入狀態、互動回饋與行動裝置可讀性，並完成 sign-off。

## 5. User Stories
- As a 家長, I want 在家長端學習評量上直接看到第幾堂, so that 我能理解孩子目前課程進度。
  - Acceptance Criteria：
  - [ ] 家長在學習評量列表每筆卡片可看到「第 X 堂」。
  - [ ] 家長展開評量詳情時，堂次資訊與列表一致。
  - [ ] 同一筆評量於重新整理後仍顯示相同堂次。
- As a 老師, I want 匯出的評量圖片標示第幾堂, so that 家長收到圖片時能直接辨識課程進度。
  - Acceptance Criteria：
  - [ ] 老師單筆下載圖檔時，圖上顯示「第 X 堂」。
  - [ ] 老師批次匯出時，每張學生圖上的各筆評量都顯示正確堂次。
  - [ ] 圖上的「第 X 堂」不是匯出排序編號，而是課程堂次序號。
- As a 主任, I want 老師端與家長端看到相同堂次, so that 校方對外口徑一致。
  - Acceptance Criteria：
  - [ ] 同一筆 approved learning record 在老師端與家長端顯示相同堂次。
  - [ ] 請假堂不會產生誤導性的堂次跳號顯示。

## 5b. UI/UX 精緻化需求
- 老師端匯出圖：
  - 版面層次：將「第 X 堂」放在每筆評量區塊標題列，優先級高於科目、低於日期時段，避免與現有 `#1/#2` 輸出序號混淆。
  - 色彩一致性：沿用現有藍色系標題條與狀態 chip，不新增突兀色票；堂次標籤使用中性或品牌輔助色。
  - 互動回饋：下載成功/失敗 toast 維持現有位置與時長；若缺少堂次資料須有保守顯示策略，避免輸出空白異常。
  - 空狀態設計：若單筆評量缺少可判定堂次資料，圖片仍可匯出，但不得顯示錯誤占位字樣；需有明確降級文案策略。
  - 載入狀態：沿用現有下載中狀態，避免新增造成 layout shift。
  - 防呆設計：若匯出前資料尚未帶到堂次欄位，不可顯示錯誤的匯出順序編號來冒充堂次。
  - 響應式 / 行動裝置：本次匯出圖為固定寬度圖片，不需額外手機版配置，但匯出觸發按鈕行為需保持手機可點擊。
- 家長端學習評量頁：
  - 版面層次：在卡片 header 或 meta 區顯示「第 X 堂」，與日期/時段並列但視覺上清楚區隔。
  - 色彩一致性：沿用家長端現有卡片風格，堂次標示應與科目/教師資訊協調，不可搶過分數與學習內容。
  - 互動回饋：切換展開/收合時堂次資訊固定可見，不因展開狀態而消失。
  - 空狀態設計：無學習評量時維持既有圖示 + 說明；不可為了堂次功能新增多餘空白欄位。
  - 載入狀態：初次載入與載入更多時，堂次位置應保留一致，避免卡片抖動。
  - 防呆設計：若堂次暫無法判定，顯示策略需經 PM/設計確認，避免家長誤解為系統錯誤。
  - 響應式 / 行動裝置：家長端需支援手機查看，堂次標示不可造成 header 換行混亂或水平溢出。

## 6. 功能需求（FR）
- FR-001：系統應為 learning record 提供對應課程的堂次序號欄位，供前端直接讀取。
- FR-002：系統應以單一規則計算堂次序號，並在老師端與家長端使用相同口徑。
- FR-003：老師端單筆匯出評量圖片時，圖片每筆評量應顯示「第 X 堂」。
- FR-004：老師端批次匯出評量圖片時，每筆評量應顯示「第 X 堂」，且不得以匯出排序代替。
- FR-005：家長端學習評量列表卡片應顯示「第 X 堂」。
- FR-006：家長端學習評量展開詳情應顯示與卡片一致的「第 X 堂」。
- FR-007：當 learning record 屬於請假或不應占堂次的情境時，系統應依既有堂次語意處理，不得產生錯誤序號。
- FR-008：若堂次資料暫時不可判定，系統應採一致降級策略，不得顯示具有誤導性的編號。

## 7. 非功能需求（NFR）
- API 效能：既有學習評量列表與家長端 dashboard 查詢增加堂次欄位後，單次請求應維持可接受範圍，目標不超過既有平均延遲 +20%。
- 一致性：同一筆 learning record 不得因分頁、排序、匯出批次範圍不同而得到不同堂次。
- 降級策略：若單筆資料缺少可計算堂次的必要關聯，前端應顯示保守文案或不顯示堂次，且不可回退成匯出序號。
- 相容性：不得破壞現有家長端 learning_records 分頁、老師端匯出、主任審核流程。

## 8. 技術方向
- 受影響頁面：
  - [LearningRecordsPage.vue](frontend/src/pages/LearningRecordsPage.vue)
  - [ParentPortal.vue](frontend/src/pages/ParentPortal.vue)
- 受影響前端匯出模組：
  - [learningRecordExport.js](frontend/src/lib/learningRecordExport.js)
- 受影響後端 API / 模組：
  - [LearningRecordController.php](backend/app/Http/Controllers/LearningRecordController.php)
  - [ParentPortalController.php](backend/app/Http/Controllers/ParentPortalController.php)
  - [api.php](backend/routes/api.php)
  - [LearningRecord.php](backend/app/Models/LearningRecord.php)
  - [ClassSession.php](backend/app/Models/ClassSession.php)
  - [StudentClass.php](backend/app/Models/StudentClass.php)
- 架構選擇：
  - 堂次序號應由後端統一產出，而不是由老師端或家長端各自前端計算，因為家長端有分頁、老師端有單筆與批次匯出，若前端自行推導容易出現口徑不一致。
  - 堂次口徑應對齊現有課程端對「第幾堂」的商業理解，避免同一堂課在不同頁面出現不同編號。
  - 匯出圖現有 `#1/#2` 是輸出順序，不可沿用作堂次；需改為明確區分「堂次」與「區塊序號」或僅保留堂次資訊。
- Migration：
  - 預設不新增資料表欄位，先以查詢期動態提供堂次序號。
  - 若效能評估不足，再列為後續 P1 選項評估快取或持久化欄位。
- 子任務 Agent 派發：
  - `[FEATURE]`：後端回傳堂次欄位、前端老師匯出與家長端呈現。
  - `[TEST]`：設計 API/前端匯出/家長端回歸測試。
  - `[REVIEW]`：確認堂次口徑、請假/補課/分頁與多課程情境無回歸。
  - `[DOCS]`：更新 `docs/CHANGELOG.md` 與操作說明。

## 9. 資安與存取控制
- 存取角色：
  - 老師/主任：既有 learning records API 使用 role middleware 與 `require_campus`，僅可見授權資料。
  - 家長：僅能透過 parent session 查看自己學生的 approved learning records。
- PII / 敏感資料：堂次序號本身非敏感資料，但需避免跨學生、跨課程、跨校區誤帶。
- 稽核 log：本次若僅為讀取顯示欄位，原則上不新增稽核 log；如後續新增下載紀錄需求再另案規劃。
- STRIDE 快評：
  - Spoofing：沿用既有 teacher / parent 認證機制。
  - Tampering：不可讓前端自造堂次值覆寫後端真值。
  - Info Disclosure：需確保家長端只看到自己學生的堂次，不可混入他課程或他學生資料。

## 10. QA 驗收標準與測試計畫
- FR-001 / FR-002：
  - Happy Path：同一筆評量於老師端 API 與家長端 API 回傳相同堂次序號。
  - Edge Case：同一學生有多門課、同日期不同課程時，堂次各自獨立。
  - Error Case：缺少關聯堂次資料時，回應符合降級策略且不回傳誤導數字。
  - 回歸測試：請假、補課、作廢評量、approved-only 家長端查詢。
- FR-003 / FR-004：
  - Happy Path：單筆與批次匯出圖片顯示正確「第 X 堂」。
  - Edge Case：同一學生同次批次匯出多筆評量時，各筆堂次不受匯出排序影響。
  - Error Case：匯出失敗時仍顯示既有下載失敗回饋，不因堂次欄位新增而中斷。
  - 回歸測試：現有匯出欄位如日期、科目、週考、作業、評語仍正常。
- FR-005 / FR-006：
  - Happy Path：家長端卡片與展開詳情均可見相同堂次。
  - Edge Case：learning_records 分頁載入更多後，先前與新增資料堂次顯示一致。
  - Error Case：無已核准評量時維持既有空狀態。
  - 回歸測試：家長端分科篩選、展開收合、手機版顯示正常。
- FR-007 / FR-008：
  - Happy Path：不占堂次的請假情境不顯示錯誤累計序號。
  - Edge Case：補課、調課、非連續日期堂次仍維持正確序號。
  - Error Case：無法判定堂次時不顯示假序號。
- UI/UX 驗收清單：
  - [ ] 空狀態有圖示 + 說明 + CTA，非空白或純文字。
  - [ ] 所有非同步操作有 loading 狀態，無 layout shift。
  - [ ] 成功 / 失敗操作有明確 toast 或 inline 回饋。
  - [ ] 表單防呆與下載流程文案維持正向且一致。
  - [ ] 色彩 / 間距 / 字型層次符合既有設計語彙。
  - [ ] 若有危險操作不新增無確認流程的風險。
  - [ ] 行動裝置無水平 overflow，觸控可用。

## 11. 上線與維運
- 部署步驟：
  - 後端 API 變更先部署。
  - 前端完成後執行 `cd frontend && npm run deploy`，確保 `index.html` 與 `assets` 同步。
  - 驗證老師端匯出與家長端 dashboard API 正常。
- 監控新增項目：
  - 觀察 learning records 查詢效能是否因堂次計算明顯下降。
  - 觀察家長端 dashboard 首屏是否因欄位增加出現卡頓。
- 回滾方案：
  - 前端可先回退堂次顯示 UI。
  - 後端可暫時移除輸出欄位但保留既有查詢結構，避免破壞現有頁面。

## 12. 里程碑與優先級
- P0（Must Have）：
  - 後端統一提供 learning record 堂次序號。
  - 老師端匯出圖顯示「第 X 堂」。
  - 家長端學習評量列表與詳情顯示「第 X 堂」。
- P1（Should Have）：
  - 視覺微調，優化堂次標示在圖面與手機版家長卡片的可讀性。
  - 補充回歸測試覆蓋請假/補課/分頁場景。
- P2（Nice to Have）：
  - 後續評估主任/老師頁面其他評量詳情區也顯示同欄位。
  - 後續評估是否加入家長端分享或下載評量圖。

## 13. 風險、假設、開放問題
- 風險：
  - 高：堂次定義若未與既有商業邏輯對齊，會出現老師端、課程端、家長端各說各話。
  - 中：家長端分頁資料不完整，若前端計算會失真，因此需堅持後端統一口徑。
  - 中：請假/補課/調課若未納入既有堂次規則，可能導致跳號或重號。
- 假設：
  - 預設以「該學生該課程的非請假堂次序號」作為堂次定義，對齊現有課程詳情語意。
  - 預設家長端頁面也要顯示堂次，不只匯出圖片。
- 開放問題：
  - [TODO: 需確認] 堂次標示文案是否固定為「第 X 堂」，或需依 UI 語氣調整為「本課程第 X 堂」。Owner：PM
  - [TODO: 需確認] 若單筆資料無法判定堂次，是否顯示「堂次待補」或直接不顯示。Owner：PM / UI

## 14. Definition of Done
- [ ] 所有 FR 通過 QA 驗收。
- [ ] UI/UX 驗收清單全部打勾，UI/UX Designer sign-off。
- [ ] 資安審查無阻擋項。
- [ ] 前端已執行 `npm run deploy`，且老師端匯出與家長端 API health 正常。
- [ ] `docs/CHANGELOG.md` 更新。
- [ ] PM sign-off。
- [ ] CTO / 工程 Lead sign-off。
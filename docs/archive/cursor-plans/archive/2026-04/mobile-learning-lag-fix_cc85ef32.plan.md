---
name: mobile-learning-lag-fix
overview: 針對老師手機填寫評量在尖峰時段卡頓，先用量測分離前端渲染與後端負載，再分兩波優化（快速止血 + 結構優化），並設定明確驗收指標。
todos:
  - id: baseline-metrics
    content: 建立老師評量頁尖峰/離峰基準：TTI、API P95、重運算耗時
    status: completed
  - id: qa-script-baseline
    content: 建立固定QA測試腳本與blocking criteria（老師評量完整流程）
    status: completed
  - id: phase1-relief
    content: 先做輪詢降壓與評量頁首屏減載（不改業務規則）
    status: completed
  - id: unread-count-decouple
    content: 拆分 unread-count 讀取與同步責任，避免讀取端點觸發重同步
    status: completed
  - id: phase2-structural
    content: 完成列表渲染與 API 分頁/快取結構優化
    status: completed
  - id: api-dual-track-rollback
    content: API 契約改動採雙軌或版本化，並驗證一鍵回退可用
    status: completed
  - id: slo-alert-setup
    content: 建立SLO告警（P95/P99/error rate/timeout/long-task）與值班門檻
    status: completed
  - id: qa-rollout
    content: 真機回歸 + 分批上線 + 尖峰觀測與回退開關
    status: completed
  - id: uat-signoff
    content: 完成主任/老師UAT簽核與兩個尖峰時段觀測後再全量
    status: completed
isProject: false
---

# 老師手機評量卡頓修正計畫

## 問題判讀（PM）
- 你回報的主症狀是：老師在「填評量」時卡頓，且尖峰時段更明顯。
- 目前較可能是「前端渲染壓力 + 後端請求壓力」疊加，不是單一「多人登入」：
  - 前端在主框架固定輪詢多個 badge API（每 25 秒一次），見 [App.vue](/home/admin/frontend/src/App.vue)。
  - `notifications/unread-count` 本身含 sync 行為（已在回歸文件記錄），尖峰時容易放大延遲，見 [AI_REGRESSION_LESSONS.md](/home/admin/docs/AI_REGRESSION_LESSONS.md)。
  - 評量頁首次載入會連續打多支 API，且包含大筆數（例如 `per_page=200/500/2000`）與多次 regroup/sort，見 [LearningRecordsPage.vue](/home/admin/frontend/src/pages/LearningRecordsPage.vue)。
  - 手機端仍存在多層 scroll/fixed/modal 與 viewport 計算熱點，見 [styles.css](/home/admin/frontend/src/styles.css)、[CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)、[ChatPage.vue](/home/admin/frontend/src/pages/ChatPage.vue)。

## 架構與CTO決策原則（新增）
- 不改核心商業語意：評量待審、請假過濾、核准扣堂流程不變，只做效能優化。
- 任何優化必須可回退：以 feature flags 控制輪詢、lazy load、API 新舊路徑。
- 以 trace-id 串聯前端事件、API、SQL，確保瓶頸可定位。
- 範圍先聚焦 `LearningRecordsPage + App badge 輪詢`，其他頁面列為後續。

## 目標與驗收
- 目標：老師手機在評量頁操作（切 tab、展開列表、開啟/送出表單）體感順暢，尖峰時段也不明顯掉幀。
- 驗收指標（MVP）
  - 首次可互動時間（TTI）下降 30% 以上。
  - 評量列表 API P95 降到 1.2s 內（尖峰）。
  - 滑動掉幀主觀改善：同裝置腳本下「可明顯卡頓」回報數下降 70%。

## CTO補強KPI（新增）
- 商業/營運指標
  - 老師評量送出成功率（尖峰 vs 離峰）上升。
  - 評量逾期率下降。
  - 卡頓工單/口頭回報下降（先以 50% 為硬門檻，70% 為挑戰值）。
- SLO/監控指標
  - `GET /api/v1/learning-records`：P95 <= 1.2s、P99 <= 2.0s、error rate < 0.5%。
  - `GET /api/v1/notifications/unread-count`：P95 <= 300ms，且不得觸發重同步。
  - 前端長任務（>50ms）次數：30 秒互動腳本內下降 >= 40%。

## QA測試與驗收補強（新增）
- 測試分層
  - API/後端：效能基準 + 正確性回歸（避免「修快但邏輯壞」）。
  - 前端：真機互動流暢度、長任務、操作成功率。
  - E2E：老師從課表點入評量到送出的完整流程。
- 測試環境要求
  - 至少區分尖峰與離峰兩組資料。
  - 真機至少 4 台（iPhone Safari 2、Android Chrome 2）。
  - 網路條件至少含 Wi-Fi 與 4G 模擬。
- 驗收證據要求
  - 每個 phase 提交「修前/修後」對照：TTI、P95、錯誤率、長任務次數。
  - 提交回歸測試結果（通過/失敗清單）與 rollback 演練紀錄。

## 執行分期

### Phase 0：1 天內完成證據化（先確認瓶頸占比）
- 在 [LearningRecordsPage.vue](/home/admin/frontend/src/pages/LearningRecordsPage.vue) 加入前端埋點（不改 UX）：
  - 首屏時間、API 各段耗時、資料整理耗時（group/sort/filter）。
- 在後端對高頻端點加 request 計量與慢查詢追蹤：
  - `/api/v1/learning-records`
  - `/api/v1/notifications/unread-count`
  - `/api/v1/class-sessions`
- 產出尖峰與離峰對照（前端耗時占比 vs API 耗時占比）。
- 補充容量基線（新增）
  - 尖峰同時在線老師數、badge 輪詢QPS、DB連線與慢查詢比例。
  - 產出 go/no-go 結論：前端占比 >=30% 先做前端，否則先做後端。
- QA任務（新增）
  - 建立固定測試腳本：登入老師 -> 進入評量頁 -> 切 tab -> 展開學生群組 -> 開啟評量 -> 送出。
  - 定義 P0/P1 回歸清單與阻擋條件（blocking criteria）。

### Phase 1：快速止血（2-3 天）
- 降低不必要輪詢壓力（低風險）
  - [App.vue](/home/admin/frontend/src/App.vue) 將 25 秒 badge 輪詢改為「頁面可見 + 角色必要」才執行；背景頁暫停。
  - 避免在老師評量流程中同步打所有 badge API，改成延後/合併。
- 拆分 unread-count 副作用（新增）
  - 「讀取計數」與「同步計算」分離；讀取端點不得觸發重同步。
- 評量頁減載（不改業務規則）
  - [LearningRecordsPage.vue](/home/admin/frontend/src/pages/LearningRecordsPage.vue) 將首次載入拆成關鍵資料優先（records）與次要資料延後（teacher/students/courses）。
  - 先收斂 `per_page`，加入分頁或漸進載入，避免一次拉滿 200~500 筆。
- 手機繪製成本降級
  - [styles.css](/home/admin/frontend/src/styles.css) 於行動端弱化 blur/backdrop-filter 熱點。
- Gate（新增）
  - Go：TTI 改善 >=20%，`learning-records` P95 改善 >=15%，無 P1 回歸。
  - No-Go：核心流程回歸（待審/請假/送出）即回退 flag。
- QA案例（新增）
  - Case 1：老師評量頁首次載入與切 tab 不出現明顯卡頓。
  - Case 2：評量送出成功率不下降（含尖峰）。
  - Case 3：badge 在可接受延遲內更新，且不漏通知。
  - Case 4：背景分頁時輪詢降載生效，回前景可恢復。

### Phase 2：結構優化（3-5 天）
- 評量頁渲染優化
  - [LearningRecordsPage.vue](/home/admin/frontend/src/pages/LearningRecordsPage.vue) 將重型 `computed`（group/sort/filter）拆分為可快取的分層資料管線。
  - 長列表優先採分段/分頁；虛擬清單列為備選方案（達標可不做）。
- API 契約優化
  - `learning-records` 支援更精準查詢/分頁欄位，減少前端二次運算量。
  - 高頻 badge API 做快取窗口與索引檢查，避免尖峰重複計算。
- 契約回退策略（新增）
  - API 契約改動採雙軌（舊欄位保留）或版本化；可一鍵切回舊路徑。
- Gate（新增）
  - Go：尖峰 `learning-records` P95 <= 1.2s、timeout <1%。
  - No-Go：無雙軌回退或回歸未清，不可發布。
- QA案例（新增）
  - Case 5：分頁/漸進載入後，資料完整性與排序一致（不漏學生、不重複）。
  - Case 6：搜尋/篩選/展開狀態在載入更多後不錯亂。
  - Case 7：API 新舊契約雙軌期間，前端行為一致且可一鍵切回。
  - Case 8：尖峰壓測下 error rate、timeout 不超門檻。

### Phase 3：回歸與上線（1-2 天）
- 尖峰時段實測（老師真機）
  - iPhone Safari + Android Chrome，各 2 台。
- 核對回歸重點
  - 評量待審邏輯、請假過濾、老師工作台 badge 不回歸（依既有回歸文件）。
- 分批上線：先 1 分校 teacher 灰度，再全量；每批至少觀察 2 個尖峰時段。
- Gate（新增）
  - Go：卡頓回報下降 >=50%（挑戰值 70%）。
  - No-Go：告警連續 30 分鐘超閾值，維持灰度並回退 Phase 2。
- QA最終驗收（新增）
  - UAT 驗收單：主任/老師各至少 1 位簽核「可接受」。
  - 連續兩個尖峰時段觀測通過後才可全量。
  - 執行回退演練一次（含 feature flag 與 API 路徑切回），確保 5 分鐘內可恢復。

## 風險與管控
- 風險：過度降頻輪詢可能造成紅點延遲；評量分頁可能改變老師使用習慣。
- 管控：
  - 先 A/B 觀察（特定角色/分校灰度）。
  - 保留開關可快速回退（polling interval、lazy load 開關）。
  - 增加告警閾值與值班手冊（P95、error rate、timeout、前端 long-task）。

## QA阻擋條件（Blocking Criteria，新增）
- 任一核心回歸（評量待審、請假過濾、核准扣堂、評量送出）失敗，禁止上線。
- `learning-records` P95 或 error rate 未達門檻，禁止從灰度升級全量。
- 無法在 5 分鐘內完成回退，禁止進入全量發布。

## 主要檔案
- [App.vue](/home/admin/frontend/src/App.vue)
- [LearningRecordsPage.vue](/home/admin/frontend/src/pages/LearningRecordsPage.vue)
- [styles.css](/home/admin/frontend/src/styles.css)
- [AI_REGRESSION_LESSONS.md](/home/admin/docs/AI_REGRESSION_LESSONS.md)
- [CHANGELOG.md](/home/admin/docs/CHANGELOG.md)
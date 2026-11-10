---
name: single-session-edit-ux-clarity
overview: 針對 SmartCalendar 的「檢視／單堂操作」介面，整理一份給 PM 的修正計畫，降低操作人員對星期與首堂日期語意的誤解。
todos:
  - id: pm-clarify-terms
    content: 定義單堂資訊與整門課資訊的術語與顯示規則
    status: completed
  - id: pm-ab-wireframes
    content: 提供A/B版面草圖，明確分隔本堂與契約參考欄位
    status: completed
  - id: ops-usability-check
    content: 找5-8位實際操作人員驗證是否仍誤解欄位語意
    status: completed
  - id: prd-handoff
    content: 輸出含驗收標準的PRD並交付工程排期
    status: completed
  - id: ux-info-hierarchy-audit
    content: 完成單堂視窗資訊層級審視（本堂資訊優先、契約資訊次層、操作按鈕最後）
    status: completed
  - id: ux-copy-clarity-pass
    content: 針對關鍵詞彙做文案清晰化審查（星期幾、首堂日期、單堂操作）並給替代文案
    status: completed
  - id: ux-error-prevention-patterns
    content: 盤點易誤操作點並設計防呆提示（提示文、分組標題、不可編輯說明）
    status: completed
  - id: ux-consistency-cross-pages
    content: 比對 SmartCalendar、CourseManagement、UniversalClassScheduler 術語與欄位語意一致性
    status: completed
  - id: ux-success-metrics
    content: 定義 UX 驗收指標（理解時間、誤判率、任務完成率）並納入 PRD
    status: completed
  - id: cto-risk-register
    content: 建立本次改動風險清單與風險等級（誤操作、訓練成本、跨頁語意漂移）
    status: completed
  - id: cto-scope-boundary
    content: 定義本次只改UI文案/資訊架構，不改後端契約與核心流程的邊界
    status: completed
  - id: cto-cross-functional-owners
    content: 指定PM/UX/Frontend/QA/營運負責人與交付物，避免責任模糊
    status: completed
  - id: cto-release-gates
    content: 設定上線前Gate（UX驗收達標、回歸測試通過、關鍵角色簽核）
    status: completed
  - id: cto-rollout-and-rollback
    content: 規劃分階段上線與回滾方案（若誤判率上升可快速退回舊文案）
    status: completed
  - id: cto-telemetry-observability
    content: 定義觀測指標與追蹤事件（單堂操作完成率、取消率、重開編輯次數）
    status: completed
  - id: cto-training-communication
    content: 準備營運訓練與變更公告（新舊詞彙對照、常見誤解FAQ）
    status: completed
  - id: qa-test-matrix
    content: 建立測試矩陣（角色x入口路徑x操作類型）涵蓋單堂檢視與單堂操作
    status: completed
  - id: qa-acceptance-scenarios
    content: 撰寫驗收案例腳本（請假/調課/加課）並明確預期文案與欄位理解結果
    status: completed
  - id: qa-regression-suite
    content: 執行回歸清單確認改文案後不影響既有流程與 API 行為
    status: completed
  - id: qa-negative-confusion-tests
    content: 增加混淆情境測試（誤把首堂當本堂、誤判可改整門課）並驗證防呆提示有效
    status: completed
  - id: qa-uat-signoff
    content: 安排營運UAT簽核，收集問題清單與修正優先級
    status: completed
  - id: qa-post-release-monitoring
    content: 設定上線後7天監測與缺陷分級處理機制
    status: completed
  - id: sec-privacy-copy-review
    content: 檢查新文案與提示是否暴露不必要個資或營運敏感資訊
    status: completed
  - id: sec-role-access-validation
    content: 驗證不同角色僅可見其授權操作與提示內容，避免越權引導
    status: completed
  - id: sec-audit-log-coverage
    content: 確認單堂操作相關關鍵行為具備可追溯稽核紀錄（誰在何時做了什麼）
    status: completed
  - id: sec-incident-response-playbook
    content: 建立誤操作或爭議事件的調查流程（查詢路徑、責任界定、復原步驟）
    status: completed
  - id: sec-change-control-checkpoint
    content: 設定文案與流程變更的資安審核關卡，避免未審核內容直接上線
    status: completed
  - id: sec-data-minimization-ui
    content: 檢查單堂視窗僅呈現完成任務所需最小資料，降低資訊曝露面
    status: completed
isProject: false
---

# 單堂操作頁語意澄清 PM 計畫

## 目標
- 降低「從行事曆點單堂」進入編輯視窗時，操作人員對欄位語意的誤判。
- 明確區分「本次點擊堂次資訊」與「整門課契約資訊」，避免把單堂修改誤解為整門課修改。

## 已確認現況（問題證據）
- 在 [SmartCalendar.vue](/home/admin/frontend/src/pages/SmartCalendar.vue) 的單堂模式（`editingCourseId` 有值）中，畫面同時出現：
  - 標題與提示：`檢視／單堂操作`、`正在檢視 YYYY-MM-DD（星期X）此堂`
  - 欄位：`星期幾`（disabled）
  - 欄位：`首堂上課日期 (First Class Date)`（disabled）
- 這三種資訊混在同一區塊，會讓一線人員誤以為「本次單堂日期」=「首堂日期」或「固定每週契約日」。
- 資料來源也不同：
  - `day_of_week` 來自點擊堂次 (`course.day_of_week`)
  - `first_class_date` 來自整門課基礎資料 (`baseCourse.first_class_date`)
  - `action_date` 才是本次點擊的實際堂次日期 (`fullDateStr`)

## PM 交付內容與決策項
- 決定資訊架構：單堂編輯視窗內是否分成「本堂資訊」與「整門課參考」兩區。
- 決定文案詞彙：
  - `星期幾` 是否改為 `本堂星期` 或 `本次上課星期`。
  - `首堂上課日期` 是否改為 `課程首堂日期（僅參考）`，或在單堂模式隱藏。
- 決定互動策略（2 選 1）：
  - A. 單堂模式僅保留 `本堂日期/星期`，整門課欄位全部改成「唯讀參考區」。
  - B. 保留欄位但加顯眼說明與分隔（避免誤改期待）。

## UI/UX 審視重點（新增）
- 資訊層級：
  - 第一層只呈現「這一堂」必要資訊（日期、星期、可做操作）。
  - 第二層才呈現「整門課參考」資訊（首堂日期、固定排課）。
- 視覺掃讀：
  - 單堂資訊與契約資訊需要明確分段標題與背景區塊，避免同一視覺層級混讀。
- 文案辨識：
  - 禁止使用容易雙關的短詞；每個欄位需能單獨回答「是本堂還是整門課」。
- 錯誤預防：
  - disabled 欄位旁補上「僅參考／不可在此修改整門課」的原因提示。
- 術語一致：
  - 與課程管理、統一排課器的同欄位採同名規則，避免跨頁切換時再學習成本。

## 執行步驟（PM 主導）
1. 盤點角色情境（主任/櫃台/排課人員）與高頻誤操作路徑（從日曆格子、從課程列點擊）。
2. 產出 2 版低保真稿（A/B 方案）與文案對照表（舊詞 vs 新詞）。
3. 與營運代表做快速可用性檢視（5-8 人），驗證是否仍誤解「首堂」與「本堂」。
4. 定版後輸出 PRD：
   - 欄位顯示規則（單堂模式 vs 新增課程模式）
   - 文案規範
   - 驗收條件與回歸範圍
5. 交給工程排期，分前端文案/版面與測試案例兩個子任務。

## UX 驗收量測（新增）
- 理解時間：受測者需在 3 秒內指出「本堂日期」與「首堂日期」各自意義。
- 誤判率：把首堂日期誤認為本堂日期的比例需低於 10%。
- 任務成功率：完成「只做單堂請假、不誤認可改整門課」成功率需達 95% 以上。
- 主觀清晰度：SUS 或簡化問卷的「欄位語意清楚」題項平均需達 4/5 以上。

## CTO 治理檢視（新增）
- 範圍控管：
  - 本次優先解決語意與操作理解問題，避免夾帶流程改造導致排期失焦。
- 風險治理：
  - 將「跨頁術語不一致」「上線後一線仍誤判」列為主要營運風險並指定 owner。
- 決策機制：
  - A/B 方案需有明確決策人（PM）與最終裁決人（產品/CTO）時間點。
- 上線策略：
  - 採小範圍先行（例如先單一分校或核心角色），觀察指標後再全量。
- 回滾機制：
  - 文案與版面改動需可快速回退，避免高峰時段影響櫃台作業。

## QA 測試驗收（新增）
- 測試矩陣：
  - 角色：主任、櫃台、排課人員。
  - 入口：日檢視格子點擊、週檢視課程列點擊、例外堂次點擊。
  - 操作：請假、調課、加課、僅檢視關閉。
- 驗收案例：
  - 每個案例需驗證使用者是否正確辨識「本堂日期」與「首堂日期（僅參考）」。
  - 每個案例需驗證是否理解「不能在此修改整門課」。
- 負向測試：
  - 故意引導誤操作（例如要求測試者修改首堂日期）確認介面能阻止誤判。
- 回歸測試：
  - 確認單堂操作完成後，請假/調課/加課流程、提示訊息與資料寫入行為不變。
- UAT 簽核：
  - 營運代表完成情境任務後簽核，未達標需回到文案或版面調整。

## 資安檢視與驗收（新增）
- 最小揭露原則：
  - 單堂操作頁僅顯示任務必要資訊，避免把整門課或其他非必要資訊暴露在同一畫面。
- 角色權限一致：
  - 不同角色看到的提示與可執行操作需與既有權限模型一致，避免造成越權暗示。
- 稽核可追溯：
  - 對請假/調課/加課的操作入口與關鍵提交行為，需可追查操作者、時間與動作。
- 變更管制：
  - 文案調整雖屬 UI 變更，仍需納入審核與簽核流程，避免高風險時段未審核上線。
- 事件應變：
  - 若上線後出現大量誤操作或客訴，需有明確的回滾、公告與調查機制。

## 工程影響範圍（供 PM 評估）
- 主要： [SmartCalendar.vue](/home/admin/frontend/src/pages/SmartCalendar.vue)
- 次要（如需保持術語一致）：
  - [CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)
  - [UniversalClassScheduler.vue](/home/admin/frontend/src/components/UniversalClassScheduler.vue)

## 驗收標準（PM 可直接放入規格）
- 操作人員能在 3 秒內分辨：
  - 「本次堂課日期」
  - 「每週固定星期（若有）」
  - 「整門課首堂日期（僅參考）」
- 單堂模式下，不再出現會被理解為可改整門課契約的視覺暗示。
- Help 文案與按鈕文案一致，且不與「課程管理」頁術語衝突。
- 回歸確認：請假／調課／加課流程文案不變或更清楚，不影響既有 API 行為。

## 建議時程
- D1: PM 完成問題定義與 A/B 草稿
- D2: 營運快速驗證 + 定版
- D3: 工程實作與 QA 驗收
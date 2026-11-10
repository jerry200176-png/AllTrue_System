---
name: parttime-teacher-payroll-prd
overview: 撰寫一份聚焦「兼職老師薪資計算與月結報表」的 PRD，範圍不含跨分校調課/代課流程，並對齊現有 AllTrue 前後端架構與權限模型。
todos:
  - id: confirm-payroll-formula
    content: 在 PRD 固化四種學段基礎時薪與每多 1 人 +50/h 規則（含範例）
    status: completed
  - id: define-product-scope
    content: 在 PRD 明確標註本期僅做薪資計算/月結，不含跨分校調課或代課流程
    status: completed
  - id: design-data-contract
    content: 規劃薪資查詢 API 與資料來源口徑，對齊現有 Campus/role 隔離
    status: completed
  - id: write-prd-doc
    content: 撰寫 docs/PRD_PARTTIME_TEACHER_PAYROLL.md 完整章節與驗收標準
    status: completed
  - id: define-payroll-information-architecture
    content: 補齊薪資頁資訊架構（總覽卡、明細表、試算區、異常提示）與頁面層級
    status: completed
  - id: create-ux-flow-and-wireframe
    content: 在 PRD 定義主任從查詢到匯出的端到端 UX 流程與 wireframe 草圖需求
    status: completed
  - id: define-visual-system
    content: 補上視覺規範（色彩語意、字級層級、間距、狀態元件）確保美感一致
    status: completed
  - id: define-usability-accessibility-acceptance
    content: 新增可用性與無障礙驗收指標（可讀性、鍵盤操作、錯誤可理解性）
    status: completed
  - id: plan-empty-loading-error-states
    content: 補齊空狀態/載入中/錯誤狀態與文案策略，避免薪資頁體驗斷裂
    status: completed
  - id: align-payroll-source-of-truth
    content: 定義薪資計算唯一資料來源與認列條件（避免 LearningRecord/ClassSession 口徑不一致）
    status: completed
  - id: design-payroll-api-contract
    content: 設計 payroll API 回傳契約（summary + teacherRows + sessionRows）並補上分頁與排序參數
    status: completed
  - id: define-month-lock-and-audit
    content: 在 PRD 加入月結鎖帳與重算稽核需求（誰在何時重算、匯出）避免財務爭議
    status: completed
  - id: plan-performance-and-indexing
    content: 評估薪資查詢效能需求並預留索引/快取策略，避免月底查詢卡頓
    status: completed
  - id: define-frontend-integration-path
    content: 確認前端掛載位置與導覽入口（TeachersList 擴充或獨立薪資頁）並定義狀態管理策略
    status: completed
  - id: add-regression-test-scenarios
    content: 補充薪資回歸測試矩陣（跨分校隔離、試聽排除、人數加成、時數換算、匯出一致性）
    status: completed
  - id: qa-acceptance-test-plan
    content: 建立 QA 驗收測試計畫（功能驗收、公式驗算、權限隔離、鎖帳流程、匯出一致性）
    status: completed
  - id: qa-api-contract-tests
    content: 規劃 API 契約測試（欄位完整性、型別、分頁排序、錯誤碼）並納入回歸
    status: completed
  - id: qa-payroll-calculation-golden-cases
    content: 建立薪資計算金樣本案例（高中/國中/國小/輔導、一對一到一對三、跨時長）做自動比對
    status: completed
  - id: qa-branch-and-role-isolation-tests
    content: 規劃分校與角色隔離測試（director 僅可見授權分校，teacher 不可見薪資頁）
    status: completed
  - id: qa-month-lock-recompute-tests
    content: 補上月結狀態機測試（draft/reviewed/locked、鎖帳後限制、重開與稽核紀錄）
    status: completed
  - id: qa-export-reconciliation-tests
    content: 驗證匯出報表與畫面查詢金額一致（同條件、同快照、筆數與總額一致）
    status: completed
  - id: qa-nonfunctional-tests
    content: 增加非功能驗收（效能 SLA、大資料量穩定性、錯誤可恢復性、操作可用性）
    status: completed
  - id: qa-uat-checklist-and-signoff
    content: 建立 UAT 清單與簽核門檻（PM/財務/主任）作為上線前必要條件
    status: completed
  - id: it-raspberrypi-capacity-budget
    content: 針對樹莓派設定容量預算（CPU/RAM/磁碟）與薪資查詢 SLO 門檻，作為上線 gate
    status: completed
  - id: it-memory-safe-query-strategy
    content: 定義記憶體安全查詢策略（分頁、chunk、欄位白名單、禁止一次載入全月全老師明細）
    status: completed
  - id: it-export-job-safety
    content: 規劃匯出任務防爆機制（背景任務、單次筆數上限、超時與重試）避免前台阻塞
    status: completed
  - id: it-observability-and-alerting
    content: 建立監控與告警（slow-request、slo-breach、memory 峰值）並定義值班處置流程
    status: completed
  - id: it-load-and-soak-test-on-pi
    content: 在樹莓派環境執行壓力/耐久測試，驗證月底高峰下不會 OOM 或 swap 失控
    status: completed
  - id: it-fail-safe-and-kill-switch
    content: 增加安全閥（最大 per_page、最大匯出範圍、feature flag 一鍵降載）與回退步驟
    status: completed
isProject: false
---

# 兼職老師薪資 PRD 撰寫計畫

## 目標與範圍
- 產出一份可交付研發的 PRD，主題為「兼職老師薪資計算與月結」。
- 本次僅涵蓋薪資規則、薪資計算、薪資報表與權限；不新增跨分校調課/代課功能。
- 結算週期採每月結算（使用者已確認）。

## 關鍵規格（將明確寫入 PRD）
- 基礎一對一時薪：
  - 高中 `400/h`
  - 國中 `350/h`
  - 國小 `300/h`
  - 輔導 `200/h`
- 人數加成規則：在一對一基礎上，每多 1 位學生，每小時加 `50`。
  - 範例：高中一對二 `450/h`，高中一對三 `500/h`。
- 依實際上課時數（堂次長度）換算應付薪資。

## 系統對應與資料流設計（PRD 內會落地）
- 前端頁面優先放在既有管理端（如 `TeachersList` 或新增薪資頁），避免改動老師工作台核心流程。
- 後端優先新增/擴充薪資查詢 API（建議由財務模組承接），沿用 `role` 與 `require_campus` 控制。
- 薪資資料來源以已完成/可認列堂次為準，並遵守分校隔離（`CampusID` / `branch_id`）。

## 技術評估結論（架構師 / 前端 / 後端）
- 整體方向可行，且適合掛在既有財務模組延伸（目前已有 `finance/teacher-payroll` 可作為升級基礎）。
- 目前最大風險是「薪資認列口徑」尚未定義到可實作：
  - 現有 `teacher-payroll` 僅回傳老師堂次數（`session_count`），不足以計算時薪制薪資。
  - 必須明確指定是否以 `LearningRecord(Status=approved)` 作為唯一認列來源，或改用 `ClassSession(Status=attended)`；PRD 需固定單一口徑。
- 路由與權限面可直接沿用 `role:director` + `require_campus`，技術風險低；但需要補充月結鎖帳與重算稽核規格，避免財務數字在月結後漂移。

## 架構師建議調整（需寫進 PRD）
- 新增「薪資計算引擎」章節，定義：
  - 計算公式、取數來源、四捨五入規則、時區與跨日課處理。
  - 固定單一 truth source（建議先以 approved LearningRecord，與既有統計口徑一致）。
- 新增「月結狀態機」：
  - `draft -> reviewed -> locked`，鎖帳後僅允許特定角色重開並留下 audit trail。
- 新增「資料一致性策略」：
  - 匯出時使用同一批次快照 ID，避免使用者查詢畫面與匯出檔金額不一致。

## 前端工程師建議調整（需寫進 PRD）
- 建議採「獨立薪資頁」優先於塞進 `TeachersList`，避免老師管理頁過重與狀態耦合。
- 明確定義前端資料模型：
  - `summary`（總額卡）
  - `teacherRows`（老師列表）
  - `sessionRows`（堂次明細，可分頁）
- 補上互動規格：
  - 條件切換採「顯式套用」並顯示最後更新時間。
  - 匯出與重算為長任務時需顯示進度、禁重複點擊、失敗可重試。

## 後端工程師建議調整（需寫進 PRD）
- 建議新增 API 而非硬改舊 `finance/teacher-payroll`，降低回歸風險：
  - `GET /api/v1/finance/parttime-payroll`（總覽 + 老師列）
  - `GET /api/v1/finance/parttime-payroll/{teacherId}/sessions`（堂次明細）
  - `GET /api/v1/finance/parttime-payroll/export`（匯出）
- API 必要參數：
  - `month`、`branch_id`（可選，受權限限制）、`teacher_id`（明細查詢用）
  - `page/per_page/sort`（避免大資料量超時）
- 查詢效能：
  - 先在 PRD 註記索引評估（`LearningRecord(Status, SessionDate, TeacherID)` 等）與慢查詢門檻。

## IT 風險評估（Raspberry Pi 容量保護）
- 結論：計畫方向可行，但若未加「查詢與匯出防爆機制」，月底高峰有機會觸發 RAM 壓力與 swap 抖動。
- 高風險爆點（需先防）：
  - 一次查整月＋全分校＋全部老師堂次明細（JSON payload 過大）。
  - 匯出同步生成大檔（CPU/RAM 長時間佔用，拖慢其他 API）。
  - 前端連續切條件觸發多次重查（N 次重疊請求）。
- 必加保護策略：
  - API 強制分頁與上限（例如 `per_page` 預設 50、上限 200）。
  - 明細查詢必須「先老師後堂次」，禁止直接吐出全量 sessionRows。
  - 匯出改背景任務或至少限制單次匯出範圍（單月、單分校）。
  - 對慢查詢與 SLO 超標寫 log（沿用現有 perf/slow-request 機制）並有告警門檻。

## Pi 上線前 IT 驗收門檻（No-Go 條件）
- 記憶體：薪資查詢/匯出壓測期間，不可出現 OOM-killer 事件。
- swap：不可長時間高占用（避免進入持續 swap thrashing）。
- 延遲：`/finance/parttime-payroll` 在目標資料量下需達到可接受 P95。
- 穩定性：連續 30~60 分鐘壓測後，API error rate 維持在可控範圍。
- 任一項不達標即 No-Go，需先啟用降載方案再重新驗收。

## UI/UX 需補強項（直接納入 PRD）
- 資訊架構分三層：
  - 第一層：月結總覽（應付總額、總時數、老師數、平均時薪）
  - 第二層：老師薪資清單（可排序/搜尋/篩選）
  - 第三層：單一老師薪資明細（堂次級計算展開）
- 流程設計需明確定義：
  - 主任進入薪資頁 → 選月份與分校 → 檢視總覽 → 點老師看明細 → 匯出報表
- 必須加入「試算透明度」：
  - 每筆堂次顯示計價來源（學段基礎價 + 人數加成 + 時數換算）
  - 提供小計與總計，避免黑箱感。

## 視覺與互動設計原則
- 視覺風格：沿用現有後台 design language，採「財務資料高可讀」優先，避免過度裝飾。
- 清晰層級：
  - 總額數字高對比大字
  - 次資訊（備註、計算說明）低權重但可見
- 狀態完整：
  - Loading Skeleton、Empty State、Error State、無權限 State 必須各自有文案與操作建議。
- 操作回饋：
  - 匯出按鈕有進度與完成提示
  - 篩選條件變更要有即時刷新或明確「套用」行為。

## 可用性與美感驗收
- 可用性指標：
  - 首次使用者 1 分鐘內可完成「選月份 → 查看老師明細 → 匯出」。
  - 使用者可在 5 秒內理解單堂計價來源。
- 版面一致性：
  - 與現有管理頁（表格、篩選器、按鈕樣式）一致，避免新頁面風格跳脫。
- 無障礙最低要求：
  - 文字與背景對比達可讀標準
  - 關鍵操作可鍵盤觸達
  - 錯誤訊息文字化，不只靠顏色提示。

## 產出文件結構
- 建立 PRD 檔案：`docs/PRD_PARTTIME_TEACHER_PAYROLL.md`
- 章節包含：
  - 背景與問題
  - 產品目標與非目標
  - 角色與權限
  - 薪資規則（含公式與範例）
  - 功能需求（查詢、結算、明細、匯出）
  - API 與資料模型建議
  - 驗收標準（AC）
  - 測試與回歸清單
  - 風險與上線策略
  - UI/UX 規格（資訊架構、版面、互動、狀態）
  - 可用性驗收（Usability）與視覺驗收（Visual QA）清單

## 初版實作邊界（在 PRD 中先行限制）
- 不處理跨分校調派造成的複雜拆帳。
- 不處理獎金/罰款/津貼，僅先做「時薪 x 時數」核心。
- 不改動既有堂數扣除主流程（避免影響高風險區域）。
- 不做自動發薪/銀行串接（僅計算、查詢、匯出）。
- 不提供「全歷史無限制匯出」，初版僅允許單月份查詢與匯出。

## 驗收與里程碑
- M1：可按月份 + 分校 + 老師查出應付薪資總額與堂次明細。
- M2：可匯出薪資明細（CSV/Excel 擇一）。
- M3：完成權限與跨分校資料隔離驗證。
- M4：完成月結鎖帳與重算稽核流程驗收（含操作紀錄）。
# Bug Fix Plan：課程管理月結逐期帳單可視化

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 前端 UI 漏接既有 API / 帳務狀態呈現不足 |
| 根因摘要 | `CourseManagement.vue` 只顯示課程層級 `payment_status` / `last_paid_at`，未串接既有逐期帳單 API，因此主任無法在課程管理判斷哪一期月結已繳或未繳。 |
| 錯誤行為 | 月結課程在課程管理只看到「已繳費 / 未繳費」與最後繳費日，無法看到 `2026-04`、`2026-05` 等期別狀態。 |
| 預期行為 | 月結課程在課程管理可直接查看逐期帳單，包含期別、繳費日、金額、已繳 / 未繳 / 部分繳。 |
| 影響範圍 | 主任 / 課程管理頁 / 月結制課程 / 核帳判斷流程。 |
| B1 偵查來源 | 本次 B1：後端已有 `GET /api/v1/student-classes/{id}/invoices`；`StudentsList.vue` 已有帳單 modal；`CourseManagement.vue` 無 invoices 串接。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 課程管理月結逐期帳單可視化 |
| 日期 | 2026-04-27 |
| 狀態 | Draft - 等待使用者批准進入 ARCH/DEV |
| 嚴重度 | P2 |
| 目標角色 | director / admin / super_admin |
| 關聯 Bug | 主任反應：課程管理看不出哪些課程日期 / 月份有繳費、哪些沒有。 |

## 2. 業務背景與影響

主任管理月結課程時，需要知道每一期帳單是否已收款。業界 recurring billing 會把「合約 / 課程」與「帳單期別 / invoice」分離：課程代表持續教學關係，帳單代表每月收款狀態。

AllTrue 目前後端已朝此方向建模，但課程管理頁仍把付款狀態壓平成單一課程狀態，導致主任必須去其他頁面或靠記憶判斷，容易漏收、重複催繳或誤以為整門課已繳。

修復後預期行為：
- 月結課程維持一筆課程，不因每月續報複製課程。
- 每次月結續報 / 每期收款由 `Invoice.billing_period` 表示。
- 課程管理可直接查看逐期帳單狀態，降低主任查帳成本。

## 3. 範圍

### In Scope

- 在課程管理月結課程加入逐期帳單入口或詳情區塊。
- 串接既有 `GET /api/v1/student-classes/{id}/invoices`。
- 顯示期別、繳費日、總金額、已繳金額、狀態 chip。
- 沿用 `StudentsList.vue` 既有帳單 modal 的欄位語義，避免雙套規則。
- 若無帳單資料，明確顯示「尚無帳單記錄（舊有課程）」。
- 補前端與後端回歸測試 / CI 驗證。
- 更新 `docs/CHANGELOG.md`。

### Out of Scope

- 不改月結續報資料模型：仍採「同一課程 + 多期 Invoice」。
- 不新增每月複製 `StudentClass` 的行為。
- 不改 `AlertController::tuition` 提醒條件。
- 不改堂數制加購新批次規則。
- 不處理付款作廢 / 退款 / 沖銷流程。
- 不新增 migration，除非 ARCH 階段發現既有欄位不足。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[DEV]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（審查） | `[REVIEW]` Agent | R |
| AI Agent（文件） | `[DOCS]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 使用者 | CEO / 產品方 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 後端 API | `GET /api/v1/student-classes/{id}/invoices` 已存在，具 campus isolation 測試。 | 已完成 |
| DB | `Invoice.billing_period` 已存在。 | 已完成 |
| 前端參考 | `StudentsList.vue` 已有月結帳單 modal 可參考。 | 已完成 |
| 外部服務 | 無。 | 不適用 |
| Migration | 本次預期不需要。 | 不適用 |

## 5. Acceptance Criteria

### AC-001：月結課程可查看逐期帳單

- AC-001-a：主任在課程管理看到月結課程時，系統顯示「帳單」入口或在詳情區顯示逐期帳單。
- AC-001-b：點開帳單後，系統列出每期 `billing_period`、繳費日、金額、已繳金額、狀態。

### AC-002：帳單狀態清楚可辨識

- AC-002-a：`paid` 顯示「已繳」。
- AC-002-b：`unpaid` 顯示「未繳」。
- AC-002-c：`partial` 顯示「部分繳」。

### AC-003：舊有月結課程有空狀態

- AC-003-a：若 API 回傳空陣列，系統顯示「尚無帳單記錄（舊有課程）」。
- AC-003-b：空狀態不得讓主任誤以為已繳。

### AC-004：堂數制不混入月結帳單入口

- AC-004-a：堂數制課程維持現有付款與續報加購操作。
- AC-004-b：堂數制不顯示月結逐期帳單入口。

### AC-005：權限與分校隔離維持

- AC-005-a：主任只能查看自己分校可存取課程的帳單。
- AC-005-b：跨分校呼叫 invoices API 仍回 403。

## 6. 功能需求 FR

- FR-001：系統應在課程管理月結課程提供逐期帳單入口。
- FR-002：系統應使用既有 invoices API 取得帳單列表，不重算付款狀態。
- FR-003：系統應顯示期別、繳費日、總金額、已繳金額與狀態。
- FR-004：系統應在帳單資料載入中提供 loading 狀態。
- FR-005：系統應在無帳單時顯示舊課程空狀態文案。
- FR-006：系統應保持堂數制加購與月結續報分流不變。
- FR-007：系統應保留 campus isolation，不新增可繞過授權的資料查詢。

## 7. 非功能需求 NFR

- NFR-001：開啟單一課程帳單 modal 的 API 回應目標 < 500ms（一般資料量）。
- NFR-002：前端不得在載入全部課程時一次拉取所有課程 invoices，避免 N+1 API；採使用者點擊後 lazy load。
- NFR-003：帳單 modal 載入失敗時顯示錯誤或空狀態，不造成課程管理整頁白屏。

## 8. 技術方向

- 前端：在 `CourseManagement.vue` 增加月結帳單入口與 modal / 或詳情內帳單區塊。
- API：沿用 `GET /api/v1/student-classes/{id}/invoices`。
- 後端：預期不改；若 ARCH 發現 CourseManagement 需要額外欄位，優先擴充既有 invoices API，而非新增平行 API。
- UI 策略：參考 `StudentsList.vue` 現有帳單 modal，避免同一帳務資訊在兩個頁面呈現不一致。
- 資料模型：維持「課程 / 合約」與「Invoice / 帳單期別」分離；月結續報不新增 `StudentClass`。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-27 | 月結續報維持同一課程，新增 / 顯示逐期 Invoice。 | 每月複製一筆 `StudentClass`。 | 業界 recurring billing 通常分離 contract / invoice；複製課程會讓排課、出勤、統計碎片化。 |
| 2026-04-27 | CourseManagement 採 lazy load invoices。 | 課程列表一次載入所有 invoices。 | 避免列表 N+1 資料膨脹，只有主任需要查看時才打 API。 |
| 2026-04-27 | 優先重用 StudentsList 的帳單呈現語義。 | 重新設計另一套帳單 UI。 | 避免同一帳單狀態在不同頁面文案和顏色不一致。 |

## 9. 資安與存取控制

**觸發原因**：帳單資料包含學生姓名、付款狀態、金額，屬 PII / 財務資訊；且涉及 director 權限與 campus isolation。

**STRIDE 快評**
- S：沿用既有 Bearer token 與 director route middleware。
- T：本次預期為 read-only UI；不新增修改帳單狀態的入口。
- R：不新增付款寫入，因此不新增核帳 log requirement。
- I：不得在前端暴露跨分校帳單；API 403 行為維持。
- D：採 click lazy load，避免列表頁大量請求。
- E：不新增公開端點；沿用已授權 route。

## 10. QA 驗收

### Happy Path

- 月結課程有兩期帳單：最新未繳、上期已繳，課程管理可看到兩期且排序正確。
- 主任點「帳單」後 modal 顯示期別、繳費日、金額、狀態 chip。

### Edge Cases

- 舊有月結課程沒有 Invoice：顯示空狀態，不顯示已繳。
- 部分繳款：顯示「部分繳」與已繳金額。
- 堂數制課程：不顯示月結帳單入口。

### Error Cases

- invoices API 403：UI 顯示無權限或載入失敗，不白屏。
- invoices API 500 / network error：UI 顯示載入失敗，不影響課程列表。

### Revert-proof 驗證

- 新增前端測試後，將 CourseManagement 帳單入口改回不存在，測試應失敗。
- 後端既有 `MonthlyInvoiceListTest` 維持通過。

## 11. 上線與維運

**部署步驟**
- 本次會修改 `frontend/src/**`，需走 feature branch → PR → CI 全綠 → merge → `deploy.yml` 自動部署。
- 不直接在 Pi 修改任何檔案。
- 不手動執行 `npm run deploy`。

**Feature Flag 策略**
- 不使用 feature flag。理由：此修復只增加已存在資料的 read-only 可視化入口，不改收款狀態寫入流程。

**Observability**

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 前端部署 | `deploy.yml` success | workflow failure | `[OPS]` |
| API 健康 | `/api/v1/health` | 非 200 | `[OPS]` |
| 帳單 API | `student-classes/{id}/invoices` | 4xx/5xx 明顯增加 | `[OPS]` |

**回滾**
- 無 migration 時：`git revert <commit>` 走 PR / CI / deploy，預估 10-20 分鐘。
- 若 ARCH 後確認需要後端欄位異動，需另補 migration rollback 策略。

## 12. 優先級

| 優先級 | 項目 | Agent |
|---|---|---|
| P0 | 不改 `AlertController::tuition`、不改已確認月結提醒規則。 | `[DEV]` |
| P1 | CourseManagement 顯示逐期帳單入口與 modal。 | `[DEV]` |
| P1 | 測試月結帳單顯示與堂數制不顯示入口。 | `[TEST]` |
| P1 | 前端 build / CI 全綠。 | `[TEST]` |
| P2 | UI 文案、空狀態、loading、狀態 chip 精緻化。 | `[DEV]` |
| P2 | CHANGELOG 記錄。 | `[DOCS]` |
| P2 | 部署後 health check。 | `[OPS]` |

## 13. 風險 / 假設 / 開放問題

WebSearch 摘要：SaaS / EdTech recurring billing 常見做法是定義 billable units、生成 recurring invoices、同步付款狀態回產品；InvoiceQuickly 與 SchematicHQ 都強調 invoice date、service period、usage / access / invoice / payment 狀態需分離與定期 reconciliation。EdTech subscription billing 文章也指出 recurring payments 需要 subscription management 與 LMS / enrollment 整合。

| 風險 | 等級 | 業界標準解法（來源） | 本專案採行方式 |
|---|---|---|---|
| 月結續報若每月複製課程，課務歷史與排課統計碎片化。 | 中 | SaaS billing 將 contract / subscription 與 invoice period 分離（SchematicHQ、InvoiceQuickly）。 | 維持 `StudentClass` 為課程合約，`Invoice.billing_period` 為每期帳單。 |
| 課程頁只顯示單一 paid 狀態，會掩蓋多期帳單狀態。 | 高 | Billing state should sync back into product and remain visible by billing cycle（SchematicHQ）。 | CourseManagement 顯示逐期 invoices，不只顯示 `StudentClass.Paid`。 |
| 一次載入所有 invoices 造成列表變慢。 | 中 | Billing data should be queried by relevant customer / subscription context, not bulk-loaded unnecessarily。 | 點擊帳單時 lazy load。 |
| 帳單與付款狀態不一致。 | 中 | Recurring billing 需 regular reconciliation（SchematicHQ）。 | 本次顯示 invoice 原始狀態；不另行推算，避免雙重 truth。 |

**假設**
- 既有 invoices API 已部署且可用；若 CI 測試或 staging 呼叫失敗，回退為先修後端 API。
- CourseManagement 月結課程可由 `payment_type === 'monthly'` 判斷；若資料混用 `ScheduleMode`，由 ARCH 階段補一致 mapping。
- 本次只解決可視化與查詢，不改收款入帳期別選擇；若主任需要「指定某期入帳」，需另開後續 PR。

**開放問題**
- `[AI-RESOLVABLE]` 是否將帳單入口放在列表操作列，或詳情展開區同步顯示最近 3 期：由 ARCH/UX 讀現有版面後決定。
- `[AI-RESOLVABLE]` 是否抽共用 `MonthlyInvoiceModal` 元件，避免 `StudentsList.vue` 與 `CourseManagement.vue` 重複：由 DEV 評估重構成本。

## 14. Definition of Done

- [ ] FR-001/FR-003：驗證方式：前端測試或 component test 斷言月結課程顯示帳單入口與期別 / 狀態文字。
- [ ] FR-002/FR-007：驗證方式：CI 執行 `MonthlyInvoiceListTest` 回傳 success，跨分校案例 403。
- [ ] FR-004/FR-005：驗證方式：前端測試覆蓋 loading 與 empty state。
- [ ] FR-006：驗證方式：前端測試斷言堂數制課程不顯示月結帳單入口。
- [ ] Revert-proof：驗證方式：移除 CourseManagement 帳單入口後，新增前端測試至少 1 case failure。
- [ ] Frontend build：驗證方式：GitHub Actions 前端 job completed success。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-04-27 課程管理月結帳單可視化條目。
- [ ] 部署：驗證方式：PR merge 後 `deploy.yml` success，`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 HTTP 200 與 `status=ok`。

## Todos

- [ ] `[ARCH]` 確認 CourseManagement 帳單入口位置、是否抽共用 modal、是否需要擴充 API 欄位。
- [ ] `[DEV]` 前端 UI：在 CourseManagement 月結課程加入帳單入口與 modal / 詳情帳單區。
- [ ] `[DEV]` 後端 API：預期不改；若 ARCH 發現欄位不足，擴充既有 invoices API。
- [ ] `[DEV]` UI/UX 精緻化：loading、empty state、status chip、錯誤狀態、響應式。
- [ ] `[TEST]` Regression Tests：覆蓋月結顯示、堂數制不顯示、空狀態、API 403。
- [ ] `[TEST]` Revert-proof 驗證。
- [ ] `[REVIEW]` 資安靜態審查：確認無新增公開端點、無跨分校洩漏。
- [ ] `[REVIEW]` Code Review：逐條對照 FR 與既有月結提醒規則。
- [ ] `[DOCS]` 更新 `docs/CHANGELOG.md`。
- [ ] `[OPS]` PR merge 後監控 `deploy.yml`、health check、前端版本更新。

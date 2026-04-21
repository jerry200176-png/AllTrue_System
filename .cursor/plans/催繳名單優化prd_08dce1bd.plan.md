---
name: 催繳名單優化PRD
overview: 針對新店分校催繳名單的付款狀態誤解、付款資訊呈現不清，以及主任希望可直接在名單操作繳費狀態的需求，提出一份以資料正確性、可稽核性與前台操作效率為核心的跨部門優化 PRD。版本 v1.0，狀態：Approved for implementation。
todos:
  - id: backend-payment-summary
    content: "[FEATURE/後端] 擴充 `GET /api/v1/alerts/tuition` 回傳欄位：每筆加入 `charge`（應繳）、`paid_amount`（已繳）、`outstanding`（未結清）、`payment_status`（六種狀態值）、`latest_payment_report_id`（最近一筆 pending report id）。查詢方式：對現有 studentClassIds 批次 join Invoice + Payment 聚合，不改動 alerts/tuition 列入規則，僅補充資料。"
    status: completed
  - id: backend-void-api
    content: "[FEATURE/後端] 新增 `PUT /api/v1/payment-reports/{id}/void` 撤銷收款 API：僅限 director/admin/super_admin；僅允許對 status=confirmed 的 report 操作；需傳入 `void_reason`（必填 string max:500）；執行回滾：新建負值 Payment（amount = -original）、重算 Invoice.PaidAmount / Invoice.Status、若 Invoice 回到 unpaid 則清除 StudentClass.Paid 改回 0 並清 PayDate；原 PaymentReport 標記 status=voided，寫入 voided_by、voided_at、void_reason；全程 DB transaction + Log::info('[PaymentVoid]')。"
    status: completed
  - id: backend-tests
    content: "[TEST/後端] 補齊 Pest Feature Tests：(1) alerts/tuition 回傳六種 payment_status 的正確案例；(2) void API 正常回滾 Payment/Invoice/StudentClass；(3) void API 拒絕對 pending/voided report 操作（422）；(4) void API 分校隔離（403）；(5) 部分付款後 outstanding 計算正確；(6) void 後 alerts/tuition 仍正確列入（Paid 回到 0）。"
    status: completed
  - id: frontend-columns
    content: "[FEATURE/前端] 改造 `TuitionCollectionPage.vue` 表格欄位：移除原本的「繳費日期」欄（語意不清）；新增「應繳」「已繳」「未結清」三欄（顯示金額數字，未結清 > 0 時以警示色標注）；「狀態」欄改用 `payment_status` 驅動六種標籤（見 §6 狀態機）；「最近付款」欄改為從新欄位 `last_paid_at` 或 paid_amount > 0 時才顯示，且加上 tooltip 說明語意。"
    status: completed
  - id: frontend-quick-action
    content: "[FEATURE/前端] 在催繳名單列表的「操作」欄新增快速操作邏輯：payment_status=unpaid 或 partial 時，顯示「核帳登記」（呼叫現有 PaymentEntryModal）；payment_status=pending_report 時，顯示「確認入帳」（呼叫 PUT payment-reports/{id}/confirm）與「退回」（呼叫 PUT .../reject）；payment_status=paid / renew_needed 時，已繳列顯示「撤銷收款」按鈕（二次確認 + 原因輸入 → 呼叫 PUT .../void）；核帳完成後呼叫 loadAlerts() 刷新整列。"
    status: completed
  - id: frontend-uiux
    content: "[FEATURE/UIUX] 精緻化 TuitionCollectionPage 視覺：(1) 表格上方 summary cards 新增「未結清總額」；(2) 六種狀態標籤對應色彩（見 §5b）；(3) 所有非同步操作加 loading spinner + disabled button；(4) 成功 / 失敗改為 toast（現有 tc-toast 機制延伸）；(5) 空狀態補圖示 + 說明 + CTA；(6) 首次載入改為 skeleton rows（5列骨架，寬度固定）；(7) 撤銷收款二次確認彈窗含原因 textarea。"
    status: completed
  - id: security-review
    content: "[REVIEW/資安] 審查：(1) void API 是否有分校隔離（student.CampusID in auth_campus_ids）；(2) void API 是否有 role 限制（teacher 不可呼叫）；(3) alerts/tuition 補充欄位是否洩漏他校 Payment 資料；(4) outstanding 金額計算是否可被前端偽造。"
    status: completed
  - id: code-review
    content: "[REVIEW] 審查：(1) void 回滾的 DB transaction 是否完整（Payment/Invoice/StudentClass 三表一致性）；(2) alerts/tuition 補充欄位的 N+1 query 風險；(3) payment_status 計算邏輯是否與 DIRECTOR_PAYMENT_ALERT_RULES.md 一致；(4) 已繳不產催繳圖的限制是否仍完整。"
    status: completed
  - id: qa-acceptance
    content: "[QA] 執行 §10 Happy Path / Edge / Error / 回歸測試案例，含六種狀態渲染、void 流程、部分付款顯示、金額三欄對照、分校切換後名單刷新。"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 docs/CHANGELOG.md（加本次改版項目）；更新 docs/AI_REGRESSION_LESSONS.md（補 void API 的禁止回歸項）；更新 docs/DIRECTOR_PAYMENT_ALERT_RULES.md（注明 payment_status 補充欄位與 alerts/tuition 規則的分離關係）。"
    status: completed
  - id: deploy-release
    content: "[Ops] 前端改動後執行 `cd /home/admin/frontend && npm run deploy`，確認 index.html 與 assets 同步；驗證催繳名單頁載入正常、六種狀態標籤正確顯示、核帳登記後該列立即刷新。"
    status: completed
  - id: pm-signoff
    content: "[PM] 確認所有 FR、回歸測試、稽核 log、DoD 全部完成後 sign-off。"
    status: completed
isProject: false
---

# 催繳名單頁面優化 PRD

## 1. 文件資訊

- 功能名稱：催繳名單頁面優化（付款狀態精確化 + 快速操作 + 撤銷收款）
- 版本 / 日期：v1.0 / 2026-04-16
- 狀態：Approved for implementation
- 目標角色：主任、櫃檯 / 會計

---

## 2. 目標與業務背景

**根本問題（Root Cause）**

目前 `alerts/tuition` 只回傳 `paid`（布林）與 `last_paid_at`（最後一筆 Payment 日期）。前端把這兩個欄位同時顯示在同一列，造成「有日期 → 以為已結清」的誤判，實際上該課程可能是：
- 堂數制：有舊付款紀錄但本期 Paid=0（部分付款）
- 月結制：已繳本月但下月即將到期
- 待核帳：家長已回報但主任尚未確認

沒有「部分付款 / 待核帳 / 月結逾期」等中間狀態，一律只靠 `未繳費 / 已繳費` 二分法是設計缺陷，不是資料錯誤。

**業務價值**
- 消除「有日期卻顯示未繳」的現場困惑
- 主任在催繳名單直接完成收款，不需切頁
- 建立可稽核的付款回退機制，符合補教財務基本合規需求

**成功 KPI**
- 催繳名單相關現場疑問回報歸零
- 從名單完成一筆核帳的點擊步數 ≤ 3 步
- 付款狀態不一致案例（Paid=0 但有 Payment 紀錄且應結清）下降至 0

---

## 3. 範圍

**In Scope**
- 擴充 `alerts/tuition` 回傳：加入金額三欄與六種 `payment_status`
- 催繳名單表格欄位重設計（應繳 / 已繳 / 未結清 / 狀態 / 操作）
- 快速操作：在名單直接核帳、確認入帳、退回家長回報
- 撤銷收款（Void）API 與前端二次確認流程
- 狀態標籤色彩系統、skeleton loading、toast 回饋

**Out of Scope**
- 不更動 `alerts/tuition` 的列入條件（規則由 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 管制）
- 不重構 `BillingList`、`TuitionReportPage`、家長端付款回報主流程
- 不實作 CSV 匯出或批次催繳功能（可列入未來 P2）

---

## 4. RACI

- PM：Accountable — 確認範圍、驗收與上線節奏；審查 §13 緩解策略是否落地。
- CTO / 工程：Responsible — 實作 §8 所有後端 API 與前端頁面變更；確認 §13 每條風險的緩解技術路徑可行。
- UI/UX Designer：Responsible — 設計 §5b 所有視覺精緻化規格，並在 QA 完成後 sign-off。
- QA：Responsible — 依 §10 執行所有驗收案例，包含六種狀態渲染、void 回滾、金額計算、回歸。
- 資安：Consulted — 審查 §9 STRIDE 快評，重點確認 void API 的分校隔離與角色權限。
- IT / Ops：Informed — deploy 流程、migration 執行、監控設定。

---

## 5. User Stories

**US-1 主任：一眼看懂每筆狀態**
As a 主任, I want 在催繳名單看到明確的付款狀態標籤與金額三欄對照, so that 我不會因為日期存在就誤判課程已結清。

Acceptance Criteria：
- [ ] 每筆名單顯示「應繳 / 已繳 / 未結清」三個金額欄位。
- [ ] 狀態欄顯示六種語意標籤之一（見 §6 狀態機），不再只有「未繳費 / 已繳費」。
- [ ] 已有付款紀錄但尚未結清時，狀態顯示「部分付款」而非「未繳費」。

**US-2 櫃檯：快速完成收款登記**
As a 櫃檯 / 會計, I want 從名單列表直接核帳或確認家長回報，不需跳到其他頁面, so that 電話催繳時可立即更新狀態。

Acceptance Criteria：
- [ ] 未繳 / 部分付款的列有「核帳登記」按鈕，點擊開啟現有 PaymentEntryModal。
- [ ] 待核帳的列有「確認入帳」與「退回」兩個按鈕。
- [ ] 核帳或確認完成後，該列狀態與金額欄位在同一頁面立即刷新，無需手動重整。
- [ ] 每次狀態變更保留付款日期、方式、金額、操作者 ID 與時間戳。

**US-3 主任：撤銷錯誤的核帳紀錄**
As a 主任, I want 在發現核帳錯誤時，對已完成的收款做正式撤銷，而不是靠手動清資料庫, so that 財務紀錄可追溯且不留孤兒資料。

Acceptance Criteria：
- [ ] 已繳狀態的列有「撤銷收款」按鈕（僅 director / admin / super_admin）。
- [ ] 點擊後顯示二次確認彈窗，必須輸入撤銷原因才能送出。
- [ ] 撤銷完成後：原 Payment 建立對應負值沖銷紀錄、Invoice 狀態重算、StudentClass.Paid 回歸 0、PaymentReport 標記 voided。
- [ ] 撤銷後名單該列恢復未繳 / 部分付款狀態，可再次進行核帳操作。

---

## 5b. UI/UX 精緻化需求

受影響頁面：`frontend/src/pages/TuitionCollectionPage.vue`

**版面層次**
- Summary cards 區塊新增第四張「未結清總額」卡（紅色）。
- 表格欄位從左至右：學生、科目、模式、狀態標籤、應繳、已繳、未結清、最近付款日、到期 / 逾期、操作。
- 原本的「繳費日期」欄整合為「最近付款日」，僅在有付款紀錄時顯示，且欄位 tooltip 說明「此為最後一筆付款日，非本期是否結清的依據」。

**六種狀態標籤色彩規格**

| payment_status | 標籤文案 | 色彩語意 |
|---|---|---|
| `unpaid` | 未繳費 | 危險色（紅）|
| `partial` | 部分付款 | 警示色（橘）|
| `pending_report` | 待核帳 | 警示色（橘）+ 閃爍點 |
| `paid` | 已繳費 | 成功色（綠）|
| `renew_needed` | 已繳需續課 | 中性色（藍）|
| `monthly_due_soon` | 月結將到期 / 已逾期 | 逾期用危險色，近期用警示色 |

**互動回饋**
- 所有按鈕點擊後立即進入 `disabled + loading spinner` 狀態，避免重複送出。
- 核帳成功顯示綠色 toast（持續 3 秒）：「已完成核帳登記」。
- 撤銷成功顯示橘色 toast：「已撤銷收款，狀態已重置」。
- 操作失敗顯示紅色 toast 並說明原因（直接顯示後端 `message`）。

**空狀態設計**
- 全校無提醒：顯示綠色勾勾圖示 + 「本分校目前無待催繳課程」+ 「查看當月學收」CTA。
- 搜尋無結果：顯示放大鏡圖示 + 「找不到包含 xxx 的學生」+ 「清除搜尋」按鈕。

**載入狀態**
- 首次載入與分校切換時顯示 5 列 skeleton rows（學生欄寬 120px、金額欄寬 80px），待 API 完成後替換。

**撤銷收款彈窗**
- 彈窗標題：「撤銷收款確認」。
- 說明文字：「此操作將作廢原付款紀錄並重置繳費狀態，無法自動還原，請確認。」
- 輸入框：「撤銷原因（必填）」textarea，max 500 字。
- 按鈕：「取消」（ghost）、「確認撤銷」（danger 色，disabled 直到有輸入內容）。

**響應式**
- 桌機（≥ 1024px）：完整表格欄位。
- 平板（768–1023px）：隱藏「應繳」欄，合併為「已繳 / 應繳」tooltip。
- 手機（< 768px）：改為卡片式，每張卡顯示學生名、狀態標籤、未結清金額、操作按鈕。

---

## 6. 功能需求（FR）

**付款狀態機定義（payment_status 六種值）**

後端計算邏輯優先序（由後端算好回傳，前端不自行判斷）：

```
1. pending_report：payment_reports 中存在 status='pending' 的未確認回報
2. partial：Invoice.PaidAmount > 0 且 Invoice.PaidAmount < Invoice.TotalAmount
3. unpaid：StudentClass.Paid = 0（且無以上兩種情況）
4. renew_needed：StudentClass.Paid = 1 且 RemainingSessions <= 2（堂數制）
5. monthly_due_soon：ScheduleMode='date' 且 paid=true（月結制已繳，下期將到）
6. paid：StudentClass.Paid = 1 且不屬於 4 / 5（已完全結清）
```

注意：同一課程在 `alerts/tuition` 出現，代表它已符合提醒條件。`payment_status` 只是補充顯示語意，不影響是否列入名單。

---

- **FR-001**：`GET /api/v1/alerts/tuition` 每筆回傳新增以下欄位：
  - `payment_status`（string，六種值之一）
  - `charge`（integer，應繳金額，來自 `StudentClass.Charge`）
  - `paid_amount`（integer，已繳金額，來自 Invoice.PaidAmount 聚合；無 Invoice 則為 0）
  - `outstanding`（integer，= charge - paid_amount，最小值為 0）
  - `latest_payment_report_id`（integer | null，最近一筆 status=pending 的 PaymentReport.id）
  - 現有 `last_paid_at`、`paid`、`due_date`、`days_until_settlement` 欄位保留不變。

- **FR-002**：`payment_status` 必須由後端計算，前端禁止自行推導，確保所有頁面（催繳名單、主任總覽）狀態語意一致。

- **FR-003**：催繳名單表格新增「應繳」「已繳」「未結清」三個金額欄位，格式為 `NT$ X,XXX`；未結清 > 0 時以警示色標注數字。

- **FR-004**：狀態欄改用 `payment_status` 驅動，顯示六種標籤文案（見 §5b 色彩規格），不再只有「已繳費 / 未繳費」。

- **FR-005**：`payment_status = unpaid` 或 `partial` 時，操作欄顯示「核帳登記」按鈕，呼叫現有 `PaymentEntryModal`（`POST /api/v1/payment-reports/director-record`）。

- **FR-006**：`payment_status = pending_report` 時，操作欄顯示「確認入帳」（`PUT /api/v1/payment-reports/{latest_payment_report_id}/confirm`）與「退回」（`PUT .../reject`）兩個按鈕。

- **FR-007**：`payment_status = paid` 或 `renew_needed` 時，操作欄顯示「撤銷收款」按鈕（僅 director / admin / super_admin 可見），點擊觸發撤銷二次確認彈窗。

- **FR-008**：撤銷收款 API `PUT /api/v1/payment-reports/{id}/void`，規格如下：
  - 僅允許 `status = confirmed` 的 PaymentReport。
  - 必填 body：`void_reason`（string, max:500）。
  - 執行順序（DB transaction）：
    1. 建立一筆負值 Payment（`Amount = -original_amount`，`Method = 'void'`，`Note = void_reason`，`payment_report_id = report.id`）。
    2. 重算 `Invoice.PaidAmount`（`PaidAmount + (-original_amount)`，最小為 0）。
    3. 重算 `Invoice.Status`（`PaidAmount = 0 → unpaid`，`0 < PaidAmount < TotalAmount → partial`）。
    4. 若 Invoice 回到 `unpaid`，清除 `StudentClass.Paid = 0`，清除 `StudentClass.PayDate`。
    5. 更新 `PaymentReport.status = 'voided'`，寫入 `voided_by`（user_id）、`voided_at`、`void_reason`。
  - 寫入 `Log::info('[PaymentVoid]', [report_id, payment_id, user_id, void_reason])`。
  - 回傳 200 `{ message: '已撤銷收款', report_id, invoice_status, student_class_paid }`。
  - 拒絕條件：`status != confirmed` → 422；跨分校 → 403；不存在 → 404。

- **FR-009**：所有操作（核帳登記、確認入帳、退回、撤銷收款）完成後，前端呼叫 `loadAlerts()` 重新拉取名單，更新整列狀態、金額欄、操作欄，不需完整重整頁面。

- **FR-010**：`alerts/tuition` 的列入規則（堂數制 / 月結制條件）不因本次改版更動，僅補充回傳欄位。`payment_status` 的計算邏輯與列入規則無關，即使 `payment_status = paid`，只要符合列入條件（如低堂數）該筆仍出現在名單。

- **FR-011**：已繳（`paid = true`）的課程仍不得產出「催繳通知單圖片」（`GET /api/v1/alerts/tuition-slip`），後端 422 擋關，前端「繳費單」按鈕只在 `unpaid` / `partial` 時顯示。此限制不因本次改版移除。

- **FR-012**：撤銷收款操作不得暴露給 `teacher` 角色，前端按鈕依 `auth_role` 判斷是否渲染，後端 API 中介層也需驗證角色。

---

## 7. 非功能需求（NFR）

- **效能**：`alerts/tuition` 補充欄位的 Invoice / Payment 聚合查詢，必須使用批次 `whereIn`（現有 `lastPaidAtByStudentClassIds` 模式延伸），不得對每筆課程個別查詢，避免 N+1。目標：分校 50 筆課程的 API 回應時間 < 800ms。
- **一致性**：核帳完成後，單次 `loadAlerts()` 刷新後，同一課程的 `payment_status`、`paid_amount`、`outstanding` 必須與資料庫一致，不允許有中間態。
- **降級策略**：若 Invoice / Payment 聚合查詢失敗（DB timeout），API 仍可回傳基本提醒名單，但 `paid_amount`、`outstanding` 標記為 `null`，前端對 `null` 欄位顯示「—」而不崩潰。
- **可觀測性**：以下操作必須有 `Log::info` 紀錄：核帳登記、撤銷收款（`[PaymentVoid]`）、確認入帳、退回。每條 log 含 `report_id`、`user_id`、`student_class_id`、`amount`。
- **可維護性**：`payment_status` 計算邏輯集中在後端（`AlertController` 或獨立 private method），前端不重複實作任何付款狀態推導。

---

## 8. 技術方向

### 後端異動

**`backend/app/Http/Controllers/AlertController.php`**
- 在 `tuition()` 方法中，對現有 `$allClassIds` 批次查詢：
  - Invoice 聚合：`SELECT StudentClassID, SUM(PaidAmount), MAX(Status) FROM Invoice WHERE StudentClassID IN (...) GROUP BY StudentClassID`
  - PaymentReport 未確認：`SELECT StudentClassID, id FROM payment_reports WHERE StudentClassID IN (...) AND status='pending' ORDER BY created_at DESC`（取每個 StudentClassID 最新一筆）
- 新增 private method `computePaymentStatus(StudentClass $c, ?int $invoicePaid, ?int $hasPendingReport): string`，依 §6 狀態機優先序回傳六種值之一。
- 在回傳的 `$rows` map 區塊補入 `payment_status`、`charge`、`paid_amount`、`outstanding`、`latest_payment_report_id`。

**`backend/routes/api.php`**
- 在 director 路由群組新增：`Route::put('payment-reports/{id}/void', [PaymentReportController::class, 'void'])`。

**`backend/app/Http/Controllers/PaymentReportController.php`**
- 新增 `void(Request $request, $id)` 方法，實作 §FR-008 完整邏輯（DB transaction，負值 Payment，Invoice 重算，StudentClass 重置，report 標記）。
- 資料表需補 `payment_reports` 的 `voided_by`、`voided_at`、`void_reason` 欄位（migration）。

**Migration（若需要）**
- `2026_04_16_XXXXXX_add_void_fields_to_payment_reports_table.php`：新增 `voided_by`（unsignedBigInteger nullable）、`voided_at`（timestamp nullable）、`void_reason`（string 500 nullable）、並更新 `status` enum 新增 `voided` 值。

### 前端異動

**`frontend/src/pages/TuitionCollectionPage.vue`**
- 表格 `<thead>` 移除「繳費日期」欄，新增「應繳」「已繳」「未結清」「最近付款」四欄（可視情況合併欄位以控制寬度）。
- `<tbody>` 的狀態欄由 `r.paid` 布林改為 `r.payment_status` string 驅動，使用 computed helper `paymentStatusLabel(status)` 與 `paymentStatusClass(status)` 轉換。
- 操作欄邏輯改為 `v-if` 依 `payment_status` 分支渲染（unpaid/partial → 核帳登記；pending_report → 確認/退回；paid/renew_needed/monthly_due_soon → 撤銷收款）。
- 新增 `openVoid(row)` 方法，開啟撤銷確認彈窗；新增 `confirmVoid(reason)` 方法，呼叫 `PUT /api/v1/payment-reports/{id}/void`，成功後 `loadAlerts()`。
- Summary cards 補第四張「未結清總額」：`rows.value.reduce((sum, r) => sum + (r.outstanding || 0), 0)`。
- Loading 狀態改為 skeleton rows（現有 `tc-loading` div 替換為 skeleton table）。

**`frontend/src/components/PaymentEntryModal.vue`**
- 無需改動（現有 `director-record` 路徑繼續使用）。

### 資料流圖

```
主任開啟催繳名單
  └→ GET /api/v1/alerts/tuition?branch_id=X
       ├→ StudentClass（提醒條件）
       ├→ Invoice 聚合（paid_amount）
       ├→ PaymentReport pending（latest_report_id）
       └→ 回傳：提醒列表 + payment_status + 金額三欄

主任點「核帳登記」
  └→ POST /api/v1/payment-reports/director-record
       └→ 建 Payment + Invoice + report(confirmed) + StudentClass.Paid=1
  └→ loadAlerts() 刷新

主任點「撤銷收款」→ 確認彈窗（輸入原因）
  └→ PUT /api/v1/payment-reports/{id}/void
       └→ 建負值 Payment + 重算 Invoice + StudentClass.Paid=0 + report(voided)
  └→ loadAlerts() 刷新
```

---

## 9. 資安與存取控制

- **角色限制**：`核帳登記`、`確認入帳`、`退回`、`撤銷收款` 僅限 `director / admin / super_admin`（middleware `role:director,admin,super_admin`）；`teacher` 角色不得存取任何付款狀態修改 API。
- **分校隔離**：void API 須驗證 PaymentReport 對應的 `student.CampusID` 在 `auth_campus_ids` 內，非 super_admin 時拒絕跨分校操作（403）。
- **PII**：學生姓名、付款金額、帳號末五碼僅在認證後且同分校條件下可見；不得在 log 中記錄完整金額或個資（只記 IDs 與操作型別）。
- **稽核 log**：每次付款狀態變更（核帳、確認、退回、撤銷）必須有 `Log::info` 含 `[操作類型]`、`user_id`、`report_id`、`student_class_id`。
- **STRIDE 快評**：
  - Spoofing：void API 需 Sanctum token 驗證，無法匿名呼叫。
  - Tampering：禁止前端直接 PATCH StudentClass.Paid；所有狀態變更走 PaymentReport transaction。
  - Repudiation：每筆 void 記錄 `voided_by` + `voided_at` + `void_reason`，不可事後清除。
  - Information Disclosure：`outstanding` 金額來自 Invoice（後端計算），前端不得自行以其他方式推算。

---

## 10. QA 驗收標準與測試計畫

### Happy Path

| # | 情境 | 預期結果 |
|---|---|---|
| H1 | 堂數制未繳課程 | payment_status=unpaid，outstanding = charge，顯示「核帳登記」按鈕 |
| H2 | 主任完成核帳登記 | 該列 payment_status 變 paid / renew_needed，outstanding=0，顯示「撤銷收款」按鈕，toast 成功 |
| H3 | 主任撤銷已完成的核帳（輸入原因） | payment_status 回 unpaid，StudentClass.Paid=0，Invoice.Status=unpaid，原 Payment 建立負值沖銷，report.status=voided |
| H4 | 家長回報待核帳 | payment_status=pending_report，顯示「確認入帳」「退回」按鈕 |
| H5 | 主任確認入帳 | payment_status → paid，list 刷新，確認入帳按鈕消失 |
| H6 | 部分付款（Invoice.PaidAmount > 0 < Total） | payment_status=partial，已繳欄顯示已繳金額，未結清欄顯示差額 |
| H7 | 月結逾期未繳 | payment_status=monthly_due_soon，days_until_settlement 為負，逾期文案與危險色 |

### Edge Case

| # | 情境 | 預期結果 |
|---|---|---|
| E1 | 有 last_paid_at 但 Paid=0（部分付款） | 不顯示「已繳費」，顯示 partial + 未結清金額，不讓使用者誤判已結清 |
| E2 | void 對 pending 狀態 report | 422：「此回報尚未確認，無法撤銷」 |
| E3 | void 對已 voided report | 422：「此收款已撤銷過」 |
| E4 | void 跨分校 | 403 Forbidden |
| E5 | 多次付款後 outstanding 計算 | paid_amount = 所有 Payment 的 SUM，outstanding 正確反映差額 |
| E6 | StudentClass.Charge = null / 0 | charge 顯示 0，outstanding 不為負值（最小為 0） |

### Error Case

| # | 情境 | 預期結果 |
|---|---|---|
| X1 | alerts/tuition 回傳 403 | 頁面顯示錯誤訊息，不崩潰 |
| X2 | void API 網路失敗 | toast 顯示失敗原因，按鈕恢復可點擊，名單不刷新 |
| X3 | 兩個瀏覽器同時核帳同一筆 | 第二個操作收到 422（payment_report 狀態已變），前端顯示可讀錯誤 |

### 回歸測試（禁止被這次改版破壞）

- `alerts/tuition` 的列入條件不得改變（與 `TuitionAlertsApiTest` 全部通過為準）。
- 已繳（`paid=true`）不得再呼叫 `GET /api/v1/alerts/tuition-slip`，後端 422 不得移除。
- 分校隔離不得因新增欄位查詢而洩漏他校 Payment 資料。
- `PaymentEntryModal` 的現有核帳登記流程不受影響。

### UI/UX 驗收清單

- [ ] 六種 payment_status 標籤文案與色彩符合 §5b 規格。
- [ ] 金額三欄（應繳 / 已繳 / 未結清）在有 / 無 Invoice 情況下均正確顯示。
- [ ] skeleton loading 在首次載入與分校切換時出現，API 完成後替換為真實資料。
- [ ] 所有操作按鈕點擊後立即進入 disabled + loading 狀態。
- [ ] 成功 / 失敗 toast 位置、持續時間與文案符合 §5b 規格。
- [ ] 撤銷收款彈窗：未輸入原因時「確認撤銷」按鈕保持 disabled。
- [ ] 空狀態（無提醒）顯示圖示 + 說明 + CTA。
- [ ] 搜尋無結果顯示正確空狀態。
- [ ] 手機版（< 768px）改為卡片式，關鍵操作按鈕不被截斷。

---

## 11. 上線與維運

**部署順序**
1. 執行 migration（void 欄位）。
2. 部署後端（AlertController 補充欄位 + PaymentReportController void method）。
3. 驗證 `GET /api/v1/alerts/tuition` 回傳包含新欄位且值正確。
4. 部署前端：`cd /home/admin/frontend && npm run deploy`（確保 index.html 與 assets 同步）。
5. 驗證催繳名單頁：六種狀態標籤正確、金額三欄顯示、核帳登記後即時刷新。

**監控項目**
- `GET /api/v1/alerts/tuition` 回應時間（目標 < 800ms）。
- `PUT /api/v1/payment-reports/{id}/void` 錯誤率（目標 0%）。
- 前端 `console.error` 出現 `payment_status undefined` 的頻率（目標 0）。

**回滾方案**
- 若 void API 出現問題：關閉前端「撤銷收款」按鈕渲染（feature flag 或快速 deploy），不影響現有核帳登記流程。
- 若 alerts/tuition 補充欄位導致效能問題：降回僅回傳原有欄位，前端 `payment_status` fallback 為 `paid ? 'paid' : 'unpaid'`（視覺降級但不崩潰）。

---

## 12. 里程碑與優先級

| 優先級 | 工項 | 執行 Agent |
|---|---|---|
| P0 | 擴充 `alerts/tuition` 新增 payment_status + 金額三欄 | `[FEATURE]` 後端 |
| P0 | 前端表格欄位重設計 + 六種狀態標籤 | `[FEATURE]` 前端 |
| P0 | 補齊 Pest 回歸測試（payment_status 六種 + 金額計算） | `[TEST]` |
| P1 | 新增 void API + migration | `[FEATURE]` 後端 |
| P1 | 前端撤銷收款入口 + 二次確認彈窗 | `[FEATURE]` 前端 |
| P1 | 補齊 void API 回歸測試 | `[TEST]` |
| P1 | UI/UX 精緻化（skeleton / toast / 空狀態） | `[FEATURE/UIUX]` |
| P1 | 資安 Review（void 分校隔離 / 角色限制） | `[REVIEW]` |
| P2 | 催繳名單與待核帳清單整合財務工作台 | 未來迭代 |

---

## 13. 風險、緩解策略與 Owner

| # | 風險 | 影響 | 緩解策略 | Owner |
|---|---|---|---|---|
| R1 | 補充欄位查詢導致 N+1，alerts/tuition 變慢 | 主任每次進催繳名單等待過久，影響使用意願 | 用批次 `whereIn` 聚合，不逐筆查詢；超過 800ms 需 Query Explain 分析並加索引 | 工程（Responsible）；QA 驗收效能（Consulted） |
| R2 | void 回滾流程若中途失敗（Payment 建立成功但 Invoice 未更新），導致資料不一致 | 財務紀錄矛盾，收款狀態錯誤 | 整個 void 邏輯包在 `DB::transaction`，任何步驟失敗全部 rollback；`Log::info` 只在 transaction 成功後執行 | 工程（Responsible）；QA 驗證 transaction 完整性（Consulted） |
| R3 | `payment_status` 計算邏輯與 `alerts/tuition` 列入條件分開，若未來有人改了列入規則卻沒同步更新狀態機，會造成語意不一致 | 主任看到已繳但仍在催繳名單，或未繳但顯示 paid 標籤 | `payment_status` 計算邏輯集中在 `AlertController` 同一個 private method，修改列入條件時需同步 review 此 method；加入回歸測試 | 工程（Responsible）；PM 審查改動範圍（Accountable） |
| R4 | `teacher` 角色透過直接呼叫 void API URL 繞過前端權限控制 | 老師偽造付款撤銷，破壞財務稽核 | 後端 middleware 加 `role:director,admin,super_admin` 驗證，與前端 UI 限制各自獨立 | 工程（Responsible）；資安 Review（Consulted） |
| R5 | 現有 `last_paid_at` 與新增 `outstanding` 欄位並排顯示，使用者可能誤讀「有付款日期 = 已結清」（同樣的認知問題） | 問題沒有根本解決，只是改成表格呈現 | 把「最近付款日」欄改為 tooltip 補充說明（非醒目欄位）；主欄位改為「未結清金額」以顏色差異明確傳達語意；QA 驗收 UI/UX 可讀性 | UI/UX Designer（Responsible）；QA sign-off（Consulted） |

**假設**
- 現場需求的「直接編輯繳費狀態」本質上是要更快完成正式核帳，而不是要移除付款紀錄流程；本 PRD 以此假設為設計基礎。
- `StudentClass.Charge` 為課程的應繳金額標準欄位；若有課程 Charge = null，以 0 處理並不列入 outstanding 計算。

---

## 14. Definition of Done

- [ ] `GET /api/v1/alerts/tuition` 回傳 `payment_status`、`charge`、`paid_amount`、`outstanding`、`latest_payment_report_id`，六種狀態值均有 Pest 測試覆蓋。
- [ ] `PUT /api/v1/payment-reports/{id}/void` 實作完成，DB transaction 完整，回歸測試通過。
- [ ] 催繳名單表格欄位重設計完成，六種狀態標籤顯示正確。
- [ ] 快速操作（核帳登記 / 確認入帳 / 退回 / 撤銷收款）全部實作，操作後即時刷新。
- [ ] UI/UX 驗收清單（§10）全部打勾，UI/UX Designer sign-off。
- [ ] 資安 Review 完成，void API 分校隔離與角色限制無阻擋項。
- [ ] 回歸測試（`TuitionAlertsApiTest` + 新增 void 測試）全部通過。
- [ ] 已繳不產催繳圖限制確認未被移除。
- [ ] 前端 `npm run deploy` 完成，index.html 與 assets 同步，頁面正常。
- [ ] `docs/CHANGELOG.md` 更新，`docs/AI_REGRESSION_LESSONS.md` 補 void 禁止回歸項。
- [ ] PM sign-off，CTO / 工程 Lead sign-off。

---

## 參考依據

- 現有提醒規則：[docs/DIRECTOR_PAYMENT_ALERT_RULES.md](/home/admin/docs/DIRECTOR_PAYMENT_ALERT_RULES.md)
- 防再犯與催繳名單限制：[docs/AI_REGRESSION_LESSONS.md](/home/admin/docs/AI_REGRESSION_LESSONS.md)（催繳名單與 tuition-slip 節）
- 目前催繳名單頁面：[frontend/src/pages/TuitionCollectionPage.vue](/home/admin/frontend/src/pages/TuitionCollectionPage.vue)
- 目前提醒 API：[backend/app/Http/Controllers/AlertController.php](/home/admin/backend/app/Http/Controllers/AlertController.php)
- 目前付款核帳 Modal：[frontend/src/components/PaymentEntryModal.vue](/home/admin/frontend/src/components/PaymentEntryModal.vue)
- 目前付款回報 / 主任登記 API：[backend/app/Http/Controllers/PaymentReportController.php](/home/admin/backend/app/Http/Controllers/PaymentReportController.php)
- 目前 alerts 測試：[backend/tests/Feature/TuitionAlertsApiTest.php](/home/admin/backend/tests/Feature/TuitionAlertsApiTest.php)
- 目前付款回報測試：[backend/tests/Feature/PaymentReportApiTest.php](/home/admin/backend/tests/Feature/PaymentReportApiTest.php)

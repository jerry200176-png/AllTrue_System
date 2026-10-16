---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-10-15
---

# 主任儀表板「繳費提醒」規則（不可漏）

資料來源：`GET /api/v1/alerts/tuition`（`AlertController::tuition`）。  
前端：`DirectorDashboard.vue`（區塊標題為「繳費／續課提醒」等，與本規則一致）。

## 一頁摘要（給 AI／工程師快速對齊）

**輔導課例外（產品確認 #325）：** `ClassType=tutoring` 一律不是學費／續課提醒、催繳、低堂數或對外繳費通知候選；不論 `Paid`、剩餘堂數、排課模式、金額或歷史異常。既有異常仍在原課務／帳務畫面唯讀呈現，不以提醒方式催收；歷史資料不回溯改寫。

| 模式 | 列入提醒的條件（皆須 `Stop = 0`，且堂數制／月結制各自還有欄位前提） |
|------|-------------------------------------------------------------------|
| **堂數制** `ScheduleMode = count` | **未繳費**（`Paid != 1`）**或** **剩餘堂數不足**（`RemainingSessions <= 2`，**含 0 堂**；已繳費也會列，屬續課／加購提醒）**或** **仍有未結帳單**（非 paid/void 的 Invoice；Founder Q2 2026-10-11，#3797／#3864：`Paid=1` 不能藏住還欠的帳單）。 |
| **月結制** `ScheduleMode = date` | 須有有效 **`settlement_day`（1–31）**。未繳且**已過**當月繳費日 → **一律**列入（逾期至標記已繳為止）。其餘：不論已繳或未繳，僅當「今天 ↔ 本次計算之繳費日」相隔 **0～4 天**（**小於 5 天**）才列入；**第 5 天整不算**。 |

## 最近繳費日期 `last_paid_at`（#4123）

課程列的 `last_paid_at`（帳務中心學生清單「最近繳費」）只代表**還有效的收款**，由 `BillingPayableResolver::lastPaidAtByStudentClassIds` 計算：

- 只看**非作廢**帳單（`Invoice.Status != 'void'`）上的 Payment。
- **收款**＝金額 > 0 且 `Method != 'void'` 的列；**沖銷**＝金額 < 0 的列，或 `Method = 'void'` 的列（不論正負，與 `PaidStatusParityTest` 的 `void_negative_cash`／`void_method_positive` 同一套 ledger）。
- 每張帳單內，沖銷依寫入順序（`Payment.id`）逐筆處理：
  1. 先抵銷它明確對應的收款：相同 `payment_report_id` 的收款，或其付款回報 `payment_reports.payment_id` 指向的收款（`BackfillLegacyPayments` 補建的舊收款只有這個單向關聯）。
  2. 其餘只能抵銷**比它早寫入**的收款（撤銷不能抵掉之後才重繳的款）：若剛好只有一筆早於它、剩餘金額相同的收款，就抵那一筆；否則從**最舊**的收款開始抵，無法確定時寧可保留最新收款的日期，不顯示更舊的日期。
  3. 若前面沒有收款可抵，剩餘才從最舊的後續收款抵，確保淨收款為 0 時沒有日期。
- 只有抵銷後仍 > 0 的收款能提供日期；整張帳單淨收款為 0 時沒有日期。
- 舊欄位 `StudentClass.PayDate` **只**在這門課**完全沒有任何 Payment 紀錄**時（含作廢帳單上的列、沖銷列都算有紀錄）才當作備援顯示；只要有任何 Payment 紀錄，就只看上述有效收款，避免收款後例外作廢（`exceptionVoidInvoice`）時舊日期又跑回來。
- `last_paid_at` 只是顯示資訊，**不是**「已收」的依據；「已收」只看 `payment_status`（見 `ContractMoneyState::alertStatus`）。只有舊 `Paid`／方案 `paid` 標記、沒有有效收款的合約如何顯示，見下方〈只有舊「已收」標記、沒有付款紀錄〉（#4114）。

## 變更管制（AI／工程師）

上述規則為**產品確認後之行為**（含「已繳但低堂數仍提醒」）。  
**任何 AI 或工程師**在修改 `AlertController::tuition`、`GET /api/v1/alerts/tuition` 之查詢條件／回傳欄位，或主任總覽與其連動之顯示邏輯前，**必須先取得使用者（產品方）明示同意**，並同步更新本檔與相關測試（如 `TuitionAlertsApiTest`、`LargeBranchDataHandlingTest`）。**禁止**為了「畫面看起來合理」而擅自改成只提醒未繳、漏月結、或漏掉 0 堂。

防再犯與沿革敘述：`docs/AI_REGRESSION_LESSONS.md`（搜尋「繳費／續課提醒」或 `AlertController::tuition`）。

## 堂數制（`StudentClass.ScheduleMode = count`）

- 課程須為進行中：`Stop = 0`。
- 符合**任一**即列入提醒：
  1. **未繳費**：`Paid != 1`
  2. **剩餘堂數不足**：`RemainingSessions <= 2`（**含 0 堂**，需續課／加購）
  3. **仍有未結帳單**（Founder Q2 2026-10-11，#3797）：即使 `Paid=1`，帳單＋Payment 紀錄說還欠就列入、可收款；收款只收**該張帳單**依 Payment 紀錄算出的未收額，超過回 422。

`alert_type`：`unpaid` 或 `low_sessions`（若同時符合，以前者優先欄位展示時前端以堂數／未繳狀態區分）。

### 多科共用堂數方案（2026-10-13 accepted contract；future Phase 2 待實作驗證）

本節原 2026-05-16「一律 rate × total_sessions」與「任何核帳成功即整包已繳」規則，由 Founder 2026-10-13 已決 [RFC_SHARED_PACKAGE_COLLECTION](architecture/RFC_SHARED_PACKAGE_COLLECTION.md) §2／§5a／§5c 取代；count package 的金額、部分收款、待核對、停止方案與通知例外以該 RFC 為單一合約來源。單科與月結規則不受此 supersession 影響。本次只同步文件，尚未宣稱 Phase 2 runtime 已實作／部署。

若堂數制課程屬於 `CoursePackage.billing_mode = count`：
- 主任提醒必須以 **方案層** 顯示一筆合併提醒，不可拆成單一科目提醒。
- `charge/outstanding` 依 RFC R4 已決 hybrid X 與 Payment-row aggregate remaining；不可一律收方案估算全額。無法判定者依 R8 待核對、金額 null；`remaining_sessions` 仍以 `CoursePackage.remaining_sessions` 顯示，已結清低堂數續課提示保留。
- 回傳 `package_id` 辨識一列整包；既有 member `id` 不得作為金額權威或自動選帳單的依據。多帳單收款依 RFC C1 每次明確選一張 invoice，所選可收餘額與整包總餘額分開。
- 正向收款／合法 reversal 依整包 ledger 結清結果同步 `CoursePackage.paid/paid_at` 與所有 member `Paid/PayDate`；部分實收不標整包已繳。所有 producer（含 report confirm、direct invoice payment、各 void 路徑）依 RFC §3a/§3b 共用 settlement 與 campus authorization，不是「核帳一次成功就標已繳」。
- count member 自動對外提醒／LINE 全部排除，staff in-app 例外依 R2-5；停止無帳單 pending 回報由 C2 獨立審核入口處理，不把它當作新待收。

### 續課抑制（2026-04-16 新增）

若一筆堂數制課程觸發 `low_sessions`，但**同一學生、同一科目**已存在另一筆進行中（`Stop=0`）且 `RemainingSessions > 2` 的課程（代表已續課），則該 `low_sessions` 提醒會被**自動抑制**，不再出現在催繳名單。`unpaid` 類型不受此邏輯影響。

## 月結制逐期帳單（2026-04-27 起）

月結課程「月結續約」後，系統自動：
1. 建立新一期 `StudentClass`，新課程 `Paid=0`、`PayDate=null`
2. 舊期課程 `Stop=1`、`closed_reason='settled'`，保留原本 paid 歷史
3. 在新課程底下建立新期 `Invoice`（`billing_period = YYYY-MM`，`Status='unpaid'`）

`directorRecord` 核帳時，優先找 `billing_period = 當月` 的未繳 Invoice；
主任收費後 Invoice.Status 更新為 `paid`，同時新一期 `StudentClass.Paid=1`。

主任提醒若存在 `Status in (unpaid, partial)` 的逐期 Invoice，必須優先使用最早未結清 Invoice 的 `DueDate` / `billing_period` 判斷是否進入 0～4 天或逾期提醒；不可用「今天所在月份 + settlement_day」覆蓋未來期帳單。

舊月結課程（無 Invoice）行為不變，fallback `StudentClass.Paid` 欄位。

## 月結制（`StudentClass.ScheduleMode = date`）

- 課程須為進行中：`Stop = 0`。
- 須設定 **`settlement_day`**（1–31）；無設定則不由此 API 推論月結提醒。
- 繳費日：每月該日；若該月無該日（如 2/31），則取該月最後一日。
- 時區：與 `config/app.php` 的 `timezone`（預設 `Asia/Taipei`）之「今天」為準。

### 未繳費（`Paid != 1`）

1. **尚未超過本月繳費日**（含當天）：僅當「今天距繳費日」**小於 5 天**時列入（即 0～4 天；第 5 天整不算）。
2. **已超過本月繳費日**：一律列入（視為逾期催繳），直到標記已繳。

### 已繳費（`Paid = 1`）

- 若今天 **尚未超過本月繳費日**（含當天）：以「本月繳費日」為下一截止日，距該日 **小於 5 天**（0～4 天）才列入。
- 若今天 **已超過本月繳費日**：以下一個月的繳費日為準，同樣 **小於 5 天** 才列入。

`alert_type`：`monthly_due_soon`；`days_until_settlement`：負數表示已逾期天數；`due_date`：本次計算的繳費日（Y-m-d）。

## 結案與不再提醒

提醒**僅列 `Stop = 0`（進行中）**的課程。課程如何離開提醒清單：

1. **加購新批次**（`POST /api/v1/student-classes/{id}/purchase-batch`）：若來源為堂數制、已繳（`Paid=1`）、剩餘 0 堂，系統**自動**將來源設 `Stop=1`（結案），不再出現在提醒中。
2. **不續報**：主任在「課程管理」或「學生課程」對已繳 + 0 堂的課程按**「結案（不續報）」**，實質寫 `Stop=1`（與暫停相同後端 API），該課程從提醒消失。結案後**不能**直接恢復（#4188）：伺服器回 409 `course_closed_use_renewal`，要繼續上課請用「續課」建立新的一期；單純暫停（`closed_reason` 為空）的課程仍可恢復。
3. 既有**暫停課程**（`togglePause`）同樣寫 `Stop=1`，效果一致。

例外（F7 S3a）：`Stop=1` 的合約只要帳單解析結果（`BillingPayableResolver`）仍為未繳／部分繳／未開單且欠款 > 0，就以 `pending_reconciliation`（結案／待查帳）列入，不論 `closed_reason`（暫停中無 reason 者也算）；`waived`（確認不收）與已繳清者不列。進行中（`Stop=0`）合約的規則不變。

## 回歸時請測

- 堂數制：已繳但剩 1～2 堂（或 0 堂）仍應出現。
- 堂數制多科共用方案：依上述 RFC 驗收一列／hybrid X／真實部分差額、明確單張 invoice、全 producer aggregate settlement、合法 reversal／campus fail-closed 與續課／通知例外；部分確認不標整包已繳，無法判定金額不催收。
- 加購後：舊約自動 `Stop=1`，`alerts/tuition` 僅列新約。
- 結案 UI：已繳 + 0 堂 → 結案後不再列。
- 月結：未繳、繳費日前第 4 天出現、第 5 天不出現；未繳且過繳費日仍出現。
- 分校：`branch_id` 與 `CampusID` 過濾正確。

## 曾發生過的錯誤（避免再犯）

- 只查 `ScheduleMode = count` 會**完全漏掉月結**。
- `remaining > 0 && <= 2` 會**漏掉 0 堂**。
- 將 API 改成「只未繳費」會**漏掉低堂數已繳費**。

## payment_status 補充欄位與列入規則的分離關係（2026-04-16）

`alerts/tuition` 回傳的 `payment_status`（六種值）為**顯示用補充欄位**，由 `AlertController::computePaymentStatus()` 計算。此欄位**不影響列入條件**——即使 `payment_status = paid`，只要課程符合上述堂數制或月結制的列入條件，仍會出現在名單中。

修改 `computePaymentStatus()` 時，不得連帶修改列入條件的 query；反之亦然。兩者為獨立邏輯，以避免互相干擾。

## 單科合約的 payment_status 與已收金額改讀 ContractMoneyVerdict（2026-10-11，ADMIN-OPS billing）

`alerts/tuition` 單科合約列的 `payment_status`（paid / partial / unpaid 的判定）與 `paid_amount` 改讀 `ContractMoneyVerdict`（帳單＋Payment 紀錄為準）；不再用 `StudentClass.Paid` 旗標與帳單「儲存的 PaidAmount 加總」。沒有帳單的舊合約仍以 `Paid` 旗標為準，Verdict 給不出答案（`review_required`）時沿用舊算法。**列入條件完全不變**（見上節：`payment_status` 不影響列入）。同一合約在帳務中心名單、課程查找、繳費通知上的「已繳／部分繳／未繳」因此一致。跨呼叫端一致性由 `ContractMoneyCallersAgreeTest` 守住；`Paid=1` 但仍有未收帳單的合約：Founder Q2（2026-10-11，#3797）決定列入並可收款（見堂數制第 3 條）。

## CoursePackage 月結方案的 payment_status（2026-04-22 Bug Fix）

`AlertController::tuition` 的月結多科方案（`CoursePackage.billing_mode = 'monthly'`）路徑中，`payment_status` 與 `outstanding` 需正確反映 `$pkg->paid`：

| `pkg->paid` | `payment_status` | `outstanding` |
|---|---|---|
| `false`（未繳）| `unpaid` | `$charge`（應收金額）|
| `true`（已繳）| `monthly_due_soon` | `0` |

**曾犯錯誤**：`payment_status` 被硬編碼為 `'unpaid'`，導致已繳費方案仍顯示在「未繳費」tab（`paid = true` 但 `payment_status = 'unpaid'` 衝突）。  
**測試**：`CoursePackageMonthlyBillingTest::test_paid_monthly_package_shows_monthly_due_soon_not_unpaid`。

## 堂數制單科課程的 payment_status 未計入帳單收款（2026-08-06 Bug Fix，使用者/產品方已核准修改）

`computePaymentStatus()` 原本只看 `StudentClass.Paid` 這一個欄位判斷是否已繳費，沒有把「帳單已有收款且金額足額（`paid_amount >= charge`）」納入考量——即使課程管理頁面（`StudentClassController` 的對應邏輯，`Paid=1` **或** 有記錄帳單收款即視為已繳）已正確顯示「已繳費」，`alerts/tuition` 仍會回傳 `payment_status = 'unpaid'`，兩處顯示互相矛盾。主任回報：一名學生的課程已用收款紀錄結清（`charge` = `paid_amount`、`outstanding = 0`），但帳務中心「未繳費」名單仍列出該課程。

**修法**：`$isPaid = Paid=1 或 (charge > 0 且 paid_amount >= charge)`，僅在「足額收款」時才視為已繳，**不可**用「有任何收款紀錄」（`paid_at !== null`）判斷——後者連部分付款也會誤判為已繳，破壞既有 `partial` 狀態判斷（見上方 §payment_status 補充欄位）。

**2026-08-17 延伸**：課程管理列表（`StudentClassController`）已改同一口徑（TD-083 B1）。`DunningService` 顧客催繳亦經 Founder GO 可改同一 `isFullyPaid`（B3 待實作）；列入帳務中心提醒名單的 query 仍依本章「列入條件」獨立維護。

**測試**：`TuitionAlertsApiTest::test_payment_status_paid_when_invoice_fully_paid_without_paid_flag`、`TuitionAlertsApiTest::test_payment_status_renew_needed_not_unpaid_when_invoice_fully_paid_and_zero_remaining`。

## 行政已回報、待主任入帳（#1827 Phase 1）

`POST /api/v1/payment-reports/director-record` 只建立 `PaymentReport.status=pending`，**不**把 `StudentClass.Paid` 設為 1，也**不**開電子收據。顯示用 `payment_status` 可為 `pending_report`。

**列入條件不變**：`Paid != 1` 的未繳課程仍列入催繳（含已回報未入帳）。禁止把 pending 當成 paid 從名單拿掉。主任 `PUT .../confirm` 後才 Paid／核銷／收據；`reject` 後維持未繳。產品無獨立會計角色。

權威狀態機：[`architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md`](architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md)。

## 只有舊「已收」標記、沒有付款紀錄（#4114／in-app #407，Founder Q3 2026-10-15）

Founder Q3 是本次對 `GET /api/v1/alerts/tuition` 顯示語意的明示同意（§變更管制），範圍僅限以下標示：

- 列的 `payment_status` 為 `paid`／`renew_needed`／`monthly_due_soon`，但合約本身與**同方案所有課程**的非作廢帳單上都沒有淨額 > 0 的收款（Payment；負數或 `Method='void'` 為沖銷）時，回傳 `legacy_paid_flag_only = true`（`BillingPayableResolver::validPaymentEvidence`）。方案列看整個方案的課程。「收款」與「沖銷」的分法和上方 `last_paid_at`（#4123）同一套淨額 ledger：被沖銷抵掉的收款不算付款紀錄，所以只要某張帳單淨額 > 0，該帳單就同時有 `last_paid_at` 日期、也不會標成只有舊標記。
- 前端把這類 `paid` 顯示為中性的「需核對：舊資料標記已收，缺付款紀錄」（琥珀色），不顯示綠色「已收」，也不顯示「未繳」。`renew_needed`／`monthly_due_soon` 的狀態文字不變，但學生清單不再把它們算成「已收」。
- `payment_status`、列入條件、金額、`outstanding`、對外通知與催繳都**不變**：不算欠款、不進「有未繳」、不催繳；學生清單「沒有未繳」篩選照常包含。
- 唯讀：不寫帳務、不改 `StudentClass.Paid`／`CoursePackage.paid`、不需 migration。課程管理 `GET /api/v1/student-classes` 的 `paid` 列帶同一欄位。

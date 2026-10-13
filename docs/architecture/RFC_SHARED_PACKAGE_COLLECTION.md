---
owner: jerry (CEO)
status: Accepted — Founder decisions 2026-10-13 (§5a); Phase 2 in implementation
review_cycle: as-needed
last_reviewed: 2026-10-13
---

# RFC: 共用方案（CoursePackage）待收規則 — 整包一列、部分已繳、待核對

> **Status:** Accepted（Founder 2026-10-13 拍板 Q1–Q5，見 §5a）。Phase 2 實作：讀取端（待收列＋不催繳）與 confirm 寫入修正（Q5）分兩個 PR。  
> **Date:** 2026-10-13  
> **Issue:** Refs [#3785](https://github.com/jerry200176-png/AllTrue_System/issues/3785)（hold）；相關 PR #3891（`Invoice::mayOweSql`，package member 保持 stored Status）、#3892（一個方案一個收款動作）  
> **Founder 決策（2026-10-13）：** 共用方案核對後**真的**還欠錢 → 重新列入「待收」，顯示「部分已繳」與**真實差額**；整包**一列**，不按科目算、不重複算、不重收全額。先定義整包應收、付款歸屬、去重；無法判定者列「待核對」。**不自動催繳**（不發提醒、不推 LINE），**不改 production 資料**。  
> 前置閱讀：`CLAUDE.md` G-009 / G-011、`docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md`、`docs/plans/F7_PAID_STATUS_DECISION_PACKET_20261002.md`（S1 決策：package member 無帳單 → `paid package => paid, else review_required until S3 defines package amounts`）。

---

## 1. 現況（從 code 與 schema 推得，main @ e5762c4b）

### 1a. 整包應收（package receivable）目前**沒有**單一定義

| 來源 | 內容 | 問題 |
|---|---|---|
| `course_packages` schema（`2026_04_15_300001_create_package_session_ledger.php`） | `total_sessions`、`rate`、`rate_unit`（`session`\|`hour`）、`paid`、`paid_at`。**沒有** price／total_amount／discount 欄位；`monthly_fee` 已於 `2026_04_17_400000` drop。 | 整包金額從未被儲存。 |
| `AlertController::packageCountModeCharge()` | `round(total_sessions × rate)`，回傳 `charge_semantics: 'estimate_only'`。 | 忽略 `rate_unit='hour'`（`createMultiSubject` 允許 `hour`）；`CoursePackageController::update` 可改 `rate`（cascade 到成員 `Rate`）與 `total_sessions`，金額會**回溯**變動；無折扣表示法。 |
| 成員 `StudentClass.Charge` | `createMultiSubject` 與 `convertToPackage` 新增成員一律 `Charge=0`。 | Charge 不是方案金額。 |
| 成員 `Invoice.TotalAmount` | 只有人工開單（`BillingController::store`）或確認回報時才產生。`PaymentReportController::confirm` 在**沒有既有帳單**時以 `TotalAmount = reported_amount` 開單（L449）→ 該帳單當下即 `paid` → `markPackagePaid()` 把整包與全部成員標 `paid=1`（L530、L898）。 | 「先收一部分、沒預開帳單」的方案會被記成**已繳清**；帳單總額＝實收，看不出部分已繳。 |
| `convertToPackage` | 原單科合約保留自己的 `Charge`／帳單；方案 `rate`／`total_sessions` 取自原合約；新增成員 `Charge=0, Paid=1`。 | 該方案的應收實際上就是原合約的帳單；`rate × total` 只是近似。 |
| `BillingPayableResolver::courseStatus()`（L128–L146） | package member 無帳單：方案 paid → `paid`（legacy_flag），否則 `review_required`；有帳單：**只看該成員自己的帳單**。 | 解析器沒有 package 層級的答案（F7 S3 尚未定義方案金額）。 |

**結論 (a)：** 目前存在三個互不一致的候選——`rate × total_sessions`（估算）、成員帳單 `TotalAmount` 合計、`CoursePackage.paid` 旗標。code 無法決定哪個是權威 → §5 Q1。

### 1b. 付款歸屬（payment attribution）

- `Payment` 只有 `InvoiceID`（`2026_02_07_000012`），沒有 course／package 欄位。歸屬一律經帳單。
- 帳單掛課程有兩種方式：表頭 `Invoice.StudentClassID`，或明細 `InvoiceItem.StudentClassID`（`2026_04_08_000002`；`BillingController::store` 可開多課程帳單、表頭可為 null）。
- 解析器與 alert 只認**表頭**（#3785 item 1：只靠明細連結的帳單會被讀成 unbilled）。
- 已收金額規則（已決定，Founder Q2／R28）：`InvoiceAmountReconciliationService` 的 net applied——正數且非 `Method='void'` 相加、負數或 `void` 一律減 `ABS`；**不讀** stored `PaidAmount`／`Status`。#3891 的 `Invoice::netPaidSql()` 是同一規則的 SQL 版。
- 一筆付款涵蓋多個成員：**不存在**這種資料形狀——一筆 Payment 只屬於一張帳單；跨成員只會以「一張多明細帳單」出現，此時**無法**把該筆付款分到個別成員（也不需要，見 §2 規則 R2）。

### 1c. 同一方案目前可能出現多次（或金額錯）的位置

| # | 位置 | 現象 |
|---|---|---|
| D1 | `AlertController::tuition` count 方案列（L136 `countPkgQuery`、L294） | 方案列的 `paid_amount` 只取 **anchor 成員**（第一個 ID）的 stored `PaidAmount` 合計；付在其他成員帳單上的錢不算 → 已付一部分仍顯示**全額**欠款（重收全額）。`outstanding` 亦只取 anchor 的解析器值。 |
| D2 | 同上，L351 | 成員個別列只在其方案**被選入** `countPkgs`（`paid=0` 或剩 ≤2 堂或有 pending）時排除。方案 `paid=1` 且剩 >2 堂、但某成員帳單仍開著 → 該成員以**單科列**出現（每個開帳單成員一列）。#3891 為此在 `mayOweSql` 對 package member 保留 stored Status。 |
| D3 | 同上，L118 + L360 `pendingSettlementResults` | 已停課（`Stop=1`）且帳單仍欠的成員以 `pending_reconciliation` 單科列出現，**未**對方案成員去重 → 可與方案列同時出現。 |
| D4 | 同上，L262（月結方案） | 月結方案 `outstanding = paid ? 0 : 本月出席×rate`，只看旗標，永遠不會是部分已繳。 |
| D5 | `DunningService::unpaid_reminder`、`SendTuitionReminders` | 以 `StudentClass` 為單位；未繳方案的**每個成員**各自進提醒（N 科＝N 則），冷卻也按 `student_class_id`。 |
| D6 | `AccountingController::ledger`／帳務面板 | 每個成員一張卡、各自「登記這筆」→ 由 #3892 處理（一個方案一個收款動作），本 RFC 不重做。 |
| D7 | `FinanceController::arAging`、`BankReconciliation` | 以帳單為單位，同一筆錢不會重算；但一個方案會以 N 個成員的帳單分列（屬帳齡明細，不是待收佇列，本 RFC 不改）。 |

---

## 2. 規則規格（只有 §5 標註的地方未定）

以 `CoursePackage.id` 為鍵，**一個方案恰好一列**；成員 `StudentClass`（`PackageID = id`）在任何待收佇列／提醒都不得再以單科身分出現（D2、D3）。

- **R1 成員集合** `M = { StudentClass.ID | PackageID = pkg.id }`（含 `Stop=1` 成員，避免 D3）。
- **R2 帳單集合** `I = { 非作廢 Invoice | Invoice.StudentClassID ∈ M }`，以 `Invoice.id` 去重。同一張帳單只算一次，不論掛幾個成員明細。
- **R3 已收** `paid = Σ_{inv ∈ I} min(TotalAmount_inv, netApplied_inv)`；超付另記 `overpaid = Σ max(0, netApplied − TotalAmount)`。netApplied 用既有 `InvoiceAmountReconciliationService` 規則（Payment rows；不讀 stored `PaidAmount`／`Status`）。
- **R4 應收** `X = ` **§5 Q1 待定**（A：`rate × total_sessions`；B：`Σ TotalAmount_inv`；C：混合）。
- **R5 差額** `remaining = max(0, X − paid)`。
- **R6 狀態**（無 pending 回報時）：`paid ≥ X` → 已繳（不進待收）；`0 < paid < X` → **部分已繳**，顯示 `remaining`；`paid = 0` → 未繳，顯示 `X`；有 pending `PaymentReport`（任一成員）→ 既有 `pending_report`（#3892 已保證一個方案一張）。
- **R7 Legacy 旗標**（沿用 F7 S1 已決規則）：`I` 為空且 `pkg.paid = true` → 已繳（legacy_flag）。`I` 為空且 `pkg.paid = false` → 依 Q1 (B 則待核對)。`I` 非空時旗標不得蓋過 Payment rows（Founder Q2）。
- **R8 待核對**（`needs_review`；不顯示差額、**排除於任何催繳／提醒／LINE**、只給主任人工核對）任一成立即是：
  1. 任一張帳單以 `InvoiceItem.StudentClassID` 連到成員、但表頭不在 `M`（含表頭 null）——付款無法確定歸屬（#3785 item 1）。
  2. `I` 中有帳單的明細同時指到 `M` 以外的課程（一張帳單混方案與非方案）。
  3. 任一成員 `StudentID ≠ pkg.student_id`，或學生 `CampusID ≠ pkg.campus_id`（跨分校）。
  4. `X` 無法算出：`rate ≤ 0`、`total_sessions ≤ 0`、`rate_unit ≠ 'session'`（A 方案時），或 `I` 為空且旗標未繳（B 方案時）。
  5. `overpaid > 0`，或任一帳單 `amount_discrepancy = true`，或任一帳單 net 為負。
  6. 成員 `ScheduleMode` 與 `pkg.billing_mode` 不一致（count 方案裡有 date 成員或反之）。
  7. A／C 方案下，`pkg.paid = true` 但 `paid < X`（無法分辨是折扣還是欠款，見 Q1）。
- **R9 不寫入**：Phase 2 全為讀取端（alert／佇列 JSON），不新增 migration、不改 `CoursePackage.paid`、不補帳單、不動既有紀錄。
- **R10 不催繳**：本規則產生的方案「部分已繳」列與「待核對」列都**不**進 `DunningService`／`SendTuitionReminders`／LINE（見 Q4）。單科課程路徑不變。

---

## 3. 範圍

- 涵蓋：count 方案（`billing_mode='count'`）在 `GET alerts/tuition`（帳務中心待收、主任首頁、學生列表 chips）的列。
- 不涵蓋：月結方案（D4，見 Q3）；帳齡／銀行對帳明細（D7）；寫入路徑（confirm 時開單金額、`markPackagePaid`）——這些若要改屬 R3 寫入變更，另案。

## 4. Phase 2 驗收（實作時）

1. 兩成員各付一部分 → 一列，`paid` 為兩者合計，`remaining = X − 合計`（無重算）。
2. 付了一部分後永遠不顯示全額 `X`。
3. 待核對列 `payment_status = 'needs_review'`，不在 dunning／reminder 候選內。
4. 方案 `paid=1` 且成員帳單仍開 → 不出現單科列（D2），停課成員不出現 `pending_reconciliation` 單科列（D3）。
5. 單科課程（`PackageID` null）所有既有測試（`TuitionAlerts*`、`ContractMoneyCallersAgreeTest`）不變。
6. 與 #3891 的關係：若 #3891 先合，package member 在 `mayOweSql` 的排除條款保留（方案改由本規則的方案列處理）；Phase 2 在 #3891 合併後從 main 開分支，不堆疊。

---

## 5. 需要 Founder 決定的事（code 無法決定）

**Q1 整包應收 `X` 以什麼為準？**

| 選項 | 規則 | 後果 |
|---|---|---|
| **A** | `X = round(rate × total_sessions)`，僅 `rate_unit='session'`；`hour` → 待核對 | 與現在畫面上的估算一致，沒有帳單也能算差額。但：(i) 改 `rate`／加堂數會回溯改變 `X`；(ii) 沒有折扣欄位，打過折的方案會被當成「部分已繳」→ 需靠 R8.7 轉待核對；(iii) 舊流程「收多少開多少」的方案（confirm 時以實收開單並標 paid）大量落入 R8.7 待核對。 |
| **B** | `X = Σ 非作廢成員帳單 TotalAmount`；沒有帳單 → 旗標已繳＝已繳，否則待核對 | 完全以帳單為準（與 Founder Q2 一致），不會因費率估算誤報欠款。但：未預先開整包帳單的方案，部分收款會被 confirm 開成「實收＝帳單」而顯示已繳——**看不到**部分已繳，除非之後改寫入路徑（先開整包帳單再收款）。 |
| **C** | 有帳單用 B；`Σ TotalAmount ≠ rate × total_sessions` → 待核對；沒有帳單用 A | 最保守、最不會誤催；待核對列會較多，需要主任逐一核對一次。 |

建議：**C**（決策要求「無法判定就待核對」），但這是金額語意選擇，不由 Agent 決定。

**Q2 只靠明細（`InvoiceItem`）連到成員的帳單怎麼歸屬？**  
A：整包待核對（R8.1，建議，無猜測）。B：按明細 `Amount` 比例把該帳單的付款分攤給方案。B 會在帳單只付一半時發明分配規則。

**Q3 月結方案（D4）是否納入？**  
A：本 RFC 只做 count 方案，月結方案維持現狀（建議，月結以期別計，另需期別規則）。B：月結方案也按期別套用 R2–R8（需另定每期應收＝出席×rate 或帳單）。

**Q4 方案層級的提醒／LINE？**（D5：目前未繳方案的每個成員各自被提醒）  
A：Phase 2 把方案成員自 `unpaid_reminder`／`SendTuitionReminders` 全部排除，方案只在帳務中心人工收（建議，符合「不自動催繳」）。B：保留現狀的成員提醒，只排除待核對方案（每科一則的重複提醒仍在）。C：改成每方案一則提醒（新的對外通知行為，需另外批准）。

---

## 5a. Founder decisions 2026-10-13（定案）

| # | 決定 | 落實方式 |
|---|---|---|
| Q1 | **C**：有成員帳單時 `X = Σ 非作廢成員帳單 TotalAmount`；若 `X ≠ round(rate × total_sessions)`（或 `rate_unit ≠ session`、費率／堂數缺漏而無法比對）→ 待核對。沒有帳單時 `X = rate × total_sessions`。 | R4；R8.4 依 C 解讀 |
| Q2 | **A**：只靠 `InvoiceItem` 連到成員的帳單 → 整包待核對。 | R8.1 |
| Q3 | **A**：月結方案暫不納入本規則；#3892 已讓月結成員各自可收款。 | §3 範圍不變 |
| Q4 | **A**：count 方案的成員完全排除於自動提醒／催繳（`DunningService`、`tuition:send-reminders`、LINE），由人員手動跟進。 | R10 |
| Q5 | **修**：`PaymentReportController::confirm` 中，方案成員沒有預開帳單、確認的是部分金額時，**不得** `markPackagePaid`；方案維持部分已繳，直到 Payment rows 合計達到 `X`。不改寫既有資料；舊的誤標已繳方案經讀取端規則（旗標已繳但 Payment rows < X）顯示待核對。 | 寫入路徑另一 PR（建於 #3892 合併後） |

實作時的解讀（Founder 2026-10-13 round 2 已確認，見下表）：

- **R7 保留**：沒有任何成員帳單且 `pkg.paid = true` → 已繳（legacy_flag，F7 S1）。R8.7 只在有帳單時適用；否則所有舊的「旗標已繳、從未開單」方案都會一次湧入待核對。Q5 所指的舊誤標方案都有 confirm 開出的帳單，仍會被 R8.7 或 Q1 比對抓到。
- **Q4 只涵蓋 count 方案成員**（與 Q3 一致）；月結方案成員的提醒照舊。
- 已停止的 count 方案只在成員有帳單時列出（與過去「停課成員有欠款帳單才列」一致）；沒有帳單的已停止方案不列。
- 待核對列：`payment_status = 'needs_review'`、`review_reasons[]`，金額欄位（`charge`／`outstanding`／`payable_*`）皆為 null。

### Founder decisions 2026-10-13 (round 2)

Founder 2026-10-13 對 Phase 2 實作時浮現的五個歧義一律採建議選項 (a)：

| # | 問題 | 決定 |
|---|---|---|
| R2-1 | Q5：confirm 自動開的方案帳單金額 | **(a)** 以 `X = rate × total_sessions` 開單（不是「收多少開多少」）；部分收款維持部分已繳，收款合計達 X 才標已繳；超過 X 擋下（422）。X 無法判定時照收到金額開單，但不因此標已繳。 |
| R2-2 | R7 vs R8.7：沒有任何成員帳單、`pkg.paid = true` | **(a)** 視為已繳（legacy_flag，F7 S1）；R8.7 只在有帳單時適用。 |
| R2-3 | Q4 範圍 | **(a)** 只排除 count 方案成員的自動提醒；月結方案成員照舊。 |
| R2-4 | 已停止、沒有任何帳單的 count 方案 | **(a)** 不列入待收。 |
| R2-5 | 教職員 in-app 通知（`NotificationSyncService`） | **(a)** 維持現狀（每成員一則），本 RFC 不改。 |

## 6. 回滾

本檔為文件。Phase 2 為讀取端程式，回滾＝revert PR；無資料寫入、無 migration。

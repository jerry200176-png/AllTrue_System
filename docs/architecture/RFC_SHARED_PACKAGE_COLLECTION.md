---
owner: jerry (CEO)
status: Accepted decisions (§5a); Phase 2 readiness HOLD — §5b OPEN
review_cycle: as-needed
last_reviewed: 2026-10-13
---

# RFC: 共用方案（CoursePackage）待收規則 — 整包一列、部分已繳、待核對

> **Status:** Accepted（Founder 2026-10-13 拍板 Q1–Q5，見 §5a）。Phase 2 分讀取端（待收列＋不催繳）與 confirm 寫入修正（Q5）兩個 PR；完整 implementation readiness 為 HOLD，§5b OPEN 尚待 owner 決定。
> **Date:** 2026-10-13  
> **Issue:** Refs [#3785](https://github.com/jerry200176-png/AllTrue_System/issues/3785)（hold）；相關 PR #3891（`Invoice::mayOweSql`，package member 保持 stored Status）、#3892（一個方案一個收款動作）  
> **Founder 決策（2026-10-13）：** 共用方案核對後**真的**還欠錢 → 重新列入「待收」，顯示「部分已繳」與**真實差額**；整包**一列**，不按科目算、不重複算、不重收全額。先定義整包應收、付款歸屬、去重；無法判定者列「待核對」。**不自動催繳**（count 方案不發自動繳費提醒或 LINE；教職員 in-app 通知依 R2-5 維持每成員一則），**不改 production 資料**。
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

## 2. 規則規格（已決金額語意依 §5a；實作 OPEN 邊界見 §5b）

以 `CoursePackage.id` 為鍵，在 `GET alerts/tuition` 及其待收畫面中**一個方案至多一列**；符合列入條件時恰好一列，成員不得再以單科身分出現（D2、D3）。此 invariant 不涵蓋 D7 帳齡／銀行對帳明細；教職員 `NotificationSyncService` in-app 通知依 R2-5 維持每成員一則。

- **R0 既有免費排除優先**：`ClassType=tutoring` 一律不是學費待收、催繳或繳費通知候選；既有 payable resolver 判定 `free` 的合約亦不產生付款義務。先沿用上述已決政策，再進 R4–R8；不得把刻意免費的方案因零金額送進待核對。歷史異常保持原帳務畫面唯讀呈現。

- **R1 成員集合** `M = { StudentClass.ID | PackageID = pkg.id }`（含 `Stop=1` 成員，避免 D3）。
- **R2 帳單集合** `I = { 非作廢 Invoice | Invoice.StudentClassID ∈ M }`，以 `Invoice.id` 去重。同一張帳單只算一次，不論掛幾個成員明細。
- **R3 已收** `paid = Σ_{inv ∈ I} min(TotalAmount_inv, netApplied_inv)`；超付另記 `overpaid = Σ max(0, netApplied − TotalAmount)`。netApplied 用既有 `InvoiceAmountReconciliationService` 規則（Payment rows；不讀 stored `PaidAmount`／`Status`）。先保存 `signed_net = positiveTotal − voidedTotal`，負值依 R8.5 待核對，再 clamp 為 `max(0, signed_net)` 計算已收。現行 service 的 `net_applied` 已 clamp，不可直接拿它偵測負 net。
- **R4 應收**（§5a Q1=C）：`I` 非空時 `X = Σ TotalAmount_inv`；與 `round(rate × total_sessions)` 比對，不一致或無法比對即待核對（R8.4）。`I` 為空時採同一 rate-based X，無法算出則待核對；R7 無帳單 legacy 已繳例外優先。Q5 新 confirm 在 X 可判定時依已決 R2-1 使用 full X，Payment 只記實收；X 不可判定時保留 R2-1「依實收開單但不因此標已繳」例外，不得把該例外當成可靠 X。
- **R5 差額** `remaining = max(0, X − paid)`。
- **R6 狀態**：先處理 R0 排除與 R8 待核對；可靠 X 下，`paid ≥ X` → 已繳（不作待收款項）；`0 < paid < X` → **部分已繳**，顯示 `remaining`；`paid = 0` → 未繳，顯示 `X`。依 R135，結清事實優先：已繳方案有 pending 回報仍保持 `payment_status=paid`，另保留 `latest_payment_report_id`／回報摘要供顯式審核；不得隱藏非 anchor 成員回報。未結清且只有一筆 pending 回報時沿用既有 `pending_report`，不把回報金額視為已收。歷史多筆 pending 依 R8.8 處理；#3892 的新寫入去重不能證明歷史資料只有一張。已停止無帳單的 pending 回報可達性依 §5b OPEN-2，尚不能宣告驗收完成。
- **R7 Legacy 旗標**（沿用 F7 S1 已決規則）：`I` 為空且 `pkg.paid = true` → 已繳（legacy_flag）。`I` 為空且 `pkg.paid = false` → 依已決 Q1=C 的 rate-based X。R8.7 只在 `I` 非空時適用。`I` 非空時旗標不得蓋過 Payment rows（Founder Q2）。
- **R8 待核對**（`needs_review`；不顯示差額、**排除於對外自動催繳／提醒／LINE**（staff in-app 例外依 R2-5）、只給主任人工核對）任一成立即是：
  1. 任一張非作廢帳單以 `InvoiceItem.StudentClassID` 連到成員、但表頭不在 `M`（含表頭 null）——付款無法確定歸屬（#3785 item 1）。
  2. `I` 中有帳單的明細同時指到 `M` 以外的課程（一張帳單混方案與非方案）。
  3. 任一成員 `StudentID ≠ pkg.student_id`，或學生 `CampusID ≠ pkg.campus_id`（跨分校）；候選非作廢帳單的 `Invoice.StudentID` 必須等於 `pkg.student_id`，該學生 campus 亦須等於 `pkg.campus_id`。表頭／明細引用課程仍須符合相同 student/campus 邊界，不能因同 campus 就挪用另一學生付款。
  4. R0 免費排除後，Q1=C 的 X 或 rate-based 比對值無法算出：`rate ≤ 0`、`total_sessions ≤ 0`、`rate_unit ≠ 'session'`；或 `I` 非空且 `Σ TotalAmount ≠ round(rate × total_sessions)`。R7 無帳單 legacy 已繳例外仍保留。
  5. `overpaid > 0`，或任一帳單 `amount_discrepancy = true`，或任一帳單 `signed_net < 0`（clamp 前，見 R3）。
  6. 成員 `ScheduleMode` 與 `pkg.billing_mode` 不一致（count 方案裡有 date 成員或反之）。
  7. `I` 非空且 `pkg.paid = true` 但 `paid < X`（無法分辨是折扣還是欠款，見 Q1）；不得套用到 R7 legacy 無帳單已繳。
  8. 同一方案成員存在多筆 pending `PaymentReport`：回傳所有相關 report IDs 與 `duplicate_pending_reports` reason，不任選一筆隱藏其餘回報；核對前不提供單筆／批次 confirm 收款動作。這是無法確定回報歸屬的人工核對要求，不新增付款狀態或自動修復。
- **R9 不寫入**：Phase 2-read 為 alert／佇列 JSON 與自動提醒候選排除，不新增 migration、不改 `CoursePackage.paid`、不補帳單、不動既有帳務紀錄。Q5 的 Phase 2-write 是獨立寫入 PR；不能把「Phase 2 全無寫入」套用到它。
- **R10 不催繳**：所有 count 方案成員（unpaid、partial、paid、pending_report、needs_review，及未被選入 alert 的成員）皆排除 `DunningService`／`SendTuitionReminders` 及其對外 LINE 發送；不得只按 partial／needs_review 列過濾。單科與月結方案路徑依既有政策不變。教職員 in-app 通知依 R2-5 維持，不屬本條對外自動催繳範圍。

---

## 3. 範圍

- 涵蓋：count 方案（`billing_mode='count'`）在 `GET alerts/tuition`（帳務中心待收、主任首頁、學生列表 chips）的列。
- 不涵蓋：月結方案（D4，見 Q3）；帳齡／銀行對帳明細（D7）；Phase 2-read 不涵蓋寫入路徑（confirm 時開單金額、`markPackagePaid`）；已決 Q5/R2-1 由獨立 Phase 2-write PR 處理。§5b 未定事項不能用本文件作已批准實作的依據。

### 3a. API／client 與既有隔離邊界

- `GET alerts/tuition` 及 package/invoice/Payment aggregates 必須先限制在 authenticated campus scope。非 super_admin 的空 `auth_campus_ids` 回空或 403；`branch_id` 不得擴大 scope。只保留既有文件化 super_admin 例外。
- Q5 single／batch confirm 在任何帳務 write 前驗證 report、student、course、package 的身份與 campus 一致，且屬 authenticated campus scope；空 scope fail closed。batch 不能繞過 single 的同一驗證。
- `TuitionCollectionPage.vue` 卡片、tabs、counts、action filters 與 `StudentsList.vue` chips（及主任首頁同 payload consumers）明確支持 `needs_review`：顯示待核對與 reasons、保留 null 金額，不轉成 0／全額或未繳 label；人工核對佇列可見，但不加入可收總額，不提供登錄／批次 confirm／催繳。

## 4. Phase 2 驗收（實作時）

1. 兩成員各付一部分 → 一列，`paid` 為兩者合計，`remaining = X − 合計`（無重算）。
2. 付了一部分後永遠不顯示全額 `X`。
3. 待核對列 `payment_status = 'needs_review'`，各 client 明確可見、金額 null、不可作一般登錄／批次 confirm，不在 dunning／reminder 候選內。
4. 方案 `paid=1` 且成員帳單仍開 → 不出現單科列（D2），停課成員不出現 `pending_reconciliation` 單科列（D3）。
5. 單科課程（`PackageID` null）所有既有測試（`TuitionAlerts*`、`ContractMoneyCallersAgreeTest`）不變。
6. 與 #3891 的關係：若 #3891 先合，package member 在 `mayOweSql` 的排除條款保留（方案改由本規則的方案列處理）；Phase 2 在 #3891 合併後從 main 開分支，不堆疊。

7. 每種 count 付款狀態與未被 alert 選中的成員都在實際 sender 層驗證零自動提醒／LINE；月結、單科及 R2-5 staff in-app 例外保持既有政策。
8. 已結清且非 anchor 有 pending 回報維持 paid 並可顯式審核；歷史多筆 pending 回傳全部 IDs 且不可任選 confirm。保留 R135 的 paid-package regression。
9. signed negative net、另一學生帳單、跨 campus header/items、nonvoid item-only 帳單 → 待核對；void item-only 不觸發此歸屬 anomaly；免費／輔導課先排除。
10. 非 super_admin 空 scope、越權 branch_id 與跨 campus package 讀取不漏資料；single／batch confirm 越權時零帳務寫入。
11. Q5 full-X 自動開單的部分／結清回歸依 R2-1；§5b OPEN 未完成前，不能以其餘測試通過宣稱完整 Phase 2-ready。

---

## 5. Founder 問題記錄（歷史選項；定案以 §5a 為準）

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

## 5b. OPEN implementation hold（不是新 Founder 決定）

§5a 原始決策原文保留。以下發現尚未被該決策完整定義；owner 需明確選擇、批准具體邊界或確認延後。完整 Phase 2 implementation readiness 為 **HOLD**，不能把 docs merge 或 read-side 綠燈當作這些寫入行為已批准。

| ID | 未定邊界／驗證提案 | owner 決定前的限制 |
|---|---|---|
| OPEN-1 | 多張仍欠成員帳單：package remaining 為總和，但 PaymentReport／Payment 只指向一張 Invoice。需選擇一列內明確選一張 invoice、顯示其可收額並逐張收，或另定跨 invoice allocation／atomicity／idempotency／receipt 契約。 | 不發明分攤／先後順序，不把 aggregate remaining 直接送入單張 confirm；現有可算金額仍可讀取，但該 package 收款 action 合約尚未 ready。 |
| OPEN-2 | 已停止、無帳單但有 pending 回報：R2-4 明定不列待收，R135 要求回報可達。提案是保留待收排除並提供獨立 report-review projection／入口；另一選項為批准 R2-4 的窄 pending 例外。 | 不默改 R2-4；確認／拒絕入口可達性是驗收 blocker，具體 UI/API 邊界仍待 owner 決定。 |
| OPEN-3 | inverse void 驗證提案：新 confirm 結清後合法 void/reversal，是否在同 transaction／locks 重算 package 與全部 member settlement flags，避免 sibling 仍 paid、合法 reversal 被讀成 legacy anomaly；需涵蓋每個 reversal producer。 | Q5 尚未明文定義逆向 flags 行為；若改動既有 void 行為，需 owner 對具體 write scope 決定及 settle→void 回歸／rollback 證據。不自動修既有資料。 |
| OPEN-4 | receipt 驗證提案：partial／final package 款項的收據是否採 package-aware snapshot，列整包名稱、covered subjects/session 範圍與實收，不只顯示 anchor 科目；不引入科目價款分攤。 | 收據內容與 snapshot 範圍需 owner 確認；multi-subject partial/final／重印回歸必備，不將提案寫成既有 Founder 決策。 |

## 6. 回滾

本檔為文件，回滾＝revert 本 PR。Phase 2-read 回滾＝revert 該讀取／提醒候選 PR，無帳務寫入、無 migration。Phase 2-write 必須另附具體 rollback boundary、ledger／audit 與驗證證據；revert 程式不會刪除已產生的 Invoice／Payment／reversal，既有帳務資料不得以本 RFC 自動修復。

---
owner: jerry (CEO)
status: Accepted decisions (§5a, §5c); future Phase 2 implementation and verification pending
review_cycle: as-needed
last_reviewed: 2026-10-13
---

# RFC: 共用方案（CoursePackage）待收規則 — 整包一列、部分已繳、待核對

> **Status:** Accepted（Founder 2026-10-13 拍板 Q1–Q5，見 §5a）。Phase 2 分讀取端（待收列＋不催繳）與 confirm 寫入修正（Q5）兩個 PR；§5b OPEN-1–4 與加購帳單已由 §5c Founder 收尾決策 C1–C5 定案；本 PR 僅文件，Phase 2 尚待獨立實作與驗證。
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

## 2. 規則規格（已決金額語意依 §5a；收尾合約依 §5c）

以 `CoursePackage.id` 為鍵，在 `GET alerts/tuition` 及其待收畫面中**一個方案至多一列**；符合列入條件時恰好一列，成員不得再以單科身分出現（D2、D3）。此 invariant 不涵蓋 D7 帳齡／銀行對帳明細；教職員 `NotificationSyncService` in-app 通知依 R2-5 維持每成員一則。

- **R0 既有免費排除優先**：`ClassType=tutoring` 一律不是學費待收、催繳或繳費通知候選；既有 payable resolver 判定 `free` 的合約亦不產生付款義務。方案免費排除僅在 package 與全部成員的 tutoring／free 分類一致且明確時套用；個別 tutoring／free 成員不得被建立付款義務。package.class_type 與成員在免費／需收費分類上矛盾或混合時，不猜整包免費或收費，依 R8 人工核對且不顯示可收額；全部明確免費的方案不得因零金額送進待核對。歷史異常保持原帳務畫面唯讀呈現。

- **R1 成員集合** `M = { StudentClass.ID | PackageID = pkg.id }`（含 `Stop=1` 成員，避免 D3）。
- **R2 帳單集合** `I = { 非作廢 Invoice | Invoice.StudentClassID ∈ M }`，以 `Invoice.id` 去重。同一張帳單只算一次，不論掛幾個成員明細。
- **R3 已收** `paid = Σ_{inv ∈ I} min(TotalAmount_inv, netApplied_inv)`；超付另記 `overpaid = Σ max(0, netApplied − TotalAmount)`。netApplied 用既有 `InvoiceAmountReconciliationService` 規則（Payment rows；不讀 stored `PaidAmount`／`Status`）。先保存 `signed_net = positiveTotal − voidedTotal`，負值依 R8.5 待核對，再 clamp 為 `max(0, signed_net)` 計算已收。現行 service 的 `net_applied` 已 clamp，不可直接拿它偵測負 net。
- **R4 應收**（§5a Q1=C）：`I` 非空時 `X = Σ TotalAmount_inv`；與 `round(rate × total_sessions)` 比對，不一致或無法比對即待核對（R8.4）。`I` 為空時採同一 rate-based X，無法算出則待核對；R7 無帳單 legacy 已繳例外優先。Q5 新 confirm 在 X 可判定時依已決 R2-1 使用 full X，Payment 只記實收；X 不可判定時保留 R2-1「依實收開單但不因此標已繳」例外，不得把該例外當成可靠 X。
- **R5 差額** `remaining = max(0, X − paid)`。
- **R6 狀態**：先處理 R0 排除與 R8 金額／歸屬 anomaly；R8.8 只屬 report-review，不覆蓋可靠 ledger 結清事實。可靠 X 下，`paid ≥ X` → 金錢已結清（不作待收款項）；僅進行中（`pkg.stop=false`）且已繳的 count 方案剩餘堂數 ≤2 時，保留既有 `renew_needed`／`low_sessions` 續課提示與續課／結束控制，不把零欠款誤作新待收。`0 < paid < X` → **部分已繳**，顯示 `remaining`；`paid = 0` → 未繳，顯示 `X`。依 R135，結清事實優先：已繳方案有 pending 回報不得改成未結清；一般為 `payment_status=paid`，低堂數時保留上述既有續課狀態，另保留 `latest_payment_report_id`／回報摘要供顯式審核；不得隱藏非 anchor 成員回報。未結清且只有一筆 pending 回報時沿用既有 `pending_report`，不把回報金額視為已收。歷史多筆 pending 依 R8.8 處理；#3892 的新寫入去重不能證明歷史資料只有一張。已停止方案不產生 `renew_needed`／`low_sessions` 或續課／結束控制；僅實際未結清帳單或 R8 人工核對工作可列出，已結清帳單存在本身不構成列入理由。已停止無帳單仍不列待收；pending 回報透過獨立審核入口呈現，依 §5c C2，不推算可收金額。
- **R7 Legacy 旗標**（沿用 F7 S1 已決規則）：`I` 為空且 `pkg.paid = true` → 已繳（legacy_flag）。`I` 為空且 `pkg.paid = false` → 依已決 Q1=C 的 rate-based X。R8.7 只在 `I` 非空時適用。`I` 非空時旗標不得蓋過 Payment rows（Founder Q2）。R7 是已存在歷史狀態的讀取相容，不能由未來 `CoursePackageController::update` 等直接 `paid/paid_at` 寫入製造新 legacy 結清；prospective settlement flag 修改須走同一 ledger settlement routine，不能無帳單／Payment 設成已繳。讀取端不回填或更改歷史旗標。
- **R8 待核對**（`needs_review`；不顯示差額、**排除於對外自動催繳／提醒／LINE**（staff in-app 例外依 R2-5）、只給主任人工核對）任一成立即是：
  1. 任一張非作廢帳單以 `InvoiceItem.StudentClassID` 連到成員、但表頭不在 `M`（含表頭 null）——付款無法確定歸屬（#3785 item 1）。
  2. `I` 中有帳單的明細同時指到 `M` 以外的課程（一張帳單混方案與非方案）。
  3. 任一成員 `StudentID ≠ pkg.student_id`，或學生 `CampusID ≠ pkg.campus_id`（跨分校）；候選非作廢帳單的 `Invoice.StudentID` 必須等於 `pkg.student_id`，該學生 campus 亦須等於 `pkg.campus_id`。表頭／明細引用課程仍須符合相同 student/campus 邊界，不能因同 campus 就挪用另一學生付款。
  4. R0 免費排除後，Q1=C 的 X 或 rate-based 比對值無法算出：`rate ≤ 0`、`total_sessions ≤ 0`、`rate_unit ≠ 'session'`；或 `I` 非空且 `Σ TotalAmount ≠ round(rate × total_sessions)`。R7 無帳單 legacy 已繳例外仍保留。
  5. `overpaid > 0`，或任一帳單 `amount_discrepancy = true`，或任一帳單 `signed_net < 0`（clamp 前，見 R3）。
  6. 成員 `ScheduleMode` 與 `pkg.billing_mode` 不一致（count 方案裡有 date 成員或反之），或 package 與成員的 tutoring／free 與 billable 分類不一致（見 R0）；不為混合方案推算科目價款。
  7. `I` 非空且 `pkg.paid = true` 但 `paid < X`（無法分辨是折扣還是欠款，見 Q1）；不得套用到 R7 legacy 無帳單已繳。
  8. 同一方案成員存在多筆 pending `PaymentReport`：回傳所有相關 report IDs 與 `duplicate_pending_reports` reason，不任選一筆隱藏其餘回報；核對前不提供單筆／批次 confirm 收款動作。若其他金額／歸屬無 anomaly 且 reliable ledger 已結清，保留 money status `paid`（進行中低堂數可保留既有續課狀態），另外提供 `duplicate_pending_reports` review flag／所有 IDs；不得僅因回報重複改為 money `needs_review` 或失去結清事實。尚未結清或 X 不可靠時保留待核對。這是無法確定回報歸屬的人工核對要求，不新增付款狀態或自動修復。
- **R9 不寫入**：Phase 2-read 為 alert／佇列 JSON 與自動提醒候選排除，不新增 migration、不改 `CoursePackage.paid`、不補帳單、不動既有帳務紀錄。Q5 的 Phase 2-write 是獨立寫入 PR；不能把「Phase 2 全無寫入」套用到它。
- **R10 不催繳**：所有 count 方案成員（unpaid、partial、paid、pending_report、needs_review，及未被選入 alert 的成員）皆排除 `DunningService`／`SendTuitionReminders` 及其對外 LINE 發送；不得只按 partial／needs_review 列過濾。單科與月結方案路徑依既有政策不變。教職員 in-app 通知依 R2-5 維持每成員一則，但 count-package tuition 通知必須標為 in-app-only，`NotificationObserver` → `NotificationLineDispatcher` 的實際 staff LINE sender 同樣抑制這些 rows；不得以 in-app 例外重新產生自動 LINE。單科／月結及其他通知不變。通知的 `markTuitionPaid` 等 money-writing action 不得以 member ID 無 `InvoiceID` 繞過 C1；count-package member 通知僅唯讀並 deep-link 到整包列的明確 invoice 選擇入口，不直接登錄收款。

---

## 3. 範圍

- 涵蓋：count 方案（`billing_mode='count'`）在 `GET alerts/tuition`（帳務中心待收、主任首頁、學生列表 chips）的列。
- 不涵蓋：月結方案（D4，見 Q3）；帳齡／銀行對帳明細（D7）；Phase 2-read 不涵蓋寫入路徑（confirm 時開單金額、`markPackagePaid`）；已決 Q5/R2-1 由獨立 Phase 2-write PR 處理。§5c 核准合約由各自獨立 future PR 實作／驗證；本 PR 不代表 runtime 已修正或已上線。

### 3a. API／client 與既有隔離邊界

- `GET alerts/tuition` 及 package/invoice/Payment aggregates 必須先限制在 authenticated campus scope。非 super_admin 的空 `auth_campus_ids` 回空或 403；`branch_id` 不得擴大 scope。只保留既有文件化 super_admin 例外。
- 所有 package 回報建立／拒絕／正向收款與 reversal／void producer（含 `PaymentReportController::directorRecord`、`directorRecordBatch`、`reject`、Q5 single／batch confirm、`BillingController::recordPayment`、`PaymentReportController::void` 及 `BillingController` 各 invoice-void 路徑），在每個帳務 transaction／write 前驗證 report／invoice、student、所有關聯 course 與 package 的身份與 campus 一致，且屬 authenticated campus scope。非 super_admin 空 scope fail closed；只有既有文件化 super_admin 例外。一般進行中方案與 C2 停止方案審核皆適用；single／batch 回報建立、拒絕與直接 invoice 入口不能繞過同一驗證，失敗須零 Payment／reversal／invoice／report／package／member 變更。
- package-aware 收據讀取／重印（`payment-reports/{id}/receipt`）必須在取出或呈現科目／covered sessions 前，驗證 report、invoice、package、student 與所有納入成員的身份一致且皆在 authenticated campus scope；不僅驗證 anchor 學生。非 super_admin 空 scope 或任一 foreign-campus／wrong-student 成員 fail closed，不回傳其科目／日期；只保留既有 super_admin campus 例外，身份／歸屬 anomaly 仍不得臆測。
- `TuitionCollectionPage.vue` 卡片、tabs、counts、action filters 與 `StudentsList.vue` chips（及主任首頁同 payload consumers）明確支持 `needs_review`：顯示待核對與 reasons、保留 null 金額，不轉成 0／全額或未繳 label；人工核對佇列可見，但不加入可收總額，不提供登錄／批次 confirm／催繳。

### 3b. Package 收款與人工審核合約

- **選單張帳單**（§5c C1）：同一 package row 保留 aggregate `remaining` 與整包科目；收款 action 明確選擇一張屬於 R2 集合的 `InvoiceID`，另顯示該張依 Payment rows 算出的可收餘額。提交／確認／batch 金額上限依選中的 invoice，不把整包總餘額當作單張可收額；付款只歸所選 invoice，付完重算 package row，其他仍欠 invoice 由人員再次明確選擇。不得自動分配或任選 anchor。
- **新帳單只建一次**：R2-1 在 package 層級查詢／鎖定既有相容且可歸屬 invoice（身份／campus、方案範圍與應收語意符合本 RFC）；第一次部分付款建立 full-X invoice 後，後續從其他 member 進入亦必須沿用該 invoice，不得因 reporting member 不同再次開 full-X invoice。多張既有 invoice 依前項明確選擇；ambiguous identity 依 R8 核對。並行／重送不得重複建立 invoice 或 Payment。
- **prospective 加購帳單**（§5c C5）：可靠既有帳單、未改 per-session 成交費率的 count package 正數加購，以一個明確核准的 add-on event 固定 package/student/campus、operation key、actor/time、舊／新 purchased sessions、exact agreed rate/rate_unit、舊／新整數應收及新增 invoice ID。原 finalized invoice／Payment／receipt monetary snapshot 不變；一個授權／locks／idempotent transaction 新增增量 invoice、增加 entitlement 並透過既定 ledger settlement 重算全部 flags，任何一步失敗整筆回滾。新 `X1 = round(agreed_rate × S1)`，舊可靠 `X0 = Σ 原非作廢帳單 TotalAmount`；增量 `ΔX = X1 − X0 > 0`，不能獨立 round 新增堂數後相加而破壞 Q1=C。若 `round(rate × added_sessions) ≠ ΔX`，新 invoice 明列 cumulative rounding adjustment，舊帳單不調整；例：10.5 元×原1堂帳單11元，加到2堂累計21元，新單為新增堂次11元＋取整差額−1元＝10元。之後重印用 event price/quantity/session snapshot，不讀最新 catalog rate。人員依 C1 明確選新 invoice 收款，原帳單不重收、不自動 allocation；同 operation key＋相同參數重送沿用結果，換參數拒絕，並行不能重複 invoice／加堂。改價／折扣／減堂／hour／無可靠舊帳單 legacy／R8 anomaly 保持人工核對，不猜 opening invoice 或修歷史資料。
- **審核與待收分開**（§5c C2）：停止、無帳單、有 pending report 的方案保持 R2-4 待收排除，提供獨立 report-review entry／projection，回傳 report IDs、摘要與 package context，使人員可依原審核規則確認／拒絕；不憑 pending 金額新增應收、不打開一般待收登錄。該入口及其 single/batch 操作遵守 §3a campus 隔離與 R8.8。
- **人工繳費單**：`DirectorDashboard` → `PaymentSlipModal` → `alerts/tuition-slip` 不得以 anchor 課程金額生成 package 繳費圖。manual copy/download 需 package-aware 路徑，顯示 package 科目、aggregate remaining 及明確所選 invoice 的本次可收額；該路徑未驗證前停用 package row 的舊 member slip action，needs_review 不產生金額繳費單。這是既有「不重收全額」要求的 consumer guard，不批准自動對外發送。
- **實收防護**：非免費 count package 的 reported receipt amount 須為嚴格大於 0 的整數新臺幣元（沿用 `Invoice.TotalAmount`／`Payment.Amount` integer authority），single／batch 登錄及確認皆驗證，不將 decimal `reported_amount` 截斷或四捨五入成 integer Payment。既有 main 的 `CollectionQueueRequestShapeTest::test_fractional_amounts_are_refused_at_intake_batch_and_confirm` 為回歸基準；歷史 fractional pending report 確認回 422 `fractional_amount`，保留 pending 並提示退回重登。本 RFC 不新增 minor-unit／decimal 金額制度；拒絕零值須無 invoice／Payment／confirmed-report／receipt 變更，不能把零值 receipt 當成已確認收款。既有免費排除 R0 不變。
- **正向結清共用邊界**：所有 package Payment producer 共用同一 package settlement routine，不限 `PaymentReportController::confirm`；已註冊 `POST invoices/{invoice}/payments`（`BillingController::recordPayment`）亦在相同 transaction／locks 下依 package ledger 重算 `CoursePackage.paid/paid_at` 與全部 member `Paid/PayDate`。部分實收不得標整包已繳，aggregate 達到可靠 X 才結清；既有 direct invoice action 仍每次只支付明確選中的 invoice。不得只同步該 invoice header/items 的 member；R8 歸屬 anomaly 不可藉此自動修復。這是已決 aggregate-settlement 的 producer 完整性要求，屬 future write PR，非本文件 PR runtime 變更。
- **逆向結清與收據**：§5c C3/C4 分別在獨立 future write PR 完成，所有合法 package void/reversal producer 先通過 §3a authorization 再原子重算 package/member flags；收據以 package snapshot 呈現整包科目、covered session 範圍及本筆實收，不創造科目價款分攤。不修歷史資料。

## 4. Phase 2 驗收（實作時）

1. 兩成員各付一部分 → 一列，`paid` 為兩者合計，`remaining = X − 合計`（無重算）。
2. 付了一部分後永遠不顯示全額 `X`。
3. 待核對列 `payment_status = 'needs_review'`，各 client 明確可見、金額 null、不可作一般登錄／批次 confirm，不在 dunning／reminder 候選內。
4. 方案 `paid=1` 且成員帳單仍開 → 不出現單科列（D2），停課成員不出現 `pending_reconciliation` 單科列（D3）。
5. 單科課程（`PackageID` null）所有既有測試（`TuitionAlerts*`、`ContractMoneyCallersAgreeTest`）不變。
6. 與 #3891 的關係：若 #3891 先合，package member 在 `mayOweSql` 的排除條款保留（方案改由本規則的方案列處理）；Phase 2 在 #3891 合併後從 main 開分支，不堆疊。

7. 每種 count 付款狀態與未被 alert 選中的成員都在實際 sender 層驗證零自動提醒／LINE；月結、單科及 R2-5 staff in-app 例外保持既有政策。
8. 已結清且非 anchor 有 pending 回報維持金錢結清並可顯式審核（低堂數既有續課狀態仍保留）；歷史多筆 pending 回傳全部 IDs 且不可任選 confirm。保留 R135 的 paid-package regression。
9. signed negative net、另一學生帳單、跨 campus header/items、nonvoid item-only 帳單 → 待核對；void item-only 不觸發此歸屬 anomaly；免費／輔導課先排除。
10. 非 super_admin 空 scope、越權 branch_id 與跨 campus package 讀取不漏資料；single／batch confirm 越權時零帳務寫入。
11. Q5 full-X 自動開單的部分／結清回歸依 R2-1；member A 部分確認後從 member B 最終確認仍使用同張 package invoice，無重複應收；並行／重送回歸證明沒有重複 invoice／Payment。
12. 兩張仍欠 invoice：一個 package row 顯示總餘額，明確選一張只收該張餘額，付完重算再選另一張；超過所選餘額或跨 package InvoiceID 被拒絕，single／batch 無自動分配。
13. 停止、無帳單但 pending report 不進待收，獨立審核入口仍可取得 report IDs 並確認／拒絕，空／越權 campus 被拒絕。
14. settle→合法 void/reversal 後原子重算 package 與全部 member flags，回到 ledger 所示 partial/unpaid；涵蓋每個 reversal sender/producer、重送與 rollback boundary，無歷史資料修復。
15. multi-subject package partial/final 收據 snapshot、實收金額、covered sessions 及重印一致，不只呈現 anchor 科目。
16. 非 anchor 部分付款後人工繳費單圖／copy/download 不再要求全額或顯示單科，所選 invoice 的本次可收額與 server 相符；未就緒舊 action 停用。已結清剩 ≤2 堂仍呈現既有續課控制且無待收款。
17. 非免費 package single／batch 的 amount=0 在登錄／確認皆拒絕，invoice／Payment／report confirmation／receipt 無變更。
18. `BillingController::recordPayment` 直接支付 package invoice：部分／最終付款皆以整包 ledger 決定 package 與全部 sibling flags，與 report confirm 一致；同一 invoice 金額限制、重送／並行、空 scope／跨 campus 零寫入均有回歸。
19. 每個 reversal producer（report void 與各 invoice-void 路徑）以非 super_admin 空 campus、跨 campus invoice/report ID、錯誤 student/course/package 身份驗證拒絕，Payment／reversal／invoice／report／package／member 全無變更；保留已授權與 super_admin 例外成功案例。
20. 一般進行中與停止方案的 single／batch 回報建立／拒絕以空 campus、跨 campus course/report ID、wrong student/member 驗證 fail closed，零 report／ledger／package 寫入；合法 scoped 與 super_admin 例外保留。
21. package partial／final 收據重印含 foreign-campus 或 wrong-student 成員時 fail closed，不回傳科目／covered dates；合法整包 snapshot 與原實收保持一致。
22. 已停止、已結清且剩 ≤2 堂不顯示續課／結束入口；停止仍欠帳單或需人工核對可達，進行中已繳低堂數提示保持。
23. single／batch fractional amount（含歷史 pending report）在登錄／確認拒絕，不截斷成 Payment／Invoice，也不產生 confirmed receipt；整數正額合法案例保持，R0 免費例外保持。
24. `CoursePackageController::update` 等 prospective direct paid flag 寫入不能在無 ledger 時產生新 R7 legacy 結清；同 ledger routine／空 scope 零寫入驗證，歷史已存在 flag 讀取保持。
25. 實際 `NotificationObserver`／`NotificationLineDispatcher` sender 對所有 count-package tuition in-app rows 零 LINE；保留每成員一則 in-app 及單科／月結既有 delivery。通知 action 僅 deep-link 到 C1，不以無 InvoiceID 登錄收款。
26. reliable paid package 有兩筆歷史 pending：保留 money paid／全部 report IDs／review flag，禁 confirm；不造新待收。混合 tutoring／billable package 不任選分類、不給可收額；全部明確 tutoring 排除。
27. 已付 16×1500＝24000 元加4堂：原24000 invoice/payment/receipt 不改，新6000 invoice，累計X30000／餘額6000；明確選新單部分2000／最終4000，flags 依整包 ledger 更新，原款不重收。
28. C5 rounding 邊界10.5元：原1堂11元，加1堂後X21元，新單10元並明列11−1；Invoice／Payment／report 合法實收皆整數元，不改舊 snapshot。
29. C5 event 重送／lost response／並行只建一次新 invoice 並加堂一次，operation key 參數不同拒絕；任何步驟失敗原子回滾。後續 catalog rate 更改不影響當次 snapshot／重印；uncertain legacy／discount／reprice／跨 campus 空 scope 不自動加堂／開單。
30. §5c 決策已定不等於完成實作；各 future PR 所屬回歸、獨立 review、required CI 與 rollback 證據通過前，不宣稱 Phase 2 runtime-ready／已部署。

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

## 5b. Closeout 決策追蹤（原 OPEN 已關閉）

OPEN-1 多帳單 action、OPEN-2 停止方案 pending 可達性、OPEN-3 合法逆向結清、OPEN-4 收據範圍，已由 §5c 的 Founder 2026-10-13 決策定案。這裡關閉的是產品／合約歧義；runtime 實作、測試及上線另由 future PR 交付。§5a 原始決策原文不變。

## 5c. Founder decisions 2026-10-13 closeout（定案）

Founder 在本次 PR 收尾 chat 明確核准下列原待決項；不授權歷史修復或 production migration。

| # | 決定 | 實作／驗證邊界 |
|---|---|---|
| C1（原 OPEN-1） | 多帳單 package 維持一列，aggregate total／remaining 與每次收款分開；人員每次明確選一張 invoice，只收其可收餘額，其他 invoice 逐張再選，不自動 allocation。 | §3b；package-wide invoice reuse、InvoiceID 身份／scope、單張金額上限及多帳單回歸。獨立 future 收款 write PR。 |
| C2（原 OPEN-2） | 已停止、無帳單但有 pending report 保留待收排除，另設獨立審核入口供確認／拒絕。 | 保留 R2-4 原意；報告可達性與 campus 隔離依 §3a/§3b。獨立 future 審核 workflow PR。 |
| C3（原 OPEN-3） | 合法 reversal／void 後重算 package 與所有 member settlement flags，不能留下 sibling 已繳。 | 同 transaction／locks、ledger 權威、各 reversal producer、settle→void 回歸與 rollback evidence。獨立 future write PR；不修歷史資料。 |
| C4（原 OPEN-4） | 收據呈現整包科目／covered session 範圍與本筆實收金額，不能只顯示 anchor 科目。 | package-aware receipt snapshot、partial／final／重印回歸，不分攤科目價款。獨立 future receipt/write PR。 |
| C5（加購收尾） | 新加購事件保存成交單價／堂數／前後整數應收與唯一操作鍵；舊定稿帳單、收款及收據不改，可靠未改費率的 count 方案另建累計應收差額帳單，明列取整差額，人員明確選單收款；事件與 invoice／entitlement／ledger settlement 原子且冪等。 | §3b prospective 加購及 AC27–29；unknown／折扣／改價／legacy 無可靠帳單保持核對。獨立 future write PR，不批准歷史修復。 |

Founder 對研究後的具體加購提案在本 chat 明確回覆：「如果這是你研究完大公司的產品後 就這樣定案吧」。此批准涵蓋 C5 新事件／價格快照／差額帳單／累計元整差額與 AC，不是 runtime 已交付或 production data 操作批准。

參考成熟產品的設計邊界：Stripe [invoicing integration](https://docs.stripe.com/invoicing/integration)（定稿金額保持／新事件另開單）、[idempotent requests](https://docs.stripe.com/api/idempotent_requests)（相同操作鍵參數重送）、Chargebee [one-time invoice](https://www.chargebee.com/docs/billing/2.0/kb/invoices-credit-notes-and-quotes/how-to-generate-an-invoice-for-a-customer)（一次性費用另建帳單）。這些資料支持 immutable invoice/event 設計，AllTrue 的累計取整與未知價款處理由本次 C5 定案；不把 vendor 的其他定價政策匯入。

每個 future PR 必須各自完成所屬實作、驗證、風險適當 review、required checks 與 rollback boundary；本文件 PR 不改 runtime，不作 historical repair／production migration，也不代表上述功能已交付。

## 6. 回滾

本檔為文件，回滾＝revert 本 PR。Phase 2-read 回滾＝revert 該讀取／提醒候選 PR，無帳務寫入、無 migration。Phase 2-write 必須另附具體 rollback boundary、ledger／audit 與驗證證據；revert 程式不會刪除已產生的 Invoice／Payment／reversal，既有帳務資料不得以本 RFC 自動修復。

# Bug Fix Plan — 吳艾潼帳務對帳狀態與收據流水口徑統一

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 帳務來源不一致 / Query 口徑錯誤 / UI 標籤語意混淆 |
| 根因摘要 | 系統同時以 `StudentClass.Paid/PayDate`、`Invoice.Status/PaidAmount`、`Payment/PaymentReport` 判斷付款狀態，導致同一課程可出現「付款流水已足額但 Invoice 仍未繳」與多頁重複顯示。 |
| 錯誤行為 | `INV-202605-000357`、`INV-202604-000199` 顯示已收 $13,200 但狀態未繳；已結清查詢與收款/收據紀錄看似重複；待處理頁的「已繳」其實混入續課/將到期提醒。 |
| 預期行為 | 帳務狀態由 ledger 淨額派生；Invoice 欄位不一致時標為例外待處理；錯帳用沖銷作廢；待處理頁標籤清楚區分「未繳待收」與「已繳但需續課/將到期」。 |
| 影響範圍 | 主任帳務中心、課程管理帳單 Modal、家長應收、主任催繳/續課提醒、收據流水。 |
| B1 偵查來源 | 本次 B1：`StudentClassController::invoices`、`PaymentReportController::directorRecord/confirm`、`AccountingController::ledger/settledCourses`、`TuitionCollectionPage`。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 帳務對帳狀態統一與錯帳沖銷 |
| 日期 | 2026-04-28 |
| 狀態 | Draft — awaiting approval |
| 目標角色 | director / admin / super_admin |
| 關聯案例 | 吳艾潼 `COURSE-000382`，正確帳單 `INV-202604-000360`，異常帳單 `INV-202604-000199`、`INV-202605-000357` |

## 2. 業務背景與影響

- 主任需要知道哪一筆是正確已收款，哪一筆是歷史錯帳。
- 已收款流水不可刪除，否則收據與稽核會斷。
- 待處理頁的「已繳」目前包含 `renew_needed` / `monthly_due_soon`，語意像「已收款」，但實際是續課/到期提醒。

## 3. 範圍

In:
- 新增 Invoice 例外沖銷作廢 API/UI，處理有 Payment 痕跡的錯帳。
- 課程帳單 Modal 顯示 `ledger_status`：`paid_by_ledger`、`open_status_without_balance`、`paid_amount_mismatch`、`voided`。
- 帳務中心「待處理」把 tab `已繳` 改名為 `續課/將到期`，並拆出「未繳待收」「待核帳」「續課/將到期」。
- 已結清查詢改為「課程層彙總」，收款與收據紀錄改為「收據流水」，文案說明兩者不是重複。
- 對 `INV-202604-000199`、`INV-202605-000357` 支援一鍵「沖銷作廢」後從應收/催繳排除。

Out:
- 不刪除任何 `Invoice`、`Payment`、`PaymentReport`。
- 不直接寫 production DB。
- 不改 `AlertController::tuition` 的核心提醒條件。
- 不做退款/銀行對帳流程。

## 4. RACI

- Responsible：AI Agent
- Accountable：AI Agent
- Consulted：使用者（作廢政策與 UI 文案批准）
- Informed：使用者

## 4b. Dependencies

- 依賴現有 `AccountingController::ledger` 的 anomaly 模型。
- 無 migration 優先；若需要結構化 credit note，另開 migration PR。

## 5. Acceptance Criteria

### AC-001：錯帳已收款但 Invoice 狀態未繳
- API 對 `Status='unpaid'` 但 Payment 淨額已足額的 Invoice 回傳 `ledger_status='open_status_without_balance'`。
- UI 不顯示成單純「未繳」，而顯示「已收足額 · 狀態待修復」。

### AC-002：例外沖銷作廢
- director 對有正向 Payment 的錯帳執行例外作廢，系統建立負值沖銷 Payment，Invoice 變 `void` 並從課程帳單列表排除。
- 原始 Payment/PaymentReport 仍可在 ledger 看到。

### AC-003：正確帳單保留
- `INV-202604-000360` 保留為正確帳單；作廢 `199/357` 不影響正確帳單與正確收據。

### AC-004：帳務中心語意
- 「待處理」不再把 `paid` 文案當作待收款；`renew_needed/monthly_due_soon` 改顯示為「續課/將到期」。
- 已結清查詢與收款流水頁各自清楚標示「課程彙總」與「收據流水」。

## 6. 功能需求

- FR-001：Invoice 列表應回傳 ledger 派生狀態，不只回傳 DB `Status`。
- FR-002：已足額付款但 Status 未繳的 Invoice 必須標記 anomaly。
- FR-003：例外作廢必須建立負值沖銷 Payment，且 `Method='void'`。
- FR-004：void invoice 不得進入家長應收、課程帳單、主任催繳未結清加總。
- FR-005：帳務中心 tab 文案必須反映實際流程，而非混用「已繳」。

## 7. NFR

- 不新增 N+1：Invoice/Payment/PaymentReport 需批次 eager load。
- 操作需 `lockForUpdate()`，避免同一 Invoice 重複沖銷。

## 8. 技術方向

- `StudentClassController::invoices`：加入 ledger net calculation 與 `ledger_status/anomalies` 回傳。
- `BillingController`：新增 `exceptionVoidInvoice`，保留既有 `voidInvoice` 的未收款 guard。
- `CourseManagement.vue`：新增「沖銷作廢」入口，並修正狀態 chip。
- `TuitionCollectionPage.vue`：重命名 tab/文案，避免把續課提醒誤解成已收款。
- `AccountingController::settledCourses`：排除 void invoice，並顯示 course-summary 與 receipt-ledger 的差異。

## 8b. Decision Log

- 2026-04-28：採 credit note / reversal 模式，不刪原 Payment。理由：符合 Xero/NetSuite/Sage 類會計 SaaS 對 paid invoice 的稽核作法。
- 2026-04-28：不放寬既有 direct void。理由：未收款作廢與已收款沖銷是不同會計事件。

## 9. 資安與存取控制

- 僅 `director/admin/super_admin`。
- 沿用 `require_campus` 與 invoice student campus guard。
- 不在 log 寫入完整個資，只記 `invoice_id/student_class_id/user_id`。

## 10. QA 驗收

- Happy：paid invoice + positive payment → exception void 成功。
- Edge：unpaid invoice no payment → direct void，不走 exception void。
- Edge：open status without balance → UI 顯示「已收足額 · 狀態待修復」。
- Error：teacher 403、cross-campus 403、already void 422。
- Revert-proof：stash 修復後新增測試應 fail。

## 11. 上線與維運

- 無 migration 優先。
- PR merge 後 deploy.yml 自動部署。
- 驗證 `/api/v1/health` 與 `version.json`。
- 回滾：PR revert，因不刪資料，沖銷紀錄保留；若需補救需走反向沖銷 PR。

## 12. 優先級

- P1：主任帳務判讀會直接影響收款/對帳。

## 13. 風險 / 假設 / 開放問題

- 業界參考：Xero paid invoice 需先移除/解除 payment 再 void；Sage/NetSuite 常用 credit memo/reversal 保留 audit trail。
- 開放問題：是否允許 `INV-202604-000199`、`INV-202605-000357` 作廢後在「例外/已作廢」查詢中可見？建議可見但不進應收。
- 開放問題：`待處理` 裡 `renew_needed/monthly_due_soon` 要改名為「續課/將到期」或另拉一個 tab？建議另拉 tab。

## 14. Definition of Done

- `vendor/bin/phpunit --filter BillingInvoiceVoidTest` 通過。
- `vendor/bin/phpunit --filter PaymentReportApiTest` 相關 accounting cases 通過。
- `cd frontend && npm run build` 通過。
- PR CI 綠。
- deploy success，`curl /api/v1/health` 回 `status=ok`。

## 2026-10-07 — refactor(billing): 發票已繳彙總與最近繳費日改由 BillingPayableResolver 提供
<!-- release-notes: silent_ship=silent-2026-10-07-arch2-c2-resolver-aggregates -->
<!-- silent-reason: 純內部搬移，數字與畫面不變；ContractMoneyState 只保留薄轉接給課程管理列表。 -->
- `invoiceAggregateByStudentClassIds` / `lastPaidAtByStudentClassIds` 的查詢搬進 `BillingPayableResolver`，與其他「已繳／未繳／未開單」答案同一個模組；AlertController、FinanceController 直接呼叫 resolver。

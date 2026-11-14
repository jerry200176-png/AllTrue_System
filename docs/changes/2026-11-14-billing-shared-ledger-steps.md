## 2026-11-14 — refactor(billing): 繳費寫入的三個共用步驟抽成共用類別（行為不變，#5113 step 3b 前置）
<!-- release-notes: silent_ship=silent-2026-11-14-billing-shared-ledger-steps -->
<!-- silent-reason: 只把既有程式搬到共用類別，確認入帳、撤銷收據與舊「已收」標記的行為都不變，教職員看不到差異 -->
- Three write steps of `PaymentReportController` move unchanged into shared classes, so the receipt move (#5113 step 3b) can reuse them instead of copying: `PackagePaidMarks` (shared-package Paid marks set by confirm and dropped by void / legacy-flag clear), `MonthlyBillFreeze` (confirm's price-before-first-payment step for monthly bills; a new optional period argument is unused here), `FirstBillAutoVoid` (void's #531 auto-void of a bill whose only receipt is taken back).
- The controller methods now delegate with the same arguments; no condition, query or write changed.

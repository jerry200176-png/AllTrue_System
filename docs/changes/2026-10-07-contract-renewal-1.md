## 2026-10-07 — refactor(billing): ContractRenewal owns renewal duplicate finders and the purchase-batch preview
<!-- release-notes: silent_ship=silent-2026-10-07-contract-renewal-1 -->
<!-- silent-reason: 純搬移重構（續報預覽與重複批次偵測移到 Services/Billing/ContractRenewal），畫面與行為不變。 -->
- ARCH2-C3 第 1 刀：StudentClassController 的 findDuplicatePurchaseBatch／findDuplicateMonthlyRenewal／redactRenewalDiscount 與 purchase_batch 預覽分支搬到 `ContractRenewal`；新增直接測試。

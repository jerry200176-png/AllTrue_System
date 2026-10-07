## 2026-10-07 — refactor(billing): ContractRenewal owns the shared course helpers
<!-- release-notes: silent_ship=silent-2026-10-07-contract-renewal-3 -->
<!-- silent-reason: 純搬移重構（建立課程紀錄、結案判斷、取消未來課堂三個 helper 移到 ContractRenewal），畫面與行為不變。 -->
- ARCH2-C3 第 3 刀：為了讓 renewMonthly／purchaseBatch／convertTrial 能搬進 `ContractRenewal`，先把它們共用的 `createStudentClassRecordResilient`、`courseNeedsPaymentReconciliation`、`cancelFutureScheduledSessions` 搬過去；控制器保留同名一行轉接，呼叫端（含 update()）不變。

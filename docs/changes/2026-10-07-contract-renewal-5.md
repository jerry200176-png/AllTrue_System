## 2026-10-07 — refactor(billing): ContractRenewal owns the purchase-batch transaction
<!-- release-notes: silent_ship=silent-2026-10-07-contract-renewal-5 -->
<!-- silent-reason: 純搬移重構（purchaseBatch 的交易本體移到 ContractRenewal::purchaseBatch），回應內容與狀態碼不變。 -->
- ARCH2-C3 第 5 刀：加購堂數的鎖課、建批次課程、展開課堂整段交易搬到 `ContractRenewal::purchaseBatch`；控制器只剩授權、驗證、權限檢查與送出回應。

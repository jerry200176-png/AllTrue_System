## 2026-10-07 — refactor(billing): ContractRenewal owns the trial conversion transaction
<!-- release-notes: silent_ship=silent-2026-10-07-contract-renewal-6 -->
<!-- silent-reason: 純搬移重構（convertTrial 的交易本體移到 ContractRenewal::convertTrial），回應內容與狀態碼不變。 -->
- ARCH2-C3 第 6 刀：試聽轉正式的鎖課、衝堂檢查、建正式課程、關閉試聽整段交易搬到 `ContractRenewal::convertTrial`，直接呼叫模組的 purchaseBatch，不再用「換掉 request 內容」的方式呼叫控制器。

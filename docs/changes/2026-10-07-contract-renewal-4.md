## 2026-10-07 — refactor(billing): ContractRenewal owns the renew-monthly transaction
<!-- release-notes: silent_ship=silent-2026-10-07-contract-renewal-4 -->
<!-- silent-reason: 純搬移重構（renewMonthly 的交易本體移到 ContractRenewal::renewMonthly），回應內容與狀態碼不變。 -->
- ARCH2-C3 第 4 刀：月結續約的鎖課、建新期課程、結算舊期、開未繳帳單整段交易搬到 `ContractRenewal::renewMonthly`；控制器只剩授權、驗證、權限檢查與送出回應。

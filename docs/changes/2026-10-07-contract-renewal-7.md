## 2026-10-07 — refactor(billing): ContractRenewal owns renewal confirmation
<!-- release-notes: silent_ship=silent-2026-10-07-contract-renewal-7 -->
<!-- silent-reason: 純搬移重構（renewalConfirm 的重算預覽、狀態比對與回執組裝移到 ContractRenewal::confirm），回應內容與狀態碼不變。 -->
- ARCH2-C3 第 7 刀：續報確認的鎖課、重算預覽、hash 不符 409／blocked 422、回執組裝搬到 `ContractRenewal::confirm`；實際建立課程仍呼叫控制器的加購／月結續約端點（保留各自的請求守門）。

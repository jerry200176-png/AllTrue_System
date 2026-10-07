## 2026-10-07 — refactor(billing): ContractRenewal owns the renewal preview
<!-- release-notes: silent_ship=silent-2026-10-07-contract-renewal-2 -->
<!-- silent-reason: 純搬移重構（續報預覽 buildRenewalPreview 移到 Services/Billing/ContractRenewal::preview），畫面與行為不變。 -->
- ARCH2-C3 第 2 刀：renew_monthly 預覽分支與 state_hash／preview_id 組裝搬到 `ContractRenewal::preview`；StudentClassController 只剩解析請求與回應。

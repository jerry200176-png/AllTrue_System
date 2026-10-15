## 2026-10-15 — security: AI 分診唯讀金鑰到期日在建立時固定、秘密掃描可偵測 (#4021 #4022 #4004)
<!-- release-notes: silent_ship=silent-2026-10-15-triage-token-hardening -->
<!-- silent-reason: 只強化超級管理員的 AI 分診金鑰安全，畫面與權限不變，不另發卡。 -->
- AI 分診唯讀金鑰的到期日在建立時就固定；之後調長有效天數只影響新金鑰，不會延長或救回已發出的金鑰（調短仍立即生效）。
- 秘密掃描（gitleaks 與 ci-preflight）會擋下誤提交的分診金鑰；gitleaks 恢復預設規則。
- 分診讀取若在伺服器端出錯，仍會留下安全稽核紀錄。

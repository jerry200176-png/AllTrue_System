## 2026-10-07 — refactor(billing): 逐堂涵蓋改走 Contract Money Verdict (ARCH2 PR2)
<!-- release-notes: silent_ship=silent-2026-10-07-arch2-coverage-on-verdict -->
<!-- silent-reason: 只是把同一套逐堂涵蓋邏輯搬進共用模組，畫面與數字不變。 -->
- 合約逐堂「已收／部分／未收」的計算移到 `ContractMoneyVerdict::lessons()`，ContractSessionCoverageController 改為呼叫它；輸出完全相同。

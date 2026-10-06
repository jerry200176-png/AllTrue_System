## 2026-10-06 — fix(ops): backlog catch-up POP — verify/rollback after drift, cross-contract coverage, rebuilt snapshot
<!-- release-notes: silent_ship=silent-2026-10-06-pop-backlog-review-fixes -->
<!-- silent-reason: 營運修復工具的安全修正，尚未在正式站執行 -->
- 補開帳單 POP：execute 成功後 verify/rollback 不再被新增資料擋住、同學生同科目他合約已涵蓋的月份不重複補收、重試時由現有資料重建回滾快照。
